<?php

namespace Tests\Feature;

use App\Models\Company;
use App\Models\PricingPlan;
use App\Models\Subscription;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Notification;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class SignupAtomicityTest extends TestCase
{
    use RefreshDatabase;

    public static function products(): array
    {
        return [['di', '/register', 'web'], ['pos', '/pos/register', 'pos'], ['fbrpos', '/fbr-pos/register', 'fbrpos']];
    }

    #[DataProvider('products')]
    public function test_late_trial_failure_rolls_back_registration_and_same_credentials_can_retry(string $product, string $url, string $guard): void
    {
        Mail::fake();
        Notification::fake();
        $other = Company::create(['name' => 'Untouched paid tenant', 'ntn' => 'ATOMIC-OTHER', 'product_type' => 'di']);
        $term = Subscription::create(['company_id' => $other->id, 'final_price' => 12000,
            'start_date' => now(), 'end_date' => now()->addYear(), 'active' => true]);
        $otherBefore = $other->fresh()->getAttributes();
        $termBefore = $term->fresh()->getAttributes();
        $plan = PricingPlan::firstOrCreate(['name' => 'Starter', 'product_type' => $product, 'is_trial' => false],
            ['price' => 19999, 'invoice_limit' => 100]);
        $tables = ['companies', 'users', 'subscriptions', 'registered_credentials', 'subscription_invoices', 'subscription_payments'];
        $before = [];
        foreach ($tables as $table) {
            $before[$table] = DB::table($table)->count();
        }
        $fail = true;
        Event::listen('eloquent.creating: '.Subscription::class, function ($subscription) use (&$fail, $other) {
            if ($fail && (int) $subscription->company_id !== (int) $other->id) {
                throw new \RuntimeException('Synthetic late trial failure');
            }
        });
        $input = ['name' => 'Fictional signup owner', 'email' => 'atomic-'.$product.'@example.invalid',
            'company_name' => 'Atomic fictional '.$product, 'company_ntn' => 'ATOMIC-'.$product,
            'password' => 'Synthetic123!', 'password_confirmation' => 'Synthetic123!',
            'pos_type' => 'general', 'pricing_plan_id' => $plan->id];
        $this->post($url, $input)->assertStatus(500);
        $this->assertGuest($guard);
        foreach ($tables as $table) {
            $this->assertSame($before[$table], DB::table($table)->count(), $table.' must roll back after trial failure');
        }
        $this->assertSame($otherBefore, $other->fresh()->getAttributes());
        $this->assertSame($termBefore, $term->fresh()->getAttributes());
        Mail::assertNothingSent();
        $fail = false;
        $this->post($url, $input)->assertSessionHasNoErrors()->assertRedirect();
        $company = Company::where('email', $input['email'])->firstOrFail();
        $this->assertSame($product, $company->product_type);
        $this->assertSame(1, Subscription::where('company_id', $company->id)->count());
        $this->assertSame(0.0, (float) $company->activeSubscription->final_price);
        $this->assertDatabaseHas('users', ['company_id' => $company->id, 'email' => $input['email']]);
        $this->assertSame($before['subscription_invoices'], DB::table('subscription_invoices')->count());
        $this->assertSame($before['subscription_payments'], DB::table('subscription_payments')->count());
    }
}

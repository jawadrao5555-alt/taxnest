<?php

namespace Tests\Feature;

use App\Models\Company;
use App\Models\PricingPlan;
use App\Models\Subscription;
use App\Services\ProspectiveSubscriptionPolicy;
use App\Support\HealthPanel;
use App\Support\NestErps;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Notification;
use Tests\TestCase;

class ProspectiveRegistrationPolicyTest extends TestCase
{
    use RefreshDatabase;

    public function test_healthcare_registration_only_offers_and_accepts_healthcare_packages(): void
    {
        Mail::fake();
        Notification::fake();
        config(['health.registration_open' => true]);
        $school = PricingPlan::create([
            'name' => 'Synthetic School Only', 'product_type' => NestErps::PRODUCT_TYPE,
            NestErps::VERTICAL_COLUMN => 'school', 'price' => 1000, 'invoice_limit' => 2, 'is_trial' => false,
        ]);
        $health = PricingPlan::create([
            'name' => 'Synthetic Healthcare Only', 'product_type' => HealthPanel::PRODUCT_TYPE,
            NestErps::VERTICAL_COLUMN => NestErps::HEALTH, 'price' => 1000, 'invoice_limit' => 2, 'is_trial' => false,
        ]);

        $this->get('/health/register?plan='.urlencode($school->name))
            ->assertOk()->assertViewHas('pickedPlanName', null)
            ->assertSee($health->name)->assertDontSee($school->name);

        $input = [
            'name' => 'Synthetic Owner', 'email' => 'health-vertical@example.invalid',
            'password' => 'Synthetic123!', 'password_confirmation' => 'Synthetic123!',
            'company_name' => 'Synthetic Hospital', 'company_ntn' => '8791901',
            'company_cnic' => '3520212345611', 'org_type' => 'hospital',
        ];
        $this->postJson('/health/register', $input + ['requested_plan' => $school->name])
            ->assertUnprocessable()->assertJsonValidationErrors('requested_plan');
        $this->postJson('/health/register', $input + ['pricing_plan_id' => $school->id])
            ->assertUnprocessable()->assertJsonValidationErrors('pricing_plan_id');
        $this->assertDatabaseMissing('companies', ['email' => $input['email']]);

        $this->post('/health/register', $input + ['pricing_plan_id' => $health->id])
            ->assertSessionHasNoErrors()->assertRedirect();
        $company = Company::where('email', $input['email'])->sole();
        $this->assertSame(NestErps::HEALTH, $company->getAttribute(NestErps::VERTICAL_COLUMN));
        $this->assertDatabaseHas('subscriptions', ['company_id' => $company->id, 'pricing_plan_id' => $health->id]);
    }

    public function test_new_public_registration_requires_own_explicit_package_and_freezes_policy(): void
    {
        Mail::fake();
        Notification::fake();
        config(['health.registration_open' => true]);
        $cases = [['di', '/register', 'web'], ['pos', '/pos/register', 'pos'], ['fbrpos', '/fbr-pos/register', 'fbrpos'], [\App\Support\HealthPanel::PRODUCT_TYPE, '/health/register', 'health']];
        foreach ($cases as $i => [$product,$url,$guard]) {
            $plan = PricingPlan::firstOrCreate(['name' => 'Business', 'product_type' => $product, 'is_trial' => false], ['price' => 1000, 'invoice_limit' => 2]);
            $input = ['name' => 'Synthetic selected owner', 'email' => 'selected-'.$i.'@example.invalid', 'password' => 'Synthetic123!', 'password_confirmation' => 'Synthetic123!', 'company_name' => 'Synthetic selected '.$i, 'company_ntn' => '879000'.($i + 1), 'company_cnic' => '35202123456'.sprintf('%02d', $i + 1), 'phone' => '0300111222'.$i, 'pos_type' => 'general', 'org_type' => 'clinic'];
            $before = Company::count();
            $this->postJson($url, $input)->assertUnprocessable();
            self::assertSame($before, Company::count());
            $this->postJson($url, $input + ['requested_plan' => 'Absent package', 'pricing_plan_id' => 999999])->assertUnprocessable();
            self::assertSame($before, Company::count());
            $this->post($url, $input + ['requested_plan' => $plan->name, 'pricing_plan_id' => $plan->id])->assertSessionHasNoErrors()->assertRedirect();
            $company = Company::where('email', $input['email'])->sole();
            $sub = Subscription::where('company_id', $company->id)->sole();
            self::assertSame($plan->id, $sub->pricing_plan_id);
            self::assertSame('selected_trial_v1', ProspectiveSubscriptionPolicy::display($sub)['version']);
            self::assertSame(-1, $sub->entitlementPlan()->invoice_limit);
            auth($guard)->logout();
            $this->flushSession();
        }
        self::assertDatabaseCount('subscription_invoices', 0);
        self::assertDatabaseCount('subscription_payments', 0);
    }

    public function test_additive_policy_migration_does_not_rewrite_legacy_terms_or_remove_frozen_history(): void
    {
        $c = Company::create(['name' => 'Synthetic retained legacy', 'ntn' => 'POLICY-RETAIN', 'product_type' => 'di']);
        $s = Subscription::create(['company_id' => $c->id, 'active' => true, 'final_price' => 1234, 'start_date' => now(), 'end_date' => now()->addYear(), 'override_type' => 'lifetime']);
        $before = $s->fresh()->getAttributes();
        $m = require database_path('migrations/2026_12_27_010000_add_prospective_subscription_policy.php');
        $m->up();
        $m->down();
        self::assertSame($before, $s->fresh()->getAttributes());
        self::assertNull($s->lifecycle_snapshot);
        self::assertTrue(\Illuminate\Support\Facades\Schema::hasColumn('subscriptions', 'lifecycle_snapshot'));
    }
}

<?php

namespace Tests\Feature;

use App\Models\{AdminAuditLog, AdminUser, Company, PaymentProof, PricingPlan, Subscription, SubscriptionInvoice};
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\{Http, Mail};
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class PaidRenewalSchemaTest extends TestCase
{
    use RefreshDatabase;

    public static function products(): array
    {
        return [
            'DI' => ['di'],
            'PRA POS' => ['pos'],
            'FBR POS' => ['fbrpos'],
            'ERP' => ['erps'],
        ];
    }

    #[DataProvider('products')]
    public function test_paid_renewal_uses_the_real_schema_and_retains_a_leap_day_term_once(string $product): void
    {
        Mail::fake();
        Http::preventStrayRequests();
        $this->travelTo(\Carbon\Carbon::parse('2026-11-07 10:00:00'));
        $admin = AdminUser::create([
            'name' => 'Synthetic reviewer', 'email' => 'paid-reviewer@example.test',
            'password' => bcrypt('SyntheticOnly123!'), 'role' => 'super_admin',
        ]);
        $company = Company::create([
            'name' => 'Synthetic renewal', 'ntn' => 'RCRENEW1', 'product_type' => $product,
            'email' => 'renewal@example.test', 'status' => 'approved', 'company_status' => 'active',
        ]);
        $plan = PricingPlan::create([
            'name' => 'Starter', 'product_type' => $product, 'price' => 1000,
            'is_trial' => false, 'invoice_limit' => 100,
            'price_yearly' => 1000,
        ]);
        $old = Subscription::create([
            'company_id' => $company->id, 'pricing_plan_id' => $plan->id, 'active' => true,
            'billing_cycle' => 'annual', 'final_price' => 1000,
            'start_date' => '2026-03-01', 'end_date' => '2028-02-29',
        ]);
        $proof = PaymentProof::create([
            'company_id' => $company->id, 'pricing_plan_id' => $plan->id, 'billing_cycle' => 'annual',
            'amount' => 1000, 'proof_path' => 'synthetic-paid-renewal', 'status' => 'pending',
        ]);
        $input = ['pricing_plan_id' => $plan->id, 'billing_cycle' => 'annual', 'verified_received_amount' => 1000];
        $this->actingAs($admin, 'admin')->post(route('saas.admin.payment-proofs.approve', $proof->id), $input)
            ->assertRedirect()->assertSessionHas('success');
        $new = Subscription::where('company_id', $company->id)->where('active', true)->sole();
        $this->assertSame('2029-02-28', $new->end_date->toDateString());
        $this->assertSame('2028-02-29', $old->fresh()->end_date->toDateString());
        $this->assertEquals(1000, $old->fresh()->final_price);
        $this->assertEquals(1000, $new->final_price);
        $this->assertSame($new->id, (int) $proof->fresh()->subscription_id);
        $invoice = SubscriptionInvoice::where('payment_proof_id', $proof->id)->sole();
        $this->assertEquals(1000, $invoice->amount);
        $this->assertEquals(1000, $invoice->payments()->sole()->amount);
        $this->post(route('saas.admin.payment-proofs.approve', $proof->id), $input)->assertRedirect();
        $this->assertSame(2, Subscription::where('company_id', $company->id)->count());
        $this->assertSame(1, SubscriptionInvoice::where('payment_proof_id', $proof->id)->count());
        $this->assertSame(1, $invoice->payments()->count());
        $event = AdminAuditLog::where('target_type', 'PaymentProof')->where('target_id', $proof->id)
            ->where('action', 'Payment proof approved')->sole();
        $this->assertSame('2029-02-28', $event->metadata['period_end']);
        $this->travelBack();
    }
}

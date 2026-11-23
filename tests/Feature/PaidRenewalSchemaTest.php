<?php

namespace Tests\Feature;

use App\Models\{AdminAuditLog, AdminUser, Company, PaymentProof, PricingPlan, Subscription, SubscriptionInvoice};
use App\Services\DistributorDiscountService;
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

    public function test_half_year_quote_cannot_renew_after_paid_branch_capacity_changes(): void
    {
        Mail::fake();
        Http::preventStrayRequests();
        config(['billing.shared_configured_cycles' => true]);
        $admin = AdminUser::create([
            'name' => 'Fictional reviewer', 'email' => 'branch-reviewer@example.test',
            'password' => bcrypt('SyntheticOnly123!'), 'role' => 'super_admin',
        ]);
        $company = Company::create([
            'name' => 'Fictional POS shop', 'ntn' => 'RCBRANCH1', 'product_type' => 'pos',
            'email' => 'branch-renewal@example.test', 'status' => 'approved', 'company_status' => 'active',
            'extra_branch_slots' => 1,
        ]);
        $plan = PricingPlan::create([
            'name' => 'Business', 'product_type' => 'pos', 'price' => 20000,
            'price_semi_annual' => 10500, 'branch_limit' => 2, 'is_trial' => false,
        ]);
        $existing = Subscription::create([
            'company_id' => $company->id, 'pricing_plan_id' => $plan->id, 'active' => true,
            'billing_cycle' => 'semi_annual', 'final_price' => 15500,
            'start_date' => now()->subMonth()->toDateString(), 'end_date' => now()->addMonths(5)->toDateString(),
        ]);
        $quote = DistributorDiscountService::quote($company, $plan, 'semi_annual');
        $this->assertEquals(5000, $quote['undiscounted_addon_amount']);
        $this->assertEquals(15500, $quote['net_quote']);
        $proof = PaymentProof::create([
            'company_id' => $company->id, 'pricing_plan_id' => $plan->id,
            'billing_cycle' => 'semi_annual', 'amount' => $quote['net_quote'],
            'proof_path' => 'synthetic-branch-renewal', 'status' => 'pending', 'distributor_quote_snapshot' => $quote,
        ]);
        $company->update(['extra_branch_slots' => 2]);
        $this->assertNull(DistributorDiscountService::validateSnapshot($quote, $company, $plan, 'semi_annual'));
        $input = [
            'pricing_plan_id' => $plan->id, 'billing_cycle' => 'semi_annual',
            'verified_received_amount' => $quote['net_quote'],
        ];

        $this->actingAs($admin, 'admin')->post(route('saas.admin.payment-proofs.approve', $proof->id), $input)
            ->assertRedirect()->assertSessionHas('error', 'Cannot approve: Paid branch slots changed after this quote. Review a fresh quote before approving payment.');
        $this->assertSame('pending', $proof->fresh()->status);
        $this->assertEquals($quote, $proof->fresh()->distributor_quote_snapshot);
        $this->assertDatabaseHas('subscriptions', ['id' => $existing->id, 'active' => true]);
        $this->assertDatabaseMissing('subscription_invoices', ['payment_proof_id' => $proof->id]);
        $this->assertEquals(2, $company->fresh()->extra_branch_slots);
    }
}

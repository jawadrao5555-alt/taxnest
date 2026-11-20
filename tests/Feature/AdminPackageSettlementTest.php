<?php

namespace Tests\Feature;

use App\Models\{AdminUser, Company, PaymentProof, PricingPlan, Subscription, SubscriptionInvoice, SubscriptionPayment};
use App\Services\AdminPackageSettlement;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

class AdminPackageSettlementTest extends TestCase
{
    use RefreshDatabase;

    private function sale(): array
    {
        $admin = AdminUser::create(['name' => 'Example reviewer', 'email' => 'admin@sale.invalid', 'role' => 'admin', 'password' => 'Synthetic123!']);
        $company = Company::create(['name' => 'Example company', 'ntn' => 'SALE0001', 'product_type' => 'pos', 'status' => 'approved', 'company_status' => 'active']);
        $plan = PricingPlan::create(['name' => 'Starter', 'product_type' => 'pos', 'price' => 1000, 'is_trial' => false, 'invoice_limit' => 100]);
        $this->actingAs($admin, 'admin');
        return [$company, $plan, $admin, [
            'verified_received_amount' => '1000.00', 'payment_method' => 'cash', 'payment_date' => today()->subDay()->toDateString(),
            'reference' => 'Example receipt 1', 'expected_subscription_id' => 0, 'idempotency_key' => (string) Str::uuid(),
        ]];
    }

    public function test_confirmed_sale_is_atomic_and_retry_returns_the_original_paid_dated_receipt(): void
    {
        [$company, $plan, $admin, $payment] = $this->sale();
        $sale = AdminPackageSettlement::assign($company->id, $plan->id, 'annual', $payment, $admin->id);
        $this->assertSame($sale->id, AdminPackageSettlement::assign($company->id, $plan->id, 'annual', $payment, $admin->id)->id);
        $receipt = $sale->payments()->sole();
        $this->assertSame('cash', $receipt->payment_method);
        $this->assertSame($payment['payment_date'], $receipt->paid_at->toDateString());
        $this->assertEquals(1000, $receipt->amount);
        $this->assertSame('assignment', $receipt->receipt_snapshot['payment_origin']);
        $this->assertSame('paid', $sale->status);
        $this->assertSame(1, Subscription::where('company_id', $company->id)->count());
        $this->assertSame(1, PaymentProof::where('company_id', $company->id)->where('status', 'verified')->count());
        try {
            AdminPackageSettlement::assign($company->id, $plan->id, 'annual', array_replace($payment, ['payment_method' => 'card']), $admin->id);
            $this->fail('Receipt replay allowed a changed method.');
        } catch (ValidationException $e) { $this->assertArrayHasKey('payment', $e->errors()); }
        $this->expectException(ValidationException::class);
        AdminPackageSettlement::assign($company->id, $plan->id, 'annual',
            array_replace($payment, ['idempotency_key' => (string) Str::uuid()]), $admin->id);
    }

    public function test_pending_uploaded_package_proof_is_not_collected_twice(): void
    {
        [$company, $plan, $admin, $payment] = $this->sale();
        $proof = PaymentProof::create(['company_id' => $company->id, 'pricing_plan_id' => $plan->id, 'amount' => 1000,
            'billing_cycle' => 'annual', 'proof_path' => 'fixture-upload', 'status' => 'pending']);
        try {
            AdminPackageSettlement::assign($company->id, $plan->id, 'annual', $payment, $admin->id);
            $this->fail('Pending payment was taken twice.');
        } catch (ValidationException $e) { $this->assertArrayHasKey('payment', $e->errors()); }
        $this->assertSame('pending', $proof->fresh()->status);
        $this->assertSame(0, SubscriptionPayment::count());
        $this->assertSame(0, Subscription::where('company_id', $company->id)->count());
    }

    public function test_historical_collection_keeps_original_term_and_separate_addon_charge(): void
    {
        [$company, $plan, $admin, $payment] = $this->sale();
        $sub = Subscription::create(['company_id' => $company->id, 'pricing_plan_id' => $plan->id, 'billing_cycle' => 'annual',
            'start_date' => today()->subYears(2), 'end_date' => today()->subYear(), 'active' => false, 'final_price' => 1000]);
        SubscriptionInvoice::create(['company_id' => $company->id, 'subscription_id' => $sub->id, 'due_date' => today(),
            'amount' => 10, 'status' => 'paid', 'document_snapshot' => ['kind' => 'extra_branch']]);
        $before = $sub->fresh()->getAttributes();
        $payment['payment_method'] = 'card';
        $doc = AdminPackageSettlement::historical($company->id, $sub->id, $payment, $admin->id);
        $this->assertSame($before, $sub->fresh()->getAttributes());
        $this->assertSame($doc->id, AdminPackageSettlement::historical($company->id, $sub->id, $payment, $admin->id)->id);
        $this->assertSame('card', $doc->payments()->sole()->payment_method);
        $this->assertSame(1, SubscriptionInvoice::packageCharge()->count());
        $this->expectException(ValidationException::class);
        AdminPackageSettlement::historical($company->id, $sub->id, array_replace($payment, ['idempotency_key' => (string) Str::uuid()]), $admin->id);
    }

    public function test_settling_a_known_unpaid_charge_keeps_that_charge_and_blocks_a_second_cash_entry(): void
    {
        [$company, $plan, $admin, $payment] = $this->sale();
        $sub = Subscription::create(['company_id' => $company->id, 'pricing_plan_id' => $plan->id, 'billing_cycle' => 'annual',
            'start_date' => today()->subYear(), 'end_date' => today(), 'active' => false, 'final_price' => 1000]);
        $legacy = SubscriptionInvoice::create(['company_id' => $company->id, 'subscription_id' => $sub->id,
            'due_date' => today(), 'amount' => 1000, 'status' => 'pending']);
        $payment['subscription_invoice_id'] = $legacy->id;
        $doc = AdminPackageSettlement::historical($company->id, $sub->id, $payment, $admin->id);
        $this->assertSame($legacy->id, $doc->id);
        $this->assertSame($doc->id, AdminPackageSettlement::historical($company->id, $sub->id, $payment, $admin->id)->id);
        $this->assertSame(1, SubscriptionInvoice::count());
        $this->assertSame('paid', $legacy->fresh()->status);
        $this->assertNull($legacy->fresh()->document_snapshot);
        $this->assertNotNull($doc->payments()->sole()->receipt_number);
    }

    public function test_verified_historical_recovery_uses_independent_amount_without_second_payment_or_activation(): void
    {
        [$company, $plan, $admin, $payment] = $this->sale();
        $sub = Subscription::create(['company_id' => $company->id, 'pricing_plan_id' => $plan->id, 'billing_cycle' => 'annual',
            'start_date' => today()->subYear(), 'end_date' => today(), 'active' => false, 'final_price' => 1000]);
        $proof = PaymentProof::create(['company_id' => $company->id, 'subscription_id' => $sub->id, 'pricing_plan_id' => $plan->id,
            'billing_cycle' => 'annual', 'amount' => 9000, 'distributor_net_amount' => 1000, 'payment_method' => 'bank',
            'payment_date' => $payment['payment_date'], 'proof_path' => 'fixture-evidence', 'status' => 'verified',
            'verified_by' => $admin->id, 'verified_at' => now()]);
        $document = AdminPackageSettlement::historicalVerifiedProof($company->id, $sub->id, $proof->id, $admin->id);
        $replay = AdminPackageSettlement::historicalVerifiedProof($company->id, $sub->id, $proof->id, $admin->id);
        $this->assertSame($replay->id, $document->id);
        $this->assertEquals(1000, $document->payments()->sole()->amount);
        $this->assertEquals(9000, $proof->fresh()->amount);
        $this->assertFalse($sub->fresh()->active);
    }
}

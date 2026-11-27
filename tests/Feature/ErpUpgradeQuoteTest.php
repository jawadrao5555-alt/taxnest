<?php

namespace Tests\Feature;

use App\Models\AdminUser;
use App\Models\Company;
use App\Models\PaymentProof;
use App\Models\PricingPlan;
use App\Models\Subscription;
use App\Models\SubscriptionFinancialAdjustment;
use App\Models\SubscriptionInvoice;
use App\Models\SubscriptionPayment;
use App\Services\FbrPosPlanComparisonService;
use App\Services\SubscriptionAssignmentService;
use App\Services\VerifiedSubscriptionDocuments;
use App\Support\NestErps;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class ErpUpgradeQuoteTest extends TestCase
{
    use RefreshDatabase;

    public function test_paid_hospital_source_quotes_only_the_difference_and_preserves_all_records(): void
    {
        [$company, $subscription, $invoice, $receipt] = $this->verifiedSource();
        $plan = $this->destinationPlan();
        $before = [$company, $subscription, $invoice, $receipt];
        $snapshots = array_map(fn ($record) => $record->fresh()->getAttributes(), $before);

        $this->moveToFebruary();
        $response = $this->get($this->estimateUrl($company, $plan));
        $response->assertOk()
            ->assertSee('PKR 90,000.00')->assertSee('PKR 18,100.00')->assertSee('PKR 71,900.00')
            ->assertSee('181 of 365 paid days remain')->assertDontSee('synthetic-bank-reference');
        $this->assertTrue($response->headers->hasCacheControlDirective('no-store'));
        $this->assertTrue($response->headers->hasCacheControlDirective('private'));

        // A repeated preview carries no payable document or reusable credit.
        $this->get($this->estimateUrl($company, $plan))->assertSee('PKR 71,900.00');
        $this->assertSame($snapshots, array_map(fn ($record) => $record->fresh()->getAttributes(), $before));
        $this->assertDatabaseCount('subscription_invoices', 1);
        $this->assertDatabaseCount('subscription_payments', 1);
        $this->assertDatabaseCount('subscription_financial_adjustments', 0);
        $this->assertDatabaseMissing('companies', ['product_type' => NestErps::PRODUCT_TYPE]);

        $cheaperPlan = $this->destinationPlan(['name' => 'Fictional smaller Hospital', 'price' => 10000]);
        $this->get($this->estimateUrl($company, $cheaperPlan))->assertSee('PKR 0.00')->assertDontSee('PKR -');
    }

    public function test_missing_or_expired_paid_evidence_cannot_quote_an_upgrade(): void
    {
        [$company, , $invoice] = $this->verifiedSource();
        $plan = $this->destinationPlan();

        $this->travelTo(CarbonImmutable::parse('2027-08-01 00:00:00', 'Asia/Karachi'));
        $this->getJson($this->estimateUrl($company, $plan))->assertUnprocessable()->assertJsonValidationErrors('source_term');

        $this->moveToFebruary();
        DB::table('payment_proofs')->where('id', $invoice->payment_proof_id)->update(['status' => 'pending']);
        $this->getJson($this->estimateUrl($company, $plan))->assertUnprocessable()->assertJsonValidationErrors('source_term');
        $this->get($this->estimateUrl($company, $plan))->assertSee('A reviewed payment receipt')->assertDontSee('Estimated difference');
    }

    public function test_estimate_refuses_other_tenants_adjusted_sources_and_ineligible_packages(): void
    {
        [$company, , $invoice] = $this->verifiedSource();
        $other = $this->sourceCompany('Second synthetic hospital');
        $plan = $this->destinationPlan();
        $foreignPlan = $this->destinationPlan(['product_type' => 'di']);
        $clinicOnly = $this->destinationPlan(['health_modules' => ['opd']]);

        $this->moveToFebruary();
        $this->getJson($this->estimateUrl($other, $plan))->assertUnprocessable()->assertJsonValidationErrors('source_term');
        $this->getJson($this->estimateUrl($company, $foreignPlan))->assertUnprocessable()->assertJsonValidationErrors('pricing_plan_id');
        $this->getJson($this->estimateUrl($company, $clinicOnly))->assertUnprocessable()->assertJsonValidationErrors('pricing_plan_id');
        $this->getJson($this->estimateUrl($company, $plan, 'monthly'))->assertUnprocessable()->assertJsonValidationErrors('billing_cycle');

        SubscriptionFinancialAdjustment::create([
            'admin_id' => auth('admin')->id(), 'company_id' => $company->id, 'subscription_invoice_id' => $invoice->id,
            'kind' => SubscriptionFinancialAdjustment::CREDIT, 'amount' => 100, 'currency' => 'PKR',
            'reason' => 'Fictional reviewed adjustment', 'idempotency_key' => 'synthetic-reviewed-adjustment',
            'occurred_at' => now(), 'evidence_snapshot' => ['effect' => 'evidence_only'],
        ]);
        $this->getJson($this->estimateUrl($company, $plan))->assertUnprocessable()->assertJsonValidationErrors('source_term');
    }

    public function test_only_a_super_admin_can_open_the_estimate(): void
    {
        [$company] = $this->verifiedSource();
        $plan = $this->destinationPlan();
        $operator = AdminUser::create(['name' => 'Fictional operator', 'email' => 'quote-operator@example.invalid',
            'role' => 'admin', 'password' => 'SyntheticOnly123!']);

        $this->actingAs($operator, 'admin')->get($this->estimateUrl($company, $plan))->assertForbidden();
    }

    private function verifiedSource(): array
    {
        $this->travelTo(CarbonImmutable::parse('2026-08-01 09:00:00', 'Asia/Karachi'));
        $admin = AdminUser::create(['name' => 'Fictional billing reviewer', 'email' => 'quote-reviewer@example.invalid',
            'role' => 'super_admin', 'password' => 'SyntheticOnly123!']);
        $this->actingAs($admin, 'admin');
        $company = $this->sourceCompany('Fictional Hospital Collections');
        $plan = PricingPlan::create(['name' => FbrPosPlanComparisonService::SELLABLE_PLAN_NAMES[0], 'product_type' => 'fbrpos',
            'is_trial' => false, 'price' => 36500, 'invoice_limit' => 100]);
        $subscription = SubscriptionAssignmentService::assign($company->id, $plan->id, 'annual', false, true);
        $proof = PaymentProof::create(['company_id' => $company->id, 'subscription_id' => $subscription->id,
            'pricing_plan_id' => $plan->id, 'billing_cycle' => 'annual', 'status' => 'verified',
            'verified_by' => $admin->id, 'verified_at' => now(), 'amount' => 38000,
            'payment_method' => 'bank_transfer', 'reference' => 'synthetic-bank-reference', 'proof_path' => 'synthetic-proof']);
        $invoice = DB::transaction(fn () => VerifiedSubscriptionDocuments::record($proof, $subscription, 38000));
        $this->assertInstanceOf(SubscriptionInvoice::class, $invoice);
        $receipt = $invoice->payments()->sole();
        $this->assertInstanceOf(SubscriptionPayment::class, $receipt);

        return [$company, $subscription, $invoice, $receipt];
    }

    private function sourceCompany(string $name): Company
    {
        return Company::create(['name' => $name, 'ntn' => 'SYN-'.substr(sha1($name), 0, 12), 'product_type' => 'fbrpos', 'pos_type' => 'clinic',
            'business_category' => 'clinic', 'status' => 'approved', 'company_status' => 'active']);
    }

    private function destinationPlan(array $overrides = []): PricingPlan
    {
        return PricingPlan::create(array_replace(['name' => 'Fictional Hospital ERP', 'product_type' => NestErps::PRODUCT_TYPE,
            'erps_vertical' => NestErps::HEALTH, 'health_modules' => ['opd', 'ipd', 'accounts'],
            'is_trial' => false, 'price' => 90000, 'invoice_limit' => 100], $overrides));
    }

    private function moveToFebruary(): void
    {
        $this->travelTo(CarbonImmutable::parse('2027-02-01 09:00:00', 'Asia/Karachi'));
    }

    private function estimateUrl(Company $company, PricingPlan $plan, string $cycle = 'annual'): string
    {
        return route('saas.admin.companies.erpUpgradeQuote', ['company' => $company,
            'pricing_plan_id' => $plan->id, 'billing_cycle' => $cycle]);
    }
}

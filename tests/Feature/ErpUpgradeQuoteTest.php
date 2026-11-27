<?php

namespace Tests\Feature;

use App\Models\AdminUser;
use App\Models\Company;
use App\Models\ErpUpgradeSettlement;
use App\Models\PaymentProof;
use App\Models\PricingPlan;
use App\Models\Subscription;
use App\Models\SubscriptionFinancialAdjustment;
use App\Models\SubscriptionInvoice;
use App\Models\SubscriptionPayment;
use App\Models\User;
use App\Services\ErpUpgradeSettlementService;
use App\Services\FbrPosPlanComparisonService;
use App\Services\SubscriptionAssignmentService;
use App\Services\VerifiedSubscriptionDocuments;
use App\Support\NestErps;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
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

    public function test_admin_upgrade_forms_pin_the_displayed_amount_and_accept_the_exact_difference(): void
    {
        [$source] = $this->verifiedSource();
        $destination = $this->upgradeDestination($source);
        $plan = $this->destinationPlan();
        $this->moveToFebruary();
        $estimate = $this->estimateUrl($source, $plan);
        $freeze = route('saas.admin.companies.erpUpgradeFreeze', $source);
        $details = [
            'destination_company_id' => $destination->id,
            'pricing_plan_id' => $plan->id,
            'billing_cycle' => 'annual',
            'owner_authorization_reference' => 'Fictional owner case 18',
            'expected_package_paisa' => 9000000,
            'expected_credit_paisa' => 1810000,
        ];

        $this->get($estimate)->assertOk()->assertSee('Freeze this quote');
        $this->from($estimate)->post($freeze, array_replace($details, ['expected_credit_paisa' => 1810001]))
            ->assertRedirect($estimate)->assertSessionHasErrors('quote');
        $this->assertDatabaseCount('erp_upgrade_settlements', 0);

        $response = $this->post($freeze, $details);
        $quote = ErpUpgradeSettlement::sole();
        $show = route('saas.admin.companies.erpUpgradeQuote', ['company' => $source, 'settlement_id' => $quote->id]);
        $response->assertRedirect($show);
        $this->get($show)->assertSee('Difference payable')->assertSee('PKR 71,900.00');
        $settle = route('saas.admin.companies.erpUpgradeSettle', [$source, $quote->id]);
        $this->from($show)->post($settle, ['verified_received_amount' => '90000', 'payment_reference' => 'Fictional transfer 18'])
            ->assertRedirect($show)->assertSessionHasErrors('verified_received_amount');
        $this->post($settle, ['verified_received_amount' => '71900', 'payment_reference' => 'Fictional transfer 18'])
            ->assertRedirect($show);
        $this->get($show)->assertSee('Difference verified.')->assertSee('Activate Hospital ERP package');
        $this->assertDatabaseMissing('subscriptions', ['company_id' => $destination->id]);
    }

    public function test_frozen_upgrade_accepts_only_the_difference_and_allocates_credit_once(): void
    {
        [$source, $subscription, $invoice, $receipt] = $this->verifiedSource();
        $destination = $this->upgradeDestination($source);
        $plan = $this->destinationPlan();
        $alternatePlan = $this->destinationPlan(['name' => 'Alternative Hospital package', 'price' => 80000]);
        $service = app(ErpUpgradeSettlementService::class);
        $adminId = (int) auth('admin')->id();
        $before = [$source, $destination, $subscription, $invoice, $receipt];
        $snapshots = array_map(fn ($record) => $record->fresh()->getAttributes(), $before);
        $this->moveToFebruary();

        $quote = $service->freeze($source->id, $destination->id, $plan->id, 'annual', $adminId, 'Fictional case 12');
        $alternate = $service->freeze($source->id, $destination->id, $alternatePlan->id, 'annual', $adminId, 'Fictional case 12');
        $again = $service->freeze($source->id, $destination->id, $plan->id, 'annual', $adminId, 'Fictional case 12');
        $this->assertSame($quote->id, $again->id);
        $this->assertNull($quote->settled_at);
        $this->assertSame(9000000, $quote->target_price_paisa);
        $this->assertSame(1810000, $quote->credit_paisa);
        $this->assertSame(7190000, $quote->difference_paisa);

        $this->expectValidation('verified_received_amount', fn () => $service->settle(
            $source->id, $quote->id, '90000', 'Fictional bank reference', $adminId
        ));
        $this->expectValidation('verified_received_amount', fn () => $service->settle(
            $source->id, $quote->id, '71899.99', 'Fictional bank reference', $adminId
        ));
        $this->expectValidation('payment_reference', fn () => $service->settle(
            $source->id, $quote->id, '71900.00', 'SYNTHETIC-BANK-REFERENCE', $adminId
        ));
        $this->assertNull($quote->fresh()->settled_at);

        $settled = $service->settle($source->id, $quote->id, '71900.00', 'Fictional bank reference', $adminId);
        $replay = $service->settle($source->id, $quote->id, '71900', ' fictional bank reference ', $adminId);
        $this->assertNotNull($settled->settled_at);
        $this->assertSame($settled->id, $replay->id);
        $this->assertSame(7190000, $settled->verified_received_paisa);
        $this->assertEquals($invoice->id, $settled->allocated_source_invoice_id);
        $this->expectValidation('source_term', fn () => $service->settle(
            $source->id, $alternate->id, '61900', 'Another fictional bank reference', $adminId
        ));

        $this->assertSame($snapshots, array_map(fn ($record) => $record->fresh()->getAttributes(), $before));
        $this->assertDatabaseCount('subscription_invoices', 1);
        $this->assertDatabaseCount('subscription_payments', 1);
        $this->assertDatabaseCount('payment_proofs', 1);
        $this->assertDatabaseMissing('subscriptions', ['company_id' => $destination->id]);
        $this->assertDatabaseCount('erp_upgrade_settlements', 2);
        $this->assertSame(1, ErpUpgradeSettlement::whereNotNull('allocated_source_invoice_id')->count());
    }

    public function test_zero_difference_is_explicit_credit_settlement_without_a_cash_receipt(): void
    {
        [$source] = $this->verifiedSource();
        $destination = $this->upgradeDestination($source);
        $plan = $this->destinationPlan(['price' => 10000]);
        $service = app(ErpUpgradeSettlementService::class);
        $adminId = (int) auth('admin')->id();
        $this->moveToFebruary();
        $quote = $service->freeze($source->id, $destination->id, $plan->id, 'annual', $adminId, 'Fictional authorization');
        $this->assertSame(0, $quote->difference_paisa);
        $this->assertSame(1000000, $quote->credit_paisa);
        $this->assertNull($quote->settled_at);

        $this->expectValidation('payment_reference', fn () => $service->settle(
            $source->id, $quote->id, '0', 'Fictional reference', $adminId
        ));
        $settlement = $service->settle($source->id, $quote->id, '0.00', null, $adminId);
        $this->assertNotNull($settlement->settled_at);
        $this->assertNull($settlement->payment_reference);
        $this->assertSame(0, $settlement->verified_received_paisa);
        $this->assertDatabaseCount('subscription_payments', 1);
    }

    public function test_settlement_refuses_wrong_owner_stale_terms_and_changed_package(): void
    {
        [$source] = $this->verifiedSource();
        $destination = $this->upgradeDestination($source);
        $other = Company::create(['name' => 'Unrelated Hospital ERP', 'ntn' => 'SYN-UNRELATED-ERP',
            'product_type' => NestErps::PRODUCT_TYPE,
            'erps_vertical' => NestErps::HEALTH, 'health_org_type' => 'hospital', 'status' => 'approved', 'company_status' => 'active']);
        $plan = $this->destinationPlan();
        $service = app(ErpUpgradeSettlementService::class);
        $adminId = (int) auth('admin')->id();
        $this->moveToFebruary();

        $this->expectValidation('destination_company_id', fn () => $service->freeze(
            $source->id, $other->id, $plan->id, 'annual', $adminId, 'Fictional case 12'
        ));
        $this->expectValidation('owner_authorization_reference', fn () => $service->freeze(
            $source->id, $destination->id, $plan->id, 'annual', $adminId, ' '
        ));
        $quote = $service->freeze($source->id, $destination->id, $plan->id, 'annual', $adminId, 'Fictional case 12');
        $plan->update(['price' => 91000]);
        $this->expectValidation('quote', fn () => $service->settle(
            $source->id, $quote->id, '71900', 'Fictional bank reference', $adminId
        ));
        $this->travelTo(CarbonImmutable::parse('2027-02-02 09:00:00', 'Asia/Karachi'));
        $this->expectValidation('quote', fn () => $service->settle(
            $source->id, $quote->id, '71900', 'Fictional bank reference', $adminId
        ));
        $this->assertNull($quote->fresh()->settled_at);
    }

    public function test_existing_prospective_payment_or_malformed_trial_blocks_the_upgrade(): void
    {
        [$source] = $this->verifiedSource();
        $destination = $this->upgradeDestination($source);
        $plan = $this->destinationPlan();
        $service = app(ErpUpgradeSettlementService::class);
        $adminId = (int) auth('admin')->id();
        $this->moveToFebruary();

        $malformedTrial = Subscription::create(['company_id' => $destination->id, 'pricing_plan_id' => $plan->id,
            'active' => true, 'trial_ends_at' => now()->subDay(), 'start_date' => now()->subDays(4),
            'end_date' => now()->subDay(), 'final_price' => 0, 'lifecycle_snapshot' => ['incomplete' => true]]);

        $this->expectValidation('destination_company_id', fn () => $service->freeze(
            $source->id, $destination->id, $plan->id, 'annual', $adminId, 'Fictional authorization'
        ));
        $malformedTrial->update(['active' => false]);
        PaymentProof::create(['company_id' => $destination->id, 'pricing_plan_id' => $plan->id,
            'amount' => 90000, 'status' => 'pending', 'payment_method' => 'bank_transfer',
            'reference' => 'Other fictional payment', 'proof_path' => 'synthetic-proof']);
        $this->expectValidation('destination_company_id', fn () => $service->freeze(
            $source->id, $destination->id, $plan->id, 'annual', $adminId, 'Fictional authorization'
        ));
        $this->assertDatabaseCount('erp_upgrade_settlements', 0);
    }

    private function upgradeDestination(Company $source): Company
    {
        $destination = Company::create(['name' => 'Fictional Hospital ERP account', 'ntn' => 'SYN-DESTINATION-ERP',
            'product_type' => NestErps::PRODUCT_TYPE,
            'erps_vertical' => NestErps::HEALTH, 'health_org_type' => 'hospital', 'status' => 'approved', 'company_status' => 'active']);
        foreach ([$source, $destination] as $company) {
            User::create(['name' => 'Fictional upgrade owner', 'email' => 'shared-upgrade-owner@example.invalid',
                'password' => 'SyntheticOnly123!', 'company_id' => $company->id, 'role' => 'company_admin', 'is_active' => true]);
        }

        return $destination;
    }

    private function expectValidation(string $field, callable $action): void
    {
        try {
            $action();
            $this->fail('Expected a validation error for '.$field);
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey($field, $exception->errors());
        }
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

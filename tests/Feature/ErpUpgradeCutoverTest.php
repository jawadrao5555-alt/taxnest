<?php

namespace Tests\Feature;

use App\Http\Controllers\FbrPosHospitalController;
use App\Http\Controllers\FbrPosSchoolFeeController;
use App\Models\AdminUser;
use App\Models\Branch;
use App\Models\Company;
use App\Models\ErpUpgradeSettlement;
use App\Models\PaymentProof;
use App\Models\PricingPlan;
use App\Models\Subscription;
use App\Models\SubscriptionInvoice;
use App\Models\User;
use App\Services\ErpUpgradeCutoverService;
use App\Services\ErpUpgradeSettlementService;
use App\Services\FbrPosPlanComparisonService;
use App\Services\ProspectiveSubscriptionPolicy;
use App\Services\SubscriptionAssignmentService;
use App\Services\TrialSubscriptionService;
use App\Services\VerifiedSubscriptionDocuments;
use App\Services\WebsiteClientPaidSubscription;
use App\Support\NestErps;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

final class ErpUpgradeCutoverTest extends TestCase
{
    use RefreshDatabase;

    private Company $source;
    private Company $destination;
    private User $sourceOwner;
    private AdminUser $admin;
    private PricingPlan $erpPlan;
    private Branch $sourceBranch;
    private Branch $destinationBranch;

    public function test_activation_records_only_verified_difference_and_is_safe_to_retry(): void
    {
        $this->prepare();
        $settled = $this->settle();
        $before = $this->source->fresh()->getAttributes();
        $originalInvoice = SubscriptionInvoice::findOrFail($settled->source_invoice_id);
        $original = $originalInvoice->getAttributes();
        $map = [$this->sourceBranch->id => $this->destinationBranch->id];
        $service = app(ErpUpgradeCutoverService::class);
        $activated = $this->activate($settled, $map);
        $replay = $this->activate($settled, $map);
        $this->assertEquals($activated->destination_subscription_id, $replay->destination_subscription_id);
        $this->assertSame($map, $activated->fresh()->branch_map);
        $this->assertSame($before, $this->source->fresh()->getAttributes());
        $this->assertSame($original, $originalInvoice->fresh()->getAttributes());
        $this->assertTrue((bool) $originalInvoice->subscription->fresh()->active);

        $subscription = Subscription::findOrFail($activated->destination_subscription_id);
        $this->assertEquals($this->destination->id, $subscription->company_id);
        $this->assertEquals($this->erpPlan->id, $subscription->entitlementPlan()->id);
        $this->assertSame('71900.00', $subscription->final_price);
        $this->assertSame([$this->destination->id], WebsiteClientPaidSubscription::eligibleCompanyIds([$this->destination->id]));
        $this->assertSame('erp_upgrade_settlement', $subscription->purchase_snapshot['source']);
        $funding = ['settlement_id' => $settled->id, 'gross_package_paisa' => 9000000,
            'applied_credit_paisa' => 1810000, 'verified_cash_paisa' => 7190000];
        $invoice = SubscriptionInvoice::where('company_id', $this->destination->id)->sole();
        $receipt = $invoice->payments()->sole();
        $this->assertSame(71900.0, $invoice->amount);
        $this->assertTrue($invoice->isPaid());
        $this->assertSame('0.00', $invoice->document_snapshot['remaining_balance']);
        $this->assertSame($funding, $invoice->document_snapshot['erp_upgrade']);
        $this->assertSame($funding, $receipt->receipt_snapshot['erp_upgrade']);
        $this->assertSame(71900.0, $receipt->amount);
        $this->assertSame('bank_transfer', $receipt->payment_method);
        $this->assertSame('Fictional cutover transfer', $receipt->transaction_ref);
        $this->assertDatabaseCount('subscription_payments', 2);
        $this->assertDatabaseCount('payment_proofs', 1);
        $this->assertDatabaseCount('subscription_financial_adjustments', 0);
        $this->expectValidation('activation_reference', fn () => $service->activate(
            $this->source->id, $settled->id, $map, 'Different synthetic request', $this->admin->id
        ));
    }

    public function test_unsettled_or_mismapped_branch_requests_never_change_access_or_billings(): void
    {
        $this->prepare();
        $secondSource = Branch::create(['company_id' => $this->source->id, 'name' => 'Fictional source B',
            'code' => 'SRC-B', 'is_active' => true]);
        $foreign = Branch::create(['company_id' => $this->source->id, 'name' => 'Foreign destination branch',
            'code' => 'SRC-C', 'is_active' => false]);
        $quote = $this->freeze();
        $this->expectValidation('quote', fn () => $this->activate($quote));
        $settled = $this->settle($quote);
        $this->expectValidation('branch_map', fn () => $this->activate($settled));
        $this->expectValidation('branch_map', fn () => $this->activate($settled, [
            $this->sourceBranch->id => $this->destinationBranch->id,
            $secondSource->id => $this->destinationBranch->id,
        ]));
        $this->expectValidation('branch_map', fn () => $this->activate($settled, [
            $this->sourceBranch->id => $this->destinationBranch->id,
            $secondSource->id => $foreign->id,
        ]));
        $this->assertNull($settled->fresh()->activated_at);
        $this->assertDatabaseMissing('subscriptions', ['company_id' => $this->destination->id]);
        $this->assertDatabaseCount('subscription_invoices', 1);
        $this->assertDatabaseCount('subscription_payments', 1);
    }

    public function test_zero_difference_preserves_the_destination_trial_and_creates_no_cash_document(): void
    {
        $this->prepare(10000);
        $this->travelTo(CarbonImmutable::parse('2027-01-28 09:00:00', 'Asia/Karachi'));
        $trial = TrialSubscriptionService::ensureSelectedTrial($this->destination->id, NestErps::PRODUCT_TYPE, $this->erpPlan->id);
        $this->expectValidation('destination_company_id', fn () => $this->freeze());
        $this->assertTrue((bool) $trial->fresh()->active);
        $this->assertDatabaseCount('erp_upgrade_settlements', 0);

        $this->travelTo(CarbonImmutable::parse('2027-02-01 09:00:00', 'Asia/Karachi'));
        $settled = $this->settle();
        $this->assertSame(0, $settled->verified_received_paisa);
        $this->assertSame([], WebsiteClientPaidSubscription::eligibleCompanyIds([$this->destination->id]));
        $activated = $this->activate($settled);
        $subscription = Subscription::findOrFail($activated->destination_subscription_id);
        $policy = ProspectiveSubscriptionPolicy::display($subscription);
        $this->assertSame('paid', $policy['phase']);
        $this->assertSame(ProspectiveSubscriptionPolicy::snapshot($trial)['trial_ends_at'], $policy['trial_ends_at']);
        $this->assertFalse((bool) $trial->fresh()->active);
        $this->assertSame('0.00', $subscription->final_price);
        $this->assertSame(1000000, $subscription->purchase_snapshot['erp_upgrade']['applied_credit_paisa']);
        $this->assertDatabaseCount('subscription_invoices', 1);
        $this->assertDatabaseCount('subscription_payments', 1);
        $this->assertDatabaseMissing('subscription_invoices', ['company_id' => $this->destination->id]);
        $this->assertSame([$this->destination->id], WebsiteClientPaidSubscription::eligibleCompanyIds([$this->destination->id]));
        $this->travelTo(CarbonImmutable::parse($policy['paid_ends_at']));
        $this->assertSame([], WebsiteClientPaidSubscription::eligibleCompanyIds([$this->destination->id]));
    }

    public function test_branchless_pos_can_switch_to_branchless_erp_without_waiting_for_legacy_trial_expiry(): void
    {
        $this->prepare();
        $this->sourceBranch->delete();
        $this->destinationBranch->delete();
        $trial = TrialSubscriptionService::ensureTrial($this->destination->id, NestErps::PRODUCT_TYPE, 14);
        $this->assertTrue($trial->trial_ends_at->isFuture());
        $this->assertFalse($trial->hasLifecycleSnapshot());

        $settled = $this->settle();
        $this->expectValidation('branch_map', fn () => $this->activate($settled));
        $activated = $this->activate($settled, []);
        $this->assertSame([], $activated->fresh()->branch_map);
        $this->assertFalse((bool) $trial->fresh()->active);
        $this->assertSame(now()->toDateString(), Subscription::findOrFail($activated->destination_subscription_id)->start_date->toDateString());
        $this->assertEquals($activated->destination_subscription_id, $this->activate($settled, [])->destination_subscription_id);
    }

    public function test_destination_owner_or_package_drift_blocks_activation(): void
    {
        $this->prepare();
        $settled = $this->settle();
        $owner = User::where('company_id', $this->destination->id)->sole();
        $owner->update(['is_active' => false]);
        $this->expectValidation('destination_company_id', fn () => $this->activate($settled));
        $owner->update(['is_active' => true]);
        $this->erpPlan->update(['health_modules' => ['opd', 'ipd', 'accounts', 'pharmacy']]);
        $this->expectValidation('quote', fn () => $this->activate($settled));
        $this->assertNull($settled->fresh()->activated_at);
        $this->assertDatabaseCount('subscription_payments', 1);
    }

    public function test_an_early_renewal_keeps_the_remaining_fully_credited_paid_term(): void
    {
        $this->prepare(10000);
        $activated = $this->activate($this->settle());
        $term = Subscription::findOrFail($activated->destination_subscription_id);
        $this->assertSame('0.00', $term->final_price);
        $this->assertSame([$this->destination->id], WebsiteClientPaidSubscription::eligibleCompanyIds([$this->destination->id]));
        $this->travelTo(CarbonImmutable::parse('2027-03-01 09:00:00', 'Asia/Karachi'));
        $renewal = SubscriptionAssignmentService::assign(
            $this->destination->id, $this->erpPlan->id, paidRenewal: true, verifiedReceived: 10000,
        );
        $this->assertSame($term->end_date->copy()->addMonthsNoOverflow(12)->toDateString(), $renewal->end_date->toDateString());
        $this->assertSame('10000.00', $renewal->final_price);
    }

    public function test_cutover_blocks_new_source_principal_preserving_saved_receipts_and_outstanding_balances(): void
    {
        Http::fake();
        $this->prepare();
        $this->actingAs($this->sourceOwner, 'fbrpos');
        app()->instance('currentCompanyId', $this->source->id);
        app()->instance('currentBranchId', $this->sourceBranch->id);
        $controller = app(FbrPosHospitalController::class);
        $payload = ['request_key' => (string) Str::uuid(), 'expected_branch_key' => $this->sourceBranch->id,
            'patient_reference' => 'Fictional balance reference', 'amount' => '400.00', 'total_amount' => '1000.00',
            'service_head' => 'consultation', 'payment_method' => 'cash', 'payment_confirmed' => '1'];
        $first = $controller->store($this->collectionRequest($payload))->getData(true);
        $original = DB::table('hospital_payments')->where('id', $first['receipt_id'])->first();
        $this->activate($this->settle());
        $cutoverPage = $controller->index()->getContent();
        $this->assertStringContainsString(__('hospital.erp_cutover_new_collection'), $cutoverPage);
        $this->assertStringNotContainsString('id="hospital-collection"', $cutoverPage);

        $replayed = $controller->store($this->collectionRequest($payload))->getData(true);
        $this->assertSame($first['receipt_id'], $replayed['receipt_id']);
        $newPrincipal = array_replace($payload, ['request_key' => (string) Str::uuid()]);
        $this->expectValidation('subscription', fn () => $controller->store($this->collectionRequest($newPrincipal)));
        $payment = array_replace($newPrincipal, ['amount' => '600.00']);
        $controller->payment($this->collectionRequest($payment), $original->invoice_id);
        $this->assertDatabaseCount('hospital_invoices', 1);
        $this->assertDatabaseCount('hospital_payments', 2);
        $this->assertDatabaseHas('hospital_payments', ['request_key' => $payment['request_key'], 'balance_paisa' => 0]);
        $this->assertEquals($original, DB::table('hospital_payments')->where('id', $first['receipt_id'])->first());
    }

    public function test_school_cutover_requires_clear_fee_balances_and_charges_only_the_difference(): void
    {
        Http::fake();
        $this->prepare(school: true);
        $hospital = PricingPlan::create(['name' => 'Fictional Hospital-only offer', 'product_type' => NestErps::PRODUCT_TYPE,
            'erps_vertical' => NestErps::HEALTH, 'health_modules' => ['ipd', 'accounts'], 'price' => 50000, 'invoice_limit' => 100]);
        $estimate = route('saas.admin.companies.erpUpgradeQuote', $this->source);
        $this->actingAs($this->admin, 'admin')->get($estimate)->assertOk()->assertSee('School ERP upgrade')
            ->assertSee($this->erpPlan->name)->assertDontSee($hospital->name);
        $this->getJson($estimate.'?pricing_plan_id='.$hospital->id.'&billing_cycle=annual')
            ->assertUnprocessable()->assertJsonValidationErrors('pricing_plan_id');
        $this->get($estimate.'?pricing_plan_id='.$this->erpPlan->id.'&billing_cycle=annual')
            ->assertOk()->assertSee('PKR 71,900.00');
        $this->postJson(route('saas.admin.companies.erpUpgradeFreeze', $this->source), [
            'destination_company_id' => $this->destination->id, 'pricing_plan_id' => $this->erpPlan->id,
            'billing_cycle' => 'annual', 'owner_authorization_reference' => 'Fictional School owner approval',
            'expected_package_paisa' => 9000000, 'expected_credit_paisa' => 1810000,
        ])->assertRedirect();
        $settlement = ErpUpgradeSettlement::sole();
        $settleUrl = route('saas.admin.companies.erpUpgradeSettle', [$this->source, $settlement]);
        $this->postJson($settleUrl, ['verified_received_amount' => '90000.00', 'payment_reference' => 'Fictional gross transfer'])
            ->assertUnprocessable()->assertJsonValidationErrors('verified_received_amount');
        $this->postJson($settleUrl, ['verified_received_amount' => '71900.00', 'payment_reference' => 'Fictional School transfer'])
            ->assertRedirect();

        $this->actingAs($this->sourceOwner, 'fbrpos');
        app()->instance('currentCompanyId', $this->source->id);
        app()->instance('currentBranchId', $this->sourceBranch->id);
        $controller = app(FbrPosSchoolFeeController::class);
        $payload = ['request_key' => (string) Str::uuid(), 'expected_branch_key' => $this->sourceBranch->id,
            'student_reference' => 'Fictional School student', 'student_name' => 'Fictional name', 'fee_period' => '2027-02',
            'fee_lines' => [['fee_head' => 'tuition', 'amount' => '1000.00']], 'amount' => '400.00',
            'payment_method' => 'cash', 'cash_confirmed' => '1'];
        $path = '/fbr-pos/school-fees';
        $first = $controller->store($this->collectionRequest($payload, $path))->getData(true);
        $original = DB::table('school_fee_receipts')->where('id', $first['receipt_id'])->first();
        $this->expectValidation('source_term', fn () => $this->activate($settlement));
        $this->assertDatabaseMissing('subscriptions', ['company_id' => $this->destination->id]);

        $controller->store($this->collectionRequest(array_replace($payload, [
            'request_key' => (string) Str::uuid(), 'account_id' => $original->account_id, 'amount' => '600.00',
        ]), $path));
        $this->actingAs($this->admin, 'admin')->postJson(
            route('saas.admin.companies.erpUpgradeActivate', [$this->source, $settlement]),
            ['branch_map' => [$this->sourceBranch->id => $this->destinationBranch->id], 'activation_reference' => 'Fictional cutover approval'],
        )->assertRedirect();
        $this->assertSame($settlement->id, $this->activate($settlement)->id);
        $invoice = SubscriptionInvoice::where('company_id', $this->destination->id)->sole();
        $this->assertSame(71900.0, $invoice->amount);
        $this->assertSame(71900.0, $invoice->payments()->sole()->amount);
        $this->assertSame(NestErps::SCHOOL, $invoice->subscription->entitlementPlan()->erps_vertical);
        $this->assertTrue($this->source->fresh()->agent_enabled);
        $this->actingAs($this->sourceOwner, 'fbrpos');
        $this->assertStringContainsString(__('school_ledger.erp_cutover_closed'), $controller->index()->getContent());

        $replay = $controller->store($this->collectionRequest($payload, $path))->getData(true);
        $this->assertSame($first['receipt_id'], $replay['receipt_id']);
        $this->expectValidation('amount', fn () => $controller->store($this->collectionRequest(
            array_replace($payload, ['request_key' => (string) Str::uuid()]), $path
        )));
        $this->assertDatabaseCount('school_fee_receipts', 2);
        $this->assertEquals($original, DB::table('school_fee_receipts')->where('id', $first['receipt_id'])->first());
    }

    private function prepare(int $price = 90000, bool $school = false): void
    {
        $this->travelTo(CarbonImmutable::parse('2026-08-01 09:00:00', 'Asia/Karachi'));
        $this->admin = AdminUser::create(['name' => 'Fictional ERP owner admin', 'email' => 'cutover-admin@example.invalid',
            'role' => 'super_admin', 'password' => 'SyntheticOnly123!']);
        $status = ['status' => 'approved', 'company_status' => 'active'];
        $sourceCategory = $school ? 'education' : 'hospital';
        $vertical = $school ? NestErps::SCHOOL : NestErps::HEALTH;
        $this->source = Company::create($status + ['name' => 'Fictional source POS', 'ntn' => 'SYN-ERP-SOURCE',
            'product_type' => 'fbrpos', 'pos_type' => $sourceCategory, 'business_category' => $sourceCategory, 'agent_enabled' => true]);
        $this->destination = Company::create($status + ['name' => 'Fictional destination ERP', 'ntn' => 'SYN-ERP-DESTINATION',
            'product_type' => NestErps::PRODUCT_TYPE, 'erps_vertical' => $vertical, 'health_org_type' => $school ? null : 'hospital']);
        $owner = ['name' => 'Fictional migrating owner', 'email' => 'cutover-owner@example.invalid',
            'role' => 'company_admin', 'is_active' => true, 'password' => 'SyntheticOnly123!'];
        $this->sourceOwner = User::create($owner + ['company_id' => $this->source->id]);
        User::create($owner + ['company_id' => $this->destination->id]);

        $this->sourceBranch = Branch::create(['company_id' => $this->source->id, 'name' => 'Fictional source A',
            'code' => 'SRC-A', 'is_active' => true]);
        $this->destinationBranch = Branch::create(['company_id' => $this->destination->id, 'name' => 'Fictional ERP A',
            'code' => 'ERP-A', 'is_active' => true]);
        $sourcePlan = PricingPlan::create(['name' => FbrPosPlanComparisonService::SELLABLE_PLAN_NAMES[0], 'product_type' => 'fbrpos',
            'is_trial' => false, 'price' => 36500, 'invoice_limit' => 100]);
        $active = SubscriptionAssignmentService::assign($this->source->id, $sourcePlan->id, paidRenewal: true);
        $proof = PaymentProof::create(['company_id' => $this->source->id, 'subscription_id' => $active->id,
            'pricing_plan_id' => $sourcePlan->id, 'billing_cycle' => 'annual', 'status' => 'verified',
            'verified_by' => $this->admin->id, 'verified_at' => now(), 'amount' => 36500,
            'payment_method' => 'bank_transfer', 'reference' => 'Fictional source bank payment', 'proof_path' => 'synthetic-proof']);
        DB::transaction(fn () => VerifiedSubscriptionDocuments::record($proof, $active, 36500));
        $this->erpPlan = PricingPlan::create(['name' => $school ? 'Fictional School ERP package' : 'Fictional Hospital ERP package',
            'product_type' => NestErps::PRODUCT_TYPE, 'erps_vertical' => $vertical, 'health_modules' => $school ? null : ['opd', 'ipd', 'accounts'],
            'is_trial' => false, 'price' => $price, 'branch_limit' => 3, 'invoice_limit' => 100]);
        $this->travelTo(CarbonImmutable::parse('2027-02-01 09:00:00', 'Asia/Karachi'));
    }

    private function freeze(): ErpUpgradeSettlement
    {
        return app(ErpUpgradeSettlementService::class)->freeze(
            $this->source->id, $this->destination->id, $this->erpPlan->id, 'annual', $this->admin->id, 'Fictional owner approval'
        );
    }

    private function settle(?ErpUpgradeSettlement $quote = null): ErpUpgradeSettlement
    {
        $quote ??= $this->freeze();

        return app(ErpUpgradeSettlementService::class)->settle($this->source->id, $quote->id,
            number_format($quote->difference_paisa / 100, 2, '.', ''),
            $quote->difference_paisa > 0 ? 'Fictional cutover transfer' : null, $this->admin->id
        );
    }

    private function activate(ErpUpgradeSettlement $quote, ?array $map = null): ErpUpgradeSettlement
    {
        return app(ErpUpgradeCutoverService::class)->activate($this->source->id, $quote->id,
            $map ?? [$this->sourceBranch->id => $this->destinationBranch->id], 'Fictional cutover approval', $this->admin->id
        );
    }

    private function collectionRequest(array $payload, string $path = '/fbr-pos/hospital'): Request
    {
        $request = Request::create($path, 'POST', $payload, server: ['HTTP_ACCEPT' => 'application/json']);
        $request->setLaravelSession(app('session.store'));
        app()->instance('request', $request);

        return $request;
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
}

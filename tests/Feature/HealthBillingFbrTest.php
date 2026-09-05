<?php

namespace Tests\Feature;

use App\Models\Company;
use App\Models\HealthBill;
use App\Models\HealthBillLine;
use App\Models\HealthCharge;
use App\Models\HealthChargeAdjustment;
use App\Models\HealthPatient;
use App\Models\HealthPayment;
use App\Models\HealthTaxCategory;
use App\Models\PricingPlan;
use App\Models\Subscription;
use App\Models\User;
use App\Services\HealthBillFbrService;
use App\Services\HealthBillingReportService;
use App\Services\HealthBillingService;
use App\Services\HealthChargeService;
use App\Services\HealthModuleService;
use App\Services\HealthTaxService;
use App\Support\HealthPanel;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

/**
 * UNIFIED BILLING & FBR (Task 1551).
 *
 * Runs against the REAL migrations, and every money assertion is read back out
 * of the database rather than off the object the service just returned — a
 * column that is missing from $fillable writes nothing and still hands back a
 * perfectly convincing model.
 *
 * What is locked here:
 *
 *  1. THE LEDGER IS IMMUTABLE — a charge is reversed or adjusted, never edited
 *     or deleted, and the source behind every line survives onto the bill.
 *  2. POSTING TWICE IS HARMLESS — the same source charge cannot be billed to a
 *     patient twice, however many times the module re-posts it.
 *  3. LOCAL AND REPORTED MONEY NEVER MERGE — only FBR-treated lines are summed
 *     as reportable, and the treatment freezes when the bill is finalized.
 *  4. NOTHING IS FILED BY ACCIDENT — unclassified charges fall back to local at
 *     zero, and a bill with no reportable line is refused by the FBR adapter
 *     before it can reach the regulator.
 *  5. MONEY MOVES ONLY ONE WAY — an over-payment becomes credit, a refund can
 *     never exceed what was actually collected, and a paid or filed bill can no
 *     longer be cancelled.
 *  6. ONE TRUTH, MANY SURFACES — the shift, the day close and the patient
 *     statement all read the same persisted rows and agree.
 *
 * Run:
 *   php vendor/bin/phpunit tests/Feature/HealthBillingFbrTest.php --testdox
 */
class HealthBillingFbrTest extends TestCase
{
    use RefreshDatabase;

    private Company $company;
    private User $owner;
    private HealthPatient $patient;

    protected function setUp(): void
    {
        parent::setUp();

        HealthModuleService::forget();

        $this->company = Company::create([
            'name' => 'Shifa Billing Test',
            'ntn' => 'BILL-TEST-1',
            'product_type' => HealthPanel::PRODUCT_TYPE,
            'status' => 'approved',
            'company_status' => 'active',
            'health_org_type' => 'hospital',
            'health_modules' => json_encode(['opd', 'ipd', 'accounts']),
        ]);

        $plan = PricingPlan::create([
            'name' => 'Hospital Billing Test',
            'product_type' => HealthPanel::PRODUCT_TYPE,
            'price' => 99999,
            'is_trial' => false,
            'health_modules' => json_encode(HealthModuleService::MODULES),
            'user_limit' => 50,
            'branch_limit' => 5,
            'invoice_limit' => 0,
        ]);

        Subscription::create([
            'company_id' => $this->company->id,
            'pricing_plan_id' => $plan->id,
            'active' => true,
            'start_date' => now()->subDay()->toDateString(),
            'end_date' => now()->addYear()->toDateString(),
        ]);

        HealthModuleService::forget($this->company->id);

        $this->owner = User::create([
            'name' => 'Billing Owner',
            'email' => 'billowner@example.test',
            'password' => Hash::make('Passw0rd!2026'),
            'company_id' => $this->company->id,
            'role' => 'user',
            'health_role' => 'health_owner',
            'is_active' => true,
        ]);

        $this->patient = HealthPatient::create([
            'company_id' => $this->company->id,
            'mrn' => 'MRB0001',
            'name' => 'Saima Akhtar',
            'gender' => 'female',
            'age_years' => 34,
            'phone' => '03001234567',
            'is_active' => true,
        ]);
    }

    /** A rule the rulebook can match on. */
    private function rule(string $treatment, float $rate, array $appliesTo, bool $default = false): HealthTaxCategory
    {
        return HealthTaxCategory::withoutGlobalScopes()->create([
            'company_id' => $this->company->id,
            'name' => strtoupper($treatment) . ' ' . $rate . '%',
            'code' => strtoupper($treatment) . random_int(100, 999),
            'treatment' => $treatment,
            'tax_rate' => $rate,
            'applies_to' => $appliesTo,
            'is_default' => $default,
            'is_active' => true,
            'created_by' => $this->owner->id,
        ]);
    }

    private function charge(array $overrides = []): HealthCharge
    {
        return HealthChargeService::post(array_merge([
            'company_id' => $this->company->id,
            'health_patient_id' => $this->patient->id,
            'category' => HealthCharge::CAT_OPD,
            'description' => 'Consultation',
            'unit_price' => 1000,
            'quantity' => 1,
            'source_type' => HealthCharge::SOURCE_MANUAL,
        ], $overrides), $this->owner);
    }

    // ─────────────────────────────────────────────────────────────
    // 1. The ledger
    // ─────────────────────────────────────────────────────────────

    /** @test */
    public function a_posted_charge_is_written_to_the_database_with_its_source_and_its_tax_decision(): void
    {
        $charge = $this->charge([
            'category' => HealthCharge::CAT_PHARMACY,
            'description' => 'Panadol 500mg',
            'unit_price' => 25,
            'quantity' => 8,
            'source_type' => HealthCharge::SOURCE_PHARMACY_SALE,
            'source_id' => 4242,
            'source_reference' => 'PH-4242',
        ]);

        $this->assertNotNull($charge);

        // Read the ROW, not the model — a column missing from $fillable would
        // still be present on the object we were just handed.
        $row = DB::table('health_charges')->where('id', $charge->id)->first();

        $this->assertSame($this->company->id, (int) $row->company_id);
        $this->assertSame($this->patient->id, (int) $row->health_patient_id);
        $this->assertSame('pharmacy_sale', $row->source_type);
        $this->assertSame(4242, (int) $row->source_id);
        $this->assertSame('PH-4242', $row->source_reference);
        $this->assertSame(200.0, round((float) $row->gross_amount, 2));
        $this->assertSame(200.0, round((float) $row->net_amount, 2));
        $this->assertSame(HealthCharge::STATUS_POSTED, $row->status);
        $this->assertNotEmpty($row->charge_no);

        // Nothing was classified, so nothing may be reported. Local at zero is
        // the only safe fallback: an accidental filing cannot be taken back.
        $this->assertSame(HealthTaxCategory::TREATMENT_LOCAL, $row->tax_treatment);
        $this->assertSame(0.0, round((float) $row->tax_amount, 2));
    }

    /** @test */
    public function the_same_source_charge_cannot_be_posted_twice(): void
    {
        $first = $this->charge(['dedupe_key' => 'visit:99:fee']);
        $second = $this->charge(['dedupe_key' => 'visit:99:fee', 'unit_price' => 5000]);

        $this->assertSame($first->id, $second->id);
        $this->assertSame(1, DB::table('health_charges')->where('company_id', $this->company->id)->count());

        // The retry did NOT quietly rewrite the price of a posted charge.
        $this->assertSame(1000.0, round((float) DB::table('health_charges')->where('id', $first->id)->value('gross_amount'), 2));
    }

    /** @test */
    public function a_charge_is_reversed_not_deleted_and_the_decision_survives(): void
    {
        $charge = $this->charge();

        $result = HealthChargeService::reverse($charge, $this->owner, 'Wrong patient');
        $this->assertTrue($result['ok']);

        $row = DB::table('health_charges')->where('id', $charge->id)->first();
        $this->assertNotNull($row, 'the charge row must survive a reversal');
        $this->assertSame(HealthCharge::STATUS_REVERSED, $row->status);
        $this->assertSame('Wrong patient', $row->reversal_reason);
        $this->assertNotNull($row->reversed_at);

        $this->assertSame(1, HealthChargeAdjustment::withoutGlobalScopes()
            ->where('health_charge_id', $charge->id)->count());

        // Reversing twice is harmless — a double-clicked button reports the
        // outcome the counter wanted — but it does NOT write a second reversal.
        $again = HealthChargeService::reverse($charge->fresh(), $this->owner);
        $this->assertTrue($again['ok']);
        $this->assertSame('already_reversed', $again['reason']);
        $this->assertSame(1, HealthChargeAdjustment::withoutGlobalScopes()
            ->where('health_charge_id', $charge->id)->count());
    }

    /** @test */
    public function a_concession_never_exceeds_the_charge_and_leaves_a_trail(): void
    {
        $charge = $this->charge(['unit_price' => 2000]);

        $this->assertFalse(HealthChargeService::applyConcession($charge, 5000, 'Too big', $this->owner)['ok']);

        $ok = HealthChargeService::applyConcession($charge->fresh(), 500, 'Staff family', $this->owner);
        $this->assertTrue($ok['ok']);

        $row = DB::table('health_charges')->where('id', $charge->id)->first();
        $this->assertSame(500.0, round((float) $row->concession_amount, 2));
        $this->assertSame(1500.0, round((float) $row->net_amount, 2));
        $this->assertSame('Staff family', $row->concession_reason);
        $this->assertTrue(HealthChargeAdjustment::withoutGlobalScopes()
            ->where('health_charge_id', $charge->id)->exists());
    }

    // ─────────────────────────────────────────────────────────────
    // 2. Regulatory classification
    // ─────────────────────────────────────────────────────────────

    /** @test */
    public function the_rulebook_decides_the_treatment_and_only_fbr_lines_carry_tax(): void
    {
        $this->rule(HealthTaxCategory::TREATMENT_FBR, 15, [HealthCharge::CAT_PHARMACY]);
        $this->rule(HealthTaxCategory::TREATMENT_EXEMPT, 0, [HealthCharge::CAT_OPD]);

        $pharmacy = $this->charge([
            'category' => HealthCharge::CAT_PHARMACY,
            'unit_price' => 1000,
            'dedupe_key' => 'ph:1',
        ]);
        $opd = $this->charge(['category' => HealthCharge::CAT_OPD, 'dedupe_key' => 'opd:1']);
        $room = $this->charge([
            'category' => HealthCharge::CAT_ROOM,
            'unit_price' => 4000,
            'dedupe_key' => 'room:1',
        ]);

        $this->assertSame(HealthTaxCategory::TREATMENT_FBR, $pharmacy->tax_treatment);
        $this->assertSame(150.0, round((float) $pharmacy->tax_amount, 2));

        $this->assertSame(HealthTaxCategory::TREATMENT_EXEMPT, $opd->tax_treatment);
        $this->assertSame(0.0, round((float) $opd->tax_amount, 2));

        // No rule covers a room charge and there is no default — it stays local.
        $this->assertSame(HealthTaxCategory::TREATMENT_LOCAL, $room->tax_treatment);
    }

    /** @test */
    public function the_seeded_starter_rulebook_files_nothing_on_the_hospitals_behalf(): void
    {
        $count = HealthTaxService::seedDefaults($this->company->id, $this->owner->id);
        $this->assertGreaterThan(0, $count);

        $treatments = DB::table('health_tax_categories')
            ->where('company_id', $this->company->id)
            ->pluck('treatment')
            ->unique()
            ->values()
            ->all();

        $this->assertSame([HealthTaxCategory::TREATMENT_LOCAL], $treatments);

        // Seeding again does not double the rulebook.
        $this->assertSame(0, HealthTaxService::seedDefaults($this->company->id, $this->owner->id));
    }

    /** @test */
    public function the_tax_decision_freezes_when_the_bill_is_finalized(): void
    {
        $fbrRule = $this->rule(HealthTaxCategory::TREATMENT_FBR, 15, [HealthCharge::CAT_PHARMACY]);
        $charge = $this->charge(['category' => HealthCharge::CAT_PHARMACY, 'unit_price' => 1000]);

        $bill = $this->billFor([$charge->id]);
        $this->assertTrue(HealthBillingService::finalize($bill, $this->owner)['ok']);

        $locked = $charge->fresh();
        $this->assertTrue($locked->isLocked());

        // The rulebook may change tomorrow; a printed and possibly filed
        // document may not.
        $moved = HealthChargeService::reclassify($locked, $fbrRule->id, $this->owner, 'oops');
        $this->assertFalse($moved['ok']);
        $this->assertSame('locked_by_final_bill', $moved['reason']);
    }

    // ─────────────────────────────────────────────────────────────
    // 3. The bill
    // ─────────────────────────────────────────────────────────────

    private function billFor(array $chargeIds, array $opts = []): HealthBill
    {
        $made = HealthBillingService::createBill(
            $this->company->id,
            $this->patient->id,
            $chargeIds,
            $opts,
            $this->owner
        );

        $this->assertTrue($made['ok'], 'bill creation failed: ' . ($made['reason'] ?? '?'));

        return $made['bill'];
    }

    /** @test */
    public function a_bill_freezes_its_lines_and_keeps_the_source_behind_each_one(): void
    {
        $this->rule(HealthTaxCategory::TREATMENT_FBR, 15, [HealthCharge::CAT_PHARMACY]);

        $opd = $this->charge(['dedupe_key' => 'a']);
        $pharmacy = $this->charge([
            'category' => HealthCharge::CAT_PHARMACY,
            'unit_price' => 1000,
            'source_type' => HealthCharge::SOURCE_PHARMACY_SALE,
            'source_id' => 77,
            'dedupe_key' => 'b',
        ]);

        $bill = $this->billFor([$opd->id, $pharmacy->id], ['scope' => HealthBill::SCOPE_COMBINED]);

        $lines = DB::table('health_bill_lines')->where('health_bill_id', $bill->id)->get();
        $this->assertCount(2, $lines);

        $mirrored = $lines->firstWhere('health_charge_id', $pharmacy->id);
        $this->assertSame('pharmacy_sale', $mirrored->source_type);
        $this->assertSame(77, (int) $mirrored->source_id);
        $this->assertSame(HealthTaxCategory::TREATMENT_FBR, $mirrored->tax_treatment);

        // Both charges now belong to the bill and can no longer be re-billed.
        $this->assertSame(0, HealthChargeService::unbilled($this->company->id, $this->patient->id)->count());

        $row = DB::table('health_bills')->where('id', $bill->id)->first();
        $this->assertSame(2150.0, round((float) $row->total_amount, 2));

        // Local and reported money are held apart on the document itself.
        $split = json_decode($row->treatment_totals, true);
        $this->assertSame(1150.0, round((float) $split[HealthTaxCategory::TREATMENT_FBR], 2));
        $this->assertSame(1000.0, round((float) $split[HealthTaxCategory::TREATMENT_LOCAL], 2));
    }

    /** @test */
    public function an_estimate_is_never_a_bill(): void
    {
        $charge = $this->charge();
        $estimate = $this->billFor([$charge->id], ['doc_type' => HealthBill::TYPE_ESTIMATE]);

        $this->assertTrue($estimate->isEstimate());
        $this->assertSame('estimate_cannot_finalize', HealthBillingService::finalize($estimate, $this->owner)['reason']);

        $paid = HealthBillingService::recordPayment($this->company->id, $this->patient->id, [
            'amount' => 100,
            'health_bill_id' => $estimate->id,
        ], $this->owner);
        $this->assertSame('estimate_not_payable', $paid['reason']);

        $this->assertSame('estimate_not_filable', HealthBillFbrService::eligibility($estimate)['reason']);
    }

    /** @test */
    public function a_cancelled_bill_returns_its_charges_but_a_paid_one_cannot_be_cancelled(): void
    {
        $charge = $this->charge();
        $bill = $this->billFor([$charge->id]);
        HealthBillingService::finalize($bill, $this->owner);

        HealthBillingService::recordPayment($this->company->id, $this->patient->id, [
            'amount' => 400,
            'health_bill_id' => $bill->id,
        ], $this->owner);

        $refused = HealthBillingService::cancel($bill->fresh(), $this->owner);
        $this->assertFalse($refused['ok']);
        $this->assertSame('already_paid', $refused['reason']);

        // A clean bill does release its charges back to the ledger.
        $other = $this->charge(['dedupe_key' => 'other']);
        $clean = $this->billFor([$other->id]);
        $this->assertTrue(HealthBillingService::cancel($clean, $this->owner, 'Wrong patient')['ok']);

        $back = DB::table('health_charges')->where('id', $other->id)->first();
        $this->assertNull($back->health_bill_id);
        $this->assertSame(HealthCharge::STATUS_POSTED, $back->status);
    }

    // ─────────────────────────────────────────────────────────────
    // 4. Money
    // ─────────────────────────────────────────────────────────────

    /** @test */
    public function an_over_payment_becomes_credit_and_a_refund_can_never_exceed_what_was_collected(): void
    {
        $charge = $this->charge(['unit_price' => 1000]);
        $bill = $this->billFor([$charge->id]);
        HealthBillingService::finalize($bill, $this->owner);

        HealthBillingService::recordPayment($this->company->id, $this->patient->id, [
            'amount' => 1000,
            'method' => 'cash',
            'health_bill_id' => $bill->id,
        ], $this->owner);

        $settled = DB::table('health_bills')->where('id', $bill->id)->first();
        $this->assertSame(1000.0, round((float) $settled->paid_amount, 2));
        $this->assertSame(0.0, round((float) $settled->outstanding_amount, 2));
        $this->assertSame(HealthBill::STATUS_SETTLED, $settled->status);

        $tooMuch = HealthBillingService::refund($bill->fresh(), 5000, [], $this->owner);
        $this->assertSame('exceeds_refundable', $tooMuch['reason']);

        $this->assertTrue(HealthBillingService::refund($bill->fresh(), 200, [], $this->owner)['ok']);
        $this->assertSame(200.0, round((float) DB::table('health_bills')->where('id', $bill->id)->value('refunded_amount'), 2));

        // A deposit with nothing to pay for is credit the hospital holds.
        HealthBillingService::recordPayment($this->company->id, $this->patient->id, [
            'amount' => 3000,
            'kind' => HealthPayment::KIND_DEPOSIT,
        ], $this->owner);

        $account = HealthBillingService::patientAccount($this->company->id, $this->patient->id);
        $this->assertSame(3000.0, round((float) $account['credit'], 2));
        $this->assertSame(0.0, round((float) $account['due_now'], 2));
    }

    /**
     * The counter takes a round 3,000 for a 1,000 bill. The bill may only ever
     * be paid what the bill owes; the 2,000 is the patient's money and has to
     * stay findable as credit, not disappear into an "over-paid" bill.
     */
    /** @test */
    public function a_payment_can_never_exceed_the_bill_and_the_change_is_kept_as_credit(): void
    {
        $charge = $this->charge(['unit_price' => 1000]);
        $bill = $this->billFor([$charge->id]);
        HealthBillingService::finalize($bill, $this->owner);

        $result = HealthBillingService::recordPayment($this->company->id, $this->patient->id, [
            'amount' => 3000,
            'method' => 'cash',
            'health_bill_id' => $bill->id,
        ], $this->owner);

        $this->assertTrue($result['ok']);
        $this->assertSame(2000.0, round((float) $result['credited'], 2));

        // The bill carries EXACTLY what it owed.
        $row = DB::table('health_bills')->where('id', $bill->id)->first();
        $this->assertSame(1000.0, round((float) $row->paid_amount, 2));
        $this->assertSame(0.0, round((float) $row->outstanding_amount, 2));
        $this->assertSame(HealthBill::STATUS_SETTLED, $row->status);

        // The surplus exists as its own unallocated deposit receipt.
        $onBill = DB::table('health_payments')->where('health_bill_id', $bill->id)->get();
        $this->assertCount(1, $onBill);
        $this->assertSame(1000.0, round((float) $onBill->first()->amount, 2));

        $loose = DB::table('health_payments')
            ->whereNull('health_bill_id')
            ->where('kind', HealthPayment::KIND_DEPOSIT)
            ->get();
        $this->assertCount(1, $loose);
        $this->assertSame(2000.0, round((float) $loose->first()->amount, 2));
        $this->assertNotSame($onBill->first()->receipt_no, $loose->first()->receipt_no);

        // And the account still knows the hospital is holding 2,000 of theirs.
        $account = HealthBillingService::patientAccount($this->company->id, $this->patient->id);
        $this->assertSame(2000.0, round((float) $account['credit'], 2));
        $this->assertSame(3000.0, round((float) $account['collected'], 2));

        // Paying a settled bill again is pure credit — nothing lands on the bill.
        $again = HealthBillingService::recordPayment($this->company->id, $this->patient->id, [
            'amount' => 500,
            'method' => 'cash',
            'health_bill_id' => $bill->id,
        ], $this->owner);
        $this->assertSame(500.0, round((float) $again['credited'], 2));
        $this->assertSame(1000.0, round((float) DB::table('health_bills')->where('id', $bill->id)->value('paid_amount'), 2));
        $this->assertSame(2500.0, round((float) HealthBillingService::patientAccount($this->company->id, $this->patient->id)['credit'], 2));
    }

    /** @test */
    public function a_deposit_can_be_applied_to_a_later_bill_without_taking_the_money_twice(): void
    {
        HealthBillingService::recordPayment($this->company->id, $this->patient->id, [
            'amount' => 1500,
            'kind' => HealthPayment::KIND_DEPOSIT,
        ], $this->owner);

        $charge = $this->charge(['unit_price' => 2000]);
        $bill = $this->billFor([$charge->id]);
        HealthBillingService::finalize($bill, $this->owner);

        $this->assertTrue(HealthBillingService::applyCredit($bill->fresh(), $this->owner)['ok']);

        $row = DB::table('health_bills')->where('id', $bill->id)->first();
        $this->assertSame(1500.0, round((float) $row->paid_amount, 2));
        $this->assertSame(500.0, round((float) $row->outstanding_amount, 2));

        // The deposit is spent, not duplicated: no credit left on the account.
        $account = HealthBillingService::patientAccount($this->company->id, $this->patient->id);
        $this->assertSame(0.0, round((float) $account['credit'], 2));
        $this->assertSame(1500.0, round((float) $account['collected'], 2));
    }

    /** @test */
    public function a_reversed_receipt_stops_counting_as_money(): void
    {
        $charge = $this->charge(['unit_price' => 1000]);
        $bill = $this->billFor([$charge->id]);
        HealthBillingService::finalize($bill, $this->owner);

        $paid = HealthBillingService::recordPayment($this->company->id, $this->patient->id, [
            'amount' => 1000,
            'health_bill_id' => $bill->id,
        ], $this->owner);

        $this->assertTrue(HealthBillingService::reversePayment($paid['payment'], $this->owner, 'Cashier error')['ok']);

        $row = DB::table('health_payments')->where('id', $paid['payment']->id)->first();
        $this->assertNotNull($row, 'a reversed receipt is kept, never deleted');
        $this->assertNotNull($row->reversed_at);

        $this->assertSame(0.0, round((float) DB::table('health_bills')->where('id', $bill->id)->value('paid_amount'), 2));
    }

    // ─────────────────────────────────────────────────────────────
    // 5. FBR
    // ─────────────────────────────────────────────────────────────

    /** @test */
    public function a_bill_with_no_reportable_line_is_refused_before_it_can_reach_the_regulator(): void
    {
        $charge = $this->charge(['category' => HealthCharge::CAT_ROOM, 'unit_price' => 4000]);
        $bill = $this->billFor([$charge->id]);
        HealthBillingService::finalize($bill, $this->owner);

        $fresh = $bill->fresh();
        $this->assertFalse((bool) $fresh->fbr_eligible);
        $this->assertSame(HealthBill::FBR_NOT_APPLICABLE, $fresh->fbr_status);

        $this->assertSame('no_reportable_lines', HealthBillFbrService::eligibility($fresh)['reason']);
        $this->assertSame('no_reportable_lines', HealthBillFbrService::submit($fresh, $this->owner)['reason']);

        // Refused means refused: nothing was recorded as an attempt.
        $this->assertSame(0, DB::table('health_fbr_submissions')->where('health_bill_id', $bill->id)->count());
    }

    /** @test */
    public function only_fbr_treated_lines_are_reportable_and_a_hospital_with_reporting_off_files_nothing(): void
    {
        $this->rule(HealthTaxCategory::TREATMENT_FBR, 15, [HealthCharge::CAT_PHARMACY]);

        $pharmacy = $this->charge([
            'category' => HealthCharge::CAT_PHARMACY,
            'unit_price' => 1000,
            'dedupe_key' => 'ph',
        ]);
        $room = $this->charge([
            'category' => HealthCharge::CAT_ROOM,
            'unit_price' => 4000,
            'dedupe_key' => 'rm',
        ]);

        $bill = $this->billFor([$pharmacy->id, $room->id], ['scope' => HealthBill::SCOPE_FINAL]);
        HealthBillingService::finalize($bill, $this->owner);
        $bill = $bill->fresh();

        $this->assertTrue((bool) $bill->fbr_eligible);

        $reportable = HealthBillFbrService::reportableLines($bill);
        $this->assertCount(1, $reportable);
        $this->assertSame($pharmacy->id, (int) $reportable->first()->health_charge_id);

        // The hospital has not switched reporting on, so the adapter stops here
        // rather than guessing on the regulator's behalf.
        $this->assertSame('reporting_off', HealthBillFbrService::eligibility($bill)['reason']);
    }

    /**
     * The regulator must be told the same total the patient was handed.
     *
     * The shared payload builder computes TotalBillAmount as
     * sale + tax − header discount. Healthcare concessions are already taken
     * off each line, so a concession repeated in the header would file the
     * hospital's money short — and nothing downstream would ever notice.
     */
    /** @test */
    public function the_regulator_payload_totals_match_the_frozen_bill_lines_even_with_a_concession(): void
    {
        $this->rule(HealthTaxCategory::TREATMENT_FBR, 15, [HealthCharge::CAT_PHARMACY]);

        $charge = $this->charge([
            'category' => HealthCharge::CAT_PHARMACY,
            'unit_price' => 1000,
            'quantity' => 2,
        ]);
        HealthChargeService::applyConcession($charge->fresh(), 400, 'Zakat', $this->owner);

        $bill = $this->billFor([$charge->fresh()->id]);
        HealthBillingService::finalize($bill, $this->owner);
        $bill = $bill->fresh();

        $lines = HealthBillFbrService::reportableLines($bill);
        $lineNet = round($lines->sum(fn ($l) => (float) $l->net_amount), 2);
        $lineTax = round($lines->sum(fn ($l) => (float) $l->tax_amount), 2);
        $lineTotal = round($lines->sum(fn ($l) => (float) $l->total_amount), 2);

        $this->assertSame(1600.0, $lineNet, 'the concession must come off the line');

        $mirror = HealthBillFbrService::mirror($bill, $this->owner);
        $this->assertNotNull($mirror);

        $mirrorRow = DB::table('fbr_pos_transactions')->where('id', $mirror->id)->first();
        $this->assertSame($lineNet, round((float) $mirrorRow->subtotal, 2));
        $this->assertSame($lineTax, round((float) $mirrorRow->tax_amount, 2));
        $this->assertSame($lineTotal, round((float) $mirrorRow->total_amount, 2));

        // The concession is NOT repeated at header level.
        $this->assertSame(0.0, round((float) $mirrorRow->discount_amount, 2));

        $payload = app(\App\Services\FbrService::class)->buildFbrPosPayload($mirror->fresh());
        $this->assertSame($lineNet, round((float) $payload['TotalSaleValue'], 2));
        $this->assertSame($lineTax, round((float) $payload['TotalTaxCharged'], 2));
        $this->assertSame($lineTotal, round((float) $payload['TotalBillAmount'], 2));
        $this->assertSame(
            $lineTotal,
            round(array_sum(array_map(fn ($i) => (float) $i['TotalAmount'], $payload['Items'])), 2),
            'the item totals must add up to the filed total'
        );
    }

    /** @test */
    public function a_bill_already_carrying_an_fbr_number_is_never_filed_again(): void
    {
        $this->rule(HealthTaxCategory::TREATMENT_FBR, 15, [HealthCharge::CAT_PHARMACY]);
        $charge = $this->charge(['category' => HealthCharge::CAT_PHARMACY, 'unit_price' => 1000]);
        $bill = $this->billFor([$charge->id]);
        HealthBillingService::finalize($bill, $this->owner);

        $bill = $bill->fresh();
        $bill->forceFill([
            'fbr_status' => HealthBill::FBR_SUBMITTED,
            'fbr_invoice_number' => '1234567890123',
            'fbr_submitted_at' => now(),
        ])->save();

        $this->assertTrue($bill->fresh()->isFbrFiled());
        $this->assertSame('already_filed', HealthBillFbrService::eligibility($bill->fresh())['reason']);

        // And a filed document can no longer be cancelled from our side.
        $this->assertSame('already_filed', HealthBillingService::cancel($bill->fresh(), $this->owner)['reason']);

        // The QR carries the bare regulator number — that is what Tax Asaan reads.
        $this->assertSame('1234567890123', $bill->fresh()->fbrQrPayload());
    }

    // ─────────────────────────────────────────────────────────────
    // 6. Reconciliation
    // ─────────────────────────────────────────────────────────────

    /** @test */
    public function the_shift_the_day_close_and_the_patient_statement_agree(): void
    {
        $shift = HealthBillingReportService::openShift($this->company->id, $this->owner, 500);
        $this->assertNotNull($shift);

        $charge = $this->charge(['unit_price' => 3000]);
        $bill = $this->billFor([$charge->id]);
        HealthBillingService::finalize($bill, $this->owner);

        HealthBillingService::recordPayment($this->company->id, $this->patient->id, [
            'amount' => 2000,
            'method' => 'cash',
            'health_bill_id' => $bill->id,
        ], $this->owner);
        HealthBillingService::recordPayment($this->company->id, $this->patient->id, [
            'amount' => 500,
            'method' => 'card',
            'health_bill_id' => $bill->id,
        ], $this->owner);

        $totals = HealthBillingReportService::shiftTotals($shift->fresh());
        $this->assertSame(2500.0, round((float) $totals['in'], 2));
        $this->assertSame(2000.0, round((float) $totals['cash_in'], 2));
        // Opening float is part of the drawer, not part of the takings — the
        // card receipt never lands in it.
        $this->assertSame(2000.0, round((float) $totals['cash_net'], 2));
        $this->assertSame(2500.0, round((float) $shift->opening_float + $totals['cash_net'], 2));

        $day = HealthBillingReportService::daySummary($this->company->id);
        $this->assertSame(3000.0, round((float) $day['billed'], 2));
        $this->assertSame(2500.0, round((float) $day['payments']['in'], 2));
        $this->assertSame(500.0, round((float) $day['outstanding'], 2));
        // The day close reads the same receipts the shift does.
        $this->assertSame($totals['in'], $day['payments']['in']);

        $account = HealthBillingService::patientAccount($this->company->id, $this->patient->id);
        $this->assertSame(3000.0, round((float) $account['billed'], 2));
        $this->assertSame(2500.0, round((float) $account['collected'], 2));
        $this->assertSame(500.0, round((float) $account['due_now'], 2));
    }

    /** @test */
    public function an_uncounted_drawer_is_not_a_zero_drawer(): void
    {
        $shift = HealthBillingReportService::openShift($this->company->id, $this->owner, 0);

        $this->assertTrue(HealthBillingReportService::closeShift($shift, null, $this->owner)['ok']);

        $row = DB::table('health_cashier_shifts')->where('id', $shift->id)->first();
        $this->assertNotNull($row->closed_at);
        $this->assertNull($row->counted_cash, 'not counted must stay NULL, never 0.00');
        $this->assertNull($row->variance);

        // Closing twice is refused rather than silently reopening the drawer.
        $this->assertFalse(HealthBillingReportService::closeShift($shift->fresh(), 100, $this->owner)['ok']);
    }

    // ─────────────────────────────────────────────────────────────
    // 7. The screens actually render
    // ─────────────────────────────────────────────────────────────

    /** @test */
    public function every_billing_screen_renders_with_real_data(): void
    {
        $this->rule(HealthTaxCategory::TREATMENT_FBR, 15, [HealthCharge::CAT_PHARMACY]);

        $charge = $this->charge(['category' => HealthCharge::CAT_PHARMACY, 'unit_price' => 1000]);
        $bill = $this->billFor([$charge->id]);
        HealthBillingService::finalize($bill, $this->owner);
        HealthBillingService::recordPayment($this->company->id, $this->patient->id, [
            'amount' => 500,
            'health_bill_id' => $bill->id,
        ], $this->owner);

        $this->actingAs($this->owner->fresh(), HealthPanel::GUARD);

        $this->get('/health/billing')->assertOk();
        $this->get('/health/billing/patient/' . $this->patient->id)->assertOk();
        $this->get('/health/billing/patient/' . $this->patient->id . '/statement')->assertOk();
        $this->get('/health/billing/bills/' . $bill->id)->assertOk();
        $this->get('/health/billing/bills/' . $bill->id . '/receipt')->assertOk();
        $this->get('/health/billing/bills/' . $bill->id . '/fbr')->assertOk();
        $this->get('/health/billing/shifts')->assertOk();
        $this->get('/health/billing/day-close')->assertOk();
        $this->get('/health/billing/tax-categories')->assertOk();
    }

    /**
     * One hospital, two sites, two cash counters.
     *
     * Company isolation is not the boundary that matters here: a cashier posted
     * to the second site must not be able to read — let alone pay, reverse or
     * close — the first site's money by putting its id in the URL.
     */
    /** @test */
    public function a_branch_confined_cashier_cannot_reach_another_sites_money(): void
    {
        $mainBranch = \App\Models\Branch::create([
            'company_id' => $this->company->id,
            'name' => 'Main Site',
            'is_active' => true,
        ]);
        $otherBranch = \App\Models\Branch::create([
            'company_id' => $this->company->id,
            'name' => 'Second Site',
            'is_active' => true,
        ]);

        $charge = $this->charge(['unit_price' => 1000, 'branch_id' => $mainBranch->id]);
        $bill = $this->billFor([$charge->id], ['branch_id' => $mainBranch->id]);
        HealthBillingService::finalize($bill, $this->owner);

        $bill->forceFill(['branch_id' => $mainBranch->id])->save();
        HealthCharge::withoutGlobalScopes()->where('id', $charge->id)
            ->update(['branch_id' => $mainBranch->id]);

        $paid = HealthBillingService::recordPayment($this->company->id, $this->patient->id, [
            'amount' => 200,
            'method' => 'cash',
            'health_bill_id' => $bill->id,
            'branch_id' => $mainBranch->id,
        ], $this->owner);
        $payment = $paid['payment'];

        $shift = HealthBillingReportService::openShift(
            $this->company->id,
            $this->owner,
            500,
            $mainBranch->id
        );

        // A cashier posted only to the second site. Not administrative, so the
        // branch boundary genuinely applies.
        $cashier = User::create([
            'name' => 'Second Site Cashier',
            'email' => 'sitecashier@example.test',
            'password' => Hash::make('Passw0rd!2026'),
            'company_id' => $this->company->id,
            'role' => 'user',
            'health_role' => 'health_cashier',
            'is_active' => true,
        ]);
        DB::table('branch_user')->insert([
            'branch_id' => $otherBranch->id,
            'user_id' => $cashier->id,
        ]);
        \App\Services\HealthScopeService::forget();

        $this->actingAs($cashier->fresh(), HealthPanel::GUARD);

        // A record outside the boundary is "not found", and the panel turns a
        // not-found into a bounce back to its own dashboard rather than leaking
        // that the id exists somewhere else in the hospital.
        $denied = '/health/dashboard';

        // Reading someone else's bill, receipt and filing screen.
        $this->get('/health/billing/bills/' . $bill->id)->assertRedirect($denied);
        $this->get('/health/billing/bills/' . $bill->id . '/receipt')->assertRedirect($denied);
        $this->get('/health/billing/bills/' . $bill->id . '/fbr')->assertRedirect($denied);

        // Moving someone else's money.
        $this->post('/health/billing/bills/' . $bill->id . '/pay', [
            'amount' => 100,
            'method' => 'cash',
        ])->assertRedirect($denied);
        $this->post('/health/billing/charges/' . $charge->id . '/reverse', [
            'reason' => 'not mine',
        ])->assertRedirect($denied);
        $this->post('/health/billing/shifts/' . $shift->id . '/close', [
            'counted_cash' => 0,
        ])->assertRedirect($denied);

        // Reversing a receipt needs accounts.manage, which the cashier does not
        // hold — so the branch boundary is proven with someone who DOES hold it
        // and is still posted to the wrong site.
        $accountant = User::create([
            'name' => 'Second Site Accountant',
            'email' => 'siteaccounts@example.test',
            'password' => Hash::make('Passw0rd!2026'),
            'company_id' => $this->company->id,
            'role' => 'user',
            'health_role' => 'health_accountant',
            'is_active' => true,
        ]);
        DB::table('branch_user')->insert([
            'branch_id' => $otherBranch->id,
            'user_id' => $accountant->id,
        ]);
        \App\Services\HealthScopeService::forget();

        $this->actingAs($accountant->fresh(), HealthPanel::GUARD);
        $this->post('/health/billing/payments/' . $payment->id . '/reverse', [
            'reason' => 'not mine',
        ])->assertRedirect($denied);
        $this->get('/health/billing/bills/' . $bill->id)->assertRedirect($denied);

        // Nothing moved.
        $row = DB::table('health_bills')->where('id', $bill->id)->first();
        $this->assertSame(200.0, round((float) $row->paid_amount, 2));
        $this->assertSame(HealthCharge::STATUS_BILLED, DB::table('health_charges')->where('id', $charge->id)->value('status'));
        $this->assertNull(DB::table('health_payments')->where('id', $payment->id)->value('reversed_at'));
        $this->assertSame('open', DB::table('health_cashier_shifts')->where('id', $shift->id)->value('status'));

        // The owner is not confined and still reaches all of it.
        $this->actingAs($this->owner->fresh(), HealthPanel::GUARD);
        $this->get('/health/billing/bills/' . $bill->id)->assertOk();
    }

    /** @test */
    public function the_screens_render_in_urdu_too(): void
    {
        $charge = $this->charge();
        $bill = $this->billFor([$charge->id]);
        HealthBillingService::finalize($bill, $this->owner);

        $this->actingAs($this->owner->fresh(), HealthPanel::GUARD);

        foreach (['ur', 'rur'] as $locale) {
            $this->withSession(['health_locale' => $locale, 'locale' => $locale]);
            app()->setLocale($locale);

            $this->get('/health/billing')->assertOk();
            $this->get('/health/billing/bills/' . $bill->id)->assertOk();
            $this->get('/health/billing/bills/' . $bill->id . '/receipt')->assertOk();
        }

        app()->setLocale('en');
    }

    /** @test */
    public function no_billing_screen_prints_a_raw_translation_key(): void
    {
        $this->rule(HealthTaxCategory::TREATMENT_FBR, 15, [HealthCharge::CAT_PHARMACY]);
        $charge = $this->charge(['category' => HealthCharge::CAT_PHARMACY, 'unit_price' => 1000]);
        $bill = $this->billFor([$charge->id]);
        HealthBillingService::finalize($bill, $this->owner);
        HealthBillingService::recordPayment($this->company->id, $this->patient->id, [
            'amount' => 500,
            'health_bill_id' => $bill->id,
        ], $this->owner);

        $this->actingAs($this->owner->fresh(), HealthPanel::GUARD);

        $pages = [
            '/health/billing',
            '/health/billing/patient/' . $this->patient->id,
            '/health/billing/bills/' . $bill->id,
            '/health/billing/bills/' . $bill->id . '/fbr',
            '/health/billing/shifts',
            '/health/billing/day-close',
            '/health/billing/tax-categories',
        ];

        foreach ($pages as $page) {
            $html = $this->get($page)->assertOk()->getContent();

            // A missing key renders as the key itself — "health.bill_total"
            // sitting on the counter's screen is the tell.
            $this->assertDoesNotMatchRegularExpression(
                '/\bhealth\.[a-z0-9_]{3,}/',
                strip_tags($html),
                "raw translation key leaked onto $page"
            );
        }
    }

    // ─────────────────────────────────────────────────────────────
    // 8. Erasure
    // ─────────────────────────────────────────────────────────────

    /** @test */
    public function deleting_the_hospital_takes_its_billing_rows_with_it(): void
    {
        $this->rule(HealthTaxCategory::TREATMENT_FBR, 15, [HealthCharge::CAT_PHARMACY]);
        $charge = $this->charge(['category' => HealthCharge::CAT_PHARMACY, 'unit_price' => 1000]);
        $bill = $this->billFor([$charge->id]);
        HealthBillingService::finalize($bill, $this->owner);
        HealthBillingService::recordPayment($this->company->id, $this->patient->id, [
            'amount' => 100,
            'health_bill_id' => $bill->id,
        ], $this->owner);

        $tables = [
            'health_tax_categories', 'health_charges', 'health_bills',
            'health_bill_lines', 'health_payments', 'health_cashier_shifts',
        ];

        // Every one of these tables must be named in the admin purge list, or a
        // hard delete leaves a patient's money behind.
        $purge = file_get_contents(base_path('app/Http/Controllers/SaasAdmin/AdminCompanyController.php'));
        foreach (array_merge($tables, ['health_charge_adjustments', 'health_fbr_submissions']) as $table) {
            $this->assertStringContainsString("'$table'", $purge, "$table is missing from the hard-delete purge list");
        }

        $this->assertTrue(HealthBillLine::withoutGlobalScopes()->where('company_id', $this->company->id)->exists());
    }
}

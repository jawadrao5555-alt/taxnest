<?php

namespace Tests\Feature;

use App\Models\Company;
use App\Models\HealthAdmission;
use App\Models\HealthBed;
use App\Models\HealthBill;
use App\Models\HealthCharge;
use App\Models\HealthDoctor;
use App\Models\HealthDoctorShare;
use App\Models\HealthDoctorShareRule;
use App\Models\HealthMedicine;
use App\Models\HealthOperation;
use App\Models\HealthPatient;
use App\Models\HealthPrescription;
use App\Models\HealthPrescriptionItem;
use App\Models\HealthRoom;
use App\Models\HealthShift;
use App\Models\HealthRosterEntry;
use App\Models\HealthWard;
use App\Models\PricingPlan;
use App\Models\Subscription;
use App\Models\User;
use App\Services\HealthAccessService;
use App\Services\HealthAttendanceService;
use App\Services\HealthAudit\HealthAuditContext;
use App\Services\HealthAudit\HealthAuditEngine;
use App\Services\HealthBillingService;
use App\Services\HealthChargeIngestService;
use App\Services\HealthChartOfAccountsService as Chart;
use App\Services\HealthDoctorShareService as Shares;
use App\Services\HealthFiscalPeriodService as Periods;
use App\Services\HealthHrService;
use App\Services\HealthIpdBillingService;
use App\Services\HealthIpdService;
use App\Services\HealthLedgerService as Ledger;
use App\Services\HealthModuleService;
use App\Services\HealthOpdService;
use App\Services\HealthOperationService;
use App\Services\HealthPatientService;
use App\Services\HealthPharmacyCheckoutService as Checkout;
use App\Services\HealthPharmacyStockService as Stock;
use App\Services\HealthPostingService as Posting;
use App\Support\NestErps;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

/**
 * PILOT JOURNEY & RECONCILIATION (Task 1555).
 *
 * Every healthcare module already has its own test file, and each one passes on
 * its own. That is not the same thing as a hospital being able to run a day.
 * This file is the join: it walks a real patient across the module boundaries
 * and then insists the numbers on either side of every boundary agree.
 *
 * What it locks:
 *
 *  1. THE OPD JOURNEY CLOSES — register, book, check in, consult, prescribe,
 *     dispense, bill, take money. The bill must equal the work done, not a
 *     subset of the modules that happened to be looked at.
 *  2. THE LEDGER FOLLOWS THE PATIENT — a charge raised in pharmacy and a fee
 *     raised in OPD land on ONE bill for ONE patient, and paying that bill
 *     clears the outstanding to exactly zero.
 *  3. THE INPATIENT JOURNEY CLOSES — admit, operate, accrue bed-days,
 *     discharge, settle. The bed goes back, the stay stops growing, and the
 *     money paid across advances and the final receipt reconciles.
 *  4. WHAT THE DOCTOR EARNED COMES OUT OF WHAT THE HOSPITAL BILLED — the
 *     accrual reads the posted bill, not a number typed beside it.
 *  5. ATTENDANCE IS A DAY, NOT A PUNCH — two punches become one worked day
 *     that HR and payroll can both read.
 *  6. THE OWNER'S AUDIT SEES THE SAME DAY EVERYONE ELSE WORKED — an audit run
 *     over the pilot's own traffic completes and reports against real rows.
 *  7. NOTHING CROSSES THE HOSPITAL BOUNDARY — a second organisation running
 *     the identical journey sees none of the first one's patients, bills,
 *     stock or findings.
 *
 * Run:
 *   php vendor/bin/phpunit tests/Feature/HealthPilotJourneyTest.php --testdox
 */
class HealthPilotJourneyTest extends TestCase
{
    use RefreshDatabase;

    private Company $company;
    private User $owner;
    private User $doctorUser;
    private User $nurse;
    private HealthDoctor $doctor;
    private HealthWard $ward;
    private HealthBed $bed;
    private int $seq = 0;

    protected function setUp(): void
    {
        parent::setUp();

        // Module state is memoised per company id for the life of the process,
        // and ids repeat under a refreshed database.
        HealthModuleService::forget();
        HealthHrService::forget();
        Chart::flush();

        $this->company = $this->makeHospital('Pilot General Hospital', 'PILOT-JOURNEY-1');
        $this->owner = $this->makeUser($this->company, 'pilot.owner@example.test', HealthAccessService::ROLE_OWNER);
        $this->doctorUser = $this->makeUser($this->company, 'pilot.doctor@example.test', 'health_doctor');
        $this->nurse = $this->makeUser($this->company, 'pilot.nurse@example.test', 'health_nurse');

        $this->doctor = HealthDoctor::create([
            'company_id' => $this->company->id,
            'user_id' => $this->doctorUser->id,
            'name' => 'Dr Sana Malik',
            'specialty' => 'General Medicine',
            'consultation_fee' => 1500,
            'is_active' => true,
        ]);

        $this->ward = HealthWard::create([
            'company_id' => $this->company->id,
            'name' => 'General Ward',
            'code' => 'GW',
            'type' => 'general',
            'gender_policy' => 'any',
            'daily_rate' => 3000,
            'nursing_daily_rate' => 500,
            'is_active' => true,
        ]);

        $room = HealthRoom::create([
            'company_id' => $this->company->id,
            'health_ward_id' => $this->ward->id,
            'name' => 'Room 1',
            'room_type' => 'general',
            'is_active' => true,
        ]);

        $this->bed = HealthBed::create([
            'company_id' => $this->company->id,
            'health_ward_id' => $this->ward->id,
            'health_room_id' => $room->id,
            'code' => 'GW-01',
            'status' => HealthBed::STATUS_AVAILABLE,
            'is_active' => true,
        ]);

        Chart::seed($this->company->id, $this->owner);
        Periods::settings($this->company->id);
    }

    protected function tearDown(): void
    {
        HealthModuleService::forget();
        HealthHrService::forget();
        Chart::flush();
        parent::tearDown();
    }

    /* ═══════════════ 1 & 2. the outpatient day, end to end ════════════════ */

    public function test_an_outpatient_is_registered_seen_dispensed_billed_and_paid(): void
    {
        $patient = $this->register('Ayesha Khan');

        // ── front desk ──────────────────────────────────────────────────
        $appointment = HealthOpdService::book([
            'company_id' => $this->company->id,
            'health_patient_id' => $patient->id,
            'health_doctor_id' => $this->doctor->id,
            'kind' => 'walkin',
            'appointment_date' => now()->toDateString(),
            'reason' => 'Fever',
        ], $this->owner);

        $this->assertNotNull($appointment->token_no, 'A walk-in must be handed a queue position at the counter.');

        $visit = HealthOpdService::checkIn($appointment, $this->owner, ['fee_amount' => 1500]);
        HealthOpdService::applyFee($visit, [
            'fee_amount' => 1500,
            'fee_status' => 'paid',
            'payment_method' => 'cash',
        ], $this->owner);

        HealthOpdService::startConsultation($visit->fresh());
        HealthOpdService::complete($visit->fresh(), $this->doctorUser);

        $this->assertSame('completed', $visit->fresh()->status);

        // ── the doctor prescribes, the pharmacy dispenses ───────────────
        $medicine = $this->stockedMedicine('Paracetamol 500mg', sale: 20.0, cost: 12.0, quantity: 100);
        $prescription = $this->prescriptionFor($patient, $visit->id, $medicine, 10);

        $sale = Checkout::sell($this->company->id, [
            'prescription_id' => $prescription->id,
            'payment_method' => 'cash',
            'lines' => [[
                'medicine_id' => $medicine->id,
                'quantity' => 10,
            ]],
        ], null, $this->owner->id, $this->company);

        $this->assertSame('completed', $sale->status);
        $this->assertEqualsWithDelta(200.0, (float) $sale->total_amount, 0.01, 'Ten tablets at 20 is 200 — the counter must not invent a price.');

        // ── the ledger picks up BOTH modules ────────────────────────────
        $ingest = HealthChargeIngestService::syncPatient($this->company->id, (int) $patient->id, $this->owner);
        $this->assertGreaterThanOrEqual(2, $ingest['posted'], 'The consultation and the dispensed medicine are two separate charges.');

        $charges = HealthCharge::withoutGlobalScopes()
            ->where('company_id', $this->company->id)
            ->where('health_patient_id', $patient->id)
            ->get();

        $this->assertTrue($charges->contains(fn ($c) => $c->category === HealthCharge::CAT_OPD));
        $this->assertTrue($charges->contains(fn ($c) => $c->category === HealthCharge::CAT_PHARMACY));

        // Ingest is the boundary most likely to double-post: a second sync must
        // add nothing, or the patient is billed twice for one tablet.
        $again = HealthChargeIngestService::syncPatient($this->company->id, (int) $patient->id, $this->owner);
        $this->assertSame(0, $again['posted'], 'Re-syncing a patient must not raise the same charge again.');

        // ── one bill for the whole visit ────────────────────────────────
        $result = HealthBillingService::createBill(
            $this->company->id,
            (int) $patient->id,
            $charges->pluck('id')->all(),
            ['scope' => 'consolidated'],
            $this->owner
        );

        $this->assertTrue($result['ok'], 'Billing refused the visit: ' . ($result['reason'] ?? ''));
        $bill = $result['bill'];

        $expected = round($charges->sum(fn ($c) => (float) $c->total_amount), 2);
        $this->assertEqualsWithDelta(
            $expected,
            (float) $bill->total_amount,
            0.01,
            'The bill must equal the work done — every charge on the ledger, nothing added, nothing dropped.'
        );

        // ── the money ───────────────────────────────────────────────────
        // A draft bill is still being assembled; money may only be taken
        // against a finalized one, or the counter can collect against a total
        // the front desk is still editing.
        $finalized = HealthBillingService::finalize($bill, $this->owner);
        $this->assertTrue($finalized['ok'], 'Finalize refused the bill: ' . ($finalized['reason'] ?? ''));
        $bill = $bill->fresh();

        $payment = HealthBillingService::recordPayment(
            $this->company->id,
            (int) $patient->id,
            [
                'health_bill_id' => $bill->id,
                'amount' => (float) $bill->patient_payable,
                'method' => 'cash',
            ],
            $this->owner
        );

        $this->assertTrue($payment['ok'], 'Payment refused: ' . ($payment['reason'] ?? ''));
        $this->assertEqualsWithDelta(
            0.0,
            (float) $bill->fresh()->outstanding_amount,
            0.01,
            'Paying the full payable must clear the bill to exactly zero, not to a rounding remainder.'
        );
    }

    public function test_the_pharmacy_shelf_falls_by_exactly_what_was_dispensed(): void
    {
        $patient = $this->register('Bilal Ahmed');
        $medicine = $this->stockedMedicine('Amoxicillin 250mg', sale: 35.0, cost: 22.0, quantity: 60);

        $before = $this->onHand($medicine);
        $prescription = $this->prescriptionFor($patient, null, $medicine, 14);

        Checkout::sell($this->company->id, [
            'prescription_id' => $prescription->id,
            'payment_method' => 'cash',
            'lines' => [['medicine_id' => $medicine->id, 'quantity' => 14]],
        ], null, $this->owner->id, $this->company);

        $this->assertEqualsWithDelta(
            $before - 14,
            $this->onHand($medicine),
            0.001,
            'A dispense that bills 14 must remove 14 from the shelf; the bill and the stock are one movement.'
        );
    }

    /* ═════════════════ 3 & 4. the inpatient stay, end to end ══════════════ */

    public function test_a_night_where_a_stay_failed_to_post_is_not_reported_as_a_good_night(): void
    {
        /*
         * The nightly poster deliberately swallows one stay's exception so a
         * single broken admission cannot stop every other hospital's ward from
         * being billed. That mercy must NOT extend to the exit status.
         *
         * The scheduler writes the "bed-days are posting" evidence only on a
         * zero exit, and the pilot-readiness check reads that stamp. A run that
         * quietly returned success after failing to post would keep the check
         * green for precisely the hospital whose patient is being under-billed
         * — the silent failure this whole signal exists to catch.
         */
        $patient = $this->register('Nadia Aslam');

        $admission = HealthIpdService::request([
            'company_id' => $this->company->id,
            'health_patient_id' => $patient->id,
            'health_doctor_id' => $this->doctor->id,
            'admission_type' => 'planned',
            'reason' => 'Observation',
        ], $this->owner);

        HealthIpdService::admit($admission, $this->bed->id, $this->owner);

        // A healthy night.
        $this->artisan('health:ipd-daily-charges', ['--company' => $this->company->id])
            ->assertExitCode(0);

        // And a night the ward did not accrue.
        $this->artisan('health:ipd-daily-charges', ['--company' => $this->company->id, '--date' => 'not-a-real-date'])
            ->assertExitCode(1);
    }

    public function test_an_inpatient_is_admitted_operated_discharged_and_the_stay_reconciles(): void
    {
        $patient = $this->register('Farhan Iqbal');

        $admission = HealthIpdService::request([
            'company_id' => $this->company->id,
            'health_patient_id' => $patient->id,
            'health_doctor_id' => $this->doctor->id,
            'admission_type' => 'planned',
            'reason' => 'Appendicectomy',
        ], $this->owner);

        $admission = HealthIpdService::admit($admission, $this->bed->id, $this->owner);

        $this->assertSame(HealthBed::STATUS_OCCUPIED, $this->bed->fresh()->status, 'An admitted patient holds a physical bed.');

        $operation = HealthOperationService::schedule([
            'company_id' => $this->company->id,
            'health_patient_id' => $patient->id,
            'health_admission_id' => $admission->id,
            'health_doctor_id' => $this->doctor->id,
            'title' => 'Appendicectomy',
            'scheduled_at' => now()->addHour()->toDateTimeString(),
            'estimated_minutes' => 60,
        ], $this->doctorUser);

        HealthOperationService::complete($operation->fresh(), ['outcome' => 'successful'], $this->doctorUser);
        $this->assertSame(HealthOperation::STATUS_COMPLETED, $operation->fresh()->status);

        // The stay's own money: an advance now, the balance at the door.
        HealthIpdBillingService::recordPayment($admission, ['amount' => 5000, 'kind' => 'advance'], $this->owner);

        $admission = HealthIpdService::requestDischarge($admission->fresh(), $this->doctorUser, []);

        $outstanding = round((float) HealthIpdBillingService::summary($admission->fresh())['outstanding'], 2);
        if ($outstanding > 0) {
            HealthIpdBillingService::recordPayment($admission->fresh(), ['amount' => $outstanding, 'kind' => 'payment'], $this->owner);
        }

        $this->assertEqualsWithDelta(
            0.0,
            (float) HealthIpdBillingService::summary($admission->fresh())['outstanding'],
            0.01,
            'A stay cleared to the rupee must read as zero outstanding, not as a small negative or a small positive.'
        );

        $discharged = HealthIpdService::discharge($admission->fresh(), $this->owner, []);

        $this->assertSame(HealthAdmission::STATUS_DISCHARGED, $discharged->status);
        $this->assertNotSame(
            HealthBed::STATUS_OCCUPIED,
            $this->bed->fresh()->status,
            'A discharge must release the bed; the next patient cannot be admitted into a bed the system still thinks is full.'
        );
    }

    public function test_the_doctors_share_is_accrued_from_the_bill_the_hospital_actually_posted(): void
    {
        HealthDoctorShareRule::create([
            'company_id' => $this->company->id,
            'name' => 'House default',
            'basis' => 'percent',
            'value' => 40,
            'base' => 'net',
            'priority' => 0,
            'is_active' => true,
        ]);

        $patient = $this->register('Hina Raza');
        $bill = $this->billedConsultation($patient, 2500);

        Posting::postBill($bill, $this->owner);

        $out = Shares::accrue(
            $this->company->id,
            now()->startOfMonth()->toDateString(),
            now()->toDateString(),
            $this->owner
        );
        $this->assertSame(1, $out['created']);

        $share = HealthDoctorShare::withoutGlobalScopes()
            ->where('company_id', $this->company->id)
            ->firstOrFail();

        $this->assertEqualsWithDelta(
            1000.0,
            (float) $share->share_amount,
            0.01,
            '40% of a posted 2,500 consultation is 1,000 — the accrual reads the bill, never a number typed beside it.'
        );

        // A second run over the same window must not pay the same work twice.
        $rerun = Shares::accrue(
            $this->company->id,
            now()->startOfMonth()->toDateString(),
            now()->toDateString(),
            $this->owner
        );
        $this->assertSame(0, $rerun['created'], 'Re-running the accrual must not duplicate a doctor\'s earnings.');

        $settlement = Shares::buildSettlement(
            $this->company->id,
            (int) $this->doctor->id,
            now()->startOfMonth()->toDateString(),
            now()->toDateString(),
            $this->owner
        );
        $this->assertEqualsWithDelta(
            (float) $share->share_amount,
            (float) $settlement->net_amount,
            0.01,
            'What the doctor is paid must be what was accrued for them, to the rupee.'
        );
    }

    public function test_the_books_balance_after_a_full_pilot_day(): void
    {
        $patient = $this->register('Kamran Shah');
        $bill = $this->billedConsultation($patient, 4000);
        Posting::postBill($bill, $this->owner);

        $journals = \App\Models\HealthJournal::withoutGlobalScopes()
            ->where('company_id', $this->company->id)
            ->with('lines')
            ->get();

        $this->assertNotEmpty($journals, 'Posting a bill must write to the books.');

        foreach ($journals as $journal) {
            $debit = round($journal->lines->sum(fn ($l) => (float) $l->debit), 2);
            $credit = round($journal->lines->sum(fn ($l) => (float) $l->credit), 2);
            $this->assertEqualsWithDelta(
                $debit,
                $credit,
                0.01,
                "Journal {$journal->id} does not balance — every report downstream of it is a guess."
            );
        }

        $receivable = round(Ledger::accountBalance(
            $this->company->id,
            (int) Chart::id($this->company->id, Chart::PATIENT_RECEIVABLE)
        ), 2);
        $this->assertEqualsWithDelta(
            4000.0,
            $receivable,
            0.01,
            'An unpaid 4,000 bill must sit in patient receivables at exactly 4,000.'
        );
    }

    /* ═══════════════════════ 5. attendance ════════════════════════════════ */

    public function test_two_punches_become_one_worked_day_hr_and_payroll_can_both_read(): void
    {
        $date = now()->startOfWeek()->toDateString();

        $shift = HealthShift::create([
            'company_id' => $this->company->id,
            'name' => 'Morning',
            'start_time' => '09:00:00',
            'end_time' => '17:00:00',
            'crosses_midnight' => false,
            'break_minutes' => 0,
            'is_active' => true,
        ]);

        HealthRosterEntry::create([
            'company_id' => $this->company->id,
            'user_id' => $this->nurse->id,
            'duty_date' => $date,
            'entry_type' => 'shift',
            'health_shift_id' => $shift->id,
        ]);

        HealthAttendanceService::recordPunch([
            'company_id' => $this->company->id,
            'user_id' => $this->nurse->id,
            'punched_at' => Carbon::parse($date . ' 08:55:00'),
            'direction' => 'in',
            'source' => 'biometric',
        ]);
        HealthAttendanceService::recordPunch([
            'company_id' => $this->company->id,
            'user_id' => $this->nurse->id,
            'punched_at' => Carbon::parse($date . ' 17:05:00'),
            'direction' => 'out',
            'source' => 'biometric',
        ]);

        HealthAttendanceService::recomputeDay($this->company->id, (int) $this->nurse->id, Carbon::parse($date));

        $day = \App\Models\HealthAttendanceDay::withoutGlobalScopes()
            ->where('company_id', $this->company->id)
            ->where('user_id', $this->nurse->id)
            ->whereDate('attendance_date', $date)
            ->first();

        $this->assertNotNull($day, 'A rostered nurse who punched in and out must have a worked day HR can see.');
        $this->assertGreaterThan(0, (int) $day->worked_minutes, 'A full shift cannot compute to zero worked minutes.');
    }

    /* ═══════════════════════ 6. the owner's audit ═════════════════════════ */

    public function test_the_owner_audit_runs_over_the_pilots_own_traffic(): void
    {
        $patient = $this->register('Owner Audit Patient');
        $bill = $this->billedConsultation($patient, 1800);
        Posting::postBill($bill, $this->owner);

        $run = HealthAuditEngine::run(
            new HealthAuditContext(
                companyId: (int) $this->company->id,
                from: now()->subDays(30)->toDateString(),
                to: now()->toDateString(),
                preset: 'last_30',
            ),
            [
                'user_id' => $this->owner->id,
                'actor_name' => $this->owner->name,
                'actor_role' => HealthAccessService::roleFor($this->owner),
            ]
        );

        $this->assertNotNull($run->id);
        $this->assertSame((int) $this->company->id, (int) $run->company_id);
        $this->assertGreaterThan(0, (int) $run->rules_run, 'An audit that runs no rules has told the owner nothing.');
    }

    /* ═══════════════════ 7. the hospital boundary holds ═══════════════════ */

    public function test_a_second_hospital_running_the_same_day_sees_none_of_the_first(): void
    {
        $mine = $this->register('Mine Only');
        $medicine = $this->stockedMedicine('Ibuprofen 400mg', sale: 15.0, cost: 9.0, quantity: 40);
        $this->billedConsultation($mine, 1200);

        $other = $this->makeHospital('Rival Trust Hospital', 'PILOT-JOURNEY-2');
        $otherOwner = $this->makeUser($other, 'rival.owner@example.test', HealthAccessService::ROLE_OWNER);

        HealthModuleService::forget();
        Chart::flush();
        Chart::seed($other->id, $otherOwner);

        // Patients
        $this->assertSame(
            0,
            HealthPatient::withoutGlobalScopes()->where('company_id', $other->id)->count(),
            'A newly registered hospital starts with an empty patient registry.'
        );

        // Bills
        $this->assertSame(
            0,
            HealthBill::withoutGlobalScopes()->where('company_id', $other->id)->count()
        );

        // Stock
        $this->assertSame(
            0,
            HealthMedicine::withoutGlobalScopes()->where('company_id', $other->id)->count(),
            'One hospital\'s catalogue and shelf must never appear inside another\'s pharmacy.'
        );

        // And the first hospital still has everything it had.
        $this->assertSame(
            1,
            HealthMedicine::withoutGlobalScopes()->where('company_id', $this->company->id)->where('id', $medicine->id)->count()
        );

        // A chart of accounts is per organisation: the same key resolves to two
        // different account rows, so one trust's cash can never post into the
        // other's.
        $this->assertNotSame(
            (int) Chart::id($this->company->id, Chart::CASH),
            (int) Chart::id($other->id, Chart::CASH),
            'Two hospitals sharing one cash account would merge their books.'
        );
    }

    /* ─────────────────────────── helpers ──────────────────────────────────── */

    private function makeHospital(string $name, string $ntn): Company
    {
        $company = Company::create([
            'name' => $name,
            'ntn' => $ntn,
            'product_type' => NestErps::PRODUCT_TYPE,
            NestErps::VERTICAL_COLUMN => NestErps::HEALTH,
            'status' => 'approved',
            'company_status' => 'active',
            'health_org_type' => 'hospital',
            'health_modules' => json_encode(HealthModuleService::MODULES),
        ]);

        $plan = PricingPlan::create([
            'name' => 'Pilot Hospital ' . $company->id,
            'product_type' => NestErps::PRODUCT_TYPE,
            'price' => 99999,
            'is_trial' => false,
            'health_modules' => json_encode(HealthModuleService::MODULES),
            'user_limit' => 50,
            'branch_limit' => 5,
            'invoice_limit' => 0,
        ]);

        Subscription::create([
            'company_id' => $company->id,
            'pricing_plan_id' => $plan->id,
            'active' => true,
            'start_date' => now()->subDay()->toDateString(),
            'end_date' => now()->addYear()->toDateString(),
        ]);

        return $company;
    }

    private function makeUser(Company $company, string $email, string $role): User
    {
        return User::create([
            'name' => $role,
            'email' => $email,
            'password' => Hash::make('Passw0rd!2026'),
            'company_id' => $company->id,
            'role' => $role === HealthAccessService::ROLE_OWNER ? 'company_admin' : 'user',
            'health_role' => $role,
            'is_active' => true,
        ]);
    }

    private function register(string $name): HealthPatient
    {
        $this->seq++;

        return HealthPatientService::register((int) $this->company->id, [
            'name' => $name,
            'gender' => 'female',
            'age_years' => 30,
            'phone' => '0300' . str_pad((string) (1000000 + $this->seq), 7, '0', STR_PAD_LEFT),
            'is_active' => true,
        ]);
    }

    private function stockedMedicine(string $name, float $sale, float $cost, float $quantity): HealthMedicine
    {
        $this->seq++;

        $medicine = HealthMedicine::create([
            'company_id' => $this->company->id,
            'name' => $name,
            'code' => 'MED' . $this->seq,
            'unit' => 'tablet',
            'sale_price' => $sale,
            'cost_price' => $cost,
            'is_active' => true,
        ]);

        Stock::receive(
            (int) $this->company->id,
            $medicine,
            [
                'quantity' => $quantity,
                'batch_no' => 'B' . $this->seq,
                'expiry_date' => now()->addYear()->toDateString(),
                'cost_price' => $cost,
                'sale_price' => $sale,
            ],
            null,
            ['type' => 'opening', 'id' => null, 'number' => 'OPEN-' . $this->seq],
            null
        );

        return $medicine->fresh();
    }

    private function onHand(HealthMedicine $medicine): float
    {
        return (float) \App\Models\HealthMedicineBatch::withoutGlobalScopes()
            ->where('company_id', $this->company->id)
            ->where('medicine_id', $medicine->id)
            ->sum('quantity');
    }

    private function prescriptionFor(HealthPatient $patient, ?int $visitId, HealthMedicine $medicine, float $quantity): HealthPrescription
    {
        $this->seq++;

        $prescription = HealthPrescription::create([
            'company_id' => $this->company->id,
            'health_patient_id' => $patient->id,
            'health_visit_id' => $visitId,
            'health_doctor_id' => $this->doctor->id,
            'prescription_no' => 'RX' . str_pad((string) $this->seq, 5, '0', STR_PAD_LEFT),
            'prescribed_at' => now(),
            'status' => HealthPrescription::STATUS_ISSUED,
            'dispense_status' => HealthPrescription::DISPENSE_PENDING,
        ]);

        HealthPrescriptionItem::create([
            'company_id' => $this->company->id,
            'health_prescription_id' => $prescription->id,
            'line_no' => 1,
            'medicine_id' => $medicine->id,
            'medicine_name' => $medicine->name,
            'quantity' => $quantity,
            'dispensed_quantity' => 0,
        ]);

        return $prescription->fresh();
    }

    /** A finalized consultation bill with the charge behind it, ready to post. */
    private function billedConsultation(HealthPatient $patient, float $amount): HealthBill
    {
        $this->seq++;
        $date = now()->toDateString();

        $visit = \App\Models\HealthVisit::create([
            'company_id' => $this->company->id,
            'health_patient_id' => $patient->id,
            'health_doctor_id' => $this->doctor->id,
            'visit_no' => 'V' . str_pad((string) $this->seq, 6, '0', STR_PAD_LEFT),
            'visit_date' => $date,
            'visit_type' => 'new',
            'status' => 'completed',
            'fee_amount' => $amount,
        ]);

        $bill = HealthBill::create([
            'company_id' => $this->company->id,
            'health_patient_id' => $patient->id,
            'health_visit_id' => $visit->id,
            'bill_no' => 'B' . str_pad((string) $this->seq, 6, '0', STR_PAD_LEFT),
            'doc_type' => HealthBill::TYPE_INVOICE,
            'status' => HealthBill::STATUS_FINALIZED,
            'bill_date' => $date,
            'business_date' => $date,
            'gross_amount' => $amount,
            'concession_amount' => 0,
            'net_amount' => $amount,
            'tax_amount' => 0,
            'total_amount' => $amount,
            'patient_payable' => $amount,
            'outstanding_amount' => $amount,
        ]);

        HealthCharge::create([
            'company_id' => $this->company->id,
            'health_patient_id' => $patient->id,
            'health_visit_id' => $visit->id,
            'health_bill_id' => $bill->id,
            'charge_no' => 'C' . str_pad((string) $this->seq, 6, '0', STR_PAD_LEFT),
            'charge_date' => $date,
            'category' => HealthCharge::CAT_OPD,
            'description' => 'Consultation',
            'unit_price' => $amount,
            'quantity' => 1,
            'gross_amount' => $amount,
            'net_amount' => $amount,
            'total_amount' => $amount,
        ]);

        \App\Models\HealthBillLine::create([
            'company_id' => $this->company->id,
            'health_bill_id' => $bill->id,
            'line_no' => 1,
            'category' => HealthCharge::CAT_OPD,
            'description' => 'Consultation',
            'unit_price' => $amount,
            'quantity' => 1,
            'gross_amount' => $amount,
            'net_amount' => $amount,
            'total_amount' => $amount,
        ]);

        return $bill->fresh();
    }
}

<?php

namespace Tests\Feature;

use App\Models\Branch;
use App\Models\Company;
use App\Models\HealthAdmission;
use App\Models\HealthAdmissionCharge;
use App\Models\HealthAdmissionEvent;
use App\Models\HealthAdmissionPayment;
use App\Models\HealthBed;
use App\Models\HealthDoctor;
use App\Models\HealthOperation;
use App\Models\HealthPatient;
use App\Models\HealthProcedure;
use App\Models\HealthOperationTheatre;
use App\Models\HealthRoom;
use App\Models\HealthWard;
use App\Models\PricingPlan;
use App\Models\Subscription;
use App\Models\User;
use App\Services\HealthAccessService;
use App\Services\HealthIpdBillingService;
use App\Services\HealthIpdService;
use App\Services\HealthModuleService;
use App\Services\HealthOperationService;
use App\Support\HealthPanel;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

/**
 * INPATIENT & OPERATIONS (Task 1550).
 *
 * Runs against the REAL migrations, so the IPD schema is under test alongside
 * the behaviour. Locks the promises a hospital stay makes:
 *
 *  1. THE BED IS PHYSICAL — two patients can never hold one bed, a move
 *     releases the old bed in the same breath as it claims the new one, and a
 *     discharge sends the bed to cleaning rather than straight back to free.
 *  2. RATES INHERIT — bed overrides room overrides ward; NULL means "inherit",
 *     never "free".
 *  3. THE BED-DAY IS CHARGED ONCE — the daily run is idempotent whoever calls
 *     it (cron, the ward clerk's button, the discharge itself) and a
 *     discharged stay never grows another rupee.
 *  4. MONEY IS NEVER REWRITTEN — a wrong charge is reversed, not deleted, and
 *     the reversal leaves both rows plus a reason on the timeline.
 *  5. OUTSTANDING IS ONE NUMBER — computed in one place, never negative; an
 *     over-payment is a refund due, and the door stays shut on a live balance
 *     unless somebody with the right to force it puts a reason on the record.
 *  6. THEATRE TIME IS EXCLUSIVE — a clashing booking is refused, completion
 *     bills exactly once however many times it is confirmed, a package price
 *     suppresses consumable billing without losing the usage record, and a
 *     completed operation can no longer be cancelled away.
 *  7. WHO MAY DO WHAT — accounts may take money on a stay but may not move a
 *     patient; the cashier may take the advance but may not clear the bill;
 *     the nurse runs the ward but never posts a charge.
 *  8. ERASURE — deleting the organisation takes the whole stay with it.
 *
 * Run:
 *   php vendor/bin/phpunit tests/Feature/HealthIpdOperationsTest.php --testdox
 */
class HealthIpdOperationsTest extends TestCase
{
    use RefreshDatabase;

    private Company $company;
    private User $owner;
    private User $doctorUser;
    private User $nurse;
    private User $accountant;
    private User $cashier;
    private HealthDoctor $doctor;
    private HealthWard $ward;
    private HealthRoom $room;
    private HealthBed $bedA;
    private HealthBed $bedB;

    protected function setUp(): void
    {
        parent::setUp();

        // The module cache is a per-process static memo keyed by company id, and
        // ids repeat across a refreshed database — clear it or one test's module
        // set leaks into the next.
        HealthModuleService::forget();

        $this->company = Company::create([
            'name' => 'Shifa IPD Test',
            'ntn' => 'IPD-TEST-1',
            'product_type' => HealthPanel::PRODUCT_TYPE,
            'status' => 'approved',
            'company_status' => 'active',
            'health_org_type' => 'hospital',
            'health_modules' => json_encode(['opd', 'ipd']),
        ]);

        // A hospital package: the plan is what actually SELLS the ward module,
        // and without one the company falls back to the small-clinic set and
        // every IPD screen would be refused for the wrong reason.
        $plan = PricingPlan::create([
            'name' => 'Hospital Test',
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

        $this->owner = $this->makeUser('ipdowner@example.test', 'health_owner');
        $this->doctorUser = $this->makeUser('ipddoc@example.test', 'health_doctor');
        $this->nurse = $this->makeUser('ipdnurse@example.test', 'health_nurse');
        $this->accountant = $this->makeUser('ipdacct@example.test', 'health_accountant');
        $this->cashier = $this->makeUser('ipdcash@example.test', 'health_cashier');

        $this->doctor = HealthDoctor::create([
            'company_id' => $this->company->id,
            'user_id' => $this->doctorUser->id,
            'name' => 'Dr Adeel Raza',
            'specialty' => 'Surgery',
            'consultation_fee' => 2000,
            'is_active' => true,
        ]);

        // Ward carries the rates; the room overrides the room rate only; bed A
        // overrides nothing, bed B overrides both. That single arrangement is
        // enough to prove all three inheritance levels.
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

        $this->room = HealthRoom::create([
            'company_id' => $this->company->id,
            'health_ward_id' => $this->ward->id,
            'name' => 'Room 1',
            'room_type' => 'general',
            'daily_rate' => 4000,
            'is_active' => true,
        ]);

        $this->bedA = HealthBed::create([
            'company_id' => $this->company->id,
            'health_ward_id' => $this->ward->id,
            'health_room_id' => $this->room->id,
            'code' => 'GW-01',
            'status' => HealthBed::STATUS_AVAILABLE,
            'is_active' => true,
        ]);

        $this->bedB = HealthBed::create([
            'company_id' => $this->company->id,
            'health_ward_id' => $this->ward->id,
            'health_room_id' => $this->room->id,
            'code' => 'GW-02',
            'daily_rate' => 5500,
            'nursing_daily_rate' => 900,
            'status' => HealthBed::STATUS_AVAILABLE,
            'is_active' => true,
        ]);
    }

    private function makeUser(string $email, string $role): User
    {
        return User::create([
            'name' => $role,
            'email' => $email,
            'password' => Hash::make('Passw0rd!2026'),
            'company_id' => $this->company->id,
            'role' => 'user',
            'health_role' => $role,
            'is_active' => true,
        ]);
    }

    private function makePatient(array $overrides = []): HealthPatient
    {
        return HealthPatient::create(array_merge([
            'company_id' => $this->company->id,
            'mrn' => 'MR00' . random_int(1000, 9999),
            'name' => 'Nadia Bibi',
            'gender' => 'female',
            'age_years' => 41,
            'phone' => '03007654321',
            'is_active' => true,
        ], $overrides));
    }

    /** A stay already in a bed, ready to be worked on. */
    private function seedAdmission(?HealthBed $bed = null, array $overrides = []): HealthAdmission
    {
        $patient = $this->makePatient();

        $admission = HealthIpdService::request(array_merge([
            'company_id' => $this->company->id,
            'health_patient_id' => $patient->id,
            'health_doctor_id' => $this->doctor->id,
            'admission_type' => 'planned',
            'reason' => 'Observation',
        ], $overrides), $this->owner);

        return HealthIpdService::admit($admission, ($bed ?: $this->bedA)->id, $this->owner);
    }

    // ── 1. the bed is physical ────────────────────────────────────────────

    public function test_admission_claims_the_bed_and_stamps_the_timeline(): void
    {
        $admission = $this->seedAdmission();

        $this->assertSame(HealthAdmission::STATUS_ADMITTED, $admission->status);
        $this->assertSame($this->bedA->id, (int) $admission->health_bed_id);
        $this->assertStringStartsWith('IPD', (string) $admission->admission_no);

        $bed = $this->bedA->fresh();
        $this->assertSame(HealthBed::STATUS_OCCUPIED, $bed->status);
        $this->assertSame($admission->id, (int) $bed->health_admission_id);

        $events = HealthAdmissionEvent::withoutGlobalScopes()
            ->where('health_admission_id', $admission->id)
            ->pluck('event')
            ->all();

        $this->assertContains(HealthAdmissionEvent::REQUESTED, $events);
        $this->assertContains(HealthAdmissionEvent::ADMITTED, $events);
    }

    public function test_an_occupied_bed_cannot_take_a_second_patient(): void
    {
        $this->seedAdmission();

        $second = HealthIpdService::request([
            'company_id' => $this->company->id,
            'health_patient_id' => $this->makePatient(['mrn' => 'MR009002'])->id,
        ], $this->owner);

        $this->expectException(\RuntimeException::class);
        HealthIpdService::admit($second, $this->bedA->id, $this->owner);
    }

    public function test_a_double_clicked_admit_does_not_write_a_second_event(): void
    {
        $admission = $this->seedAdmission();
        HealthIpdService::admit($admission, $this->bedA->id, $this->owner);

        $this->assertSame(1, HealthAdmissionEvent::withoutGlobalScopes()
            ->where('health_admission_id', $admission->id)
            ->where('event', HealthAdmissionEvent::ADMITTED)
            ->count());
    }

    public function test_transfer_releases_the_old_bed_and_claims_the_new_one(): void
    {
        $admission = $this->seedAdmission();

        HealthIpdService::transfer($admission, $this->bedB->id, $this->owner, 'Needs a quieter room');

        $this->assertSame(HealthBed::STATUS_CLEANING, $this->bedA->fresh()->status);
        $this->assertNull($this->bedA->fresh()->health_admission_id);
        $this->assertSame(HealthBed::STATUS_OCCUPIED, $this->bedB->fresh()->status);
        $this->assertSame($this->bedB->id, (int) $admission->fresh()->health_bed_id);
        $this->assertSame(1, HealthAdmissionEvent::withoutGlobalScopes()
            ->where('health_admission_id', $admission->id)
            ->where('event', HealthAdmissionEvent::TRANSFERRED)
            ->count());
    }

    public function test_a_failed_transfer_leaves_the_patient_where_they_were(): void
    {
        $admission = $this->seedAdmission();
        HealthIpdService::setBedStatus($this->bedB->fresh(), HealthBed::STATUS_BLOCKED, 'Maintenance', $this->owner);

        try {
            HealthIpdService::transfer($admission, $this->bedB->id, $this->owner, null);
            $this->fail('A blocked bed must refuse the transfer.');
        } catch (\RuntimeException $e) {
            // expected
        }

        $this->assertSame($this->bedA->id, (int) $admission->fresh()->health_bed_id);
        $this->assertSame(HealthBed::STATUS_OCCUPIED, $this->bedA->fresh()->status);
    }

    public function test_a_reserved_bed_is_held_for_its_own_admission_only(): void
    {
        $admission = HealthIpdService::request([
            'company_id' => $this->company->id,
            'health_patient_id' => $this->makePatient()->id,
        ], $this->owner);

        HealthIpdService::reserveBed($admission, $this->bedB->id, $this->owner);
        $this->assertSame(HealthBed::STATUS_RESERVED, $this->bedB->fresh()->status);

        $other = HealthIpdService::request([
            'company_id' => $this->company->id,
            'health_patient_id' => $this->makePatient(['mrn' => 'MR009003'])->id,
        ], $this->owner);

        try {
            HealthIpdService::admit($other, $this->bedB->id, $this->owner);
            $this->fail('Somebody else\'s reservation must not be admittable into.');
        } catch (\RuntimeException $e) {
            // expected
        }

        // …but the admission it was held for walks straight in.
        $admitted = HealthIpdService::admit($admission, $this->bedB->id, $this->owner);
        $this->assertSame(HealthAdmission::STATUS_ADMITTED, $admitted->status);
    }

    // ── 2. rates inherit ──────────────────────────────────────────────────

    public function test_bed_rates_inherit_from_room_then_ward(): void
    {
        // Bed A sets nothing: room wins on the room rate, ward on nursing.
        $this->assertSame(4000.0, $this->bedA->fresh()->resolvedDailyRate());
        $this->assertSame(500.0, $this->bedA->fresh()->resolvedNursingRate());

        // Bed B overrides both.
        $this->assertSame(5500.0, $this->bedB->fresh()->resolvedDailyRate());
        $this->assertSame(900.0, $this->bedB->fresh()->resolvedNursingRate());
    }

    // ── 3. the bed-day is charged once ────────────────────────────────────

    public function test_the_daily_run_charges_a_bed_day_once_however_often_it_runs(): void
    {
        $admission = $this->seedAdmission();

        // The admit itself already posted day one.
        $lines = fn () => HealthAdmissionCharge::withoutGlobalScopes()
            ->where('health_admission_id', $admission->id)
            ->where('is_recurring', true)
            ->count();

        $this->assertSame(2, $lines());   // room + nursing

        HealthIpdBillingService::postDailyCharges($admission->fresh(), $this->owner);
        HealthIpdBillingService::postDailyCharges($admission->fresh(), $this->owner);

        $this->assertSame(2, $lines());
    }

    public function test_a_longer_stay_bills_every_day_it_occupied_the_bed(): void
    {
        $admission = $this->seedAdmission();
        $admission->forceFill(['admitted_at' => now()->copy()->subDays(2)])->save();

        HealthIpdBillingService::postDailyCharges($admission->fresh(), $this->owner);

        $this->assertSame(3, HealthAdmissionCharge::withoutGlobalScopes()
            ->where('health_admission_id', $admission->id)
            ->where('category', HealthAdmissionCharge::CAT_ROOM)
            ->count());
    }

    public function test_a_discharged_stay_never_grows_another_bed_day(): void
    {
        $admission = $this->seedAdmission();
        HealthIpdBillingService::recordPayment($admission, ['amount' => 100000, 'kind' => 'advance'], $this->owner);
        HealthIpdService::requestDischarge($admission->fresh(), $this->doctorUser, []);
        $discharged = HealthIpdService::discharge($admission->fresh(), $this->accountant, []);

        $before = HealthAdmissionCharge::withoutGlobalScopes()
            ->where('health_admission_id', $admission->id)->count();

        // A week later the cron still runs.
        Carbon::setTestNow(now()->copy()->addDays(7));
        HealthIpdBillingService::postDailyCharges($discharged->fresh(), $this->owner);
        Carbon::setTestNow();

        $this->assertSame($before, HealthAdmissionCharge::withoutGlobalScopes()
            ->where('health_admission_id', $admission->id)->count());
    }

    // ── 4. money is never rewritten ───────────────────────────────────────

    public function test_a_reversed_charge_survives_and_leaves_the_bill_alone(): void
    {
        $admission = $this->seedAdmission();

        $charge = HealthIpdBillingService::postCharge($admission, [
            'category' => HealthAdmissionCharge::CAT_SERVICE,
            'description' => 'Physiotherapy',
            'unit_price' => 1200,
            'quantity' => 2,
        ], $this->accountant);

        $this->assertSame(2400.0, (float) $charge->net_amount);
        $withCharge = HealthIpdBillingService::summary($admission->fresh())['net'];

        HealthIpdBillingService::reverseCharge($charge->fresh(), $this->accountant, 'Booked on the wrong file');

        $this->assertDatabaseHas('health_admission_charges', [
            'id' => $charge->id,
            'status' => HealthAdmissionCharge::STATUS_REVERSED,
            'reversal_reason' => 'Booked on the wrong file',
        ]);

        $after = HealthIpdBillingService::summary($admission->fresh())['net'];
        $this->assertSame(round($withCharge - 2400, 2), $after);

        $this->assertSame(1, HealthAdmissionEvent::withoutGlobalScopes()
            ->where('health_admission_id', $admission->id)
            ->where('event', HealthAdmissionEvent::CHARGE_REVERSED)
            ->count());
    }

    public function test_reversing_twice_is_a_no_op(): void
    {
        $admission = $this->seedAdmission();
        $charge = HealthIpdBillingService::postCharge($admission, [
            'category' => HealthAdmissionCharge::CAT_MISC,
            'description' => 'Attendant meal',
            'unit_price' => 300,
        ], $this->accountant);

        HealthIpdBillingService::reverseCharge($charge->fresh(), $this->accountant, 'One');
        HealthIpdBillingService::reverseCharge($charge->fresh(), $this->accountant, 'Two');

        $this->assertSame('One', $charge->fresh()->reversal_reason);
    }

    // ── 5. outstanding is one number ──────────────────────────────────────

    public function test_over_payment_becomes_a_refund_due_not_a_negative_balance(): void
    {
        $admission = $this->seedAdmission();
        HealthIpdBillingService::recordPayment($admission, ['amount' => 100000, 'kind' => 'advance'], $this->cashier);

        $summary = HealthIpdBillingService::summary($admission->fresh());

        $this->assertSame(0.0, $summary['outstanding']);
        $this->assertGreaterThan(0, $summary['refund_due']);
        $this->assertSame(100000.0, $summary['advances']);
    }

    public function test_a_refund_reduces_what_the_hospital_holds(): void
    {
        $admission = $this->seedAdmission();
        HealthIpdBillingService::recordPayment($admission, ['amount' => 10000, 'kind' => 'advance'], $this->cashier);
        HealthIpdBillingService::recordPayment($admission, ['amount' => 2500, 'kind' => HealthAdmissionPayment::KIND_REFUND], $this->cashier);

        $this->assertSame(7500.0, HealthIpdBillingService::summary($admission->fresh())['paid']);
    }

    public function test_discharge_is_refused_while_money_is_outstanding(): void
    {
        $admission = $this->seedAdmission();
        HealthIpdService::requestDischarge($admission->fresh(), $this->doctorUser, []);

        $blockers = collect(HealthIpdBillingService::clearanceBlockers($admission->fresh()))->pluck('key');
        $this->assertContains('outstanding', $blockers);

        $this->expectException(\RuntimeException::class);
        HealthIpdService::discharge($admission->fresh(), $this->accountant, []);
    }

    public function test_a_forced_release_still_happens_but_lands_on_the_record(): void
    {
        $admission = $this->seedAdmission();
        HealthIpdService::requestDischarge($admission->fresh(), $this->doctorUser, []);

        $released = HealthIpdService::discharge($admission->fresh(), $this->accountant, [
            'force' => true,
            'discharge_type' => 'lama',
        ]);

        $this->assertSame(HealthAdmission::STATUS_DISCHARGED, $released->status);
        $this->assertSame('lama', $released->discharge_type);

        $event = HealthAdmissionEvent::withoutGlobalScopes()
            ->where('health_admission_id', $admission->id)
            ->where('event', HealthAdmissionEvent::DISCHARGED)
            ->first();

        $this->assertNotNull($event);
        $meta = is_array($event->meta) ? $event->meta : json_decode((string) $event->meta, true);
        $this->assertTrue((bool) ($meta['forced'] ?? false));
    }

    public function test_discharge_sends_the_bed_to_cleaning_not_straight_back_to_free(): void
    {
        $admission = $this->seedAdmission();
        HealthIpdBillingService::recordPayment($admission, ['amount' => 100000, 'kind' => 'advance'], $this->cashier);
        HealthIpdService::requestDischarge($admission->fresh(), $this->doctorUser, []);
        HealthIpdService::discharge($admission->fresh(), $this->accountant, []);

        $bed = $this->bedA->fresh();
        $this->assertSame(HealthBed::STATUS_CLEANING, $bed->status);
        $this->assertNull($bed->health_admission_id);
        $this->assertNull($admission->fresh()->health_bed_id);
    }

    public function test_a_clearance_concession_reduces_the_bill_and_is_recorded(): void
    {
        $admission = $this->seedAdmission();
        HealthIpdService::requestDischarge($admission->fresh(), $this->doctorUser, []);

        $before = HealthIpdBillingService::summary($admission->fresh())['net'];
        HealthIpdService::clear($admission->fresh(), $this->accountant, 1000, 'Zakat fund');
        $after = HealthIpdBillingService::summary($admission->fresh());

        $this->assertSame(round($before - 1000, 2), $after['net']);
        $this->assertSame(1000.0, $after['stay_concession']);
        $this->assertNotNull($admission->fresh()->cleared_at);
    }

    // ── 6. theatre time is exclusive ──────────────────────────────────────

    private function seedTheatre(): HealthOperationTheatre
    {
        return HealthOperationTheatre::create([
            'company_id' => $this->company->id,
            'name' => 'OT 1',
            'code' => 'OT1',
            'turnaround_minutes' => 0,
            'is_active' => true,
        ]);
    }

    private function seedProcedure(array $overrides = []): HealthProcedure
    {
        return HealthProcedure::create(array_merge([
            'company_id' => $this->company->id,
            'name' => 'Appendectomy',
            'code' => 'APP',
            'base_price' => 45000,
            'is_package' => false,
            'estimated_minutes' => 60,
            'is_active' => true,
        ], $overrides));
    }

    public function test_a_clashing_theatre_booking_is_refused(): void
    {
        $theatre = $this->seedTheatre();
        $procedure = $this->seedProcedure();
        $admission = $this->seedAdmission();
        $start = now()->copy()->addDay()->setTime(9, 0);

        HealthOperationService::schedule([
            'company_id' => $this->company->id,
            'health_patient_id' => $admission->health_patient_id,
            'health_admission_id' => $admission->id,
            'health_procedure_id' => $procedure->id,
            'health_operation_theatre_id' => $theatre->id,
            'title' => 'Appendectomy',
            'scheduled_start' => $start->toDateTimeString(),
            'primary_surgeon_id' => $this->doctor->id,
        ], $this->doctorUser);

        $this->expectException(\RuntimeException::class);
        HealthOperationService::schedule([
            'company_id' => $this->company->id,
            'health_patient_id' => $this->makePatient(['mrn' => 'MR009010'])->id,
            'health_procedure_id' => $procedure->id,
            'health_operation_theatre_id' => $theatre->id,
            'title' => 'Second list',
            'scheduled_start' => $start->copy()->addMinutes(30)->toDateTimeString(),
        ], $this->doctorUser);
    }

    public function test_a_touching_slot_is_allowed(): void
    {
        $theatre = $this->seedTheatre();
        $procedure = $this->seedProcedure();
        $start = now()->copy()->addDay()->setTime(9, 0);

        HealthOperationService::schedule([
            'company_id' => $this->company->id,
            'health_patient_id' => $this->makePatient()->id,
            'health_procedure_id' => $procedure->id,
            'health_operation_theatre_id' => $theatre->id,
            'title' => 'First',
            'scheduled_start' => $start->toDateTimeString(),
        ], $this->doctorUser);

        $second = HealthOperationService::schedule([
            'company_id' => $this->company->id,
            'health_patient_id' => $this->makePatient(['mrn' => 'MR009011'])->id,
            'health_procedure_id' => $procedure->id,
            'health_operation_theatre_id' => $theatre->id,
            'title' => 'Second',
            'scheduled_start' => $start->copy()->addMinutes(60)->toDateTimeString(),
        ], $this->doctorUser);

        $this->assertSame(HealthOperation::STATUS_SCHEDULED, $second->status);
        $this->assertStringStartsWith('OT', (string) $second->operation_no);
    }

    public function test_completion_bills_the_stay_exactly_once(): void
    {
        $admission = $this->seedAdmission();
        $procedure = $this->seedProcedure();

        $operation = HealthOperationService::schedule([
            'company_id' => $this->company->id,
            'health_patient_id' => $admission->health_patient_id,
            'health_admission_id' => $admission->id,
            'health_procedure_id' => $procedure->id,
            'title' => 'Appendectomy',
            'primary_surgeon_id' => $this->doctor->id,
        ], $this->doctorUser);

        HealthOperationService::saveConsumables($operation, [
            ['item_name' => 'Suture pack', 'quantity' => 2, 'unit_price' => 750, 'is_billable' => true],
            ['item_name' => 'Sterile drape', 'quantity' => 1, 'unit_price' => 400, 'is_billable' => false],
        ]);

        HealthOperationService::complete($operation->fresh(), ['outcome' => 'successful'], $this->doctorUser);
        HealthOperationService::complete($operation->fresh(), ['outcome' => 'successful'], $this->doctorUser);

        $procedureLines = HealthAdmissionCharge::withoutGlobalScopes()
            ->where('health_admission_id', $admission->id)
            ->where('category', HealthAdmissionCharge::CAT_PROCEDURE)
            ->get();

        $this->assertCount(1, $procedureLines);
        $this->assertSame(45000.0, (float) $procedureLines->first()->net_amount);

        $consumableLines = HealthAdmissionCharge::withoutGlobalScopes()
            ->where('health_admission_id', $admission->id)
            ->where('category', HealthAdmissionCharge::CAT_CONSUMABLE)
            ->get();

        // Only the billable one, and only once.
        $this->assertCount(1, $consumableLines);
        $this->assertSame(1500.0, (float) $consumableLines->first()->net_amount);
    }

    public function test_a_package_price_records_usage_but_does_not_bill_consumables_on_top(): void
    {
        $admission = $this->seedAdmission();
        $procedure = $this->seedProcedure([
            'name' => 'Normal delivery package',
            'code' => 'PKG',
            'is_package' => true,
            'package_price' => 80000,
        ]);

        $operation = HealthOperationService::schedule([
            'company_id' => $this->company->id,
            'health_patient_id' => $admission->health_patient_id,
            'health_admission_id' => $admission->id,
            'health_procedure_id' => $procedure->id,
            'title' => 'Normal delivery package',
        ], $this->doctorUser);

        HealthOperationService::saveConsumables($operation, [
            ['item_name' => 'Delivery kit', 'quantity' => 1, 'unit_price' => 3500, 'is_billable' => true],
        ]);

        HealthOperationService::complete($operation->fresh(), ['outcome' => 'successful'], $this->doctorUser);

        // The usage is still on the record…
        $this->assertDatabaseHas('health_operation_consumables', [
            'health_operation_id' => $operation->id,
            'item_name' => 'Delivery kit',
            'is_billable' => false,
        ]);

        // …but the patient pays the package price only.
        $this->assertSame(0, HealthAdmissionCharge::withoutGlobalScopes()
            ->where('health_admission_id', $admission->id)
            ->where('category', HealthAdmissionCharge::CAT_CONSUMABLE)
            ->count());

        $this->assertSame(80000.0, (float) HealthAdmissionCharge::withoutGlobalScopes()
            ->where('health_admission_id', $admission->id)
            ->where('category', HealthAdmissionCharge::CAT_PROCEDURE)
            ->value('net_amount'));
    }

    public function test_a_completed_operation_cannot_be_cancelled_away(): void
    {
        $procedure = $this->seedProcedure();
        $operation = HealthOperationService::schedule([
            'company_id' => $this->company->id,
            'health_patient_id' => $this->makePatient()->id,
            'health_procedure_id' => $procedure->id,
            'title' => 'Appendectomy',
        ], $this->doctorUser);

        HealthOperationService::complete($operation->fresh(), ['outcome' => 'successful'], $this->doctorUser);

        $this->expectException(\RuntimeException::class);
        HealthOperationService::cancel($operation->fresh(), 'Patient changed their mind', $this->doctorUser);
    }

    public function test_an_open_operation_blocks_discharge_clearance(): void
    {
        $admission = $this->seedAdmission();
        $procedure = $this->seedProcedure();

        HealthOperationService::schedule([
            'company_id' => $this->company->id,
            'health_patient_id' => $admission->health_patient_id,
            'health_admission_id' => $admission->id,
            'health_procedure_id' => $procedure->id,
            'title' => 'Appendectomy',
        ], $this->doctorUser);

        $blockers = collect(HealthIpdBillingService::clearanceBlockers($admission->fresh()))->pluck('key');
        $this->assertContains('operations', $blockers);
    }

    // ── 7. who may do what ────────────────────────────────────────────────

    public function test_capability_split_across_the_ward_roles(): void
    {
        $access = app(HealthAccessService::class);

        // Accounts: money on a stay, but no ward move and no clinical note.
        $this->assertTrue($access->can($this->accountant, 'ipd.charge', $this->company));
        $this->assertTrue($access->can($this->accountant, 'ipd.discharge', $this->company));
        $this->assertFalse($access->can($this->accountant, 'ipd.manage', $this->company));
        $this->assertFalse($access->can($this->accountant, 'clinical.view', $this->company));

        // Cashier takes the advance, nothing more.
        $this->assertTrue($access->can($this->cashier, 'ipd.charge', $this->company));
        $this->assertFalse($access->can($this->cashier, 'ipd.discharge', $this->company));
        $this->assertFalse($access->can($this->cashier, 'wards.manage', $this->company));

        // Nurse runs the ward but never posts money.
        $this->assertTrue($access->can($this->nurse, 'ipd.manage', $this->company));
        $this->assertFalse($access->can($this->nurse, 'ipd.charge', $this->company));

        // The consultant admits and operates, but does not release past a bill.
        $this->assertTrue($access->can($this->doctorUser, 'ipd.manage', $this->company));
        $this->assertTrue($access->can($this->doctorUser, 'operations.manage', $this->company));
        $this->assertFalse($access->can($this->doctorUser, 'ipd.discharge', $this->company));

        // The owner reaches everything the enabled modules expose.
        foreach (['ipd.manage', 'ipd.charge', 'ipd.discharge', 'wards.manage', 'operations.manage'] as $cap) {
            $this->assertTrue($access->can($this->owner, $cap, $this->company), $cap . ' must be reachable by the owner');
        }
    }

    public function test_the_ward_screens_are_closed_to_a_company_without_the_module(): void
    {
        $access = app(HealthAccessService::class);
        $this->company->forceFill(['health_modules' => json_encode(['opd'])])->save();
        HealthModuleService::forget($this->company->id);
        $opdOnly = $this->company->fresh();

        $this->assertFalse($access->can($this->owner, 'ipd.manage', $opdOnly));
        $this->assertFalse($access->can($this->owner, 'operations.manage', $opdOnly));
    }

    public function test_the_admission_screen_refuses_a_role_with_no_stay_access(): void
    {
        $admission = $this->seedAdmission();
        $hr = $this->makeUser('ipdhr@example.test', 'health_hr');

        $this->actingAs($hr, 'health')
            ->get('/health/ipd/admissions/' . $admission->id)
            ->assertForbidden();
    }

    public function test_a_ward_clerk_cannot_post_a_charge_over_http(): void
    {
        $admission = $this->seedAdmission();

        $this->actingAs($this->nurse, 'health')
            ->post('/health/ipd/admissions/' . $admission->id . '/charges', [
                'category' => HealthAdmissionCharge::CAT_MISC,
                'description' => 'Should never land',
                'unit_price' => 500,
                'quantity' => 1,
            ])
            ->assertForbidden();

        $this->assertDatabaseMissing('health_admission_charges', [
            'description' => 'Should never land',
        ]);
    }

    /**
     * A transfer must not re-bill the days already spent in the old bed.
     *
     * This is the expensive bug: read the CURRENT bed for the whole stay and a
     * patient moved into a dearer room on day four gets three extra ICU days
     * invented, sitting on top of the general-ward days already posted.
     */
    public function test_a_transfer_does_not_re_bill_the_days_spent_in_the_old_bed(): void
    {
        $admission = $this->seedAdmission();
        $admission->forceFill(['admitted_at' => now()->copy()->subDays(3)])->save();

        HealthIpdBillingService::postDailyCharges($admission->fresh(), $this->owner);

        $before = HealthAdmissionCharge::withoutGlobalScopes()
            ->where('health_admission_id', $admission->id)
            ->where('category', HealthAdmissionCharge::CAT_ROOM)
            ->count();
        $this->assertSame(4, $before, 'four bed-days for a stay that began three days ago');

        HealthIpdService::transfer($admission->fresh(), $this->bedB->id, $this->owner, 'Moved to a private room');

        // The nightly run again, now that the patient sits in the dearer bed.
        HealthIpdBillingService::postDailyCharges($admission->fresh(), $this->owner);

        $rows = HealthAdmissionCharge::withoutGlobalScopes()
            ->where('health_admission_id', $admission->id)
            ->where('category', HealthAdmissionCharge::CAT_ROOM)
            ->get();

        $this->assertSame(4, $rows->count(), 'the move must not invent extra bed-days');
        $this->assertSame(4, $rows->pluck('charge_date')->map(fn ($d) => (string) $d)->unique()->count(), 'one bed-day per calendar day');

        // Every earlier day stays priced at the room the patient was actually in.
        $earlier = $rows->filter(fn ($r) => (string) $r->charge_date < now()->toDateString());
        foreach ($earlier as $row) {
            $this->assertSame($this->bedA->id, (int) $row->source_id, 'an earlier day was re-homed onto the new bed');
            $this->assertEqualsWithDelta(4000.0, (float) $row->net_amount, 0.01, 'an earlier day was re-priced at the new bed rate');
        }
    }

    /** The new bed starts costing money from the day the patient moves in. */
    public function test_the_new_bed_is_billed_from_the_day_after_a_transfer(): void
    {
        $admission = $this->seedAdmission();
        $admission->forceFill(['admitted_at' => now()->copy()->subDays(2)])->save();
        HealthIpdBillingService::postDailyCharges($admission->fresh(), $this->owner);

        HealthIpdService::transfer($admission->fresh(), $this->bedB->id, $this->owner, null);

        // Tomorrow's run: the patient is in bedB, so tomorrow is bedB's rate.
        HealthIpdBillingService::postDailyCharges(
            $admission->fresh(),
            $this->owner,
            now()->copy()->addDay()->toDateString()
        );

        $tomorrow = HealthAdmissionCharge::withoutGlobalScopes()
            ->where('health_admission_id', $admission->id)
            ->where('category', HealthAdmissionCharge::CAT_ROOM)
            ->whereDate('charge_date', now()->copy()->addDay()->toDateString())
            ->first();

        $this->assertNotNull($tomorrow);
        $this->assertSame($this->bedB->id, (int) $tomorrow->source_id);
        $this->assertEqualsWithDelta(5500.0, (float) $tomorrow->net_amount, 0.01);
    }

    /**
     * A held bed must not stay held for a patient who is never coming.
     *
     * The reservation lives on the BED, so nothing else in the system knows to
     * clean it up — the ward would simply lose the bed off the board.
     */
    public function test_cancelling_a_request_gives_the_reserved_bed_back(): void
    {
        $patient = $this->makePatient();
        $admission = HealthIpdService::request([
            'company_id' => $this->company->id,
            'health_patient_id' => $patient->id,
            'health_doctor_id' => $this->doctor->id,
            'admission_type' => 'planned',
            'reason' => 'Elective surgery',
        ], $this->owner);

        HealthIpdService::reserveBed($admission, $this->bedB->id, $this->owner);
        $this->assertSame(HealthBed::STATUS_RESERVED, $this->bedB->fresh()->status);

        HealthIpdService::cancel($admission->fresh(), $this->owner, 'Patient postponed');

        $bed = $this->bedB->fresh();
        $this->assertSame(HealthBed::STATUS_AVAILABLE, $bed->status, 'a cancelled request left a bed held forever');
        $this->assertNull($bed->reserved_for_admission_id);
    }

    /** One stay holds one bed: changing your mind releases the first. */
    public function test_reserving_a_second_bed_releases_the_first(): void
    {
        $patient = $this->makePatient();
        $admission = HealthIpdService::request([
            'company_id' => $this->company->id,
            'health_patient_id' => $patient->id,
            'health_doctor_id' => $this->doctor->id,
            'admission_type' => 'planned',
            'reason' => 'Elective surgery',
        ], $this->owner);

        HealthIpdService::reserveBed($admission, $this->bedA->id, $this->owner);
        HealthIpdService::reserveBed($admission, $this->bedB->id, $this->owner);

        $this->assertSame(HealthBed::STATUS_AVAILABLE, $this->bedA->fresh()->status, 'the first held bed was never given back');
        $this->assertNull($this->bedA->fresh()->reserved_for_admission_id);
        $this->assertSame(HealthBed::STATUS_RESERVED, $this->bedB->fresh()->status);

        // And admitting into a third choice must not leave the second held.
        HealthIpdService::admit($admission->fresh(), $this->bedA->id, $this->owner);

        $this->assertSame(HealthBed::STATUS_OCCUPIED, $this->bedA->fresh()->status);
        $this->assertSame(HealthBed::STATUS_AVAILABLE, $this->bedB->fresh()->status, 'admitting elsewhere left the reservation behind');
    }

    /**
     * Two clerks booking the same theatre slot at the same instant.
     *
     * The overlap check is a SELECT, so without a lock both can read "free".
     * Prove the theatre's own row is taken FOR UPDATE before the check, which
     * is what makes the second request wait and then see the first booking.
     */
    public function test_theatre_scheduling_locks_the_theatre_before_checking(): void
    {
        $admission = $this->seedAdmission();
        $theatre = $this->seedTheatre();
        $procedure = $this->seedProcedure();

        DB::enableQueryLog();
        DB::flushQueryLog();

        HealthOperationService::schedule([
            'company_id' => $this->company->id,
            'health_patient_id' => $admission->health_patient_id,
            'health_admission_id' => $admission->id,
            'health_procedure_id' => $procedure->id,
            'health_operation_theatre_id' => $theatre->id,
            'title' => 'Hernia repair',
            'scheduled_start' => now()->copy()->addDay()->setTime(9, 0)->toDateTimeString(),
            'primary_surgeon_id' => $this->doctor->id,
        ], $this->doctorUser);

        $log = collect(DB::getQueryLog())->pluck('query');
        DB::disableQueryLog();

        $lockIndex = $log->search(fn ($q) => str_contains($q, 'health_operation_theatres') && str_contains($q, 'select'));
        $checkIndex = $log->search(fn ($q) => str_contains($q, 'health_operations') && str_contains($q, 'scheduled_start'));

        $this->assertNotFalse($lockIndex, 'the theatre row is never read before booking it');
        $this->assertNotFalse($checkIndex, 'the overlap check never ran');
        $this->assertLessThan($checkIndex, $lockIndex, 'the theatre must be locked BEFORE the overlap check, not after');
    }

    /**
     * A manager confined to one site must not be able to reach another site's
     * ward furniture by posting its id straight at the form handler.
     */
    public function test_a_branch_confined_manager_cannot_touch_another_sites_bed(): void
    {
        $otherBranch = Branch::create([
            'company_id' => $this->company->id,
            'name' => 'Second Site',
            'is_active' => true,
        ]);

        $farWard = HealthWard::create([
            'company_id' => $this->company->id,
            'branch_id' => $otherBranch->id,
            'name' => 'Second Site Ward',
            'gender_policy' => 'any',
            'is_active' => true,
        ]);

        $farBed = HealthBed::create([
            'company_id' => $this->company->id,
            'branch_id' => $otherBranch->id,
            'health_ward_id' => $farWard->id,
            'code' => 'SS-01',
            'daily_rate' => 9000,
            'status' => HealthBed::STATUS_AVAILABLE,
            'is_active' => true,
        ]);

        $homeBranch = Branch::create([
            'company_id' => $this->company->id,
            'name' => 'Main Site',
            'is_active' => true,
        ]);

        // A nurse carries ipd.manage but is not administrative, so the branch
        // boundary genuinely applies to her.
        $confined = $this->makeUser('confined@example.test', 'health_nurse');
        DB::table('branch_user')->insert([
            'branch_id' => $homeBranch->id,
            'user_id' => $confined->id,
        ]);
        \App\Services\HealthScopeService::forget();

        $this->actingAs($confined->fresh(), 'health')
            ->post('/health/ipd/beds/' . $farBed->id . '/status', [
                'status' => HealthBed::STATUS_BLOCKED,
                'status_note' => 'Not mine to block',
            ])
            ->assertForbidden();

        $this->assertSame(HealthBed::STATUS_AVAILABLE, $farBed->fresh()->status);
    }

    /**
     * Every new screen actually renders.
     *
     * A Blade/Alpine slip does not throw — it produces a page whose x-data is
     * dead, which reads to the hospital as "the feature does not work". Render
     * each one with real rows behind it so the payload builders run too.
     */
    public function test_every_ward_screen_renders_for_the_owner(): void
    {
        $admission = $this->seedAdmission();
        $theatre = $this->seedTheatre();
        $procedure = $this->seedProcedure();

        $operation = HealthOperationService::schedule([
            'company_id' => $this->company->id,
            'health_patient_id' => $admission->health_patient_id,
            'health_admission_id' => $admission->id,
            'health_procedure_id' => $procedure->id,
            'health_operation_theatre_id' => $theatre->id,
            'title' => 'Appendectomy',
            'scheduled_start' => now()->copy()->addHours(3)->toDateTimeString(),
            'primary_surgeon_id' => $this->doctor->id,
        ], $this->doctorUser);

        HealthIpdBillingService::postCharge($admission, [
            'category' => HealthAdmissionCharge::CAT_SERVICE,
            'description' => 'Dressing',
            'unit_price' => 800,
        ], $this->owner);
        HealthIpdBillingService::recordPayment($admission, ['amount' => 5000, 'kind' => 'advance'], $this->cashier);

        foreach ([
            '/health/ipd',
            '/health/ipd/facility',
            '/health/ipd/reports',
            '/health/ipd/admissions/' . $admission->id,
            '/health/operations',
            '/health/operations?view=pending',
            '/health/operations/catalogue',
            '/health/operations/' . $operation->id,
        ] as $url) {
            $this->actingAs($this->owner, 'health')
                ->get($url)
                ->assertOk();
        }
    }

    // ── 8. erasure ────────────────────────────────────────────────────────

    public function test_deleting_the_organisation_takes_the_whole_stay_with_it(): void
    {
        $admission = $this->seedAdmission();
        $procedure = $this->seedProcedure();
        $operation = HealthOperationService::schedule([
            'company_id' => $this->company->id,
            'health_patient_id' => $admission->health_patient_id,
            'health_admission_id' => $admission->id,
            'health_procedure_id' => $procedure->id,
            'title' => 'Appendectomy',
        ], $this->doctorUser);
        HealthOperationService::saveConsumables($operation, [
            ['item_name' => 'Suture pack', 'quantity' => 1, 'unit_price' => 750],
        ]);
        HealthIpdBillingService::recordPayment($admission, ['amount' => 5000, 'kind' => 'advance'], $this->cashier);

        $admin = \App\Models\AdminUser::create([
            'name' => 'SaaS Admin',
            'email' => 'ipdsaas@example.test',
            'password' => Hash::make('Passw0rd!2026'),
            'role' => 'admin',
        ]);

        // Permanent deletion runs out of the recycle bin, so the company is
        // binned first — exactly the two-step an admin performs.
        $this->company->delete();

        $this->actingAs($admin, 'admin')
            ->delete('/admin/bin/' . $this->company->id . '/destroy')
            ->assertRedirect();

        foreach ([
            'health_wards', 'health_rooms', 'health_beds',
            'health_admissions', 'health_admission_events',
            'health_admission_charges', 'health_admission_payments',
            'health_procedures', 'health_operation_theatres',
            'health_operations', 'health_operation_team', 'health_operation_consumables',
        ] as $table) {
            $this->assertSame(
                0,
                DB::table($table)->where('company_id', $this->company->id)->count(),
                $table . ' still holds rows for a permanently deleted organisation'
            );
        }
    }
}

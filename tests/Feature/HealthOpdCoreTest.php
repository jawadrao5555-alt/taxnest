<?php

namespace Tests\Feature;

use App\Models\Company;
use App\Models\HealthAppointment;
use App\Models\HealthDoctor;
use App\Models\HealthPatient;
use App\Models\HealthVisit;
use App\Models\User;
use App\Services\HealthAccessService;
use App\Services\HealthRecordAccessService;
use App\Support\HealthPanel;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * PATIENT & OPD CORE (Task 1548).
 *
 * Runs against the REAL migrations, so the OPD schema itself is under test
 * alongside the behaviour. Locks the promises the module makes:
 *
 *  1. IDENTITY — one permanent medical record number per patient, allocated
 *     from a counter (never COUNT(*)), and a duplicate CNIC refused outright.
 *  2. QUEUE — a walk-in IS an appointment, checked in on the spot, and the
 *     visit is born from that check-in with the fee frozen onto it.
 *  3. WHO MAY DO WHAT — a nurse holding only nursing.record can open the
 *     clinical screen and record vitals, but cannot author the clinical note;
 *     reception can do neither; the accountant cannot reach the file at all.
 *  4. CONFIDENTIALITY — a confidential file is unreachable for staff with no
 *     treating relationship, and is not merely refused: it never appears.
 *  5. ERASURE — permanently deleting the organisation takes the uploaded
 *     medical documents off disk, not just the rows out of the database.
 *
 * Run:
 *   php vendor/bin/phpunit tests/Feature/HealthOpdCoreTest.php --testdox
 */
class HealthOpdCoreTest extends TestCase
{
    use RefreshDatabase;

    private Company $company;
    private User $owner;
    private User $doctorUser;
    private User $nurse;
    private User $receptionist;
    private User $accountant;
    private HealthDoctor $doctor;

    protected function setUp(): void
    {
        parent::setUp();

        $this->company = Company::create([
            'name' => 'Shifa OPD Test',
            'ntn' => 'OPD-TEST-1',
            'product_type' => HealthPanel::PRODUCT_TYPE,
            'status' => 'approved',
            'company_status' => 'active',
            'health_org_type' => 'clinic',
            'health_modules' => json_encode(['opd']),
        ]);

        $this->owner = $this->makeUser('opdowner@example.test', 'health_owner');
        $this->doctorUser = $this->makeUser('opddoc@example.test', 'health_doctor');
        $this->nurse = $this->makeUser('opdnurse@example.test', 'health_nurse');
        $this->receptionist = $this->makeUser('opdrcpt@example.test', 'health_receptionist');
        $this->accountant = $this->makeUser('opdacct@example.test', 'health_accountant');

        $this->doctor = HealthDoctor::create([
            'company_id' => $this->company->id,
            'user_id' => $this->doctorUser->id,
            'name' => 'Dr Sara Khan',
            'specialty' => 'General Physician',
            'consultation_fee' => 1500,
            'follow_up_fee' => 500,
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

    /** A patient already through the door, mid-consultation, ready to be worked on. */
    private function seedVisit(array $patientOverrides = []): array
    {
        $patient = HealthPatient::create(array_merge([
            'company_id' => $this->company->id,
            'mrn' => 'MR000900',
            'name' => 'Bilal Ahmed',
            'gender' => 'male',
            'age_years' => 34,
            'phone' => '03001234567',
            'is_active' => true,
        ], $patientOverrides));

        $appointment = HealthAppointment::create([
            'company_id' => $this->company->id,
            'health_patient_id' => $patient->id,
            'health_doctor_id' => $this->doctor->id,
            'kind' => 'walkin',
            'appointment_date' => now()->toDateString(),
            'token_no' => 1,
            'status' => HealthAppointment::STATUS_IN_CONSULTATION,
        ]);

        $visit = HealthVisit::create([
            'company_id' => $this->company->id,
            'health_patient_id' => $patient->id,
            'health_doctor_id' => $this->doctor->id,
            'health_appointment_id' => $appointment->id,
            'visit_no' => 'V000900',
            'visit_date' => now()->toDateString(),
            'visit_type' => 'new',
            'status' => HealthVisit::STATUS_IN_CONSULTATION,
            'fee_amount' => 1500,
            'concession_amount' => 0,
            'net_fee' => 1500,
            'fee_status' => 'pending',
        ]);

        $appointment->health_visit_id = $visit->id;
        $appointment->save();

        return [$patient, $visit];
    }

    /* ───────────────── 1. Identity ───────────────── */

    public function test_medical_record_numbers_are_allocated_from_a_counter_and_never_reused(): void
    {
        $this->actingAs($this->receptionist, HealthPanel::GUARD);

        foreach (['Ali Raza', 'Ahmed Raza', 'Usman Raza'] as $i => $name) {
            $this->post('/health/patients', [
                'name' => $name,
                'gender' => 'male',
                'age_years' => 30 + $i,
                'consent_treatment' => 1,
                'confirm_new' => 1,
            ])->assertRedirect();
        }

        $numbers = HealthPatient::orderBy('id')->pluck('mrn')->all();
        $this->assertCount(3, $numbers, 'All three registrations should have produced a patient.');
        $this->assertSame($numbers, array_unique($numbers), 'Medical record numbers must be unique.');

        // Deleting the newest patient must NOT hand its number to the next one:
        // a counter survives a delete, COUNT(*) does not.
        HealthPatient::orderByDesc('id')->first()->delete();
        $this->post('/health/patients', [
            'name' => 'Kamran Raza', 'gender' => 'male', 'age_years' => 41,
            'consent_treatment' => 1, 'confirm_new' => 1,
        ])->assertRedirect();

        $fresh = HealthPatient::orderByDesc('id')->first()->mrn;
        $this->assertNotContains($fresh, $numbers, 'A recycled medical record number would merge two people.');
    }

    public function test_a_second_patient_on_the_same_cnic_is_refused_outright(): void
    {
        $this->actingAs($this->receptionist, HealthPanel::GUARD);

        $payload = [
            'name' => 'Nadia Iqbal', 'gender' => 'female', 'age_years' => 29,
            'cnic' => '3520112345678', 'consent_treatment' => 1,
        ];
        $this->post('/health/patients', $payload)->assertRedirect();
        $this->assertSame(1, HealthPatient::count());

        // Even confirming past the warning must not create a second file: one
        // CNIC is one human being, and merging them later is not possible.
        $this->post('/health/patients', $payload + ['confirm_new' => 1]);
        $this->assertSame(1, HealthPatient::count(), 'A duplicate CNIC must be a hard refusal, not a warning.');
    }

    /* ───────────────── 2. Queue and fee ───────────────── */

    public function test_a_walk_in_checks_itself_in_and_freezes_the_fee_onto_the_visit(): void
    {
        $this->actingAs($this->receptionist, HealthPanel::GUARD);
        $this->post('/health/patients', [
            'name' => 'Hina Malik', 'gender' => 'female', 'age_years' => 26, 'consent_treatment' => 1,
        ])->assertRedirect();
        $patient = HealthPatient::firstOrFail();

        $this->post('/health/appointments', [
            'health_patient_id' => $patient->id,
            'health_doctor_id' => $this->doctor->id,
            'kind' => 'walkin',
            'appointment_date' => now()->toDateString(),
            'check_in_now' => 1,
        ])->assertRedirect();

        $appointment = HealthAppointment::firstOrFail();
        $this->assertSame(1, $appointment->token_no, 'The first walk-in of the day is token 1.');
        $this->assertNotNull($appointment->health_visit_id, 'Checking in must create the visit.');

        $visit = HealthVisit::findOrFail($appointment->health_visit_id);
        $this->assertEquals(1500, $visit->fee_amount);
        $this->assertEquals(1500, $visit->net_fee);

        // Raising the doctor's fee AFTER the visit must not rewrite what this
        // patient was charged — reports read the stored number, not the schedule.
        $this->doctor->update(['consultation_fee' => 2500]);
        $this->assertEquals(1500, $visit->fresh()->net_fee);
    }

    public function test_a_concession_is_recorded_against_the_visit_and_can_never_make_the_fee_negative(): void
    {
        [, $visit] = $this->seedVisit();
        $this->actingAs($this->receptionist, HealthPanel::GUARD);

        $this->post("/health/appointments/visits/{$visit->id}/fee", [
            'fee_amount' => 1500,
            'concession_amount' => 9999,
            'concession_reason' => 'Staff family',
            'fee_status' => 'paid',
            'fee_method' => 'cash',
        ])->assertRedirect();

        $visit->refresh();
        $this->assertGreaterThanOrEqual(0, (float) $visit->net_fee, 'A concession must never produce a negative fee.');
        $this->assertSame('Staff family', $visit->concession_reason);
    }

    /* ───────────────── 3. Who may do what ───────────────── */

    public function test_a_nurse_can_open_the_clinical_screen_and_record_vitals(): void
    {
        [, $visit] = $this->seedVisit();

        // Exactly the ward nurse's job description: observations, no prescribing.
        $this->nurse->update(['health_permissions' => json_encode([
            'dashboard.view', 'patients.view', 'appointments.view', 'nursing.record',
        ])]);
        $this->assertFalse(
            HealthAccessService::can($this->nurse->fresh(), 'clinical.view', $this->company),
            'This test is only meaningful for a nurse WITHOUT general clinical reading.'
        );

        $this->actingAs($this->nurse->fresh(), HealthPanel::GUARD);
        $this->get('/health/clinical')->assertOk();
        $this->get("/health/clinical/visits/{$visit->id}")->assertOk();

        $this->post("/health/clinical/visits/{$visit->id}/vitals", [
            'temperature_c' => 38.4,
            'pulse_bpm' => 96,
            'bp_systolic' => 126,
            'bp_diastolic' => 82,
        ])->assertRedirect();

        $visit->refresh();
        $this->assertEquals(38.4, (float) $visit->temperature_c);
        $this->assertEquals(96, (int) $visit->pulse_bpm);
        $this->assertSame($this->nurse->id, $visit->vitals_recorded_by);
    }

    public function test_a_nurse_cannot_author_the_clinical_note_or_the_prescription(): void
    {
        [, $visit] = $this->seedVisit();
        $visit->update(['diagnosis' => 'Original diagnosis']);

        $this->nurse->update(['health_permissions' => json_encode([
            'dashboard.view', 'patients.view', 'appointments.view', 'nursing.record',
        ])]);
        $this->actingAs($this->nurse->fresh(), HealthPanel::GUARD);

        $this->post("/health/clinical/visits/{$visit->id}/notes", [
            'diagnosis' => 'Nurse rewrote the diagnosis',
        ])->assertForbidden();

        $this->post("/health/clinical/visits/{$visit->id}/prescription", [
            'items' => [['medicine_name' => 'Panadol', 'dose' => '1 tab']],
        ])->assertForbidden();

        $this->assertSame('Original diagnosis', $visit->fresh()->diagnosis);
        $this->assertSame(0, DB::table('health_prescriptions')->count());
    }

    public function test_reception_can_run_the_desk_but_never_touch_the_clinical_record_or_the_fee_schedule(): void
    {
        [, $visit] = $this->seedVisit();
        $this->actingAs($this->receptionist, HealthPanel::GUARD);

        $this->get('/health/patients')->assertOk();
        $this->get('/health/appointments')->assertOk();

        $this->get('/health/clinical')->assertForbidden();
        $this->get("/health/clinical/visits/{$visit->id}")->assertForbidden();
        $this->post("/health/clinical/visits/{$visit->id}/notes", ['diagnosis' => 'Reception note'])->assertForbidden();

        // Changing what a consultation COSTS is an administrative act.
        $this->get('/health/doctors')->assertForbidden();
        $this->put("/health/doctors/{$this->doctor->id}", [
            'name' => 'Dr Sara Khan', 'consultation_fee' => 50,
        ])->assertForbidden();
        $this->assertEquals(1500, $this->doctor->fresh()->consultation_fee);
    }

    public function test_the_accountant_never_reaches_the_clinical_file(): void
    {
        [, $visit] = $this->seedVisit();
        $this->actingAs($this->accountant, HealthPanel::GUARD);

        $this->get('/health/clinical')->assertForbidden();
        $this->get("/health/clinical/visits/{$visit->id}")->assertForbidden();
        $this->get('/health/patients')->assertForbidden();
    }

    public function test_the_owner_can_walk_the_whole_module(): void
    {
        [$patient, $visit] = $this->seedVisit();
        $this->actingAs($this->owner, HealthPanel::GUARD);

        foreach ([
            '/health/patients',
            "/health/patients/{$patient->id}",
            '/health/doctors',
            '/health/appointments',
            '/health/clinical',
            "/health/clinical/visits/{$visit->id}",
            '/health/reports',
        ] as $path) {
            $this->get($path)->assertOk($path . ' should open for the owner.');
        }
    }

    /* ───────────────── 4. Confidentiality ───────────────── */

    public function test_a_confidential_file_is_hidden_from_staff_with_no_treating_relationship(): void
    {
        [$patient, $visit] = $this->seedVisit(['is_confidential' => true]);

        // The nurse has no encounter on this file, so it is not merely refused —
        // it must not appear in the queue either. A row saying "access denied"
        // still reveals that the person attended today.
        $this->nurse->update(['health_permissions' => json_encode([
            'dashboard.view', 'patients.view', 'appointments.view', 'nursing.record',
        ])]);
        $this->actingAs($this->nurse->fresh(), HealthPanel::GUARD);

        $this->get('/health/clinical')->assertOk()->assertDontSee($patient->name);
        $this->get("/health/clinical/visits/{$visit->id}")->assertForbidden();
        $this->post("/health/clinical/visits/{$visit->id}/vitals", ['pulse_bpm' => 80])->assertForbidden();

        // The treating doctor keeps full access, and so does an administrator.
        $this->assertTrue(HealthRecordAccessService::canOpenClinical($this->doctorUser, $patient, $this->company));
        $this->assertTrue(HealthRecordAccessService::canOpenClinical($this->owner, $patient, $this->company));
    }

    /* ───────────────── 5. Erasure ───────────────── */

    public function test_permanently_deleting_the_organisation_removes_its_medical_documents_from_disk(): void
    {
        Storage::fake('local');
        [, $visit] = $this->seedVisit();

        $this->actingAs($this->doctorUser, HealthPanel::GUARD);
        $this->post("/health/clinical/visits/{$visit->id}/attachments", [
            'file' => UploadedFile::fake()->create('lab-report.pdf', 20, 'application/pdf'),
            'title' => 'Lab report',
        ])->assertRedirect();

        $stored = DB::table('health_visit_attachments')->where('company_id', $this->company->id)->first();
        $this->assertNotNull($stored, 'The upload should have produced an attachment row.');
        Storage::disk('local')->assertExists($stored->path);

        $this->company->delete();
        // Task 1585: the bin holds a company for 7 days before a permanent
        // delete is possible, so age the bin entry past the hold.
        $this->company->forceFill(['deleted_at' => now()->subDays(\App\Models\Company::BIN_HOLD_DAYS + 1)])->saveQuietly();
        $admin = $this->makeAdmin();
        $this->actingAs($admin, 'admin')->delete(route('saas.admin.companies.destroy', $this->company->id));

        $this->assertSame(0, DB::table('health_visit_attachments')->where('company_id', $this->company->id)->count());
        Storage::disk('local')->assertMissing($stored->path);
    }

    private function makeAdmin()
    {
        $class = \App\Models\AdminUser::class;

        return $class::create([
            'name' => 'Purge Admin',
            'email' => 'purge-admin@example.test',
            'password' => Hash::make('Passw0rd!2026'),
        ]);
    }
}

<?php

namespace Tests\Feature;

use App\Models\Company;
use App\Models\HealthAccount;
use App\Models\HealthDepartment;
use App\Models\HealthDoctor;
use App\Models\HealthJournal;
use App\Models\HealthMedicine;
use App\Models\HealthMedicineBatch;
use App\Models\HealthPatient;
use App\Models\HealthProcedure;
use App\Models\HealthStaffProfile;
use App\Models\Supplier;
use App\Models\User;
use App\Services\HealthAccessService;
use App\Services\HealthChartOfAccountsService;
use App\Services\HealthModuleService;
use App\Services\HealthOnboardingImportService as Onboarding;
use App\Services\HealthScopeService;
use App\Support\HealthPanel;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Storage;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx as XlsxWriter;
use Tests\TestCase;

/**
 * HOSPITAL SETUP BY SPREADSHEET (Task 1555 — pilot data onboarding).
 *
 * Runs against the REAL migrations, because half of what this feature can get
 * wrong is schema-shaped: a column that is not fillable, a unique key it
 * collides with, an opening journal that does not balance.
 *
 * What an onboarding importer has to be right about:
 *
 *  1. NOTHING IS WRITTEN BEFORE THE PREVIEW. Uploading a file must change
 *     nothing at all. The hospital reads the verdict first.
 *  2. THE PREVIEW IS THE TRUTH. Whatever the preview counted as create /
 *     update / skip is exactly what the commit does — the commit re-reads the
 *     same stored file rather than trusting the browser.
 *  3. A BAD ROW IS NAMED, NOT SWALLOWED, AND DOES NOT TAKE THE GOOD ONES DOWN.
 *  4. RE-RUNNING IS SAFE. The same sheet twice leaves one of everything.
 *  5. OPENING FIGURES REACH THE BOOKS. An opening balance that only sits on the
 *     account row and never posts a journal is not an opening balance.
 *  6. IT IS OWNER-ONLY AND TENANT-SEALED. No delegated set opens it, and one
 *     hospital's upload token resolves to nothing in another's session.
 *
 * Run:
 *   php vendor/bin/phpunit tests/Feature/HealthOnboardingImportTest.php --testdox
 */
class HealthOnboardingImportTest extends TestCase
{
    use RefreshDatabase;

    private Company $company;
    private User $owner;
    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();

        HealthScopeService::forget();
        HealthChartOfAccountsService::flush();
        // The enabled-module answer is memoised per process and company ids are
        // reused between tests, so without this the second test in the file
        // would be told the first test's module set.
        HealthModuleService::forget();

        $this->company = Company::create([
            'name' => 'Shifa Onboarding Test',
            'ntn' => 'ONB-TEST-1',
            'product_type' => HealthPanel::PRODUCT_TYPE,
            'status' => 'approved',
            'company_status' => 'active',
            'health_org_type' => 'hospital',
            'health_modules' => json_encode(['opd', 'pharmacy', 'ipd', 'accounts', 'hr']),
            // A pilot hospital arrives with staff headroom already agreed. The
            // quota itself is exercised separately below.
            'user_limit_override' => -1,
        ]);

        $this->owner = User::create([
            'name' => 'Onboarding Owner',
            'email' => 'onbowner@example.test',
            'password' => Hash::make('Passw0rd!2026'),
            'company_id' => $this->company->id,
            'role' => 'company_admin',
            'health_role' => HealthAccessService::ROLE_OWNER,
            'is_active' => true,
        ]);

        // An administrator: the widest NON-owner role there is. If even this
        // account cannot reach the importer, nothing below the owner can.
        $this->admin = User::create([
            'name' => 'Onboarding Admin',
            'email' => 'onbadmin@example.test',
            'password' => Hash::make('Passw0rd!2026'),
            'company_id' => $this->company->id,
            'role' => 'user',
            'health_role' => 'health_admin',
            'is_active' => true,
        ]);
    }

    /* ────────────────────────────── Access ────────────────────────────────── */

    public function test_import_is_owner_only_even_for_an_administrator(): void
    {
        $this->assertTrue(
            HealthAccessService::can($this->owner, 'setup.import', $this->company),
            'The owner must hold setup.import.'
        );

        $this->assertFalse(
            HealthAccessService::can($this->admin, 'setup.import', $this->company),
            'A health_admin must NOT hold setup.import — it creates logins and posts opening balances.'
        );

        $this->assertNotContains(
            'setup.import',
            HealthAccessService::delegatableCapabilities($this->company),
            'setup.import must never appear on the team screen as something the owner can tick for a member.'
        );

        $this->actingAs($this->admin, HealthPanel::GUARD)
            ->get('/health/setup/import')
            ->assertForbidden();

        $this->actingAs($this->owner, HealthPanel::GUARD)
            ->get('/health/setup/import')
            ->assertOk();
    }

    public function test_the_path_gate_covers_the_screen_independently_of_the_route(): void
    {
        // Belt and braces: HealthAuth derives the capability from the PATH, so a
        // future route added under /health/setup without its middleware
        // argument is still refused.
        $this->assertSame(
            'setup.import',
            HealthAccessService::capabilityForPath('health/setup/import/departments/template')
        );
    }

    /* ──────────────────────────── Templates ───────────────────────────────── */

    public function test_every_offered_template_downloads_and_carries_its_headers(): void
    {
        foreach (Onboarding::datasetsFor($this->company) as $dataset) {
            $response = $this->actingAs($this->owner, HealthPanel::GUARD)
                ->get('/health/setup/import/' . $dataset . '/template');

            $response->assertOk();

            $path = tempnam(sys_get_temp_dir(), 'tpl');
            file_put_contents($path, $response->streamedContent());

            $parsed = Onboarding::parseFile($dataset, $path);
            unlink($path);

            $this->assertNull($parsed['error'], "Template for {$dataset} must be readable by our own parser.");
            $this->assertSame([], $parsed['missing'], "Template for {$dataset} must carry every column the parser expects.");
            $this->assertSame(
                [],
                $parsed['rows'],
                "Template for {$dataset} must arrive with no rows in it. An example row under the header is imported like anything else — the hospital would end up with a sample department, a sample login or sample stock, and prose telling them to delete it is a promise the code does not keep."
            );
        }
    }

    public function test_an_untouched_template_filled_in_from_the_second_row_imports_only_what_was_typed(): void
    {
        // The realistic mistake: the hospital downloads the template, types its
        // own rows in, and uploads. Nothing else may come along for the ride.
        $response = $this->actingAs($this->owner, HealthPanel::GUARD)
            ->get('/health/setup/import/departments/template');
        $response->assertOk();

        $path = tempnam(sys_get_temp_dir(), 'tpl');
        file_put_contents($path, $response->streamedContent());

        $book = \PhpOffice\PhpSpreadsheet\IOFactory::createReaderForFile($path)->load($path);
        $sheet = $book->getSheetByName('data');
        $sheet->setCellValue('A2', 'Radiology');
        $sheet->setCellValue('B2', 'RAD');
        $sheet->setCellValue('C2', 'radiology');
        (new \PhpOffice\PhpSpreadsheet\Writer\Xlsx($book))->save($path);
        $book->disconnectWorksheets();

        $parsed = Onboarding::parseFile('departments', $path);
        unlink($path);

        $this->assertNull($parsed['error']);
        $this->assertCount(1, $parsed['rows'], 'Only the row the hospital typed may be imported.');
        $this->assertSame('Radiology', $parsed['rows'][0]['name']);

        $analysis = Onboarding::analyse('departments', $this->company, $parsed['rows']);
        Onboarding::commit('departments', $this->company->fresh(), $analysis['rows'], $this->owner);

        $names = HealthDepartment::withoutGlobalScopes()
            ->where('company_id', $this->company->id)
            ->pluck('name')
            ->all();
        $this->assertSame(['Radiology'], $names, 'A downloaded template must never carry an example department into a real hospital.');
    }

    public function test_a_module_that_is_off_is_not_offered_a_template(): void
    {
        $this->company->health_modules = json_encode(['opd']);
        $this->company->save();
        HealthModuleService::forget();
        $fresh = Company::find($this->company->id);

        $offered = Onboarding::datasetsFor($fresh);

        $this->assertContains('departments', $offered);
        $this->assertContains('doctors', $offered);
        $this->assertNotContains('medicines', $offered, 'A hospital without the pharmacy module must not be offered a medicine template.');
        $this->assertNotContains('opening_accounts', $offered);

        // The panel turns a not-found into a dashboard redirect rather than a
        // bare 404 page, so that is what "this template is not for you" looks
        // like here.
        $this->actingAs($this->owner, HealthPanel::GUARD)
            ->get('/health/setup/import/medicines/template')
            ->assertRedirect('/health/dashboard');
    }

    /* ─────────────────────── Preview writes nothing ───────────────────────── */

    public function test_uploading_shows_a_verdict_and_writes_absolutely_nothing(): void
    {
        $file = $this->sheet('departments', [
            ['name' => 'Outpatient', 'code' => 'OPD', 'type' => 'opd', 'branch' => '', 'description' => 'Clinics'],
            ['name' => 'Radiology', 'code' => 'RAD', 'type' => 'radiology', 'branch' => '', 'description' => ''],
        ]);

        $before = HealthDepartment::withoutGlobalScopes()->count();

        $upload = $this->actingAs($this->owner, HealthPanel::GUARD)
            ->post('/health/setup/import/departments/upload', ['file' => $file]);

        $upload->assertRedirect();
        $this->assertSame(
            $before,
            HealthDepartment::withoutGlobalScopes()->count(),
            'Uploading a file must not create a single row.'
        );

        $preview = $this->actingAs($this->owner, HealthPanel::GUARD)->get($upload->headers->get('Location'));
        $preview->assertOk();
        $preview->assertSee('Outpatient');
        $preview->assertSee('Radiology');

        $this->assertSame(
            $before,
            HealthDepartment::withoutGlobalScopes()->count(),
            'Rendering the preview must not create a single row either.'
        );
    }

    public function test_the_commit_lands_exactly_what_the_preview_promised(): void
    {
        $rows = [
            ['name' => 'Outpatient', 'code' => 'OPD', 'type' => 'opd', 'branch' => '', 'description' => 'Clinics'],
            ['name' => 'Ward A', 'code' => 'W-A', 'type' => 'ipd', 'branch' => '', 'description' => ''],
            // Refused: 'surgery' is not a department type the panel knows.
            ['name' => 'Broken', 'code' => 'BRK', 'type' => 'surgery', 'branch' => '', 'description' => ''],
        ];

        [$token, $analysis] = $this->uploadAndAnalyse('departments', $rows);

        $this->assertSame(2, $analysis['summary'][Onboarding::ACTION_CREATE]);
        $this->assertSame(0, $analysis['summary'][Onboarding::ACTION_UPDATE]);
        $this->assertSame(1, $analysis['summary'][Onboarding::ACTION_ERROR]);

        $this->actingAs($this->owner, HealthPanel::GUARD)
            ->post('/health/setup/import/departments/commit/' . $token)
            ->assertRedirect(route('health.setup.import'));

        $this->assertSame(2, HealthDepartment::withoutGlobalScopes()->where('company_id', $this->company->id)->count());
        $this->assertDatabaseHas('health_departments', ['company_id' => $this->company->id, 'code' => 'OPD', 'type' => 'opd']);
        $this->assertDatabaseMissing('health_departments', ['company_id' => $this->company->id, 'code' => 'BRK']);
    }

    public function test_re_uploading_the_same_sheet_updates_instead_of_duplicating(): void
    {
        $rows = [['name' => 'Outpatient', 'code' => 'OPD', 'type' => 'opd', 'branch' => '', 'description' => 'First pass']];
        $this->importNow('departments', $rows);

        $this->assertSame(1, HealthDepartment::withoutGlobalScopes()->where('company_id', $this->company->id)->count());

        $rows[0]['description'] = 'Second pass';
        [$token, $analysis] = $this->uploadAndAnalyse('departments', $rows);

        $this->assertSame(0, $analysis['summary'][Onboarding::ACTION_CREATE]);
        $this->assertSame(1, $analysis['summary'][Onboarding::ACTION_UPDATE], 'A row already imported must preview as an UPDATE, not a second create.');

        $this->actingAs($this->owner, HealthPanel::GUARD)->post('/health/setup/import/departments/commit/' . $token);

        $this->assertSame(1, HealthDepartment::withoutGlobalScopes()->where('company_id', $this->company->id)->count());
        $this->assertSame('Second pass', HealthDepartment::withoutGlobalScopes()->where('code', 'OPD')->value('description'));
    }

    public function test_one_sheet_holding_the_same_row_twice_is_caught_before_it_lands(): void
    {
        $analysis = Onboarding::analyse('departments', $this->company, [
            ['name' => 'Outpatient', 'code' => 'OPD', 'type' => 'opd', 'branch' => null, 'description' => null, '__row' => 2],
            ['name' => 'Out Patient', 'code' => 'OPD', 'type' => 'opd', 'branch' => null, 'description' => null, '__row' => 3],
        ]);

        $this->assertSame(1, $analysis['summary'][Onboarding::ACTION_CREATE]);
        $this->assertSame(1, $analysis['summary'][Onboarding::ACTION_ERROR]);
        $this->assertStringContainsString('2', implode(' ', $analysis['rows'][1]['errors']));
    }

    /* ───────────────────────── Referential rules ──────────────────────────── */

    public function test_a_doctor_pointing_at_a_department_that_does_not_exist_is_refused_by_name(): void
    {
        HealthDepartment::create([
            'company_id' => $this->company->id, 'name' => 'Cardiology', 'code' => 'CARD', 'type' => 'opd', 'is_active' => true,
        ]);

        $analysis = Onboarding::analyse('doctors', $this->company, [
            ['name' => 'Dr. Ayesha', 'department' => 'Cardiology', 'consultation_fee' => 2000, '__row' => 2],
            ['name' => 'Dr. Imran', 'department' => 'Neurology', 'consultation_fee' => 2500, '__row' => 3],
        ]);

        $this->assertSame(Onboarding::ACTION_CREATE, $analysis['rows'][0]['action']);
        $this->assertSame(Onboarding::ACTION_ERROR, $analysis['rows'][1]['action']);
        $this->assertStringContainsString('Neurology', implode(' ', $analysis['rows'][1]['errors']));
    }

    public function test_doctors_import_carries_the_fee_schedule_and_the_department(): void
    {
        $department = HealthDepartment::create([
            'company_id' => $this->company->id, 'name' => 'Cardiology', 'code' => 'CARD', 'type' => 'opd', 'is_active' => true,
        ]);

        $this->importNow('doctors', [[
            'name' => 'Dr. Ayesha Khan', 'department' => 'CARD', 'specialty' => 'Cardiology',
            'consultation_fee' => 2500, 'follow_up_fee' => 1200, 'follow_up_days' => 14, 'slot_minutes' => 20,
        ]]);

        $doctor = HealthDoctor::withoutGlobalScopes()->where('company_id', $this->company->id)->first();
        $this->assertNotNull($doctor);
        $this->assertSame($department->id, (int) $doctor->health_department_id, 'A department CODE must resolve as readily as its name.');
        $this->assertSame(2500.0, (float) $doctor->consultation_fee);
        $this->assertSame(14, (int) $doctor->follow_up_days);
    }

    /* ─────────────────────────── Staff and logins ─────────────────────────── */

    public function test_staff_import_creates_a_login_a_profile_and_one_visible_password(): void
    {
        HealthDepartment::create([
            'company_id' => $this->company->id, 'name' => 'Reception', 'code' => 'REC', 'type' => 'admin', 'is_active' => true,
        ]);

        $result = $this->importNow('staff', [[
            'name' => 'Bilal Ahmed', 'email' => 'bilal.onb@example.test', 'health_role' => 'health_receptionist',
            'department' => 'Reception', 'employee_code' => 'EMP-001', 'designation' => 'Front Desk',
            'employment_type' => 'permanent', 'joined_on' => '2026-01-15', 'basic_salary' => 45000,
        ]]);

        $user = User::withoutGlobalScopes()->where('email', 'bilal.onb@example.test')->first();
        $this->assertNotNull($user, 'A staff row must create a real login.');
        $this->assertSame('health_receptionist', $user->health_role);
        $this->assertTrue((bool) $user->is_active);

        // Verified against the DATABASE, not against the HTTP response: a write
        // to a column that is not fillable is dropped in silence.
        $this->assertDatabaseHas('health_staff_profiles', [
            'company_id' => $this->company->id,
            'user_id' => $user->id,
            'employee_code' => 'EMP-001',
        ]);

        $this->assertCount(1, $result['credentials'], 'A generated password must be handed back exactly once.');
        $this->assertSame('bilal.onb@example.test', $result['credentials'][0]['email']);
        $this->assertGreaterThanOrEqual(8, strlen($result['credentials'][0]['password']));

        // The generated password must actually be the one that signs in.
        $this->assertTrue(Hash::check($result['credentials'][0]['password'], $user->fresh()->password));
    }

    public function test_a_staff_row_that_exceeds_the_package_is_named_not_silently_dropped(): void
    {
        // One seat left; two people in the sheet. The hospital must be told
        // which one did not get in, by name, rather than discovering it at the
        // counter on Monday.
        $this->company->user_limit_override = 3;
        $this->company->save();

        $result = $this->importNow('staff', [
            ['name' => 'Nurse One', 'email' => 'n1.onb@example.test', 'health_role' => 'health_nurse'],
            ['name' => 'Nurse Two', 'email' => 'n2.onb@example.test', 'health_role' => 'health_nurse'],
        ]);

        $this->assertSame(1, $result['created']);
        $this->assertSame(1, $result['failed']);
        $this->assertNotEmpty($result['messages']);
        $this->assertNotNull(User::withoutGlobalScopes()->where('email', 'n1.onb@example.test')->first());
        $this->assertNull(User::withoutGlobalScopes()->where('email', 'n2.onb@example.test')->first());
    }

    public function test_a_staff_sheet_cannot_mint_an_owner(): void
    {
        $analysis = Onboarding::analyse('staff', $this->company, [[
            'name' => 'Sneaky', 'email' => 'sneaky.onb@example.test',
            'health_role' => HealthAccessService::ROLE_OWNER, '__row' => 2,
        ]]);

        $this->assertSame(Onboarding::ACTION_ERROR, $analysis['rows'][0]['action']);
    }

    public function test_a_login_belonging_to_another_organisation_is_refused(): void
    {
        $other = Company::create([
            'name' => 'Another Shop', 'ntn' => 'OTHER-1', 'product_type' => 'pos',
            'status' => 'approved', 'company_status' => 'active',
        ]);
        User::create([
            'name' => 'Existing', 'email' => 'taken.onb@example.test', 'password' => Hash::make('Passw0rd!2026'),
            'company_id' => $other->id, 'role' => 'company_admin', 'is_active' => true,
        ]);

        $analysis = Onboarding::analyse('staff', $this->company, [[
            'name' => 'Clash', 'email' => 'taken.onb@example.test', 'health_role' => 'health_nurse', '__row' => 2,
        ]]);

        $this->assertSame(Onboarding::ACTION_ERROR, $analysis['rows'][0]['action']);
        $this->assertStringContainsString('taken.onb@example.test', implode(' ', $analysis['rows'][0]['errors']));
    }

    /* ──────────────────────────── Patients ────────────────────────────────── */

    public function test_a_patient_row_without_a_file_number_is_refused_rather_than_duplicated_later(): void
    {
        // A file number is the only stable identity an old patient record has.
        // Allocating one on the way in looks helpful and is not: the same sheet
        // uploaded again would register every one of those people a second time,
        // and duplicate patient files are the worst thing an import can leave
        // behind — two histories, two balances, one person. The desk can still
        // register a walk-in without a number; a bulk migration cannot.
        $analysis = Onboarding::analyse('patients', $this->company, [
            ['mrn' => 'OLD-77', 'name' => 'Fatima Bibi', 'gender' => 'female', 'age_years' => 34, 'phone' => '0300-1234567', '__row' => 2],
            ['mrn' => '', 'name' => 'Ali Raza', 'gender' => 'male', 'age_years' => 41, 'phone' => '', '__row' => 3],
        ]);

        $this->assertSame(Onboarding::ACTION_CREATE, $analysis['rows'][0]['action']);
        $this->assertSame(Onboarding::ACTION_ERROR, $analysis['rows'][1]['action'], 'A patient row with no file number must be named, not quietly numbered for them.');

        Onboarding::commit('patients', $this->company->fresh(), $analysis['rows'], $this->owner);

        $kept = HealthPatient::withoutGlobalScopes()->where('company_id', $this->company->id)->where('mrn', 'OLD-77')->first();
        $this->assertNotNull($kept, "A hospital's own file number must be kept, not replaced.");
        $this->assertSame('03001234567', $kept->phone_digits, 'Search depends on the digits-only copy being written too.');
        $this->assertSame(1, HealthPatient::withoutGlobalScopes()->where('company_id', $this->company->id)->count());
    }

    public function test_re_uploading_a_patient_sheet_corrects_the_same_files_instead_of_duplicating_them(): void
    {
        $row = ['mrn' => 'OLD-77', 'name' => 'Fatima Bibi', 'gender' => 'female', 'age_years' => 34, 'phone' => '0300-1234567'];

        $this->importNow('patients', [$row]);
        $this->importNow('patients', [array_merge($row, ['phone' => '0301-7654321', 'city' => 'Lahore'])]);

        $this->assertSame(
            1,
            HealthPatient::withoutGlobalScopes()->where('company_id', $this->company->id)->count(),
            'The same file number must stay one patient however many times the sheet is uploaded.'
        );

        $patient = HealthPatient::withoutGlobalScopes()->where('company_id', $this->company->id)->first();
        $this->assertSame('03017654321', $patient->phone_digits, 'A corrected sheet must update the file it matched.');
    }

    /* ───────────────────── Catalogue and opening stock ────────────────────── */

    public function test_opening_stock_needs_the_catalogue_first_and_then_reaches_the_shelf(): void
    {
        $stockRow = [[
            'medicine' => 'MED-0001', 'batch_no' => 'B-2401', 'expiry_date' => '2029-06-30',
            'quantity' => 480, 'cost_price' => 18.5, 'sale_price' => 25, 'branch' => '', '__row' => 2,
        ]];

        $before = Onboarding::analyse('opening_stock', $this->company, $stockRow);
        $this->assertSame(Onboarding::ACTION_ERROR, $before['rows'][0]['action'], 'Stock for a medicine that does not exist must be refused, not invented.');

        $this->importNow('medicines', [[
            'name' => 'Panadol 500mg', 'generic_name' => 'Paracetamol', 'strength' => '500mg',
            'form' => 'tablet', 'code' => 'MED-0001', 'pack_size' => 10, 'purchase_price' => 18.5, 'sale_price' => 25,
        ]]);

        $medicine = HealthMedicine::withoutGlobalScopes()->where('company_id', $this->company->id)->first();
        $this->assertNotNull($medicine);
        $this->assertNotNull($medicine->product_id, 'A medicine must own its shared products row or nothing can stock it.');

        $this->importNow('opening_stock', [[
            'medicine' => 'MED-0001', 'batch_no' => 'B-2401', 'expiry_date' => '2029-06-30',
            'quantity' => 480, 'cost_price' => 18.5, 'sale_price' => 25,
        ]]);

        $batch = HealthMedicineBatch::withoutGlobalScopes()->where('company_id', $this->company->id)->first();
        $this->assertNotNull($batch);
        $this->assertSame(480.0, (float) $batch->quantity);
        $this->assertSame('B-2401', $batch->batch_no);
    }

    public function test_re_uploading_a_corrected_opening_count_restates_the_shelf_instead_of_doubling_it(): void
    {
        // The scenario this exists for: the store keeper counts 480, uploads,
        // then recounts and finds 500. An opening balance is a STATEMENT about
        // the shelf, not a delivery — so the corrected sheet must leave 500 on
        // the shelf, not 980. Getting this wrong makes a correction worse than
        // the original mistake, and nobody would notice until the first audit.
        $this->importNow('medicines', [[
            'name' => 'Panadol 500mg', 'generic_name' => 'Paracetamol', 'strength' => '500mg',
            'form' => 'tablet', 'code' => 'MED-0001', 'pack_size' => 10, 'purchase_price' => 18.5, 'sale_price' => 25,
        ]]);

        $row = [
            'medicine' => 'MED-0001', 'batch_no' => 'B-2401', 'expiry_date' => '2029-06-30',
            'quantity' => 480, 'cost_price' => 18.5, 'sale_price' => 25,
        ];

        $this->importNow('opening_stock', [$row]);

        // Second time round, the preview must already SAY it is an update —
        // an owner who is told "480 will be created" and then finds 960 on the
        // shelf was misled by the screen, not by the file.
        $again = Onboarding::analyse('opening_stock', $this->company->fresh(), [$row + ['__row' => 2]]);
        $this->assertSame(
            Onboarding::ACTION_UPDATE,
            $again['rows'][0]['action'],
            'A batch that is already on the shelf must preview as an update, not as a fresh arrival.'
        );

        $this->importNow('opening_stock', [$row]);

        $this->assertSame(
            1,
            HealthMedicineBatch::withoutGlobalScopes()->where('company_id', $this->company->id)->count(),
            'The same batch must stay one lot however many times the sheet is uploaded.'
        );
        $this->assertSame(
            480.0,
            (float) HealthMedicineBatch::withoutGlobalScopes()->where('company_id', $this->company->id)->value('quantity'),
            'Re-uploading an unchanged opening count must leave the shelf exactly where it was.'
        );

        // Now the correction.
        $this->importNow('opening_stock', [array_merge($row, ['quantity' => 500])]);

        $this->assertSame(
            500.0,
            (float) HealthMedicineBatch::withoutGlobalScopes()->where('company_id', $this->company->id)->value('quantity'),
            'A corrected opening count must restate the shelf to the counted figure, never add to it.'
        );

        // And downwards, which is the direction a hospital corrects most often.
        $this->importNow('opening_stock', [array_merge($row, ['quantity' => 460])]);

        $this->assertSame(
            460.0,
            (float) HealthMedicineBatch::withoutGlobalScopes()->where('company_id', $this->company->id)->value('quantity'),
            'Counting DOWN must work too, or an over-count can never be fixed.'
        );
    }

    /* ───────────────────────── Services / charge heads ────────────────────── */

    public function test_the_service_catalogue_imports_and_a_re_upload_corrects_it(): void
    {
        $this->importNow('services', [[
            'name' => 'Appendectomy', 'code' => 'SRV-0001', 'category' => 'General surgery',
            'base_price' => 45000, 'estimated_minutes' => 60, 'default_anaesthesia' => 'general',
        ]]);

        $procedure = HealthProcedure::withoutGlobalScopes()->where('company_id', $this->company->id)->first();
        $this->assertNotNull($procedure, 'Without a priced catalogue a hospital cannot bill an operation at an agreed rate.');
        $this->assertSame(45000.0, (float) $procedure->base_price);

        // Same code, corrected price: one row, the new rate.
        $this->importNow('services', [[
            'name' => 'Appendectomy', 'code' => 'SRV-0001', 'category' => 'General surgery',
            'base_price' => 52000, 'estimated_minutes' => 60, 'default_anaesthesia' => 'general',
        ]]);

        $this->assertSame(1, HealthProcedure::withoutGlobalScopes()->where('company_id', $this->company->id)->count());
        $this->assertSame(
            52000.0,
            (float) HealthProcedure::withoutGlobalScopes()->where('company_id', $this->company->id)->value('base_price'),
            'Re-importing the same service code must correct the rate, not add a second service at the old one.'
        );
    }

    public function test_a_package_service_without_a_package_price_is_refused(): void
    {
        // "Package" means the quoted figure includes the consumables. Ticking
        // it without a figure would post the operation at zero and the hospital
        // would only find out at the discharge counter.
        $analysis = Onboarding::analyse('services', $this->company, [[
            'name' => 'Normal Delivery Package', 'code' => 'SRV-PKG', 'base_price' => 60000,
            'is_package' => 'yes', 'package_price' => '', '__row' => 2,
        ]]);

        $this->assertSame(Onboarding::ACTION_ERROR, $analysis['rows'][0]['action']);
        $this->assertSame(0, HealthProcedure::withoutGlobalScopes()->count());
    }

    public function test_a_staff_sheet_cannot_carry_a_password_at_all(): void
    {
        // The credential must be generated server-side and shown once. A sheet
        // column for it would leave a readable password in an uploaded file
        // sitting on disk between preview and commit, and in every copy of that
        // file the hospital keeps in its own email.
        $this->assertArrayNotHasKey(
            'temporary_password',
            Onboarding::spec('staff')['columns'],
            'The staff template must not offer a password column.'
        );

        $result = $this->importNow('staff', [[
            'name' => 'Bilal Ahmed', 'email' => 'bilal.pwd@example.test',
            'health_role' => 'health_receptionist', 'temporary_password' => 'letmein12345',
        ]]);

        $this->assertNotEmpty($result['credentials'], 'The owner must still be handed the generated password once.');
        $this->assertNotSame(
            'letmein12345',
            $result['credentials'][0]['password'],
            'A password smuggled into an unknown column must be ignored, not honoured.'
        );

        $user = User::withoutGlobalScopes()->where('email', 'bilal.pwd@example.test')->first();
        $this->assertNotNull($user);
        $this->assertFalse(
            Hash::check('letmein12345', $user->password),
            'A password from a spreadsheet must never become the login password.'
        );
    }

    public function test_expired_opening_stock_is_refused(): void
    {
        $this->importNow('medicines', [[
            'name' => 'Old Syrup', 'strength' => '100ml', 'form' => 'syrup', 'code' => 'MED-OLD', 'sale_price' => 100,
        ]]);

        $analysis = Onboarding::analyse('opening_stock', $this->company, [[
            'medicine' => 'MED-OLD', 'batch_no' => 'B-OLD', 'expiry_date' => '2020-01-01',
            'quantity' => 10, 'cost_price' => 50, 'sale_price' => 100, 'branch' => '', '__row' => 2,
        ]]);

        $this->assertSame(Onboarding::ACTION_ERROR, $analysis['rows'][0]['action']);
        $this->assertSame(0, HealthMedicineBatch::withoutGlobalScopes()->count());
    }

    /* ───────────────────── Suppliers and opening books ────────────────────── */

    public function test_a_supplier_opening_balance_shows_up_as_money_owed(): void
    {
        $this->importNow('suppliers', [[
            'name' => 'City Pharma Distributors', 'phone' => '04235000000', 'city' => 'Lahore',
            'opening_balance' => 125000, 'opening_balance_date' => '2026-07-01',
        ]]);

        $supplier = Supplier::where('company_id', $this->company->id)->first();
        $this->assertNotNull($supplier);
        $this->assertSame(125000.0, (float) $supplier->opening_balance);

        $balances = \App\Services\HealthPharmacyReportService::supplierBalances($this->company->id);
        $row = $balances->firstWhere('supplier_id', $supplier->id);

        $this->assertNotNull($row, 'A supplier owed money on day one must appear on the payables screen even with no purchase orders.');
        $this->assertSame(125000.0, (float) $row->balance);
    }

    public function test_an_opening_account_balance_posts_a_journal_and_restates_rather_than_doubles(): void
    {
        HealthChartOfAccountsService::seed($this->company->id);

        $rows = [['code' => '1500', 'name' => 'Equipment', 'type' => 'asset', 'subtype' => 'fixed_asset', 'opening_balance' => 850000, 'opening_balance_date' => '2026-07-01']];
        $this->importNow('opening_accounts', $rows);

        $account = HealthAccount::withoutGlobalScopes()->where('company_id', $this->company->id)->where('code', '1500')->first();
        $this->assertNotNull($account);
        $this->assertSame(850000.0, (float) $account->opening_balance);

        $posted = HealthJournal::withoutGlobalScopes()
            ->where('company_id', $this->company->id)
            ->where('source_type', HealthJournal::SRC_OPENING)
            ->where('status', HealthJournal::STATUS_POSTED)
            ->whereNull('reverses_journal_id')
            ->count();
        $this->assertSame(1, $posted, 'An opening balance that never posts a journal is not in the books.');

        // The hospital typed the wrong figure and re-uploads a corrected sheet.
        $rows[0]['opening_balance'] = 500000;
        $this->importNow('opening_accounts', $rows);

        $this->assertSame(
            500000.0,
            (float) HealthAccount::withoutGlobalScopes()->where('company_id', $this->company->id)->where('code', '1500')->value('opening_balance')
        );

        $balance = \App\Services\HealthLedgerService::accountBalance($this->company->id, (int) $account->id);
        $this->assertEqualsWithDelta(
            500000.0,
            abs((float) $balance),
            0.01,
            'A corrected opening balance must REPLACE the first one, not pile 850,000 and 500,000 on top of each other.'
        );
    }

    public function test_an_opening_balance_that_cannot_reach_the_books_fails_the_whole_row(): void
    {
        /*
         * July is closed, so the ledger refuses an entry dated inside it.
         *
         * Saving the account anyway and reporting the row as imported is the
         * worst available outcome: the accounts screen would show 850,000 that
         * the books have never heard of, and nobody would find out until a
         * trial balance failed to balance weeks later — by which time nobody
         * remembers which sheet did it. Failing the row loudly, now, is cheap.
         */
        \App\Models\HealthFiscalPeriod::withoutGlobalScopes()->create([
            'company_id' => $this->company->id,
            'name' => '2026-07',
            'starts_on' => '2026-07-01',
            'ends_on' => '2026-07-31',
            'status' => \App\Models\HealthFiscalPeriod::STATUS_CLOSED,
        ]);

        $result = $this->importNow('opening_accounts', [[
            'code' => '1500', 'name' => 'Equipment', 'type' => 'asset', 'subtype' => 'fixed_asset',
            'opening_balance' => 850000, 'opening_balance_date' => '2026-07-01',
        ]]);

        $this->assertSame(1, $result['failed'], 'A balance that never reached the ledger is not an imported row.');
        $this->assertSame(0, $result['created']);

        $this->assertNull(
            HealthAccount::withoutGlobalScopes()->where('company_id', $this->company->id)->where('code', '1500')->first(),
            'The row runs in its own transaction, so a refused posting must take the account back out with it.'
        );

        $this->assertSame(
            0,
            HealthJournal::withoutGlobalScopes()->where('company_id', $this->company->id)->where('source_type', HealthJournal::SRC_OPENING)->count()
        );
    }

    public function test_a_system_account_cannot_be_retyped_by_spreadsheet(): void
    {
        HealthChartOfAccountsService::seed($this->company->id);
        $cash = HealthAccount::withoutGlobalScopes()->where('company_id', $this->company->id)->where('is_system', true)->where('type', 'asset')->first();
        $this->assertNotNull($cash);

        $analysis = Onboarding::analyse('opening_accounts', $this->company, [[
            'code' => $cash->code, 'name' => 'Hijacked', 'type' => 'expense', 'subtype' => null,
            'opening_balance' => 0, 'opening_balance_date' => null, '__row' => 2,
        ]]);

        $this->assertSame(Onboarding::ACTION_ERROR, $analysis['rows'][0]['action']);
        $this->assertSame('asset', HealthAccount::withoutGlobalScopes()->find($cash->id)->type);
    }

    /* ────────────────────────── Tenant sealing ────────────────────────────── */

    public function test_one_hospitals_upload_token_is_invisible_to_another(): void
    {
        $file = $this->sheet('departments', [['name' => 'Private', 'code' => 'PRV', 'type' => 'opd', 'branch' => '', 'description' => '']]);
        $upload = $this->actingAs($this->owner, HealthPanel::GUARD)
            ->post('/health/setup/import/departments/upload', ['file' => $file]);
        $token = $this->tokenFrom($upload->headers->get('Location'));

        $intruderCompany = Company::create([
            'name' => 'Rival Hospital', 'ntn' => 'RIVAL-1', 'product_type' => HealthPanel::PRODUCT_TYPE,
            'status' => 'approved', 'company_status' => 'active', 'health_org_type' => 'clinic',
            'health_modules' => json_encode(['opd']),
        ]);
        $intruder = User::create([
            'name' => 'Rival Owner', 'email' => 'rival.onb@example.test', 'password' => Hash::make('Passw0rd!2026'),
            'company_id' => $intruderCompany->id, 'role' => 'company_admin',
            'health_role' => HealthAccessService::ROLE_OWNER, 'is_active' => true,
        ]);

        $this->actingAs($intruder, HealthPanel::GUARD)
            ->get('/health/setup/import/departments/preview/' . $token)
            ->assertRedirect(route('health.setup.import'));

        $this->assertSame(0, HealthDepartment::withoutGlobalScopes()->where('company_id', $intruderCompany->id)->count());
    }

    public function test_a_committed_file_is_deleted_rather_than_left_lying_around(): void
    {
        [$token] = $this->uploadAndAnalyse('departments', [['name' => 'Outpatient', 'code' => 'OPD', 'type' => 'opd', 'branch' => '', 'description' => '']]);

        $this->actingAs($this->owner, HealthPanel::GUARD)->post('/health/setup/import/departments/commit/' . $token);

        // A second commit finds nothing, which is also what stops a double press
        // from importing the same sheet twice.
        $this->actingAs($this->owner, HealthPanel::GUARD)
            ->post('/health/setup/import/departments/commit/' . $token)
            ->assertRedirect(route('health.setup.import'));

        $this->assertSame(1, HealthDepartment::withoutGlobalScopes()->where('company_id', $this->company->id)->count());
    }

    /* ─────────────────────────── Cell handling ────────────────────────────── */

    public function test_dates_are_read_day_first_and_excel_serials_are_understood(): void
    {
        $analysis = Onboarding::analyse('suppliers', $this->company, [[
            'name' => 'Slash Dates', 'opening_balance' => 1000, 'opening_balance_date' => '03/09/2026', '__row' => 2,
        ]]);

        $this->assertSame(
            '2026-09-03',
            $analysis['rows'][0]['data']['opening_balance_date'],
            'Pakistan writes 03/09/2026 for the third of September. Reading it as March back-dates every opening balance in the sheet.'
        );

        // 46268 is 2026-09-03 in Excel's own serial numbering. A cell formatted
        // as a date arrives as this number, not as text.
        $serial = Onboarding::analyse('suppliers', $this->company, [[
            'name' => 'Serial Dates', 'opening_balance' => 1000, 'opening_balance_date' => 46268, '__row' => 2,
        ]]);
        $this->assertSame('2026-09-03', $serial['rows'][0]['data']['opening_balance_date']);
    }

    public function test_a_missing_required_cell_names_the_column_it_is_missing(): void
    {
        $analysis = Onboarding::analyse('departments', $this->company, [
            ['name' => '', 'code' => 'X', 'type' => 'opd', '__row' => 2],
        ]);

        $this->assertSame(Onboarding::ACTION_ERROR, $analysis['rows'][0]['action']);
        $this->assertStringContainsString('name', implode(' ', $analysis['rows'][0]['errors']));
    }

    public function test_a_file_without_our_headers_is_refused_with_the_columns_it_lacks(): void
    {
        $book = new Spreadsheet();
        $sheet = $book->getActiveSheet();
        $sheet->setTitle('data');
        $sheet->fromArray(['some', 'other', 'columns'], null, 'A1');
        $sheet->fromArray(['a', 'b', 'c'], null, 'A2');
        $path = tempnam(sys_get_temp_dir(), 'bad');
        (new XlsxWriter($book))->save($path);
        $book->disconnectWorksheets();

        $file = new UploadedFile($path, 'bad.xlsx', null, null, true);

        $upload = $this->actingAs($this->owner, HealthPanel::GUARD)
            ->post('/health/setup/import/departments/upload', ['file' => $file]);

        $this->actingAs($this->owner, HealthPanel::GUARD)
            ->get($upload->headers->get('Location'))
            ->assertRedirect(route('health.setup.import'))
            ->assertSessionHas('error');
    }

    /* ───────────────────────────── helpers ────────────────────────────────── */

    /** Build a real .xlsx for a dataset from associative rows. */
    private function sheet(string $dataset, array $rows): UploadedFile
    {
        $headers = Onboarding::headers($dataset);

        $book = new Spreadsheet();
        $sheet = $book->getActiveSheet();
        $sheet->setTitle('data');
        $sheet->fromArray($headers, null, 'A1');

        $line = 2;
        foreach ($rows as $row) {
            $ordered = [];
            foreach ($headers as $header) {
                $ordered[] = $row[$header] ?? '';
            }
            $sheet->fromArray($ordered, null, 'A' . $line);
            $line++;
        }

        $path = tempnam(sys_get_temp_dir(), 'onb') . '.xlsx';
        (new XlsxWriter($book))->save($path);
        $book->disconnectWorksheets();

        return new UploadedFile($path, $dataset . '.xlsx', null, null, true);
    }

    /** Upload through the real screen and return the token plus the verdict. */
    public function test_a_doctor_with_no_department_never_takes_over_a_namesake_in_one(): void
    {
        /*
         * Two doctors called Ahmed Raza is an ordinary Tuesday in a Pakistani
         * hospital. The key is name AND department, and an empty department
         * cell has to mean exactly that — not "any department".
         *
         * If a blank cell simply drops the constraint, the key quietly becomes
         * the name alone, and this sheet would reach into Cardiology and
         * rewrite that consultant's fee, his share and his room instead of
         * registering the new one.
         */
        $cardiology = HealthDepartment::withoutGlobalScopes()->create([
            'company_id' => $this->company->id, 'name' => 'Cardiology', 'code' => 'CARD', 'type' => 'opd', 'is_active' => true,
        ]);

        $this->importNow('doctors', [[
            'name' => 'Ahmed Raza', 'department' => 'Cardiology', 'specialty' => 'Cardiology',
            'qualification' => 'MBBS, FCPS', 'registration_no' => 'PMC-1', 'phone' => '03001112222',
            'email' => '', 'gender' => 'male', 'room' => '4', 'consultation_fee' => 3000,
            'follow_up_fee' => 1500, 'follow_up_days' => 14, 'slot_minutes' => 15, 'branch' => '',
        ]]);

        $inCardiology = HealthDoctor::withoutGlobalScopes()
            ->where('company_id', $this->company->id)
            ->where('health_department_id', $cardiology->id)
            ->firstOrFail();

        $this->importNow('doctors', [[
            'name' => 'Ahmed Raza', 'department' => '', 'specialty' => 'General',
            'qualification' => 'MBBS', 'registration_no' => 'PMC-2', 'phone' => '03013334444',
            'email' => '', 'gender' => 'male', 'room' => '9', 'consultation_fee' => 1000,
            'follow_up_fee' => 500, 'follow_up_days' => 7, 'slot_minutes' => 15, 'branch' => '',
        ]]);

        $this->assertSame(
            2,
            HealthDoctor::withoutGlobalScopes()->where('company_id', $this->company->id)->where('name', 'Ahmed Raza')->count(),
            'A blank department must not match the consultant already sitting in one.'
        );

        $inCardiology->refresh();
        $this->assertSame($cardiology->id, $inCardiology->health_department_id);
        $this->assertSame(3000.0, (float) $inCardiology->consultation_fee, "The cardiologist's fee must be untouched.");
        $this->assertSame('Cardiology', $inCardiology->specialty);
        $this->assertSame('4', (string) $inCardiology->room);
    }

    public function test_a_commit_that_skipped_the_preview_is_refused(): void
    {
        /*
         * The three presses are the whole safety story of this screen: nothing
         * is written until a human has read, row by row, what would be written.
         * If a token straight out of the upload redirect is enough to commit,
         * that story is only a suggestion the URL bar can decline — and the
         * first thing anyone in a hurry declines.
         */
        $file = $this->sheet('departments', [['name' => 'Outpatient', 'code' => 'OPD', 'type' => 'opd', 'branch' => '', 'description' => '']]);
        $upload = $this->actingAs($this->owner, HealthPanel::GUARD)
            ->post('/health/setup/import/departments/upload', ['file' => $file]);
        $token = $this->tokenFrom($upload->headers->get('Location'));

        $this->actingAs($this->owner, HealthPanel::GUARD)
            ->post('/health/setup/import/departments/commit/' . $token)
            ->assertRedirect(route('health.setup.import.preview', ['dataset' => 'departments', 'token' => $token]));

        $this->assertSame(
            0,
            HealthDepartment::withoutGlobalScopes()->where('company_id', $this->company->id)->count(),
            'A commit that never showed anyone the preview must write nothing.'
        );

        // And the same token works normally once the preview has been seen.
        $this->actingAs($this->owner, HealthPanel::GUARD)
            ->get('/health/setup/import/departments/preview/' . $token)->assertOk();
        $this->actingAs($this->owner, HealthPanel::GUARD)
            ->post('/health/setup/import/departments/commit/' . $token)
            ->assertRedirect(route('health.setup.import'));

        $this->assertSame(1, HealthDepartment::withoutGlobalScopes()->where('company_id', $this->company->id)->count());
    }

    public function test_a_file_swapped_after_the_preview_has_to_be_looked_at_again(): void
    {
        // "The commit does exactly what the preview showed" is only true while
        // the file is the same file.
        [$token] = $this->uploadAndAnalyse('departments', [['name' => 'Outpatient', 'code' => 'OPD', 'type' => 'opd', 'branch' => '', 'description' => '']]);

        $directory = Onboarding::DISK_DIRECTORY . '/' . $this->company->id;
        $stored = null;
        foreach (Storage::disk('local')->files($directory) as $file) {
            if (str_starts_with(basename($file), $token . '.')) {
                $stored = $file;
            }
        }
        $this->assertNotNull($stored, 'The upload must be on disk to swap.');

        $swapped = $this->sheet('departments', [['name' => 'Somewhere Else', 'code' => 'ELS', 'type' => 'ipd', 'branch' => '', 'description' => '']]);
        Storage::disk('local')->put($stored, file_get_contents($swapped->getRealPath()));

        $this->actingAs($this->owner, HealthPanel::GUARD)
            ->post('/health/setup/import/departments/commit/' . $token)
            ->assertRedirect(route('health.setup.import.preview', ['dataset' => 'departments', 'token' => $token]));

        $this->assertSame(0, HealthDepartment::withoutGlobalScopes()->where('company_id', $this->company->id)->count());
    }

    private function uploadAndAnalyse(string $dataset, array $rows): array
    {
        $upload = $this->actingAs($this->owner, HealthPanel::GUARD)
            ->post('/health/setup/import/' . $dataset . '/upload', ['file' => $this->sheet($dataset, $rows)]);

        $token = $this->tokenFrom($upload->headers->get('Location'));

        // Walk the real middle step. Reproducing the analysis in-process would
        // test the numbers while quietly skipping the press the flow is built
        // around — and would not notice if commit stopped requiring it.
        $this->actingAs($this->owner, HealthPanel::GUARD)
            ->get('/health/setup/import/' . $dataset . '/preview/' . $token)
            ->assertOk();

        $indexed = [];
        foreach ($rows as $offset => $row) {
            $row['__row'] = $offset + 2;
            $indexed[] = $row;
        }

        return [$token, Onboarding::analyse($dataset, $this->company->fresh(), $indexed)];
    }

    /** Upload, then immediately commit, and hand back the commit result. */
    private function importNow(string $dataset, array $rows): array
    {
        $indexed = [];
        foreach ($rows as $offset => $row) {
            $row['__row'] = $offset + 2;
            $indexed[] = $row;
        }

        $analysis = Onboarding::analyse($dataset, $this->company->fresh(), $indexed);

        return Onboarding::commit($dataset, $this->company->fresh(), $analysis['rows'], $this->owner);
    }

    private function tokenFrom(?string $location): string
    {
        $this->assertNotNull($location, 'The upload must redirect to a preview.');
        $parts = explode('/', rtrim((string) $location, '/'));

        return end($parts);
    }
}

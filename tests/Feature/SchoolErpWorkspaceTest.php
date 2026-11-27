<?php

namespace Tests\Feature;

use App\Models\Company;
use App\Models\PaymentProof;
use App\Models\PricingPlan;
use App\Models\Subscription;
use App\Models\User;
use App\Services\TrialSubscriptionService;
use App\Support\NestErps;
use App\Support\SchoolErpPanel;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class SchoolErpWorkspaceTest extends TestCase
{
    use RefreshDatabase;

    private const PASSWORD = 'SyntheticSchool123!';

    public function test_school_login_is_separate_from_healthcare(): void
    {
        $owner = $this->owner('login');
        $healthOwner = $this->owner('hospital', NestErps::HEALTH);
        $this->get('/school-erp/dashboard')->assertRedirect('/school-erp/login');
        $this->get('/school-erp/login')->assertOk()->assertSee('School ERP')->assertDontSee('Create School account');
        $this->postJson('/school-erp/login', ['email' => $healthOwner->email, 'password' => self::PASSWORD])
            ->assertUnprocessable()->assertJsonValidationErrors('email');
        $this->post('/school-erp/login', ['email' => $owner->email, 'password' => self::PASSWORD])
            ->assertRedirect('/school-erp/dashboard');
        $this->assertAuthenticatedAs($owner, SchoolErpPanel::GUARD);
        $this->assertGuest('health');
        $this->get('/school-erp/dashboard')->assertOk()->assertSee('Fictional School login');
        $this->post('/school-erp/logout')->assertRedirect('/school-erp/login');
        $this->assertGuest(SchoolErpPanel::GUARD);
    }

    public function test_owner_admissions_retry_and_transfer_preserve_original_campus(): void
    {
        $owner = $this->owner('admissions');
        $this->actingAs($owner, SchoolErpPanel::GUARD);
        foreach (['First campus', 'Second campus'] as $campusName) {
            $this->post('/school-erp/campuses', ['campus_name' => $campusName])->assertSessionHasNoErrors()->assertRedirect();
        }
        [$first, $second] = DB::table('branches')->where('company_id', $owner->company_id)->orderBy('id')->pluck('id')->all();
        $this->post('/school-erp/terms', [
            'code' => 'SPR27', 'name' => 'Spring 2027', 'starts_on' => '2027-01-01', 'ends_on' => '2027-05-30',
        ])->assertSessionHasNoErrors()->assertRedirect();
        $this->post('/school-erp/students', [
            'student_reference' => 'FIC-1', 'display_name' => 'Fictional student', 'campus_id' => $first,
        ])->assertSessionHasNoErrors()->assertRedirect();

        $student = DB::table('school_erp_students')->where('company_id', $owner->company_id)->sole();
        $term = DB::table('school_erp_terms')->where('company_id', $owner->company_id)->sole();
        $admission = ['student_id' => $student->id, 'term_id' => $term->id, 'admitted_on' => '2027-01-01'];
        $this->post('/school-erp/admissions', $admission)->assertSessionHasNoErrors()->assertRedirect();
        $this->post('/school-erp/admissions', $admission)->assertSessionHasNoErrors()->assertRedirect();
        $this->assertDatabaseCount('school_erp_enrollments', 1);

        $this->post('/school-erp/students/'.$student->id.'/transfer', ['campus_id' => $second])
            ->assertSessionHasNoErrors()->assertRedirect();
        $this->post('/school-erp/students/'.$student->id.'/transfer', ['campus_id' => $second])
            ->assertSessionHasNoErrors()->assertRedirect();
        $this->assertDatabaseHas('school_erp_students', ['id' => $student->id, 'campus_id' => $second]);
        $this->assertDatabaseHas('school_erp_enrollments', ['student_id' => $student->id, 'campus_id' => $first]);
        $this->assertDatabaseHas('school_erp_campus_transfers', ['student_id' => $student->id, 'from_campus_id' => $first, 'to_campus_id' => $second, 'changed_by' => $owner->id]);
        $this->assertDatabaseCount('school_erp_campus_transfers', 1);
        $this->get('/school-erp/dashboard?campus_id='.$first)->assertOk()->assertSee($student->student_reference)->assertDontSee($student->display_name);
        $this->get('/school-erp/dashboard?campus_id='.$second)->assertOk()->assertSee($student->display_name);
    }

    public function test_campus_staff_see_only_assigned_records_and_viewers_cannot_write(): void
    {
        $owner = $this->owner('campus-staff');
        $other = $this->owner('other');
        $assigned = $this->branch($owner, 'North campus');
        $unassigned = $this->branch($owner, 'West campus');
        $foreign = $this->branch($other, 'Foreign campus');
        foreach ([[$assigned, $owner, 'visible'], [$unassigned, $owner, 'hidden'], [$foreign, $other, 'foreign']] as [$branch, $companyOwner, $label]) {
            DB::table('school_erp_students')->insert([
                'company_id' => $companyOwner->company_id, 'campus_id' => $branch, 'student_reference' => 'FIC-'.$label, 'display_name' => 'Student-'.$label,
            ]);
        }

        $this->actingAs($owner, SchoolErpPanel::GUARD);
        foreach (['full', 'read_only'] as $level) {
            $this->post('/school-erp/staff', [
                'staff_name' => 'Fictional staff', 'staff_email' => 'staff-'.$level.'@example.invalid',
                'staff_campus_id' => $assigned, 'staff_access' => $level, 'password' => self::PASSWORD, 'password_confirmation' => self::PASSWORD,
            ])->assertSessionHasNoErrors()->assertRedirect();
        }
        $staff = User::where('email', 'staff-full@example.invalid')->sole();
        $viewer = User::where('email', 'staff-read_only@example.invalid')->sole();
        $this->actingAs($staff, SchoolErpPanel::GUARD)->get('/school-erp/dashboard')
            ->assertOk()->assertSee('Student-visible')->assertDontSee('Student-hidden')->assertDontSee('Student-foreign')->assertDontSee('/school-erp/staff');
        $this->getJson('/school-erp/dashboard?campus_id='.$foreign)->assertUnprocessable()->assertJsonValidationErrors('campus_id');

        $payload = ['student_reference' => 'FIC-2', 'display_name' => 'New Fictional Student'];
        foreach ([$unassigned, $foreign] as $forbiddenCampus) {
            $this->postJson('/school-erp/students', $payload + ['campus_id' => $forbiddenCampus])
                ->assertUnprocessable()->assertJsonValidationErrors('campus_id');
        }
        $this->post('/school-erp/students', $payload + ['campus_id' => $assigned])->assertSessionHasNoErrors()->assertRedirect();
        $this->actingAs($viewer, SchoolErpPanel::GUARD)->get('/school-erp/dashboard')->assertOk()->assertSee('Student-visible')->assertDontSee('/school-erp/students');
        $this->postJson('/school-erp/students', $payload + ['campus_id' => $assigned])->assertForbidden();
    }

    public function test_campus_foreign_key_rejects_another_school_branch(): void
    {
        $owner = $this->owner('valid');
        $other = $this->owner('foreign');
        $campus = $this->branch($other, 'Foreign Campus');
        $this->expectException(QueryException::class);
        DB::table('school_erp_students')->insert([
            'company_id' => $owner->company_id, 'campus_id' => $campus, 'student_reference' => 'FIC-1', 'display_name' => 'Fictional student',
        ]);
    }

    public function test_unapproved_and_expired_schools_have_read_only_workspaces(): void
    {
        $owner = $this->owner('pending');
        $owner->company->update(['status' => 'pending', 'company_status' => 'pending']);
        $this->actingAs($owner, SchoolErpPanel::GUARD);
        $this->get('/school-erp/dashboard')->assertOk()->assertSee('Changes require an approved School account');
        $this->postJson('/school-erp/campuses', ['campus_name' => 'No changes'])->assertForbidden();

        $owner->company->update(['status' => 'approved', 'company_status' => 'active']);
        Subscription::where('company_id', $owner->company_id)->update(['end_date' => now()->subDay(), 'trial_ends_at' => now()->subDay()]);
        $this->get('/school-erp/dashboard')->assertOk()->assertSee('Changes require an approved School account');
        $this->postJson('/school-erp/campuses', ['campus_name' => 'Still no changes'])->assertForbidden();
    }

    public function test_registration_requires_explicit_opt_in_and_a_published_school_package(): void
    {
        $this->get('/school-erp/register')->assertRedirect('/school-erp/login');
        $this->postJson('/school-erp/register', [])->assertNotFound();
        config(['school-erp.registration_open' => true]);
        $school = $this->plan('Synthetic School package', NestErps::SCHOOL, true);
        $hidden = $this->plan('Synthetic School private package', NestErps::SCHOOL, false);
        $health = $this->plan('Synthetic Hospital package', NestErps::HEALTH, true);
        $this->get('/school-erp/register')->assertOk()->assertSee($school->name)->assertDontSee($hidden->name)->assertDontSee($health->name);
        $signup = [
            'name' => 'Synthetic School Owner', 'email' => 'school-signup@example.invalid',
            'password' => self::PASSWORD, 'password_confirmation' => self::PASSWORD,
            'company_name' => 'Synthetic School Signup', 'company_ntn' => '8789981', 'company_cnic' => '3520212345611',
        ];
        foreach ([$hidden, $health] as $unavailable) {
            $this->postJson('/school-erp/register', $signup + ['package_choice' => $unavailable->id.':annual'])
                ->assertUnprocessable()->assertJsonValidationErrors('package_choice');
        }
        $this->post('/school-erp/register', $signup + ['package_choice' => $school->id.':annual'])
            ->assertSessionHasNoErrors()->assertRedirect('/school-erp/dashboard');
        $company = Company::where('email', $signup['email'])->sole();
        $this->assertSame(NestErps::SCHOOL, $company->erps_vertical);
        $this->assertSame($school->id, $company->requested_plan_id);
        $this->assertDatabaseHas('signup_package_requests', ['company_id' => $company->id, 'pricing_plan_id' => $school->id]);
        $this->assertDatabaseMissing('subscription_invoices', ['company_id' => $company->id]);
    }

    public function test_generic_trial_keeps_school_and_healthcare_entitlements_separate(): void
    {
        $schoolOwner = $this->owner('school-trial', NestErps::SCHOOL, false);
        $healthOwner = $this->owner('health-trial', NestErps::HEALTH, false);
        $trial = TrialSubscriptionService::ensureTrial($schoolOwner->company_id, NestErps::PRODUCT_TYPE);
        $this->assertNull($trial->pricing_plan_id);
        $this->assertNotNull($trial->trial_ends_at);
        $healthPlan = TrialSubscriptionService::ensureTrial($healthOwner->company_id, NestErps::PRODUCT_TYPE)->entitlementPlan();
        $this->assertSame(NestErps::HEALTH, NestErps::verticalOf($healthPlan));
        $this->assertTrue($healthPlan->is_trial);

        $schoolPlan = $this->plan('Synthetic School Trial', NestErps::SCHOOL, false, true);
        $nextSchool = $this->owner('planned-trial', NestErps::SCHOOL, false);
        $this->assertSame($schoolPlan->id, TrialSubscriptionService::ensureTrial($nextSchool->company_id, NestErps::PRODUCT_TYPE)->pricing_plan_id);
    }

    public function test_school_checkout_uses_the_school_package_and_requires_verified_payment(): void
    {
        Mail::fake();
        Storage::fake('local');
        config(['school-erp.registration_open' => false]);
        $owner = $this->owner('billing');
        $school = $this->plan('Published School package', NestErps::SCHOOL, true);
        $private = $this->plan('Private School package', NestErps::SCHOOL, false);
        $health = $this->plan('Published Hospital package', NestErps::HEALTH, true);
        $this->actingAs($owner, SchoolErpPanel::GUARD);

        $this->get('/school-erp/package-checkout')->assertOk()
            ->assertSee($school->name)->assertDontSee($private->name)->assertDontSee($health->name);
        $this->postJson('/school-erp/package-checkout', ['plan_id' => $health->id, 'billing_cycle' => 'annual'])
            ->assertUnprocessable();
        $this->post('/school-erp/package-checkout', ['plan_id' => $school->id, 'billing_cycle' => 'annual'])
            ->assertSessionHasNoErrors()->assertRedirect(route('school-erp.package-checkout', ['plan_id' => $school->id]));
        $this->post('/school-erp/package-payment-proof', [
            'pricing_plan_id' => $health->id, 'billing_cycle' => 'annual',
            'proof' => UploadedFile::fake()->create('hospital.pdf', 1, 'application/pdf'),
        ])->assertSessionHasErrors('pricing_plan_id');
        $checkout = route('school-erp.package-checkout', ['plan_id' => $school->id]);
        $this->from($checkout)->post('/school-erp/package-payment-proof', [
            'pricing_plan_id' => $school->id, 'billing_cycle' => 'annual', 'amount' => 1,
            'proof' => UploadedFile::fake()->create('school.pdf', 1, 'application/pdf'),
        ])->assertSessionHasNoErrors()->assertRedirect($checkout)->assertSessionHas('payment_proof', 'submitted');
        $this->get($checkout)->assertOk()->assertSee('Payment proof submitted!');
        $proof = PaymentProof::where('company_id', $owner->company_id)->sole();
        $this->assertSame($school->id, $proof->pricing_plan_id);
        $this->assertSame('pending', $proof->status);
        $this->assertEquals(3200, $proof->distributor_quote_snapshot['net_quote']);
        $this->assertEquals(1, $proof->amount);
        $this->assertNull($proof->distributor_net_amount);
        $this->assertNull($proof->auto_access_until);
        Storage::disk('local')->assertExists($proof->proof_path);
    }

    public function test_attendance_retries_and_transfers_keep_tenant_campus_and_date_boundaries(): void
    {
        $this->travelTo(now()->setDate(2027, 2, 12));
        $owner = $this->owner('attendance');
        $other = $this->owner('foreign-attendance');
        $first = $this->branch($owner, 'First school campus');
        $second = $this->branch($owner, 'Second school campus');
        $foreign = $this->branch($other, 'Other school campus');
        $this->actingAs($owner, SchoolErpPanel::GUARD);
        $this->post('/school-erp/students', [
            'student_reference' => 'FIC-ATTENDANCE', 'display_name' => 'Fictional attendance student', 'campus_id' => $first,
        ])->assertSessionHasNoErrors();
        $this->post('/school-erp/terms', [
            'code' => 'SPR27', 'name' => 'Spring 2027', 'starts_on' => '2027-01-01', 'ends_on' => '2027-05-31',
        ])->assertSessionHasNoErrors();
        $student = DB::table('school_erp_students')->where('company_id', $owner->company_id)->sole();
        $term = DB::table('school_erp_terms')->where('company_id', $owner->company_id)->sole();
        $selection = ['student_id' => $student->id, 'term_id' => $term->id];
        $this->post('/school-erp/admissions', $selection + ['admitted_on' => '2027-02-01'])->assertSessionHasNoErrors();

        $this->postJson('/school-erp/attendance', $selection + ['attendance_on' => '2027-01-31', 'status' => 'present'])
            ->assertUnprocessable()->assertJsonValidationErrors('attendance_on');
        $this->postJson('/school-erp/attendance', $selection + ['attendance_on' => '2027-02-13', 'status' => 'present'])
            ->assertUnprocessable()->assertJsonValidationErrors('attendance_on');
        $this->get('/school-erp/attendance?attendance_on=2027-02-11')->assertOk()->assertSee($student->display_name);
        $record = $selection + ['attendance_on' => '2027-02-11'];
        $this->post('/school-erp/attendance', $record + ['status' => 'present'])->assertSessionHasNoErrors()->assertRedirect();
        $this->post('/school-erp/attendance', $record + ['status' => 'present'])->assertSessionHasNoErrors()->assertRedirect();
        $this->post('/school-erp/attendance', $record + ['status' => 'late'])->assertSessionHasNoErrors()->assertRedirect();
        $this->assertDatabaseCount('school_erp_attendance', 1);

        $this->post('/school-erp/students/'.$student->id.'/transfer', ['campus_id' => $second])->assertSessionHasNoErrors();
        $this->postJson('/school-erp/attendance', $record + ['status' => 'excused'])
            ->assertUnprocessable()->assertJsonValidationErrors('attendance_on');
        $this->post('/school-erp/attendance', $selection + ['attendance_on' => '2027-02-12', 'status' => 'present'])
            ->assertSessionHasNoErrors()->assertRedirect();

        $this->get('/school-erp/attendance?attendance_on=2027-02-11&campus_id='.$first)
            ->assertOk()->assertSee($student->student_reference)->assertSee('Late')->assertDontSee($student->display_name);
        $this->get('/school-erp/attendance?attendance_on=2027-02-11&campus_id='.$second)
            ->assertOk()->assertDontSee($student->student_reference);
        $this->getJson('/school-erp/attendance?campus_id='.$foreign)->assertUnprocessable()->assertJsonValidationErrors('campus_id');

        $viewer = User::factory()->create([
            'company_id' => $owner->company_id, 'role' => 'viewer', 'is_active' => true,
        ]);
        DB::table('branch_user')->insert(['branch_id' => $first, 'user_id' => $viewer->id, 'access_level' => 'read_only']);
        $this->actingAs($viewer, SchoolErpPanel::GUARD)->get('/school-erp/attendance?attendance_on=2027-02-11')
            ->assertOk()->assertSee($student->student_reference)->assertDontSee('name="status"', false);
        $this->postJson('/school-erp/attendance', $record + ['status' => 'present'])->assertForbidden();
        $this->actingAs($other, SchoolErpPanel::GUARD)->get('/school-erp/attendance?attendance_on=2027-02-11')
            ->assertOk()->assertDontSee($student->student_reference);
        $this->postJson('/school-erp/attendance', $record + ['status' => 'present'])
            ->assertUnprocessable()->assertJsonValidationErrors('student_id');

        $this->assertDatabaseHas('school_erp_attendance', $record + ['campus_id' => $first, 'status' => 'late']);
        $this->assertDatabaseHas('school_erp_attendance', $selection + ['attendance_on' => '2027-02-12', 'campus_id' => $second, 'status' => 'present']);
        $this->assertDatabaseCount('school_erp_attendance', 2);
    }

    public function test_gradebook_classes_enforce_term_campus_and_maximum_score(): void
    {
        $this->travelTo(now()->setDate(2027, 2, 12));
        $owner = $this->owner('gradebook');
        $other = $this->owner('foreign-gradebook');
        $campus = $this->branch($owner, 'Fictional Gradebook Campus');
        $elsewhere = $this->branch($owner, 'Fictional Other Campus');
        $foreign = $this->branch($other, 'Fictional Foreign Campus');
        $this->actingAs($owner, SchoolErpPanel::GUARD);
        $this->post('/school-erp/terms', [
            'code' => 'SPR27', 'name' => 'Spring 2027', 'starts_on' => '2027-01-01', 'ends_on' => '2027-05-31',
        ])->assertSessionHasNoErrors();
        $term = DB::table('school_erp_terms')->where('company_id', $owner->company_id)->sole();
        $classInput = ['name' => 'Synthetic Science', 'code' => 'SCI-1', 'term_id' => $term->id];
        $this->postJson('/school-erp/gradebook/classes', $classInput + ['campus_id' => $foreign])
            ->assertUnprocessable()->assertJsonValidationErrors('campus_id');
        $this->post('/school-erp/gradebook/classes', $classInput + ['campus_id' => $campus])
            ->assertSessionHasNoErrors()->assertRedirect();
        $class = DB::table('school_erp_classes')->where('company_id', $owner->company_id)->sole();
        $this->get('/school-erp/gradebook')->assertOk()->assertSee($classInput['name']);
        $classPath = '/school-erp/gradebook/classes/'.$class->id;

        $this->post('/school-erp/students', [
            'student_reference' => 'FIC-GRADE-1', 'display_name' => 'Fictional Gradebook Student', 'campus_id' => $campus,
        ])->assertSessionHasNoErrors();
        $student = DB::table('school_erp_students')->where('company_id', $owner->company_id)->sole();
        $this->postJson($classPath.'/students', ['student_id' => $student->id])->assertUnprocessable()->assertJsonValidationErrors('student_id');
        $this->post('/school-erp/admissions', [
            'student_id' => $student->id, 'term_id' => $term->id, 'admitted_on' => '2027-02-01',
        ])->assertSessionHasNoErrors();
        $this->post($classPath.'/students', ['student_id' => $student->id])->assertSessionHasNoErrors()->assertRedirect();
        $this->post($classPath.'/students', ['student_id' => $student->id])->assertSessionHasNoErrors()->assertRedirect();
        $this->assertDatabaseCount('school_erp_class_students', 1);

        $assessmentInput = ['code' => 'QUIZ-1', 'title' => 'Synthetic Quiz', 'max_points' => '7.50'];
        $this->postJson($classPath.'/assessments', $assessmentInput + ['assessed_on' => '2026-12-31'])
            ->assertUnprocessable()->assertJsonValidationErrors('assessed_on');
        $this->post($classPath.'/assessments', $assessmentInput + ['assessed_on' => '2027-02-10'])->assertSessionHasNoErrors()->assertRedirect();
        $assessment = DB::table('school_erp_assessments')->where('class_id', $class->id)->sole();
        $gradeInput = ['assessment_id' => $assessment->id, 'student_id' => $student->id];
        $this->postJson($classPath.'/grades', $gradeInput + ['score' => '7.51'])->assertUnprocessable()->assertJsonValidationErrors('score');
        $this->postJson($classPath.'/grades', $gradeInput + ['score' => '6.005'])->assertUnprocessable()->assertJsonValidationErrors('score');
        $this->post($classPath.'/grades', $gradeInput + ['score' => '6.05'])->assertSessionHasNoErrors()->assertRedirect();
        $this->post($classPath.'/grades', $gradeInput + ['score' => '6.05'])->assertSessionHasNoErrors()->assertRedirect();
        $this->assertDatabaseHas('school_erp_grades', $gradeInput + ['score_hundredths' => 605]);
        $this->assertDatabaseCount('school_erp_grades', 1);

        $gradePage = '/school-erp/gradebook?class_id='.$class->id.'&assessment_id='.$assessment->id;
        $this->get($gradePage)->assertOk()->assertSee($assessmentInput['title'])->assertSee('6.05')->assertSee($student->display_name);
        $viewer = User::factory()->create(['company_id' => $owner->company_id, 'role' => 'viewer', 'is_active' => true]);
        DB::table('branch_user')->insert(['branch_id' => $campus, 'user_id' => $viewer->id, 'access_level' => 'read_only']);
        $this->actingAs($viewer, SchoolErpPanel::GUARD)->get($gradePage)->assertOk()->assertSee('6.05')->assertDontSee($classPath.'/grades');
        $this->postJson($classPath.'/grades', $gradeInput + ['score' => '6.25'])->assertForbidden();
        $employee = User::factory()->create(['company_id' => $owner->company_id, 'role' => 'employee', 'is_active' => true]);
        DB::table('branch_user')->insert(['branch_id' => $elsewhere, 'user_id' => $employee->id, 'access_level' => 'full']);
        $this->actingAs($employee, SchoolErpPanel::GUARD)->getJson($gradePage)->assertNotFound();
        $this->postJson($classPath.'/grades', $gradeInput + ['score' => '6.25'])->assertNotFound();

        $this->actingAs($other, SchoolErpPanel::GUARD)->get('/school-erp/gradebook')->assertOk()->assertDontSee($classInput['name']);
        $this->getJson($gradePage)->assertNotFound();
        $this->actingAs($owner, SchoolErpPanel::GUARD)->post('/school-erp/students/'.$student->id.'/transfer', ['campus_id' => $elsewhere])->assertSessionHasNoErrors();
        $this->get($gradePage)->assertOk()->assertSee($student->student_reference)->assertSee('6.05')->assertDontSee($student->display_name);
        $this->postJson($classPath.'/grades', $gradeInput + ['score' => '6.25'])->assertUnprocessable()->assertJsonValidationErrors('student_id');
    }

    private function owner(string $name, string $vertical = NestErps::SCHOOL, bool $subscribe = true): User
    {
        $company = Company::create([
            'name' => 'Fictional School '.$name, 'ntn' => 'SCHOOL-'.$name, 'status' => 'approved',
            'company_status' => 'active', 'product_type' => NestErps::PRODUCT_TYPE, 'erps_vertical' => $vertical,
        ]);
        if ($subscribe) {
            TrialSubscriptionService::ensureTrial($company->id, NestErps::PRODUCT_TYPE);
        }

        return User::factory()->create([
            'company_id' => $company->id, 'role' => 'company_admin', 'is_active' => true, 'password' => self::PASSWORD, 'language' => 'en',
        ]);
    }

    private function branch(User $owner, string $name): int
    {
        return DB::table('branches')->insertGetId(['company_id' => $owner->company_id, 'name' => $name, 'is_active' => true]);
    }

    private function plan(string $name, string $vertical, bool $published, bool $trial = false): PricingPlan
    {
        return PricingPlan::create([
            'name' => $name, 'product_type' => NestErps::PRODUCT_TYPE, 'erps_vertical' => $vertical,
            'price' => $trial ? 0 : 3200, 'invoice_limit' => -1, 'is_trial' => $trial, 'is_public' => true, 'erp_website_price_visible' => $published,
        ]);
    }
}

<?php

namespace Tests\Feature;

use App\Models\Company;
use App\Models\Subscription;
use App\Models\User;
use App\Services\TrialSubscriptionService;
use App\Services\TwoStepLogin;
use App\Support\NestErps;
use App\Support\SchoolErpPanel;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

final class SchoolErpFamilyAccessTest extends TestCase
{
    use RefreshDatabase;

    public function test_family_account_can_read_only_authorized_records_after_school_sign_in(): void
    {
        $owner = $this->school('First');
        $otherOwner = $this->school('Other');
        $attendanceOn = '2027-01-15';
        $linked = $this->student($owner, 'LINKED', 'Linked Learner', 1735, $attendanceOn);
        $unlinked = $this->student($owner, 'UNLINKED', 'Unlinked Learner', 1980, '2027-01-20');
        $foreign = $this->student($otherOwner, 'OTHER', 'Other School Learner', 1000, '2027-01-17');

        $this->get('/school-erp/family')->assertRedirect('/school-erp/login');
        $family = $this->createAccount($owner, 'family@example.invalid', 'LINKED');
        $this->get('/school-erp/family')->assertForbidden();
        $this->post('/school-erp/logout')->assertRedirect('/school-erp/login');
        $this->post('/school-erp/login', ['email' => $family->email, 'password' => 'FictionalSchool2027!'])
            ->assertRedirect('/school-erp/family');
        $this->assertAuthenticatedAs($family, SchoolErpPanel::GUARD);
        $this->assertGuest('web');
        $this->post('/school-erp/language', ['language' => 'en'])->assertRedirect();
        $this->get('/school-erp/family')->assertOk()->assertSee('Linked Learner')
            ->assertDontSee('Unlinked Learner')->assertDontSee('Other School Learner')
            ->assertDontSee('school-erp/package-checkout')->assertDontSee('school-erp/family-access');
        $detail = $this->get('/school-erp/family/'.$linked)->assertOk()->assertSee('LINKED Assessment')
            ->assertSee($attendanceOn)->assertSee('17.35 / 20.00')->assertSee('Present')
            ->assertDontSee('UNLINKED Assessment')->assertDontSee('OTHER Assessment');
        $this->assertStringContainsString('no-store', $detail->headers->get('Cache-Control'));
        $this->getJson('/school-erp/family/'.$unlinked)->assertNotFound();
        $this->getJson('/school-erp/family/'.$foreign)->assertNotFound();
        $this->get('/school-erp/dashboard')->assertForbidden();
        $this->get('/school-erp/fees')->assertForbidden();
        $this->get('/school-erp/gradebook')->assertForbidden();
        $this->get('/school-erp/attendance')->assertForbidden();
        $this->get('/school-erp/package-checkout')->assertForbidden();
        $this->postJson('/school-erp/package-payment-proof', [])->assertForbidden();
        $this->postJson('/school-erp/family-access', [])->assertForbidden();
        $this->postJson('/school-erp/attendance', [])->assertForbidden();
    }

    public function test_only_school_owner_may_link_or_revoke_and_revocation_works_after_expiry(): void
    {
        $owner = $this->school('First');
        $other = $this->school('Other');
        $linked = $this->student($owner, 'LINKED', 'Linked Learner', 1735);
        $sibling = $this->student($owner, 'SIBLING', 'Sibling Learner', 1812);
        $this->student($other, 'FOREIGN', 'Foreign Learner', 1600);
        $family = $this->createAccount($owner, 'family@example.invalid', 'LINKED');
        $otherFamily = $this->createAccount($other, 'otherfamily@example.invalid', 'FOREIGN');
        $grant = DB::table('school_erp_family_access')->where('user_id', $family->id)->value('id');
        $staff = User::factory()->create(['company_id' => $owner->company_id, 'role' => 'employee', 'is_active' => true]);

        $this->actingAs($staff, SchoolErpPanel::GUARD)->get('/school-erp/family-access')->assertForbidden();
        $this->postJson('/school-erp/family-access/'.$family->id.'/students', ['student_reference' => 'SIBLING'])->assertForbidden();
        $this->deleteJson('/school-erp/family-access/'.$grant)->assertForbidden();
        $this->actingAs($other, SchoolErpPanel::GUARD)->deleteJson('/school-erp/family-access/'.$grant)->assertNotFound();
        $this->actingAs($owner, SchoolErpPanel::GUARD)
            ->postJson('/school-erp/family-access/'.$otherFamily->id.'/students', ['student_reference' => 'LINKED'])->assertNotFound();
        $this->postJson('/school-erp/family-access/'.$family->id.'/students', ['student_reference' => 'FOREIGN'])
            ->assertUnprocessable()->assertJsonValidationErrors('student_reference');
        $this->post('/school-erp/family-access/'.$family->id.'/students', ['student_reference' => 'SIBLING'])
            ->assertRedirect('/school-erp/family-access');
        $this->post('/school-erp/family-access/'.$family->id.'/students', ['student_reference' => 'SIBLING'])
            ->assertRedirect('/school-erp/family-access');
        $this->assertSame(2, DB::table('school_erp_family_access')->where('user_id', $family->id)->count());
        $this->get('/school-erp/family-access')->assertOk()->assertSee('Linked Learner')->assertSee('Sibling Learner')
            ->assertDontSee($otherFamily->email);

        // A subscription lapse never blocks a school from removing access.
        Subscription::where('company_id', $owner->company_id)->update(['active' => false]);
        $this->delete('/school-erp/family-access/'.$grant)->assertRedirect('/school-erp/family-access');
        $this->assertNotNull(DB::table('school_erp_family_access')->where('id', $grant)->value('revoked_at'));
        $this->assertDatabaseHas('school_erp_family_access', ['id' => $grant, 'revoked_by' => $owner->id]);
        $this->actingAs($family, SchoolErpPanel::GUARD)->getJson('/school-erp/family/'.$linked)->assertNotFound();
        $this->get('/school-erp/family/'.$sibling)->assertOk()->assertSee('18.12 / 20.00');
    }

    public function test_school_two_step_for_family_returns_to_the_family_portal(): void
    {
        $owner = $this->school('Factor');
        $this->student($owner, 'SYNTHETIC', 'Test Learner', 1500);
        $family = $this->createAccount($owner, 'factor-family@example.invalid', 'SYNTHETIC');
        $key = TwoStepLogin::secret();
        DB::table('two_step_credentials')->insert(TwoStepLogin::identity($family) + [
            'secret' => Crypt::encryptString($key), 'recovery_hashes' => '[]', 'created_at' => now(), 'updated_at' => now(),
        ]);
        $this->actingAs($family, SchoolErpPanel::GUARD)->get('/school-erp/family')
            ->assertRedirect(route('two-step.challenge', ['panel' => SchoolErpPanel::GUARD]));
        $this->post('/security/two-step/schoolerp/verify', ['code' => TwoStepLogin::code($key, intdiv(now()->timestamp, 30))])
            ->assertRedirect('/school-erp/family');
        $this->get('/school-erp/family')->assertOk()->assertSee('Test Learner');
    }

    public function test_family_announcements_follow_current_campuses_active_access_and_expiry(): void
    {
        $this->travelTo(now()->setDate(2027, 2, 10)->setHour(10));
        $owner = $this->school('Announcements');
        $other = $this->school('Other');
        $firstStudent = $this->student($owner, 'FIRST', 'First Learner', 1720);
        $secondStudent = $this->student($owner, 'SECOND', 'Second Learner', 1810);
        $this->student($other, 'FOREIGN', 'Foreign Learner', 1250);
        $family = $this->createAccount($owner, 'notice-family@example.invalid', 'FIRST');
        $firstCampus = (int) DB::table('school_erp_students')->where('id', $firstStudent)->value('campus_id');
        $secondCampus = (int) DB::table('school_erp_students')->where('id', $secondStudent)->value('campus_id');
        $offer = ['publication_key' => (string) Str::uuid(), 'title' => 'First campus event', 'body' => '<b>Plain text</b>',
            'campus_id' => $firstCampus, 'expires_on' => now()->toDateString()];
        $this->post('/school-erp/announcements', $offer)->assertRedirect('/school-erp/announcements');
        $this->post('/school-erp/announcements', $offer)->assertRedirect('/school-erp/announcements');
        $this->assertDatabaseCount('school_erp_announcements', 1);
        $this->postJson('/school-erp/announcements', array_replace($offer, ['title' => 'Changed on retry']))
            ->assertUnprocessable()->assertJsonValidationErrors('title');
        $this->publishNotice('Second campus event', $secondCampus);
        $this->publishNotice('All school event');
        $this->actingAs($other, SchoolErpPanel::GUARD);
        $this->publishNotice('Unrelated School event');

        $this->actingAs($family, SchoolErpPanel::GUARD)->get('/school-erp/family')
            ->assertOk()->assertSee('First campus event')->assertSee('All school event')
            ->assertSee('<b>Plain text</b>')->assertDontSee('<b>Plain text</b>', false)
            ->assertDontSee('Second campus event')->assertDontSee('Unrelated School event');
        $this->get('/school-erp/announcements')->assertForbidden();

        $this->travel(1)->day();
        $this->get('/school-erp/family')->assertOk()->assertDontSee('First campus event')->assertSee('All school event');
        $this->actingAs($owner, SchoolErpPanel::GUARD)->post('/school-erp/students/'.$firstStudent.'/transfer', ['campus_id' => $secondCampus])
            ->assertSessionHasNoErrors()->assertRedirect();
        $this->actingAs($family, SchoolErpPanel::GUARD)->get('/school-erp/family')
            ->assertOk()->assertSee('Second campus event')->assertSee('All school event');

        $grant = DB::table('school_erp_family_access')->where('user_id', $family->id)->value('id');
        $this->actingAs($owner, SchoolErpPanel::GUARD)->delete('/school-erp/family-access/'.$grant)
            ->assertRedirect('/school-erp/family-access');
        $this->actingAs($family, SchoolErpPanel::GUARD)->get('/school-erp/family')
            ->assertOk()->assertDontSee('Second campus event')->assertDontSee('All school event');
    }

    public function test_school_owner_can_withdraw_a_notice_but_staff_and_other_schools_cannot(): void
    {
        $owner = $this->school('First');
        $other = $this->school('Other');
        $this->student($owner, 'LINKED', 'Linked Learner', 1800);
        $foreign = $this->student($other, 'FOREIGN', 'Foreign Learner', 1250);
        $foreignCampus = DB::table('school_erp_students')->where('id', $foreign)->value('campus_id');
        $family = $this->createAccount($owner, 'notice-family@example.invalid', 'LINKED');
        $otherNotice = ['publication_key' => (string) Str::uuid(), 'title' => 'Invalid target',
            'body' => 'Synthetic announcement', 'campus_id' => $foreignCampus];
        $this->postJson('/school-erp/announcements', $otherNotice)->assertUnprocessable()->assertJsonValidationErrors('campus_id');
        $this->publishNotice('To be withdrawn');
        $id = DB::table('school_erp_announcements')->sole()->id;
        $staff = User::factory()->create(['company_id' => $owner->company_id, 'role' => 'employee', 'is_active' => true]);
        $this->actingAs($staff, SchoolErpPanel::GUARD)->get('/school-erp/announcements')->assertForbidden();
        $this->postJson('/school-erp/announcements', $otherNotice)->assertForbidden();
        $this->deleteJson('/school-erp/announcements/'.$id)->assertForbidden();
        $this->actingAs($other, SchoolErpPanel::GUARD)->deleteJson('/school-erp/announcements/'.$id)->assertNotFound();
        $this->get('/school-erp/announcements')->assertOk()->assertDontSee('To be withdrawn');

        $this->actingAs($owner, SchoolErpPanel::GUARD)->delete('/school-erp/announcements/'.$id)->assertRedirect('/school-erp/announcements');
        $this->assertDatabaseHas('school_erp_announcements', ['id' => $id, 'withdrawn_by' => $owner->id]);
        $this->actingAs($family, SchoolErpPanel::GUARD)->get('/school-erp/family')->assertOk()->assertDontSee('To be withdrawn');
    }

    private function publishNotice(string $title, ?int $campus = null): void
    {
        $this->post('/school-erp/announcements', [
            'publication_key' => (string) Str::uuid(), 'title' => $title, 'body' => 'Synthetic announcement', 'campus_id' => $campus,
        ])->assertSessionHasNoErrors()->assertRedirect('/school-erp/announcements');
    }

    private function createAccount(User $owner, string $email, string $reference): User
    {
        $this->actingAs($owner, SchoolErpPanel::GUARD)->post('/school-erp/family-access', [
            'name' => 'Fictional Family', 'email' => $email, 'student_reference' => $reference,
            'password' => 'FictionalSchool2027!', 'password_confirmation' => 'FictionalSchool2027!',
        ])->assertSessionHasNoErrors()->assertRedirect('/school-erp/family-access');

        return User::where('company_id', $owner->company_id)->where('email', $email)->sole();
    }

    private function school(string $name): User
    {
        $company = Company::create([
            'name' => $name.' Fictional School', 'ntn' => 'FAMILY-'.$name,
            'status' => 'approved', 'company_status' => 'active',
            'product_type' => NestErps::PRODUCT_TYPE, NestErps::VERTICAL_COLUMN => NestErps::SCHOOL,
        ]);
        TrialSubscriptionService::ensureTrial($company->id, NestErps::PRODUCT_TYPE);

        return User::factory()->create(['company_id' => $company->id, 'role' => 'company_admin', 'is_active' => true]);
    }

    private function student(
        User $owner,
        string $reference,
        string $name,
        int $score,
        string $attendanceOn = '2027-01-15'
    ): int {
        $company = ['company_id' => $owner->company_id];
        $campus = DB::table('branches')->insertGetId($company + ['name' => $reference.' Campus', 'is_active' => true]);
        $student = DB::table('school_erp_students')->insertGetId($company + [
            'campus_id' => $campus, 'student_reference' => $reference, 'display_name' => $name,
        ]);
        $term = DB::table('school_erp_terms')->insertGetId($company + [
            'code' => $reference, 'name' => 'Fictional Term', 'starts_on' => '2027-01-01', 'ends_on' => '2027-06-01',
        ]);
        DB::table('school_erp_enrollments')->insert($company + [
            'student_id' => $student, 'term_id' => $term, 'campus_id' => $campus, 'admitted_on' => '2027-01-01',
        ]);
        DB::table('school_erp_attendance')->insert($company + [
            'student_id' => $student, 'term_id' => $term, 'campus_id' => $campus,
            'attendance_on' => $attendanceOn, 'status' => 'present',
        ]);
        $class = DB::table('school_erp_classes')->insertGetId($company + [
            'campus_id' => $campus, 'term_id' => $term, 'code' => $reference, 'name' => 'Fictional Class',
        ]);
        DB::table('school_erp_class_students')->insert($company + [
            'class_id' => $class, 'term_id' => $term, 'student_id' => $student, 'created_at' => now(),
        ]);
        $assessment = DB::table('school_erp_assessments')->insertGetId($company + [
            'class_id' => $class, 'code' => $reference, 'title' => $reference.' Assessment',
            'max_hundredths' => 2000, 'assessed_on' => '2027-02-03',
        ]);
        DB::table('school_erp_grades')->insert($company + [
            'class_id' => $class, 'assessment_id' => $assessment, 'student_id' => $student, 'score_hundredths' => $score,
        ]);

        return $student;
    }
}

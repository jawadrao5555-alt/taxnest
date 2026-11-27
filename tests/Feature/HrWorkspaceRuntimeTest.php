<?php

namespace Tests\Feature;

use App\Models\AdminUser;
use App\Models\HrEmployee;
use App\Models\HrFieldDutyRequest;
use App\Models\HrLeaveRequest;
use App\Models\HrOrganization;
use App\Models\HrPolicyVersion;
use App\Models\HrShiftAssignment;
use App\Models\User;
use App\Services\TwoStepLogin;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

final class HrWorkspaceRuntimeTest extends TestCase
{
    use RefreshDatabase;

    public function test_super_administrator_can_provision_an_explicit_owner_and_idempotently_grant_membership(): void
    {
        $this->withoutVite();
        $owner = User::factory()->create();
        $employee = User::factory()->create();
        $viewer = AdminUser::create(['role' => 'viewer', 'name' => 'Fixture viewer', 'email' => 'viewer@example.test', 'password' => 'SyntheticOnly123!']);
        $admin = AdminUser::create(['role' => 'super_admin', 'name' => 'Fixture approver', 'email' => 'approver@example.test', 'password' => 'SyntheticOnly123!']);
        $data = ['name' => 'Fictional worksite', 'timezone' => 'Asia/Karachi', 'owner_id' => $owner->id];

        $this->actingAs($viewer, 'admin')->post('/admin/hr-workspaces', $data)->assertForbidden();
        $this->actingAs($admin, 'admin')->post('/admin/hr-workspaces', $data + ['user_id' => $employee->id])
            ->assertRedirect('/admin/hr-workspaces');
        $workspace = HrOrganization::query()->sole();
        $this->assertDatabaseHas('hr_organization_memberships', ['organization_id' => $workspace->id, 'user_id' => $owner->id, 'role' => 'owner']);

        $grant = ['role' => 'employee', 'user_id' => $employee->id];
        $url = '/admin/hr-workspaces/'.$workspace->id.'/members';
        $this->post($url, $grant)->assertRedirect('/admin/hr-workspaces');
        $this->post($url, $grant)->assertRedirect('/admin/hr-workspaces');
        $this->postJson($url, array_replace($grant, ['role' => 'manager']))->assertUnprocessable()->assertJsonValidationErrors('user_id');
        $this->assertDatabaseCount('hr_organization_memberships', 2);
        $this->get('/admin/hr-workspaces')->assertOk()->assertSee('Fictional worksite')->assertSee('/hr/login?workspace='.$workspace->id, false);
        $membership = $workspace->memberships()->where('user_id', $employee->id)->sole();
        $this->get($url)->assertOk()->assertSee($employee->email)->assertSee('Revoke access');
        $this->patch($url.'/'.$membership->id, ['active' => '0'])->assertRedirect($url);
        $this->patch($url.'/'.$membership->id, ['active' => '0'])->assertRedirect($url);
        $this->assertFalse($membership->refresh()->active);
        $this->assertDatabaseCount('admin_audit_logs', 3);
        $this->assertDatabaseHas('admin_audit_logs', ['action' => 'hr_membership_revoked', 'target_id' => $membership->id]);
        $this->patch($url.'/'.$membership->id, ['active' => '1'])->assertRedirect($url);
        $this->assertTrue($membership->refresh()->active);
    }

    public function test_hr_login_requires_an_active_explicit_membership_and_shared_factor(): void
    {
        $workspace = $this->workspace('Fictional factory');
        $other = $this->workspace('Fictional tenant');
        $employee = $this->member($workspace, 'employee');
        $input = ['workspace' => $workspace->id, 'email' => $employee->email, 'password' => 'password'];
        $this->actingAs($employee, 'web')->get('/hr/workspaces/'.$workspace->id)->assertRedirect('/hr/login');
        $this->get('/hr/login?workspace='.$workspace->id)->assertOk()->assertSee('Sign in to Nest HR');
        $this->postJson('/hr/login', array_replace($input, ['workspace' => $other->id]))->assertUnprocessable()->assertJsonValidationErrors('email');
        $this->assertGuest('hr');

        $secret = TwoStepLogin::secret();
        DB::table('two_step_credentials')->insert(TwoStepLogin::identity($employee) + [
            'secret' => Crypt::encryptString($secret), 'recovery_hashes' => json_encode([]), 'failed_attempts' => 0,
            'created_at' => now(), 'updated_at' => now(),
        ]);
        $this->post('/hr/login', $input)->assertRedirect('/security/two-step/hr/challenge');
        $this->assertAuthenticatedAs($employee, 'hr');
        $this->get('/hr/workspaces/'.$workspace->id)->assertRedirect('/security/two-step/hr/challenge');
        $this->post('/security/two-step/hr/verify', ['code' => TwoStepLogin::code($secret, intdiv(now()->timestamp, 30))])
            ->assertRedirect('/hr/dashboard');
        $this->get('/hr/workspaces/'.$workspace->id)->assertOk()->assertSee('My attendance')->assertDontSee('Employee directory');
        $this->get('/hr/workspaces/'.$other->id)->assertForbidden();

        $workspace->memberships()->where('user_id', $employee->id)->update(['active' => false]);
        $this->get('/hr/workspaces/'.$workspace->id)->assertForbidden();
    }

    public function test_only_hr_roles_can_manage_directory_and_foreign_user_ids_cannot_be_linked(): void
    {
        $workspace = $this->workspace('Fictional site');
        $other = $this->workspace('Fictional outside site');
        $hr = $this->member($workspace, 'hr_admin');
        $manager = $this->member($workspace, 'manager');
        $employee = $this->member($workspace, 'employee');
        $outside = $this->member($other, 'employee');
        $url = '/hr/workspaces/'.$workspace->id;
        $data = ['employee_number' => 'E001', 'full_name' => 'Synthetic Employee', 'user_id' => $employee->id];

        $this->actingAs($manager, 'hr')->postJson($url.'/employees', $data)->assertForbidden();
        $this->actingAs($hr, 'hr')->postJson($url.'/employees', array_replace($data, ['user_id' => $outside->id]))
            ->assertUnprocessable()->assertJsonValidationErrors('user_id');
        $this->assertDatabaseCount('hr_employees', 0);
        $this->post($url.'/employees', $data)->assertRedirect($url);
        $this->postJson($url.'/employees', array_replace($data, ['employee_number' => 'E002']))->assertUnprocessable()->assertJsonValidationErrors('user_id');
        $this->postJson($url.'/employees', $data)->assertUnprocessable()->assertJsonValidationErrors('employee_number');
        $this->get($url)->assertOk()->assertSee('Synthetic Employee')->assertSee('Assign a shift');
        $employeeId = HrEmployee::query()->sole()->id;
        $this->patch($url.'/employees/'.$employeeId, ['designation' => 'Synthetic Operator'])->assertRedirect($url);
        $this->get($url)->assertOk()->assertSee('Synthetic Operator');
    }

    public function test_employee_attendance_is_scoped_uses_policy_snapshots_and_retries_are_safe(): void
    {
        $this->travelTo(CarbonImmutable::parse('2027-01-04 14:00:00', 'UTC'));
        $workspace = $this->workspace('Fictional site');
        $owner = $this->member($workspace, 'owner');
        $employee = $this->member($workspace, 'employee');
        $other = $this->member($workspace, 'employee');
        $profile = $this->profile($workspace, $employee, 'Synthetic Employee');
        $otherProfile = $this->profile($workspace, $other, 'Restricted Record');
        $workspace->policyVersions()->create(['version' => 1, 'effective_from' => '2027-01-04',
            'rules' => ['grace_minutes' => 5, 'absent_after_minutes' => 30]]);
        $baseUrl = '/hr/workspaces/'.$workspace->id;
        $shiftInput = ['employee_id' => $profile->id, 'work_date' => '2027-01-04', 'starts_at' => '22:00', 'ends_at' => '06:00'];
        $this->actingAs($owner, 'hr')->post($baseUrl.'/shifts', $shiftInput)->assertRedirect();
        $this->post($baseUrl.'/shifts', $shiftInput)->assertRedirect();
        $shift = HrShiftAssignment::query()->sole();
        $this->assertSame('2027-01-05 01:00:00', $shift->ends_at_utc);
        $this->post($baseUrl.'/shifts', array_replace($shiftInput, ['employee_id' => $otherProfile->id]))->assertRedirect();
        $this->postJson($baseUrl.'/shifts', array_replace($shiftInput, ['work_date' => '2027-01-05', 'starts_at' => '05:00', 'ends_at' => '08:00']))
            ->assertUnprocessable()->assertJsonValidationErrors('starts_at');
        $workspace->policyVersions()->create(['version' => 2, 'effective_from' => '2027-01-04',
            'rules' => ['grace_minutes' => 20, 'absent_after_minutes' => 30]]);

        $this->travelTo(CarbonImmutable::parse('2027-01-04 17:08:00', 'UTC'));
        $punchUrl = $baseUrl.'/shifts/'.$shift->id.'/punch';
        $this->actingAs($other, 'hr')->postJson($punchUrl, ['action' => 'check_in', 'request_key' => (string) Str::uuid()])->assertNotFound();
        $this->actingAs($employee, 'hr')->postJson($punchUrl, ['action' => 'check_out', 'request_key' => (string) Str::uuid()])
            ->assertUnprocessable()->assertJsonValidationErrors('action');
        $checkin = ['action' => 'check_in', 'request_key' => (string) Str::uuid(), 'occurred_at_utc' => '2027-01-04 17:00:00'];
        $this->post($punchUrl, $checkin)->assertRedirect();
        $this->post($punchUrl, array_replace($checkin, ['request_key' => strtoupper($checkin['request_key'])]))->assertRedirect();
        $this->postJson($punchUrl, array_replace($checkin, ['action' => 'check_out']))->assertUnprocessable()->assertJsonValidationErrors('request_key');
        $this->assertDatabaseCount('hr_attendance_punches', 1);
        $this->assertDatabaseHas('hr_attendance_punches', ['shift_id' => $shift->id, 'occurred_at_utc' => '2027-01-04 17:08:00']);
        $this->get($baseUrl)->assertOk()->assertSee('Late by 8 min')->assertDontSee('Restricted Record');

        $this->travelTo(CarbonImmutable::parse('2027-01-05 01:00:00', 'UTC'));
        $this->post($punchUrl, ['action' => 'check_out', 'request_key' => (string) Str::uuid()])->assertRedirect();
        $this->assertDatabaseCount('hr_attendance_punches', 2);
        $this->actingAs($owner, 'hr')->postJson($baseUrl.'/shifts', array_replace($shiftInput, ['ends_at' => '07:00']))
            ->assertUnprocessable()->assertJsonValidationErrors('work_date');
        $this->assertDatabaseCount('hr_shift_assignments', 2);
        $this->assertSame(1, (int) $shift->refresh()->policy_version_id);
    }

    public function test_leave_requests_require_another_reviewer_isolate_tenants_and_preserve_quota_on_failure(): void
    {
        $this->travelTo(CarbonImmutable::parse('2027-01-04 07:00:00', 'UTC'));
        $workspace = $this->workspace('Fictional payroll office');
        $other = $this->workspace('Unrelated office');
        $owner = $this->member($workspace, 'owner');
        $this->profile($workspace, $owner, 'Fictional proprietor');
        $employee = $this->member($workspace, 'employee');
        $this->profile($workspace, $employee, 'Fictional leave requester');
        $manager = $this->member($workspace, 'manager');
        $outsider = $this->member($other, 'manager');
        $base = '/hr/workspaces/'.$workspace->id;
        $policy = ['effective_from' => '2027-01-04', 'grace_minutes' => 5, 'absent_after_minutes' => 30, 'annual_leave_days' => 2];
        $this->actingAs($employee, 'hr')->postJson($base.'/policy', $policy)->assertForbidden();
        $this->actingAs($owner, 'hr')->postJson($base.'/policy', array_replace($policy, ['absent_after_minutes' => 4]))
            ->assertUnprocessable()->assertJsonValidationErrors('absent_after_minutes');
        $this->post($base.'/policy', $policy)->assertRedirect($base.'/policy');
        $this->post($base.'/policy', $policy)->assertRedirect($base.'/policy');
        $version = HrPolicyVersion::query()->sole();
        $this->get($base.'/policy')->assertOk()->assertSee('Policy history')->assertSee('2 days');

        $leave = ['starts_on' => '2027-01-06', 'ends_on' => '2027-01-07', 'category' => 'annual', 'request_key' => (string) Str::uuid(),
            'employee_note' => 'Synthetic leave note', 'emergency' => '1'];
        $this->actingAs($employee, 'hr')->get($base.'/leave')->assertOk()->assertSee('2027 annual leave balance: 2 calendar days.');
        $this->post($base.'/leave', $leave)->assertRedirect($base.'/leave?view=mine');
        $this->post($base.'/leave', $leave)->assertRedirect();
        $record = HrLeaveRequest::query()->sole();
        $this->postJson($base.'/leave', array_replace($leave, ['request_key' => (string) Str::uuid()]))
            ->assertUnprocessable()->assertJsonValidationErrors('starts_on');
        $this->patchJson($base.'/leave/'.$record->id.'/review', ['decision' => 'approved'])->assertForbidden();
        $this->actingAs($outsider, 'hr')->get($base.'/leave')->assertForbidden();
        $this->patchJson('/hr/workspaces/'.$other->id.'/leave/'.$record->id.'/review', ['decision' => 'approved'])->assertNotFound();
        $this->actingAs($manager, 'hr')->get($base.'/leave')->assertOk()->assertSee('Synthetic leave note')->assertSee('Emergency');
        $this->patch($base.'/leave/'.$record->id.'/review', ['decision' => 'approved'])->assertRedirect($base.'/leave');
        $this->patch($base.'/leave/'.$record->id.'/review', ['decision' => 'approved'])->assertRedirect();
        $this->assertSame($version->id, (int) $record->refresh()->policy_version_id);

        $this->actingAs($employee, 'hr')->get($base.'/leave')->assertOk()->assertSee('2027 annual leave balance: 0 calendar days.');
        $next = array_replace($leave, ['starts_on' => '2027-01-08', 'ends_on' => '2027-01-08', 'request_key' => (string) Str::uuid()]);
        $this->post($base.'/leave', $next)->assertRedirect();
        $pending = HrLeaveRequest::query()->where('status', 'requested')->sole();
        $this->actingAs($manager, 'hr')->patchJson($base.'/leave/'.$pending->id.'/review', ['decision' => 'approved'])
            ->assertUnprocessable()->assertJsonValidationErrors('decision');
        $this->assertSame('requested', $pending->refresh()->status);
        $this->actingAs($employee, 'hr')->patch($base.'/leave/'.$pending->id.'/cancel')->assertRedirect();
        $this->patch($base.'/leave/'.$pending->id.'/cancel')->assertRedirect();
        $this->assertSame('cancelled', $pending->refresh()->status);
        $this->patchJson($base.'/leave/'.$record->id.'/cancel')->assertUnprocessable()->assertJsonValidationErrors('decision');
        $this->actingAs($owner, 'hr')->post($base.'/leave', array_replace($next, ['request_key' => (string) Str::uuid(), 'category' => 'unpaid']))->assertRedirect();
        $own = HrLeaveRequest::query()->where('requested_by', $owner->id)->sole();
        $this->patchJson($base.'/leave/'.$own->id.'/review', ['decision' => 'approved'])->assertForbidden();
    }

    public function test_field_duty_requires_an_enabled_policy_an_own_shift_and_a_separate_reviewer(): void
    {
        $this->travelTo(CarbonImmutable::parse('2027-01-04 07:00:00', 'UTC'));
        $workspace = $this->workspace('Fictional field site');
        $other = $this->workspace('Unrelated site');
        $owner = $this->member($workspace, 'owner');
        $employee = $this->member($workspace, 'employee');
        $teammate = $this->member($workspace, 'employee');
        $outsider = $this->member($other, 'hr_admin');
        $profile = $this->profile($workspace, $employee, 'Fictional field employee');
        $base = '/hr/workspaces/'.$workspace->id;
        $assignment = ['employee_id' => $profile->id, 'work_date' => '2027-01-05', 'starts_at' => '09:00', 'ends_at' => '17:00'];
        $this->actingAs($owner, 'hr')->post($base.'/shifts', $assignment)->assertRedirect();
        $shift = HrShiftAssignment::query()->sole();
        $requestForm = $base.'/shifts/'.$shift->id.'/field-duty';
        $data = ['assignment_note' => 'Fictional offsite training', 'request_key' => (string) Str::uuid(), 'latitude' => '24.000000'];
        $this->actingAs($employee, 'hr')->get($requestForm)->assertOk()->assertDontSee('Submit request');
        $this->get($base)->assertOk()->assertDontSee('Request field duty');
        $this->postJson($requestForm, $data)->assertUnprocessable()->assertJsonValidationErrors('assignment_note');
        $this->actingAs($teammate, 'hr')->postJson($requestForm, $data)->assertNotFound();

        $policy = ['effective_from' => '2027-01-04', 'grace_minutes' => 5, 'absent_after_minutes' => 30, 'annual_leave_days' => 2, 'manual_field_duty' => '1'];
        $this->actingAs($owner, 'hr')->post($base.'/policy', $policy)->assertRedirect();
        $version = HrPolicyVersion::query()->sole();
        $this->actingAs($employee, 'hr')->get($requestForm)->assertOk()->assertSee('Submit request');
        $this->get($base)->assertOk()->assertSee('Request field duty');
        $this->post($requestForm, $data)->assertRedirect($base.'/field-duty');
        $this->post($requestForm, $data)->assertRedirect();
        $request = HrFieldDutyRequest::query()->sole();
        $review = '/field-duty/'.$request->id.'/review';
        $this->assertSame($version->id, (int) $request->policy_version_id);
        $this->assertSame($shift->starts_at_utc, $request->starts_at_utc);
        $this->assertSame($shift->ends_at_utc, $request->ends_at_utc);
        $this->assertArrayNotHasKey('latitude', $request->getAttributes());
        $this->postJson($requestForm, array_replace($data, ['request_key' => (string) Str::uuid()]))
            ->assertUnprocessable()->assertJsonValidationErrors('assignment_note');
        $this->patchJson($base.$review, ['decision' => 'approved'])->assertForbidden();
        $this->actingAs($teammate, 'hr')->get($base.'/field-duty')->assertOk()->assertDontSee('Fictional offsite training');
        $this->actingAs($outsider, 'hr')->patchJson('/hr/workspaces/'.$other->id.$review, ['decision' => 'approved'])->assertNotFound();

        $this->actingAs($owner, 'hr')->postJson($base.'/shifts', array_replace($assignment, ['ends_at' => '18:00']))
            ->assertUnprocessable()->assertJsonValidationErrors('work_date');
        $this->get($base.'/field-duty')->assertOk()->assertSee('Fictional offsite training')->assertSee('Pending review');
        $decision = ['decision' => 'approved', 'decision_note' => 'Fictional review approved'];
        $this->patch($base.$review, $decision)->assertRedirect($base.'/field-duty');
        $this->patch($base.$review, $decision)->assertRedirect();
        $this->patchJson($base.$review, ['decision' => 'declined'])->assertUnprocessable()->assertJsonValidationErrors('decision');
        $this->assertSame('approved', $request->refresh()->status);
        $this->assertDatabaseCount('hr_attendance_punches', 0);
        $this->actingAs($employee, 'hr')->get($base.'/field-duty')->assertOk()->assertSee('Fictional review approved');
        $this->get($base)->assertOk()->assertSee('Field duty approved');
    }

    public function test_login_and_attendance_limits_isolate_accounts_on_the_same_network(): void
    {
        $workspace = $this->workspace('Fictional shared-network site');
        $first = $this->member($workspace, 'employee');
        $second = $this->member($workspace, 'employee');
        $input = ['workspace' => $workspace->id, 'email' => $first->email, 'password' => 'incorrect'];
        for ($attempt = 0; $attempt < 6; $attempt++) {
            $this->postJson('/hr/login', $input)->assertUnprocessable();
        }
        $this->postJson('/hr/login', array_replace($input, ['email' => strtoupper($first->email), 'password' => 'password']))->assertTooManyRequests();
        $this->post('/hr/login', array_replace($input, ['email' => $second->email, 'password' => 'password']))->assertRedirect();

        $url = '/hr/workspaces/'.$workspace->id.'/shifts/500/punch';
        $punch = ['action' => 'check_in', 'request_key' => (string) Str::uuid()];
        $this->actingAs($first, 'hr');
        for ($attempt = 0; $attempt < 12; $attempt++) {
            $this->postJson($url, $punch)->assertNotFound();
        }
        $this->postJson($url, $punch)->assertTooManyRequests();
        $this->actingAs($second, 'hr')->postJson($url, $punch)->assertNotFound();
    }

    private function workspace(string $name): HrOrganization
    {
        return HrOrganization::create(['name' => $name, 'timezone' => 'Asia/Karachi']);
    }

    private function member(HrOrganization $workspace, string $role): User
    {
        $user = User::factory()->create(['is_active' => true]);
        $workspace->memberships()->create(['user_id' => $user->id, 'role' => $role, 'active' => true]);

        return $user;
    }

    private function profile(HrOrganization $workspace, User $user, string $name): HrEmployee
    {
        return $workspace->employees()->create(['user_id' => $user->id, 'employee_number' => 'E'.$user->id, 'full_name' => $name]);
    }
}

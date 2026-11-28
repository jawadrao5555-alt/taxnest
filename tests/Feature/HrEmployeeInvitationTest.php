<?php

namespace Tests\Feature;

use App\Models\Company;
use App\Models\HrEmployeeInvitation;
use App\Models\HrOrganization;
use App\Models\User;
use App\Services\TwoStepLogin;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

final class HrEmployeeInvitationTest extends TestCase
{
    use RefreshDatabase;

    public function test_prebound_account_can_claim_employee_only_access_once_with_its_configured_second_factor(): void
    {
        [$company, $workspace, $owner, $employee, $recipient, $invitationPage] = $this->fixtures();
        $secret = TwoStepLogin::secret();
        DB::table('two_step_credentials')->insert(TwoStepLogin::identity($recipient) + [
            'secret' => Crypt::encryptString($secret),
            'recovery_hashes' => '[]',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $this->actingAs($owner, 'hr')->get($invitationPage)->assertOk()->assertSee('Create private invitation');
        $response = $this->post($invitationPage, ['user_id' => $recipient->id])->assertOk();
        $url = $this->invitationUrl($response->getContent());
        $token = basename($url);
        $invitation = HrEmployeeInvitation::query()->firstOrFail();
        $this->assertSame(hash('sha256', $token), $invitation->token_hash);
        $this->assertSame($owner->id, $invitation->issued_by);
        $this->assertNull($invitation->accepted_at);
        $this->assertNull($employee->fresh()->user_id);
        $this->assertDatabaseMissing('hr_organization_memberships', ['organization_id' => $workspace->id, 'user_id' => $recipient->id]);
        $this->get($invitationPage)->assertOk()->assertDontSee($token)->assertSee('Invitation pending');
        $this->postJson($invitationPage, ['user_id' => $recipient->id])->assertUnprocessable();

        auth('hr')->logout();
        $landingPage = $this->get($url)->assertOk()->assertSee('Accept employee access')
            ->assertHeader('Referrer-Policy', 'no-referrer')->assertDontSee($recipient->email);
        $this->assertStringContainsString('no-store', $landingPage->headers->get('Cache-Control'));
        $this->postJson($url, ['email' => $owner->email, 'password' => 'password'])->assertUnprocessable();
        $this->postJson($url, ['email' => $recipient->email, 'password' => 'wrong-password'])->assertUnprocessable();
        $this->postJson($url, ['email' => $recipient->email, 'password' => 'password'])->assertUnprocessable();
        $this->assertNull($employee->fresh()->user_id);
        $this->assertNull($invitation->fresh()->accepted_at);

        $this->post($url, [
            'email' => $recipient->email,
            'password' => 'password',
            'two_step_code' => TwoStepLogin::code($secret, intdiv(now()->timestamp, 30)),
        ])->assertRedirect(route('hr.login', ['workspace' => $workspace->id]));
        $this->assertGuest('hr');
        $this->assertNotNull($invitation->fresh()->accepted_at);
        $this->assertSame($recipient->id, $employee->fresh()->user_id);
        $membership = $workspace->memberships()->where('user_id', $recipient->id)->sole();
        $this->assertSame('employee', $membership->role);
        $this->assertTrue($membership->active);
        $this->assertSame($company->id, $recipient->fresh()->company_id);
        $this->get($url)->assertNotFound();
        $this->postJson($url, ['email' => $recipient->email, 'password' => 'password'])->assertNotFound();
    }

    public function test_owner_cannot_invite_outside_linked_company_or_mutate_another_workspace(): void
    {
        [, $workspace, $owner, $employee, $recipient, $invitationPage] = $this->fixtures();
        $outsider = User::factory()->create();
        $other = HrOrganization::create(['name' => 'Fictional Other Workspace']);
        $otherEmployee = $other->employees()->create(['employee_number' => 'E01', 'full_name' => 'Fictional Other Employee']);
        $this->actingAs($owner, 'hr')->postJson($invitationPage, ['user_id' => $outsider->id])->assertUnprocessable();
        $this->postJson(str_replace('/employees/'.$employee->id.'/', '/employees/'.$otherEmployee->id.'/', $invitationPage),
            ['user_id' => $recipient->id])->assertNotFound();

        $workspace->memberships()->create(['user_id' => $outsider->id, 'role' => 'hr_admin', 'active' => true]);
        $this->actingAs($outsider, 'hr')->postJson($invitationPage, ['user_id' => $recipient->id])->assertForbidden();
        $this->assertDatabaseCount('hr_employee_invitations', 0);
    }

    public function test_pending_invites_can_be_revoked_and_expire_without_granting_membership(): void
    {
        [, $workspace, $owner, $employee, $recipient, $invitationPage] = $this->fixtures();
        $this->actingAs($owner, 'hr');
        $first = $this->invitationUrl($this->post($invitationPage, ['user_id' => $recipient->id])->assertOk()->getContent());
        $invitation = HrEmployeeInvitation::query()->sole();

        $this->delete($invitationPage.'/'.$invitation->id)->assertRedirect($invitationPage);
        $this->assertSame($owner->id, $invitation->fresh()->revoked_by);
        $this->get($first)->assertNotFound();
        $second = $this->invitationUrl($this->post($invitationPage, ['user_id' => $recipient->id])->assertOk()->getContent());
        $this->travel(49)->hours();
        $this->get($second)->assertNotFound();
        $this->postJson($second, ['email' => $recipient->email, 'password' => 'password'])->assertNotFound();
        $this->assertNull($employee->fresh()->user_id);
        $this->assertSame(1, $workspace->memberships()->count());
    }

    private function fixtures(): array
    {
        $company = Company::create([
            'name' => 'Fictional Harbour Shop', 'ntn' => '7000013', 'product_type' => 'pos',
            'status' => 'approved', 'company_status' => 'active',
        ]);
        $workspace = HrOrganization::create(['name' => 'Fictional Harbour Workshop', 'linked_company_id' => $company->id]);
        $owner = User::factory()->create(['company_id' => $company->id, 'role' => 'company_admin']);
        $workspace->memberships()->create(['user_id' => $owner->id, 'role' => 'owner', 'active' => true]);
        $employee = $workspace->employees()->create(['employee_number' => 'E01', 'full_name' => 'Fictional Harbour Employee']);
        $recipient = User::factory()->create(['company_id' => $company->id, 'role' => 'employee']);
        $invitationPage = route('hr.employees.invitation', ['organization' => $workspace->id, 'employee' => $employee->id]);

        return [$company, $workspace, $owner, $employee, $recipient, $invitationPage];
    }

    private function invitationUrl(string $html): string
    {
        preg_match('/id="invitation-url" readonly value="([^"]+)"/', $html, $link);
        $this->assertArrayHasKey(1, $link);

        return html_entity_decode($link[1], ENT_QUOTES);
    }
}

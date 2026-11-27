<?php

namespace Tests\Feature;

use App\Models\HrPayrollPeriod;
use App\Models\HrOrganization;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

final class HrPayrollTest extends TestCase
{
    use RefreshDatabase;

    public function test_owner_enters_salary_reviewer_locks_exact_version_and_export_uses_the_frozen_amounts(): void
    {
        $workspace = $this->workspace('Fictional payroll workspace');
        $owner = $this->member($workspace, 'owner');
        $reviewer = $this->member($workspace, 'hr_admin');
        $employee = $workspace->employees()->create(['employee_number' => '+S1', 'full_name' => '=Fictional employee']);
        $base = '/hr/workspaces/'.$workspace->id.'/payroll';
        $input = ['employee_id' => $employee->id, 'version' => 1, 'basic' => '50000.25',
            'allowances' => '2000.50', 'overtime' => '100.25', 'deductions' => '500.50', 'bonus' => '1000.25'];

        $this->actingAs($owner, 'hr')->get($base)->assertOk()->assertSee('Open a payroll draft');
        $this->post($base, ['month' => '2026-11'])->assertRedirect();
        $period = HrPayrollPeriod::query()->sole();
        $path = $base.'/'.$period->id;
        $this->post($base, ['month' => '2026-11'])->assertRedirect($path);
        $this->assertDatabaseCount('hr_payroll_periods', 1);
        $this->postJson($path.'/approve', ['version' => 1])->assertUnprocessable()->assertJsonValidationErrors('version');
        $this->postJson($path.'/lines', array_replace($input, ['deductions' => '90000']))
            ->assertUnprocessable()->assertJsonValidationErrors('deductions');
        $this->post($path.'/lines', $input)->assertRedirect($path);
        $line = $period->lines()->sole();
        $this->assertSame(5260075, (int) $line->net_minor);
        $this->get($path)->assertOk()->assertSee('52600.75')->assertDontSee('Download approved payroll CSV');
        $this->getJson($path.'/export')->assertNotFound();

        $this->actingAs($reviewer, 'hr')->get($path)->assertOk()->assertSee('Approve and lock this payroll')->assertDontSee('Save payroll line');
        $this->postJson($path.'/lines', $input)->assertForbidden();
        $this->postJson($path.'/approve', ['version' => 1])->assertUnprocessable()->assertJsonValidationErrors('version');
        $this->post($path.'/approve', ['version' => 2])->assertRedirect($path);
        $this->post($path.'/approve', ['version' => 2])->assertRedirect($path);
        $this->assertSame($reviewer->id, (int) $period->refresh()->approved_by);

        $this->actingAs($owner, 'hr')->postJson($path.'/lines', array_replace($input, ['version' => 2, 'basic' => '1']))
            ->assertUnprocessable()->assertJsonValidationErrors('version');
        $this->deleteJson($path.'/lines/'.$line->id, ['version' => 2])->assertUnprocessable();
        $employee->update(['full_name' => 'Renamed profile']);
        $this->get($path.'/payslips/'.$line->id)->assertOk()->assertSee('=Fictional employee')->assertDontSee('Renamed profile');
        $export = $this->get($path.'/export')->assertOk()->assertDownload('payroll-2026-11.csv');
        $this->assertStringContainsString('no-store', $export->headers->get('Cache-Control'));
        $this->assertStringContainsString("'+S1,\"'=Fictional employee\",50000.25,2000.50,100.25,500.50,1000.25,52600.75,PKR", $export->streamedContent());
    }

    public function test_only_the_approved_recipient_can_read_their_payslip_and_ids_never_cross_workspaces(): void
    {
        $first = $this->workspace('First fictional workspace');
        $second = $this->workspace('Second fictional workspace');
        $owner = $this->member($first, 'owner');
        $recipient = $this->member($first, 'employee');
        $colleague = $this->member($first, 'employee');
        $otherOwner = $this->member($second, 'owner');
        $employee = $first->employees()->create(['employee_number' => 'E1', 'full_name' => 'Recipient fixture', 'user_id' => $recipient->id]);
        $foreign = $second->employees()->create(['employee_number' => 'F1', 'full_name' => 'Foreign fixture']);
        $base = '/hr/workspaces/'.$first->id;

        $this->actingAs($owner, 'hr')->post($base.'/payroll', ['month' => '2026-10'])->assertRedirect();
        $period = $first->payrollPeriods()->sole();
        $path = $base.'/payroll/'.$period->id;
        $input = ['employee_id' => $foreign->id, 'version' => 1, 'basic' => '1500', 'allowances' => '0', 'overtime' => '0', 'deductions' => '0', 'bonus' => '0'];
        $this->postJson($path.'/lines', $input)->assertNotFound();
        $this->post($path.'/lines', array_replace($input, ['employee_id' => $employee->id]))->assertRedirect($path);
        $line = $period->lines()->sole();
        $ownPath = $base.'/payslips/'.$period->id.'/'.$line->id;

        $this->actingAs($recipient, 'hr')->get($base.'/payslips')->assertOk()->assertSee('No approved payslips yet.');
        $this->getJson($ownPath)->assertNotFound();
        $this->getJson($path)->assertForbidden();
        $this->actingAs($owner, 'hr')->post($path.'/approve', ['version' => 2])->assertRedirect($path);
        $this->actingAs($recipient, 'hr')->get($base.'/payslips')->assertOk()->assertSee('October 2026 payslip');
        $this->get($ownPath)->assertOk()->assertSee('1500.00');
        $employee->update(['user_id' => $colleague->id]);
        $this->actingAs($colleague, 'hr')->get($base.'/payslips')->assertOk()->assertSee('No approved payslips yet.');
        $this->getJson($ownPath)->assertNotFound();
        $this->actingAs($otherOwner, 'hr')->getJson($path.'/export')->assertForbidden();
        $this->getJson('/hr/workspaces/'.$second->id.'/payroll/'.$period->id.'/export')->assertNotFound();
        $this->actingAs($recipient, 'hr')->get($ownPath)->assertOk()->assertSee('Recipient fixture');
        $first->memberships()->where('user_id', $recipient->id)->update(['active' => false]);
        $this->getJson($ownPath)->assertForbidden();
    }

    private function workspace(string $name): HrOrganization
    {
        return HrOrganization::create(['name' => $name, 'timezone' => 'Asia/Karachi']);
    }

    private function member(HrOrganization $workspace, string $role): User
    {
        $member = User::factory()->create(['is_active' => true]);
        $workspace->memberships()->create(['user_id' => $member->id, 'role' => $role, 'active' => true]);

        return $member;
    }
}

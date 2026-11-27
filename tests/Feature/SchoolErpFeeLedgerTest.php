<?php

namespace Tests\Feature;

use App\Models\Branch;
use App\Models\Company;
use App\Models\User;
use App\Services\SchoolAdmissionsService;
use App\Services\Reporting\ErpAdminFinancialSummary;
use App\Services\TrialSubscriptionService;
use App\Support\NestErps;
use App\Support\SchoolErpPanel;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

final class SchoolErpFeeLedgerTest extends TestCase
{
    use RefreshDatabase;

    public function test_fee_schedule_and_partial_receipt_are_idempotent_and_reversal_keeps_original(): void
    {
        [$school, $owner, $term, $student] = $this->school('first');
        $this->actingAs($owner, SchoolErpPanel::GUARD);
        $fee = ['term_id' => $term, 'label' => 'Term tuition', 'amount' => '1000.25', 'due_on' => '2027-01-20'];
        $scheduleRoute = route('school-erp.fees.schedule');
        $this->post($scheduleRoute, $fee)->assertRedirect(route('school-erp.fees'));
        $this->post($scheduleRoute, $fee)->assertRedirect(route('school-erp.fees'));
        $this->postJson($scheduleRoute, array_replace($fee, ['amount' => '11.00']))->assertJsonValidationErrors('label');
        $this->assertDatabaseCount('school_erp_fee_schedules', 1);

        $schedule = DB::table('school_erp_fee_schedules')->where('company_id', $school->id)->value('id');
        $create = ['student_reference' => $student, 'schedule_id' => $schedule];
        $this->post(route('school-erp.fees.issue'), $create)->assertRedirect();
        $this->post(route('school-erp.fees.issue'), $create)->assertRedirect();
        $this->assertDatabaseCount('school_erp_fee_invoices', 1);
        $invoice = DB::table('school_erp_fee_invoices')->where('company_id', $school->id)->value('id');
        $payRoute = route('school-erp.fees.receive', $invoice);
        $payment = ['amount' => '410.10', 'method' => 'bank', 'reference' => 'Fictional transfer', 'request_key' => (string) Str::uuid()];
        $this->post($payRoute, $payment)->assertRedirect();
        $this->post($payRoute, $payment)->assertRedirect();
        $this->postJson($payRoute, array_replace($payment, ['amount' => '0.10']))->assertJsonValidationErrors('request_key');
        $this->postJson($payRoute, array_replace($payment, ['amount' => '590.16', 'request_key' => (string) Str::uuid()]))
            ->assertJsonValidationErrors('amount');
        $this->get(route('school-erp.fees'))->assertOk()->assertSee('PKR 590.15');
        $this->assertDatabaseCount('school_erp_fee_payments', 1);

        $receipt = DB::table('school_erp_fee_payments')->where('company_id', $school->id)->value('id');
        $paidOn = today()->toDateString();
        $this->travel(1)->days();
        $reverse = route('school-erp.fees.reverse', [$invoice, $receipt]);
        $this->post($reverse, ['reason' => 'Fictional correction'])->assertRedirect();
        $this->post($reverse, ['reason' => 'Fictional correction'])->assertRedirect();
        $this->postJson($reverse, ['reason' => 'Different correction'])->assertJsonValidationErrors('reason');
        $this->get(route('school-erp.fees.receipt', $receipt))->assertOk()->assertSee('Fictional correction')->assertSee('PKR 410.10');
        $this->get(route('school-erp.fees'))->assertOk()->assertSee('PKR 1,000.25');
        $this->assertDatabaseCount('school_erp_fee_reversals', 1);
        $this->assertDatabaseHas('school_erp_fee_payments', ['id' => $receipt, 'amount_paisa' => 41010]);
        $reports = app(ErpAdminFinancialSummary::class);
        $paymentDay = $reports->forCompanies(collect([$school]), ['date_from' => $paidOn, 'date_to' => $paidOn])->get($school->id);
        $this->assertNull($paymentDay['operations']);
        $this->assertSame(410.1, $paymentDay['school_fees']['received']);
        $this->assertSame(1000.25, $paymentDay['school_fees']['outstanding']);
        $reversalDay = $reports->allCompanyTotals(['company_id' => $school->id, 'date_from' => today()->toDateString()]);
        $this->assertSame(-410.1, $reversalDay['school_fees']['net_received']);
    }

    public function test_school_fee_reports_are_tenant_and_campus_scoped(): void
    {
        [$school, $owner, $term, $student, $campus] = $this->school('alpha');
        [$foreign, $foreignOwner, $foreignTerm, $foreignStudent, $foreignCampus] = $this->school('beta');
        $otherCampus = Branch::create(['company_id' => $school->id, 'name' => 'Restricted campus', 'code' => 'RESTRICTED', 'is_active' => true]);
        $viewer = User::create(['company_id' => $school->id, 'name' => 'Fictional viewer', 'email' => 'fees-viewer@example.invalid',
            'password' => 'SyntheticFees!2027', 'role' => 'viewer', 'is_active' => true]);
        DB::table('branch_user')->insert(['branch_id' => $otherCampus->id, 'user_id' => $viewer->id, 'access_level' => 'read_only']);

        $schedule = ['term_id' => $term, 'label' => 'Private school fees', 'amount' => '100.00', 'due_on' => '2027-01-20'];
        $this->actingAs($owner, SchoolErpPanel::GUARD)->post(route('school-erp.fees.schedule'), $schedule)->assertRedirect();
        $scheduleId = DB::table('school_erp_fee_schedules')->where('company_id', $school->id)->value('id');
        $this->post(route('school-erp.fees.issue'), ['student_reference' => $student, 'schedule_id' => $scheduleId])->assertRedirect();
        $invoice = DB::table('school_erp_fee_invoices')->where('company_id', $school->id)->value('id');
        $this->post(route('school-erp.fees.receive', $invoice), [
            'amount' => '40', 'request_key' => (string) Str::uuid(), 'method' => 'cash',
        ])->assertRedirect();
        $receipt = DB::table('school_erp_fee_payments')->where('company_id', $school->id)->value('id');

        $this->actingAs($viewer, SchoolErpPanel::GUARD)->get(route('school-erp.fees'))->assertOk()->assertDontSee('Private school fees');
        $this->getJson(route('school-erp.fees.receipt', $receipt))->assertNotFound();
        $this->postJson(route('school-erp.fees.issue'), ['student_reference' => $student, 'schedule_id' => $scheduleId])->assertForbidden();
        $this->actingAs($foreignOwner, SchoolErpPanel::GUARD)->get(route('school-erp.fees'))->assertOk()->assertDontSee('Private school fees');
        $this->getJson(route('school-erp.fees.receipt', $receipt))->assertNotFound();
        $this->postJson(route('school-erp.fees.issue'), ['student_reference' => $foreignStudent, 'schedule_id' => $scheduleId])
            ->assertJsonValidationErrors('student_id');
    }

    private function school(string $name): array
    {
        $company = Company::create(['name' => 'Fictional school '.$name, 'ntn' => 'SYN-SCHOOL-'.$name,
            'product_type' => NestErps::PRODUCT_TYPE, 'erps_vertical' => NestErps::SCHOOL, 'status' => 'approved', 'company_status' => 'active']);
        $owner = User::create(['name' => 'Fictional owner', 'email' => $name.'-school-fees@example.invalid',
            'password' => 'SyntheticFees!2027', 'company_id' => $company->id, 'role' => 'company_admin', 'is_active' => true]);
        TrialSubscriptionService::ensureTrial($company->id, NestErps::PRODUCT_TYPE, 14);
        $branch = Branch::create(['company_id' => $company->id, 'name' => 'Campus '.$name, 'code' => strtoupper($name), 'is_active' => true]);
        $term = DB::table('school_erp_terms')->insertGetId(['company_id' => $company->id, 'code' => 'FIRST',
            'name' => 'First term', 'starts_on' => '2027-01-01', 'ends_on' => '2027-03-31']);
        $reference = 'STUDENT-'.$name;
        $student = DB::table('school_erp_students')->insertGetId(['company_id' => $company->id, 'campus_id' => $branch->id,
            'student_reference' => $reference, 'display_name' => 'Fictional student '.$name]);
        app(SchoolAdmissionsService::class)->enroll($company->id, $student, $term, '2027-01-05');

        return [$company, $owner, $term, $reference, $branch];
    }
}

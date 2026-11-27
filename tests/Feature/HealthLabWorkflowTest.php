<?php

namespace Tests\Feature;

use App\Models\Branch;
use App\Models\Company;
use App\Models\HealthCharge;
use App\Models\HealthDepartment;
use App\Models\HealthDoctor;
use App\Models\HealthLabOrder;
use App\Models\HealthLabTest;
use App\Models\HealthPatient;
use App\Models\HealthVisit;
use App\Models\PricingPlan;
use App\Models\Subscription;
use App\Models\User;
use App\Services\HealthAccessService;
use App\Services\HealthModuleService;
use App\Services\HealthScopeService;
use App\Support\NestErps;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

class HealthLabWorkflowTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        HealthScopeService::forget();
    }

    public function test_shared_lab_intake_recollection_and_verified_report_preserve_order_and_revision_history(): void
    {
        $company = $this->makeWorkspace('lab', 'LAB-WORKFLOW');
        $owner = $this->staff($company, HealthAccessService::ROLE_OWNER);
        $technician = $this->staff($company, 'health_lab_tech');
        $verifier = $this->staff($company, 'health_lab_supervisor');
        $patient = $this->patient($company, 'DEMO-001');
        $this->actingAs($owner, 'health');

        $this->get(route('health.lab'))->assertOk()->assertSee('New laboratory order');
        $this->post(route('health.lab.catalogue.store'), [
            'code' => 'demo-panel', 'name' => 'Synthetic panel', 'price' => '1250.25', 'is_active' => true,
        ])->assertRedirect(route('health.lab.catalogue'));

        $test = HealthLabTest::query()->sole();
        $this->assertSame('DEMO-PANEL', $test->code);
        $this->assertSame(125025, $test->price_paisa);
        $this->get(route('health.lab.orders.create', ['q' => $patient->mrn]))
            ->assertOk()->assertSee($patient->name)->assertSee('Synthetic panel');

        $data = ['patient_id' => $patient->id, 'test_ids' => [$test->id], 'request_key' => (string) Str::uuid()];
        $this->post(route('health.lab.orders.store'), $data)->assertRedirect();
        $order = HealthLabOrder::query()->sole();
        $orderUrl = route('health.lab.orders.show', $order);
        $this->post(route('health.lab.orders.store'), $data)->assertRedirect($orderUrl);
        $this->assertDatabaseCount('health_lab_orders', 1);
        $this->assertDatabaseCount('health_lab_specimens', 1);

        $otherPatient = $this->patient($company, 'DEMO-002');
        $this->postJson(route('health.lab.orders.store'), array_replace($data, ['patient_id' => $otherPatient->id]))
            ->assertUnprocessable()->assertJsonValidationErrors('request_key');

        $this->patch(route('health.lab.catalogue.update', $test), [
            'code' => 'DEMO-PANEL', 'name' => 'Updated synthetic panel', 'price' => '1500.00', 'is_active' => true,
        ])->assertRedirect(route('health.lab.catalogue'));
        $item = $order->items()->sole();
        $this->assertSame('Synthetic panel', $item->test_name);
        $this->assertSame(125025, $item->price_paisa);

        $billingUrl = route('health.lab.orders.billing', $order);
        $this->post($billingUrl)->assertRedirect($orderUrl);
        $this->post($billingUrl)->assertRedirect($orderUrl);
        $charge = HealthCharge::query()->sole();
        $this->assertSame(HealthCharge::SOURCE_LAB_ORDER, $charge->source_type);
        $this->assertSame($order->id, $charge->source_id);
        $this->assertSame('1250.25', $charge->unit_price);
        $this->assertSame($patient->id, $charge->health_patient_id);

        $initial = $item->specimens()->sole();
        $args = [$order, $item];
        $report = route('health.lab.orders.report', $order);
        $this->getJson($report)->assertNotFound();

        $this->actingAs($technician, 'health');
        $this->postJson($billingUrl)->assertForbidden();
        $this->get($orderUrl)->assertOk()->assertSee($initial->barcode);
        $label = $this->get(route('health.lab.orders.label', [...$args, $initial]))
            ->assertOk()->assertHeader('Content-Type', 'application/pdf');
        $this->assertStringStartsWith('%PDF-', $label->getContent());
        $this->postJson(route('health.lab.orders.result', $args), [
            'specimen_id' => $initial->id, 'expected_revision' => 0, 'expected_version' => 0, 'value' => 'Draft',
        ])->assertUnprocessable()->assertJsonValidationErrors('specimen');

        $this->post(route('health.lab.orders.collect', $args), ['specimen_id' => $initial->id])->assertRedirect($orderUrl);
        $this->post(route('health.lab.orders.collect', $args), ['specimen_id' => $initial->id])->assertRedirect($orderUrl);
        $this->post(route('health.lab.orders.reject', $args), [
            'specimen_id' => $initial->id, 'reason' => 'Fixture rejection',
        ])->assertRedirect($orderUrl);
        $replacement = $item->specimens()->firstOrFail();
        $this->assertSame(2, $replacement->sequence);
        $this->assertSame('rejected', $initial->fresh()->status);
        $this->get(route('health.lab', ['q' => $initial->barcode]))->assertOk()->assertDontSee($order->order_no);
        $this->get(route('health.lab', ['q' => $replacement->barcode]))->assertOk()->assertSee($order->order_no);
        $this->getJson(route('health.lab.orders.label', [...$args, $initial]))->assertNotFound();
        $this->postJson(route('health.lab.orders.collect', $args), ['specimen_id' => $initial->id])
            ->assertUnprocessable()->assertJsonValidationErrors('specimen');

        $this->post(route('health.lab.orders.collect', $args), ['specimen_id' => $replacement->id])->assertRedirect($orderUrl);
        $result = ['specimen_id' => $replacement->id, 'expected_revision' => 0, 'expected_version' => 0, 'value' => 'First synthetic value'];
        $this->post(route('health.lab.orders.result', $args), $result)->assertRedirect($orderUrl);
        $draft = $item->results()->sole();
        $this->get($orderUrl)->assertOk()->assertSee('First synthetic value')->assertDontSee('Verify result');
        $this->postJson(route('health.lab.orders.verify', $args), ['result_id' => $draft->id, 'result_version' => 1])->assertForbidden();

        $this->post(route('health.lab.orders.result', $args), array_replace($result, [
            'expected_revision' => 1, 'expected_version' => 1, 'value' => 'Edited synthetic value',
        ]))->assertRedirect($orderUrl);
        $this->postJson(route('health.lab.orders.result', $args), array_replace($result, [
            'expected_revision' => 1, 'expected_version' => 1, 'value' => 'Superseded edit',
        ]))->assertUnprocessable()->assertJsonValidationErrors('expected_revision');

        $this->actingAs($verifier, 'health');
        $this->get($orderUrl)->assertOk()->assertSee('Edited synthetic value')->assertSee('Verify result');
        $this->postJson(route('health.lab.orders.verify', $args), ['result_id' => $draft->id, 'result_version' => 1])
            ->assertUnprocessable()->assertJsonValidationErrors('result');
        $this->post(route('health.lab.orders.verify', $args), ['result_id' => $draft->id, 'result_version' => 2])->assertRedirect($orderUrl);
        $original = $draft->fresh();
        $this->assertEquals($verifier->id, $original->verified_by);
        $pdf = $this->get($report)->assertOk()->assertHeader('Content-Type', 'application/pdf');
        $this->assertStringStartsWith('%PDF-', $pdf->getContent());
        $this->assertStringContainsString('no-store', (string) $pdf->headers->get('Cache-Control'));

        $this->actingAs($owner, 'health');
        $amend = array_replace($result, [
            'expected_revision' => 1, 'expected_version' => 2, 'value' => 'Amended synthetic value',
        ]);
        $this->postJson(route('health.lab.orders.result', $args), $amend)
            ->assertUnprocessable()->assertJsonValidationErrors('amendment_reason');
        $this->post(route('health.lab.orders.result', $args), $amend + ['amendment_reason' => 'Fixture amendment'])->assertRedirect($orderUrl);
        $amended = $item->results()->firstOrFail();
        $this->get($report)->assertOk();
        $this->postJson(route('health.lab.orders.verify', $args), ['result_id' => $amended->id, 'result_version' => 1])
            ->assertUnprocessable()->assertJsonValidationErrors('result');

        $this->actingAs($verifier, 'health');
        $this->post(route('health.lab.orders.verify', $args), ['result_id' => $amended->id, 'result_version' => 1])->assertRedirect($orderUrl);
        $this->assertSame('Edited synthetic value', $draft->fresh()->value);
        $this->assertSame('Amended synthetic value', $amended->fresh()->value);
        $this->assertEquals($verifier->id, $amended->fresh()->verified_by);
        $this->assertDatabaseCount('health_lab_results', 2);
    }

    public function test_hospital_lab_respects_branch_company_confidentiality_and_the_same_entitlement(): void
    {
        $company = $this->makeWorkspace('hospital', 'LAB-HOSPITAL');
        $other = $this->makeWorkspace('lab', 'LAB-FOREIGN');
        $branch = Branch::create(['company_id' => $company->id, 'name' => 'Main', 'is_active' => true, 'is_head_office' => true]);
        $secondary = Branch::create(['company_id' => $company->id, 'name' => 'Second', 'is_active' => true]);
        $owner = $this->staff($company, HealthAccessService::ROLE_OWNER);
        $outside = $this->staff($other, HealthAccessService::ROLE_OWNER);
        $secondTech = $this->staff($company, 'health_lab_tech');
        $frontDesk = $this->staff($company, 'health_receptionist');
        DB::table('branch_user')->insert(['branch_id' => $secondary->id, 'user_id' => $secondTech->id]);

        $patient = $this->patient($company, 'HOSPITAL-01', $branch);
        $confidential = $this->patient($company, 'HOSPITAL-02', $branch, true);
        $test = HealthLabTest::create(['company_id' => $company->id, 'code' => 'DEMO', 'name' => 'Synthetic test', 'price_paisa' => 10000, 'is_active' => true]);
        $foreignTest = HealthLabTest::create(['company_id' => $other->id, 'code' => 'DEMO', 'name' => 'Other synthetic test', 'price_paisa' => 10000, 'is_active' => true]);

        $this->actingAs($owner, 'health');
        $data = ['patient_id' => $patient->id, 'branch_id' => $branch->id, 'request_key' => (string) Str::uuid()];
        $this->postJson(route('health.lab.orders.store'), $data + ['test_ids' => [$foreignTest->id]])
            ->assertUnprocessable()->assertJsonValidationErrors('test_ids');
        $this->post(route('health.lab.orders.store'), $data + ['test_ids' => [$test->id]])->assertRedirect();
        $order = HealthLabOrder::query()->sole();
        $this->post(route('health.lab.orders.store'), array_replace($data, [
            'patient_id' => $confidential->id, 'request_key' => (string) Str::uuid(), 'test_ids' => [$test->id],
        ]))->assertRedirect();
        $private = HealthLabOrder::query()->where('health_patient_id', $confidential->id)->sole();
        $this->get(route('health.lab'))->assertOk()->assertSee($order->order_no)->assertSee($private->order_no);

        $this->actingAs($outside, 'health');
        $this->getJson(route('health.lab.orders.show', $order))->assertNotFound();
        $this->getJson(route('health.lab.catalogue.edit', $test))->assertNotFound();

        $this->actingAs($secondTech, 'health');
        $this->get(route('health.lab'))->assertOk()->assertDontSee($order->order_no);
        $this->getJson(route('health.lab.orders.show', $order))->assertNotFound();
        $this->postJson(route('health.lab.orders.collect', [$order, $order->items()->firstOrFail()]), [
            'specimen_id' => $order->items()->firstOrFail()->specimens()->firstOrFail()->id,
        ])->assertNotFound();

        $this->actingAs($frontDesk, 'health');
        $this->get(route('health.lab'))->assertOk()->assertSee($order->order_no)->assertDontSee($private->order_no);
        $this->getJson(route('health.lab.orders.show', $private))->assertNotFound();
        $this->get(route('health.lab.orders.report', $order))->assertForbidden();
        $this->postJson(route('health.lab.orders.billing', $order))->assertForbidden();

        $this->actingAs($owner, 'health');
        $company->update(['health_modules' => json_encode(['accounts'])]);
        HealthModuleService::forget();
        $this->get(route('health.lab.orders.show', $order))->assertForbidden();
    }

    public function test_opd_visit_links_a_lab_order_with_the_scoped_encounter_context(): void
    {
        $company = $this->makeWorkspace('hospital', 'LAB-OPD', ['lab', 'accounts', 'opd']);
        $foreign = $this->makeWorkspace('hospital', 'LAB-OPD-FOREIGN', ['lab', 'opd']);
        $branch = Branch::create(['company_id' => $company->id, 'name' => 'Main', 'is_active' => true]);
        $second = Branch::create(['company_id' => $company->id, 'name' => 'Second', 'is_active' => true]);
        $dept = HealthDepartment::create([
            'company_id' => $company->id, 'branch_id' => $branch->id,
            'name' => 'Outpatient', 'type' => 'opd', 'is_active' => true,
        ]);
        $doctor = $this->staff($company, 'health_doctor');
        $doctor->forceFill(['health_department_id' => $dept->id])->save();
        $practitioner = HealthDoctor::create(['company_id' => $company->id, 'user_id' => $doctor->id, 'name' => $doctor->name]);
        $nurse = $this->staff($company, 'health_nurse');
        $nurse->forceFill(['health_department_id' => $dept->id])->save();
        $otherDoctor = $this->staff($company, 'health_doctor');
        $otherDoctor->forceFill(['health_department_id' => $dept->id])->save();
        DB::table('branch_user')->insert([
            ['branch_id' => $branch->id, 'user_id' => $doctor->id],
            ['branch_id' => $branch->id, 'user_id' => $nurse->id],
            ['branch_id' => $second->id, 'user_id' => $otherDoctor->id],
        ]);
        $owner = $this->staff($company, HealthAccessService::ROLE_OWNER);
        $foreignOwner = $this->staff($foreign, HealthAccessService::ROLE_OWNER);
        $patient = $this->patient($company, 'LAB-OPD-001', $branch);
        $otherPatient = $this->patient($company, 'LAB-OPD-002', $branch);
        $test = HealthLabTest::create([
            'company_id' => $company->id, 'code' => 'OPD-DEMO', 'name' => 'Synthetic OPD test',
            'price_paisa' => 12000, 'is_active' => true,
        ]);
        $visit = HealthVisit::create([
            'company_id' => $company->id, 'branch_id' => $branch->id, 'health_department_id' => $dept->id,
            'health_patient_id' => $patient->id, 'health_doctor_id' => $practitioner->id,
            'visit_no' => 'OPD-VISIT-LAB', 'visit_date' => today(), 'visit_type' => 'new',
            'status' => HealthVisit::STATUS_IN_CONSULTATION,
        ]);

        $visitUrl = route('health.clinical.visit', $visit);
        $createUrl = route('health.lab.orders.create', ['visit_id' => $visit->id]);
        $storeUrl = route('health.lab.orders.store');
        $intake = ['visit_id' => $visit->id, 'patient_id' => $patient->id,
            'test_ids' => [$test->id], 'request_key' => (string) Str::uuid()];

        $this->actingAs($doctor, 'health');
        $this->get($visitUrl)->assertOk()->assertSee($createUrl);
        $this->get($createUrl)->assertOk()->assertSee($patient->mrn)->assertDontSee($otherPatient->mrn);
        $this->postJson($storeUrl, array_replace($intake, ['patient_id' => $otherPatient->id]))
            ->assertUnprocessable()->assertJsonValidationErrors('visit_id');
        $this->postJson($storeUrl, $intake + ['branch_id' => $second->id])
            ->assertUnprocessable()->assertJsonValidationErrors('visit_id');
        $this->post($storeUrl, $intake)->assertRedirect();

        $order = HealthLabOrder::query()->sole();
        $this->assertEquals($visit->id, $order->health_visit_id);
        $this->assertEquals($patient->id, $order->health_patient_id);
        $this->assertEquals($branch->id, $order->branch_id);
        $this->assertEquals($dept->id, $order->health_department_id);
        $this->get($visitUrl)->assertOk()->assertSee($order->order_no);
        $this->get(route('health.lab.orders.show', $order))->assertOk()->assertSee($visitUrl);

        $this->actingAs($otherDoctor, 'health');
        $this->getJson($createUrl)->assertNotFound();
        $this->postJson($storeUrl, $intake)->assertNotFound();

        $this->actingAs($foreignOwner, 'health');
        $this->getJson($createUrl)->assertNotFound();
        $this->postJson($storeUrl, $intake)->assertNotFound();

        $this->actingAs($nurse, 'health');
        $this->get($visitUrl)->assertOk()->assertSee($order->order_no)->assertDontSee($createUrl);
        $this->getJson($createUrl)->assertForbidden();

        $this->actingAs($owner, 'health');
        $this->post(route('health.lab.orders.billing', $order))->assertRedirect(route('health.lab.orders.show', $order));
        $this->assertSame($visit->id, HealthCharge::query()->sole()->health_visit_id);
        $visit->update(['status' => HealthVisit::STATUS_CANCELLED]);
        $this->get($visitUrl)->assertOk()->assertSee($order->order_no)->assertDontSee($createUrl);
        $this->getJson($createUrl)->assertNotFound();
    }

    public function test_lab_operations_uses_one_scoped_nonclinical_summary_for_the_page_and_csv(): void
    {
        $company = $this->makeWorkspace('hospital', 'LAB-OPERATIONS');
        $foreign = $this->makeWorkspace('lab', 'LAB-OPERATIONS-OTHER');
        $branch = Branch::create(['company_id' => $company->id, 'name' => '=Synthetic main', 'is_active' => true]);
        $second = Branch::create(['company_id' => $company->id, 'name' => 'Secondary private branch', 'is_active' => true]);
        $foreignBranch = Branch::create(['company_id' => $foreign->id, 'name' => 'Foreign branch', 'is_active' => true]);
        $department = HealthDepartment::create([
            'company_id' => $company->id, 'branch_id' => $branch->id, 'name' => 'Synthetic section', 'type' => 'lab', 'is_active' => true,
        ]);
        $anotherDepartment = HealthDepartment::create([
            'company_id' => $company->id, 'branch_id' => $branch->id, 'name' => 'Another section', 'type' => 'lab', 'is_active' => true,
        ]);
        $owner = $this->staff($company, HealthAccessService::ROLE_OWNER);
        $supervisor = $this->staff($company, 'health_lab_supervisor');
        $technician = $this->staff($company, 'health_lab_tech');
        $foreignOwner = $this->staff($foreign, HealthAccessService::ROLE_OWNER);
        $supervisor->forceFill(['health_department_id' => $department->id])->save();
        DB::table('branch_user')->insert(['branch_id' => $branch->id, 'user_id' => $supervisor->id]);
        $patient = $this->patient($company, 'ADMIN-DEMO-001', $branch);
        $confidential = $this->patient($company, 'ADMIN-DEMO-002', $branch, true);
        $otherPatient = $this->patient($company, 'ADMIN-DEMO-003', $second);
        $foreignPatient = $this->patient($foreign, 'ADMIN-DEMO-004', $foreignBranch);
        $this->operationsOrder($owner, $patient, '2026-08-01 00:00:00', $department->id, true);
        $this->operationsOrder($owner, $patient, '2026-08-02 23:59:59', $department->id);
        $this->operationsOrder($owner, $patient, '2026-08-03 00:00:00', $department->id);
        $this->operationsOrder($owner, $patient, '2026-08-02 00:00:00', $anotherDepartment->id);
        $this->operationsOrder($owner, $confidential, '2026-08-02 00:00:00', $department->id);
        $this->operationsOrder($owner, $otherPatient, '2026-08-01 00:00:00');
        $this->operationsOrder($foreignOwner, $foreignPatient, '2026-08-01 00:00:00');

        $filters = ['from' => '2026-08-01', 'through' => '2026-08-02'];
        $url = route('health.lab.operations', $filters);
        $exportUrl = route('health.lab.operations.export', $filters);
        $this->actingAs($supervisor, 'health');
        $this->get(route('health.lab'))->assertOk()->assertSee('Laboratory operations');
        $page = $this->get($url)->assertOk()->assertSee('=Synthetic main')->assertDontSee('Secondary private branch')
            ->assertDontSee($patient->mrn)->assertDontSee($confidential->mrn);
        $this->assertStringContainsString('no-store', (string) $page->headers->get('Cache-Control'));
        $row = $page->viewData('rows')->sole();
        $this->assertSame([2, 1, 1, 2, 1, 1], array_map('intval', [
            $row->orders, $row->ready_orders, $row->open_orders,
            $row->ordered_tests, $row->verified_tests, $row->rejected_specimens,
        ]));
        $response = $this->get($exportUrl)->assertOk()->assertDownload('laboratory-operations-2026-08-01-2026-08-02.csv');
        $csv = $response->streamedContent();
        $this->assertStringContainsString("2026-08-01,2026-08-02,{$branch->id},\"'=Synthetic main\",2,1,1,2,1,1", $csv);
        foreach ([$patient->mrn, $confidential->mrn, 'Synthetic result text', 'Secondary private branch', 'Foreign branch'] as $private) {
            $this->assertStringNotContainsString($private, $csv);
        }
        $this->assertDatabaseHas('health_audit_events', ['company_id' => $company->id, 'event' => 'lab.operations_exported']);
        $this->getJson(route('health.lab.operations', $filters + ['branch_id' => $second->id]))->assertForbidden();
        $this->getJson(route('health.lab.operations.export', $filters + ['branch_id' => $foreignBranch->id]))->assertForbidden();
        $this->getJson(route('health.lab.operations', ['from' => '2026-08-01']))->assertUnprocessable()->assertJsonValidationErrors('through');
        $this->getJson(route('health.lab.operations', ['from' => '2026-01-01', 'through' => '2026-08-02']))
            ->assertUnprocessable()->assertJsonValidationErrors('through');

        $this->actingAs($technician, 'health');
        $this->get(route('health.lab'))->assertOk()->assertDontSee('Laboratory operations');
        $this->getJson($url)->assertForbidden();
        $this->getJson($exportUrl)->assertForbidden();

        $this->actingAs($owner, 'health');
        $selectedBranch = $this->get(route('health.lab.operations', $filters + ['branch_id' => $second->id]))->assertOk();
        $this->assertEquals($second->id, $selectedBranch->viewData('rows')->sole()->branch_id);
        $this->assertSame(1, (int) $selectedBranch->viewData('rows')->sole()->orders);

        $this->actingAs($foreignOwner, 'health');
        $standalone = $this->get($url)->assertOk()->assertSee('Foreign branch')->assertDontSee('=Synthetic main');
        $this->assertSame(1, (int) $standalone->viewData('rows')->sole()->orders);
        $foreign->update(['health_modules' => json_encode(['accounts'])]);
        HealthModuleService::forget();
        $this->getJson($exportUrl)->assertForbidden();
    }

    public function test_lab_costs_freeze_at_order_placement_and_stay_visible_only_to_authorized_finance_roles(): void
    {
        $company = $this->makeWorkspace('lab', 'LAB-COSTING');
        $branch = Branch::create(['company_id' => $company->id, 'name' => 'Costing branch', 'is_active' => true]);
        $second = Branch::create(['company_id' => $company->id, 'name' => 'Other costing branch', 'is_active' => true]);
        $owner = $this->staff($company, HealthAccessService::ROLE_OWNER);
        $admin = $this->staff($company, 'health_admin');
        $accountant = $this->staff($company, 'health_accountant');
        $supervisor = $this->staff($company, 'health_lab_supervisor');
        DB::table('branch_user')->insert(['branch_id' => $branch->id, 'user_id' => $accountant->id]);
        $patient = $this->patient($company, 'COST-QA-01', $branch);
        $otherPatient = $this->patient($company, 'COST-QA-02', $second);
        $fixture = ['code' => 'COST-QA', 'name' => 'Fictional costed test', 'price' => '900.00', 'is_active' => true];
        $this->operationsOrder($owner, $patient, now()->toDateTimeString());
        $this->operationsOrder($owner, $otherPatient, now()->toDateTimeString());

        $this->actingAs($owner, 'health');
        $this->get(route('health.lab.catalogue'))->assertOk()->assertSee('Estimated cost per test (PKR)');
        $this->postJson(route('health.lab.catalogue.store'), $fixture + ['unit_cost' => '10.501'])
            ->assertUnprocessable()->assertJsonValidationErrors('unit_cost');
        $this->post(route('health.lab.catalogue.store'), $fixture + ['unit_cost' => '125.51'])
            ->assertRedirect(route('health.lab.catalogue'));
        $test = HealthLabTest::query()->sole();
        $request = ['patient_id' => $patient->id, 'branch_id' => $branch->id, 'test_ids' => [$test->id]];
        $place = function (int $expectedCost) use ($request) {
            $this->post(route('health.lab.orders.store'), $request + ['request_key' => (string) Str::uuid()])->assertRedirect();
            $item = HealthLabOrder::query()->latest('id')->firstOrFail()->items()->sole();
            $this->assertSame($expectedCost, $item->unit_cost_paisa);
            $this->assertSame(90000, $item->price_paisa);
            $this->assertArrayNotHasKey('unit_cost_paisa', $item->toArray());

            return $item;
        };
        $first = $place(12551);
        $this->patch(route('health.lab.catalogue.update', $test), $fixture + ['unit_cost' => '25.09'])
            ->assertRedirect(route('health.lab.catalogue'));
        $place(2509);
        $this->assertSame(12551, $first->fresh()->unit_cost_paisa);
        $this->assertDatabaseHas('health_audit_events', ['company_id' => $company->id, 'event' => 'lab.catalogue_cost_changed']);

        $this->actingAs($admin, 'health');
        $this->get(route('health.lab.catalogue.edit', $test))->assertOk()->assertDontSee('Estimated cost per test');
        $this->patchJson(route('health.lab.catalogue.update', $test), $fixture + ['unit_cost' => null])->assertForbidden();
        $this->patch(route('health.lab.catalogue.update', $test), $fixture)->assertRedirect(route('health.lab.catalogue'));
        $this->assertSame(2509, $test->fresh()->unit_cost_paisa);
        $this->getJson(route('health.lab.costs'))->assertForbidden();

        $this->actingAs($supervisor, 'health');
        $this->getJson(route('health.lab.costs'))->assertForbidden();
        $this->get(route('health.lab.operations'))->assertOk()->assertDontSee('Recorded cost estimates')->assertDontSee('150.60');
        $this->assertStringNotContainsString('150.60', $this->get(route('health.lab.operations.export'))->streamedContent());

        $this->actingAs($accountant, 'health');
        $summary = $this->get(route('health.lab.costs'))->assertOk()
            ->assertSee('PKR 150.60')->assertDontSee($patient->mrn)->assertDontSee('Other costing branch');
        $row = $summary->viewData('rows')->sole();
        $this->assertSame([15060, 2, 1], array_map('intval', [$row->known_cost_paisa, $row->costed_tests, $row->uncosted_tests]));
        $this->getJson(route('health.lab.costs', ['branch_id' => $second->id]))->assertForbidden();
        $this->getJson(route('health.lab'))->assertForbidden();

        $this->actingAs($owner, 'health');
        $unknown = $this->get(route('health.lab.costs', ['branch_id' => $second->id]))->assertOk()->assertSee('Unrecorded');
        $this->assertNull($unknown->viewData('rows')->sole()->known_cost_paisa);
    }

    private function operationsOrder(User $owner, HealthPatient $patient, string $created, ?int $department = null, bool $verified = false): void
    {
        $order = HealthLabOrder::create([
            'company_id' => $owner->company_id, 'branch_id' => $patient->branch_id, 'health_department_id' => $department,
            'health_patient_id' => $patient->id, 'order_no' => Str::random(12), 'request_key' => (string) Str::uuid(),
            'request_fingerprint' => str_repeat('a', 64), 'ordered_by' => $owner->id,
        ]);
        $order->forceFill(['created_at' => $created])->save();
        $item = $order->items()->create([
            'company_id' => $owner->company_id, 'health_lab_test_id' => 1,
            'test_code' => 'DEMO', 'test_name' => 'Synthetic report fixture', 'price_paisa' => 1000,
        ]);
        $item->specimens()->create([
            'company_id' => $owner->company_id, 'sequence' => 1, 'barcode' => Str::random(20),
            'status' => $verified ? 'rejected' : 'pending',
        ]);
        if (!$verified) {
            return;
        }
        $specimen = $item->specimens()->create([
            'company_id' => $owner->company_id, 'sequence' => 2, 'barcode' => Str::random(20), 'status' => 'collected',
        ]);
        foreach ([1, 2] as $revision) {
            $item->results()->create([
                'company_id' => $owner->company_id, 'health_lab_specimen_id' => $specimen->id, 'revision' => $revision,
                'value' => 'Synthetic result text', 'entered_by' => $owner->id, 'verified_at' => $revision === 1 ? now() : null,
            ]);
        }
    }

    private function makeWorkspace(string $type, string $name, array $modules = ['lab', 'accounts']): Company
    {
        $company = Company::create([
            'name' => $name,
            'ntn' => $name,
            'product_type' => NestErps::PRODUCT_TYPE,
            NestErps::VERTICAL_COLUMN => NestErps::HEALTH,
            'status' => 'approved',
            'company_status' => 'active',
            'health_org_type' => $type,
            'health_modules' => json_encode($modules),
        ]);
        $plan = PricingPlan::create([
            'name' => 'Synthetic '.$name,
            'product_type' => NestErps::PRODUCT_TYPE,
            NestErps::VERTICAL_COLUMN => NestErps::HEALTH,
            'price' => 50000,
            'invoice_limit' => -1,
            'health_modules' => json_encode($modules),
        ]);
        Subscription::create([
            'company_id' => $company->id,
            'pricing_plan_id' => $plan->id,
            'active' => true,
            'start_date' => now()->subDay(),
            'end_date' => now()->addMonth(),
        ]);

        return $company;
    }

    private function staff(Company $company, string $role): User
    {
        return User::create([
            'company_id' => $company->id,
            'health_role' => $role,
            'name' => 'Synthetic '.$role,
            'language' => 'en',
            'email' => Str::uuid().'@example.test',
            'password' => 'local-test-only',
            'is_active' => true,
        ]);
    }

    private function patient(Company $company, string $mrn, ?Branch $branch = null, bool $confidential = false): HealthPatient
    {
        return HealthPatient::create([
            'company_id' => $company->id,
            'branch_id' => $branch?->id,
            'mrn' => $mrn,
            'name' => 'Synthetic patient '.$mrn,
            'is_active' => true,
            'is_confidential' => $confidential,
        ]);
    }
}

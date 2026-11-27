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

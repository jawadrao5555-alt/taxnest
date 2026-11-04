<?php

namespace App\Http\Controllers\Health;

use App\Models\{HealthCharge, HealthDiagnosticOrder, HealthPatient};
use App\Services\{HealthAccessService, HealthAudit\HealthAuditRecorder, HealthChargeService, HealthModuleService, HealthRecordAccessService};
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

class HealthDiagnosticsController extends HealthPanelController
{
    private function orders(string $service)
    {
        return $this->scope(HealthDiagnosticOrder::where('company_id', $this->company()->id)->where('service', $service));
    }

    private function patient(int $id): HealthPatient
    {
        $patient = HealthPatient::withoutGlobalScopes()->where('company_id', $this->company()->id)->findOrFail($id);
        $this->requireBranch($patient->branch_id);
        if ($patient->is_confidential) {
            abort_unless(HealthRecordAccessService::canOpenClinical($this->user(), $patient, $this->company()), 403);
        }
        return $patient;
    }

    public function index(Request $request)
    {
        $service = $request->route('service');
        $tests = DB::table('health_diagnostic_tests')->where('company_id', $this->company()->id)
            ->where('service', $service)->orderBy('name')->get();
        $orders = $this->orders($service);
        if (!HealthAccessService::isAdministrative($this->user())) {
            // Confidential cases are opened only through a specific, authorized clinical record.
            $orders->whereIn('health_patient_id', HealthPatient::withoutGlobalScopes()
                ->where('company_id', $this->company()->id)->where('is_confidential', false)->select('id'));
        }
        return view('health.diagnostics.index', ['service' => $service, 'tests' => $tests,
            'orders' => $orders->latest('id')->paginate(30), 'capabilities' => HealthAccessService::capabilitiesFor($this->user(), $this->company())]);
    }

    public function catalog(Request $request)
    {
        $service = $request->route('service');
        $company = $this->company();
        $data = $request->validate([
            'code' => ['required', 'alpha_dash', 'max:32', Rule::unique('health_diagnostic_tests')->where('company_id', $company->id)->where('service', $service)],
            'name' => 'required|string|max:140', 'price' => 'required|numeric|decimal:0,2|between:0,9999999999.99',
            'unit' => 'nullable|string|max:40', 'reference_interval' => 'nullable|string|max:120',
        ]);
        DB::table('health_diagnostic_tests')->insert($data + ['company_id' => $company->id, 'service' => $service,
            'created_at' => now(), 'updated_at' => now()]);
        return redirect()->route('health.'.$service);
    }

    public function order(Request $request)
    {
        $service = $request->route('service');
        $data = $request->validate(['patient_id' => 'required|integer', 'test_id' => 'required|integer', 'request_key' => 'required|uuid']);
        $patient = $this->patient($data['patient_id']);
        $company = $this->company();
        $test = DB::table('health_diagnostic_tests')->where('company_id', $company->id)
            ->where('service', $service)->where('id', $data['test_id'])->firstOrFail();
        $department = $this->user()->health_department_id;
        $this->requireDepartment($department);
        $branch = $patient->branch_id ?? (app()->bound('currentBranchId') ? app('currentBranchId') : null);
        $this->requireBranch($branch);
        DB::transaction(function () use ($service, $patient, $company, $test, $department, $branch, $data) {
            $order = HealthDiagnosticOrder::firstOrCreate(['company_id' => $company->id, 'request_key' => $data['request_key']], [
                'branch_id' => $branch, 'health_department_id' => $department, 'health_patient_id' => $patient->id,
                'service' => $service, 'test_id' => $test->id, 'test_name' => $test->name,
                'price' => $test->price, 'unit' => $test->unit, 'reference_interval' => $test->reference_interval,
                'ordered_by' => $this->user()->id, 'status' => 'ordered',
            ]);
            abort_if($order->health_patient_id != $patient->id || $order->test_id != $test->id ||
                $order->branch_id != $branch || $order->health_department_id != $department, 409);
            if (!$order->wasRecentlyCreated) {
                return;
            }
            if ((float) $test->price > 0) {
                abort_unless(HealthModuleService::isEnabled($company, 'accounts'), 403);
                $charge = HealthChargeService::post([
                    'company_id' => $company->id, 'branch_id' => $branch, 'health_department_id' => $department,
                    'health_patient_id' => $patient->id, 'description' => $test->name, 'unit_price' => $test->price,
                    'category' => $service === 'lab' ? HealthCharge::CAT_LAB : HealthCharge::CAT_INVESTIGATION,
                    'source_type' => $service === 'lab' ? HealthCharge::SOURCE_LAB_ORDER : 'imaging_order',
                    'source_id' => $order->id, 'dedupe_key' => 'diagnostic:'.$order->id,
                ], $this->user());
                abort_unless($charge, 409);
            }
            $this->record($order, 'ordered');
        }, 3);
        return redirect()->route('health.'.$service);
    }

    public function collect(Request $request, int $id)
    {
        $data = $request->validate(['accession' => 'required|string|max:80']);
        return $this->advance($request, $id, 'ordered', 'collected', $data);
    }

    public function release(Request $request, int $id)
    {
        $data = $request->validate(['result' => 'required|string|max:12000']);
        return $this->advance($request, $id, 'collected', 'released', $data);
    }

    private function advance(Request $request, int $id, string $from, string $to, array $attributes)
    {
        $service = $request->route('service');
        DB::transaction(function () use ($service, $id, $from, $to, $attributes) {
            $order = $this->orders($service)->lockForUpdate()->findOrFail($id);
            $this->patient($order->health_patient_id);
            if ($order->status === $to && $order->{array_key_first($attributes)} === reset($attributes)) {
                return;
            }
            abort_unless($order->status === $from, 409);
            if (isset($attributes['accession'])) {
                validator($attributes, ['accession' => Rule::unique('health_diagnostic_orders')->where('company_id', $order->company_id)
                    ->where('service', $service)])->validate();
            }
            $order->update($attributes + ['status' => $to, $to.'_at' => now(), $to.'_by' => $this->user()->id]);
            $this->record($order, $to);
        }, 3);
        return redirect()->route('health.'.$service);
    }

    private function record(HealthDiagnosticOrder $order, string $action): void
    {
        HealthAuditRecorder::record('diagnostic.'.$action, ['actor' => $this->user(), 'company_id' => $order->company_id,
            'branch_id' => $order->branch_id, 'category' => 'clinical', 'action' => $action,
            'entity_type' => 'health_diagnostic_orders', 'entity_id' => $order->id, 'health_patient_id' => $order->health_patient_id]);
    }

    public function report(Request $request, int $id)
    {
        $service = $request->route('service');
        abort_unless($this->can($service.'.result') || $this->can('clinical.view'), 403);
        $order = $this->orders($service)->findOrFail($id);
        $patient = $this->patient($order->health_patient_id);
        abort_unless($order->status === 'released', 409);
        return Pdf::loadView('health.diagnostics.report', compact('order', 'patient'))
            ->stream($service.'-'.$order->id.'.pdf', ['Attachment' => false]);
    }
}

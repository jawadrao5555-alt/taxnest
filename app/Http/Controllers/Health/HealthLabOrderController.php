<?php

namespace App\Http\Controllers\Health;

use App\Models\HealthLabOrder;
use App\Models\HealthLabOrderItem;
use App\Models\HealthLabSpecimen;
use App\Models\HealthLabTest;
use App\Models\HealthPatient;
use App\Services\HealthAccessService;
use App\Services\HealthAudit\HealthAuditRecorder;
use App\Services\HealthLabWorkflow;
use App\Services\HealthPatientService;
use App\Services\HealthPlatformService;
use App\Services\HealthScopeService;
use App\Support\MpdfRenderer;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

class HealthLabOrderController extends HealthPanelController
{
    public function create(Request $request, HealthLabWorkflow $workflow)
    {
        $input = $request->validate([
            'q' => ['nullable', 'string', 'max:80'],
            'visit_id' => ['nullable', 'integer', 'min:1'],
        ]);
        $user = $this->user();
        $visit = isset($input['visit_id']) ? $workflow->visitForIntake($user, (int) $input['visit_id']) : null;
        $patients = HealthScopeService::applyBranchScope(HealthPatient::query(), $user)
            ->where('company_id', $user->company_id)->where('is_active', true);
        if (!HealthAccessService::isAdministrative($user)) {
            $patients->where('is_confidential', false);
        }
        HealthPatientService::applySearch($patients, $input['q'] ?? null);

        return view('health.lab.create', [
            'patients' => $visit ? collect([$visit->patient]) : $patients->latest('id')->limit(30)->get(['id', 'name', 'mrn']),
            'visit' => $visit,
            'tests' => HealthLabTest::query()->where('company_id', $user->company_id)
                ->where('is_active', true)->orderBy('name')->get(),
            'branches' => $this->branches(),
            'departments' => HealthScopeService::selectableDepartments($user),
            'defaultBranchId' => HealthPlatformService::activeBranchId() ?? HealthPlatformService::defaultBranchId($this->company()),
            'requestKey' => (string) Str::uuid(),
            'search' => $input['q'] ?? '',
        ]);
    }

    public function store(Request $request, HealthLabWorkflow $workflow)
    {
        $data = $request->validate([
            'patient_id' => ['required', 'integer', 'min:1'],
            'visit_id' => ['nullable', 'integer', 'min:1'],
            'branch_id' => ['nullable', 'integer', 'min:1'],
            'department_id' => ['nullable', 'integer', 'min:1'],
            'test_ids' => ['required', 'array', 'list', 'min:1', 'max:30'],
            'test_ids.*' => ['required', 'integer', 'min:1', 'distinct'],
            'request_key' => ['required', 'uuid'],
        ]);
        $order = $workflow->intake($this->user(), $data);

        return redirect()->route('health.lab.orders.show', $order)
            ->with('success', __('lab_workflow.order_saved'));
    }

    public function show(int $order)
    {
        $record = $this->findOrder($order);

        return response()->view('health.lab.show', [
            'order' => $record,
            'canOpenVisit' => $this->can('clinical.view') || $this->can('nursing.record'),
        ])
            ->header('Cache-Control', 'private, no-store');
    }

    public function sendFeesToBilling(int $order, HealthLabWorkflow $workflow)
    {
        $workflow->sendFeesToBilling($this->user(), $order);

        return redirect()->route('health.lab.orders.show', $order)->with('success', __('lab_workflow.fees_sent'));
    }

    public function collect(Request $request, int $order, int $item, HealthLabWorkflow $workflow)
    {
        $data = $request->validate(['specimen_id' => ['required', 'integer', 'min:1']]);
        $workflow->collect($this->user(), $order, $item, (int) $data['specimen_id']);

        return redirect()->route('health.lab.orders.show', $order)->with('success', __('lab_workflow.collected'));
    }

    public function reject(Request $request, int $order, int $item, HealthLabWorkflow $workflow)
    {
        $data = $request->validate([
            'specimen_id' => ['required', 'integer', 'min:1'],
            'reason' => ['required', 'string', 'max:300'],
        ]);
        $workflow->reject($this->user(), $order, $item, (int) $data['specimen_id'], $data['reason']);

        return redirect()->route('health.lab.orders.show', $order)->with('success', __('lab_workflow.recollected'));
    }

    public function result(Request $request, int $order, int $item, HealthLabWorkflow $workflow)
    {
        $data = $request->validate([
            'specimen_id' => ['required', 'integer', 'min:1'],
            'expected_revision' => ['required', 'integer', 'min:0'],
            'expected_version' => ['required', 'integer', 'min:0'],
            'value' => ['required', 'string', 'max:2000'],
            'unit' => ['nullable', 'string', 'max:50'],
            'reference' => ['nullable', 'string', 'max:140'],
            'amendment_reason' => ['nullable', 'string', 'max:300'],
        ]);
        $workflow->enterResult($this->user(), $order, $item, $data);

        return redirect()->route('health.lab.orders.show', $order)->with('success', __('lab_workflow.result_saved'));
    }

    public function verify(Request $request, int $order, int $item, HealthLabWorkflow $workflow)
    {
        $data = $request->validate([
            'result_id' => ['required', 'integer', 'min:1'],
            'result_version' => ['required', 'integer', 'min:1'],
        ]);
        $workflow->verifyResult($this->user(), $order, $item, (int) $data['result_id'], (int) $data['result_version']);

        return redirect()->route('health.lab.orders.show', $order)->with('success', __('lab_workflow.result_verified'));
    }

    public function label(int $order, int $item, int $specimen)
    {
        $record = $this->findOrder($order);
        $requested = $record->items->firstWhere('id', $item)?->specimens->firstWhere('id', $specimen);
        abort_if(!$requested || $requested->status === HealthLabSpecimen::REJECTED, 404);

        return MpdfRenderer::render('health.lab.label', [
            'specimen' => $requested,
            'order' => $record,
        ], [80, 35], $requested->barcode.'.pdf', true)->header('Cache-Control', 'private, no-store');
    }

    public function report(int $order)
    {
        $record = $this->findOrder($order);
        $lines = $record->items->map(fn (HealthLabOrderItem $item) => [
            'item' => $item,
            'result' => $item->results->first(fn ($result) => $result->verified_at !== null),
        ]);
        abort_if($lines->isEmpty() || $lines->contains(fn (array $line) => !$line['result']), 404);

        HealthAuditRecorder::record('lab.report_opened', [
            'actor' => $this->user(),
            'company_id' => $record->company_id,
            'branch_id' => $record->branch_id,
            'health_department_id' => $record->health_department_id,
            'health_patient_id' => $record->health_patient_id,
            'category' => 'export',
            'action' => 'exported',
            'entity_type' => 'health_lab_orders',
            'entity_id' => $record->id,
            'entity_label' => $record->order_no,
            'sensitive' => true,
        ]);

        return MpdfRenderer::render('health.lab.report', [
            'order' => $record,
            'company' => $this->company(),
            'lines' => $lines,
        ], 'a4', $record->order_no.'.pdf', true)->header('Cache-Control', 'private, no-store');
    }

    private function findOrder(int $order): HealthLabOrder
    {
        $user = $this->user();
        $results = $this->can('lab.result') || $this->can('lab.verify');
        $relations = [
            'patient:id,company_id,name,mrn',
            'items' => fn ($items) => $items->where('company_id', $user->company_id)->withExists('results'),
            'items.specimens' => fn ($specimens) => $specimens->where('company_id', $user->company_id),
        ];
        if ($results || $this->can('lab.report')) {
            $relations['items.results'] = fn ($query) => $query->where('company_id', $user->company_id)
                ->when(!$results, fn ($results) => $results->whereNotNull('verified_at'));
        }

        return HealthLabOrder::visibleTo($user)->with($relations)
            ->withCount(['items', 'items as verified_items_count' => fn ($items) => $items->whereHas(
                'results', fn ($results) => $results->whereNotNull('verified_at')
            )])->findOrFail($order);
    }
}

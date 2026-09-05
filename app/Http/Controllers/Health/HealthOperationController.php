<?php

namespace App\Http\Controllers\Health;

use App\Models\HealthAdmission;
use App\Models\HealthOperation;
use App\Models\HealthOperationTeamMember;
use App\Models\HealthOperationTheatre;
use App\Models\HealthPatient;
use App\Models\HealthProcedure;
use App\Services\HealthOperationService;
use App\Services\HealthRecordAccessService;
use App\Services\HealthScopeService;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Validation\Rule;

/**
 * The operation theatre: the day's list, the procedure catalogue and the
 * theatres themselves.
 *
 * Separate capabilities from the ward (`operations.view` / `operations.manage`)
 * because the people are different — a surgeon writes the operative notes and
 * never touches a bed board, a ward sister moves patients and never books a
 * list — but the same MODULE, since a theatre with nowhere to admit into is
 * not a thing a hospital buys.
 */
class HealthOperationController extends HealthPanelController
{
    /** The theatre list: everything booked, in progress and just finished. */
    public function index(Request $request)
    {
        $user = $this->user();

        $date = $request->filled('date')
            ? Carbon::parse($request->query('date'))->startOfDay()
            : now()->startOfDay();

        $view = $request->query('view', 'day');

        $query = HealthOperation::query()
            ->with([
                'patient:id,name,mrn,gender,age_years',
                'procedure:id,name',
                'theatre:id,name',
                'surgeon:id,name',
                'admission:id,admission_no',
            ]);
        $this->scope($query);
        HealthRecordAccessService::hideConfidential($query, $user, 'health_patient_id');

        if ($view === 'pending') {
            // Booked but never given a slot, plus anything left mid-list. These
            // are the rows that silently rot if the board only ever shows today.
            // ONE grouped where: an OR left at the top level would climb out of
            // the branch/department scope applied above and start showing other
            // people's theatres.
            $query->where(function ($q) {
                $q->where(function ($unslotted) {
                    $unslotted->whereNull('scheduled_start')
                        ->whereIn('status', [HealthOperation::STATUS_SCHEDULED, HealthOperation::STATUS_POSTPONED]);
                })->orWhere('status', HealthOperation::STATUS_IN_PROGRESS);
            })->orderByDesc('id');
        } else {
            $query->whereBetween('scheduled_start', [$date->copy(), $date->copy()->endOfDay()])
                ->orderBy('scheduled_start');
        }

        if ($request->filled('theatre_id')) {
            $query->where('health_operation_theatre_id', (int) $request->query('theatre_id'));
        }
        if ($request->filled('status') && in_array($request->query('status'), HealthOperation::STATUSES, true)) {
            $query->where('status', $request->query('status'));
        }

        $operations = $query->limit(200)->get();

        $theatreQuery = HealthOperationTheatre::query()->where('is_active', true)->orderBy('name');
        HealthScopeService::applyBranchScope($theatreQuery, $user);

        return view('health.operations.index', [
            'operations' => $operations,
            'theatres' => $theatreQuery->get(),
            'date' => $date,
            'view' => $view,
            'procedures' => $this->activeProcedures(),
            'doctors' => $this->selectableDoctors(),
            'openAdmissions' => $this->openAdmissions(),
            'branches' => $this->branches(),
            'mayManage' => $this->can('operations.manage'),
            'statusCounts' => $this->statusCounts($operations),
        ]);
    }

    public function show($id)
    {
        $operation = $this->findOperation($id);
        $operation->load([
            'patient', 'procedure', 'theatre', 'surgeon:id,name', 'anaesthetist:id,name',
            'admission:id,admission_no,status', 'team', 'consumables',
        ]);

        return view('health.operations.show', [
            'operation' => $operation,
            'procedures' => $this->activeProcedures(),
            'theatres' => HealthOperationTheatre::query()->where('is_active', true)->orderBy('name')->get(),
            'doctors' => $this->selectableDoctors(),
            'teamRoles' => HealthOperationTeamMember::ROLES,
            'outcomes' => HealthOperation::OUTCOMES,
            'anaesthesiaTypes' => HealthProcedure::ANAESTHESIA_TYPES,
            'mayManage' => $this->can('operations.manage'),
        ]);
    }

    /* ───────────────── lifecycle ───────────────── */

    public function store(Request $request)
    {
        $this->require('operations.manage');

        $data = $this->validateSchedule($request);

        $patient = HealthPatient::query()->find($data['health_patient_id']);
        if (!$patient || !HealthRecordAccessService::canOpenClinical($this->user(), $patient, $this->company())) {
            return back()->withInput()->with('error', __('health.denied_no_permission'));
        }

        if (!HealthScopeService::canAccessBranch($this->user(), $data['branch_id'] ?? null)) {
            return back()->withInput()->with('error', __('health.dept_branch_not_yours'));
        }

        // A stay named on the booking must be one this person can already
        // reach, or the theatre desk becomes a way around the ward's scope.
        if (!empty($data['health_admission_id'])) {
            $admission = $this->scope(HealthAdmission::query())->find($data['health_admission_id']);
            if (!$admission) {
                return back()->withInput()->with('error', __('health.adm_not_found'));
            }
            $data['health_patient_id'] = $admission->health_patient_id;
        }

        $data['company_id'] = $this->company()->id;

        try {
            $operation = HealthOperationService::schedule($data, $this->user());
        } catch (\RuntimeException $e) {
            return back()->withInput()->with('error', $e->getMessage());
        }

        return redirect()->route('health.operations.show', $operation->id)
            ->with('success', __('health.op_scheduled', ['no' => $operation->operation_no]));
    }

    public function reschedule(Request $request, $id)
    {
        $this->require('operations.manage');
        $operation = $this->findOperation($id);

        $data = $request->validate([
            'scheduled_start' => ['nullable', 'date'],
            'scheduled_end' => ['nullable', 'date'],
            'health_operation_theatre_id' => ['nullable', 'integer', 'exists:health_operation_theatres,id'],
            'primary_surgeon_id' => ['nullable', 'integer', 'exists:health_doctors,id'],
            'anaesthetist_id' => ['nullable', 'integer', 'exists:health_doctors,id'],
            'anaesthesia_type' => ['nullable', Rule::in(HealthProcedure::ANAESTHESIA_TYPES)],
            'urgency' => ['nullable', Rule::in(HealthOperation::URGENCIES)],
            'consent_reference' => ['nullable', 'string', 'max:120'],
            'reschedule_reason' => ['nullable', 'string', 'max:300'],
        ]);

        try {
            HealthOperationService::reschedule($operation, $data, $this->user());
        } catch (\RuntimeException $e) {
            return back()->with('error', $e->getMessage());
        }

        return back()->with('success', __('health.op_rescheduled'));
    }

    public function savePreOp(Request $request, $id)
    {
        $this->require('operations.manage');
        $operation = $this->findOperation($id);

        $request->validate([
            'ticked' => ['nullable', 'array'],
            'pre_op_notes' => ['nullable', 'string', 'max:2000'],
        ]);

        HealthOperationService::savePreOp(
            $operation,
            (array) $request->input('ticked', []),
            $request->input('pre_op_notes'),
            $this->user()
        );

        return back()->with('success', __('health.op_preop_saved'));
    }

    public function start($id)
    {
        $this->require('operations.manage');
        $operation = $this->findOperation($id);

        try {
            HealthOperationService::start($operation, $this->user());
        } catch (\RuntimeException $e) {
            return back()->with('error', $e->getMessage());
        }

        return back()->with('success', __('health.op_started'));
    }

    public function complete(Request $request, $id)
    {
        $this->require('operations.manage');
        $operation = $this->findOperation($id);

        $data = $request->validate([
            'actual_start' => ['nullable', 'date'],
            'actual_end' => ['nullable', 'date'],
            'outcome' => ['required', Rule::in(HealthOperation::OUTCOMES)],
            'operative_notes' => ['nullable', 'string', 'max:8000'],
            'findings' => ['nullable', 'string', 'max:4000'],
            'complications' => ['nullable', 'string', 'max:2000'],
            'blood_loss_ml' => ['nullable', 'integer', 'min:0', 'max:100000'],
            'specimen_sent' => ['nullable', 'boolean'],
            'post_op_instructions' => ['nullable', 'string', 'max:4000'],
            'concession_amount' => ['nullable', 'numeric', 'min:0', 'max:99999999'],
            'concession_reason' => ['nullable', 'string', 'max:300'],
        ]);

        if ((float) ($data['concession_amount'] ?? 0) > 0 && trim((string) ($data['concession_reason'] ?? '')) === '') {
            return back()->with('error', __('health.concession_needs_reason'));
        }

        try {
            HealthOperationService::complete($operation, $data, $this->user());
        } catch (\RuntimeException $e) {
            return back()->with('error', $e->getMessage());
        }

        return back()->with('success', __('health.op_completed'));
    }

    public function cancel(Request $request, $id)
    {
        $this->require('operations.manage');
        $operation = $this->findOperation($id);

        $request->validate([
            'cancel_reason' => ['required', 'string', 'max:300'],
            'postpone' => ['nullable', 'boolean'],
        ]);

        try {
            HealthOperationService::cancel(
                $operation,
                $request->input('cancel_reason'),
                $this->user(),
                (bool) $request->boolean('postpone')
            );
        } catch (\RuntimeException $e) {
            return back()->with('error', $e->getMessage());
        }

        return back()->with('success', $request->boolean('postpone')
            ? __('health.op_postponed')
            : __('health.op_cancelled'));
    }

    public function saveTeam(Request $request, $id)
    {
        $this->require('operations.manage');
        $operation = $this->findOperation($id);

        $request->validate([
            'team' => ['nullable', 'array'],
            'team.*.name' => ['nullable', 'string', 'max:150'],
            'team.*.role' => ['nullable', Rule::in(HealthOperationTeamMember::ROLES)],
            'team.*.health_doctor_id' => ['nullable', 'integer', 'exists:health_doctors,id'],
            'team.*.fee_amount' => ['nullable', 'numeric', 'min:0', 'max:99999999'],
            'team.*.note' => ['nullable', 'string', 'max:300'],
        ]);

        HealthOperationService::saveTeam($operation, (array) $request->input('team', []), $this->user());

        return back()->with('success', __('health.op_team_saved'));
    }

    public function saveConsumables(Request $request, $id)
    {
        $this->require('operations.manage');
        $operation = $this->findOperation($id);

        $request->validate([
            'consumables' => ['nullable', 'array'],
            'consumables.*.item_name' => ['nullable', 'string', 'max:200'],
            'consumables.*.unit' => ['nullable', 'string', 'max:20'],
            'consumables.*.quantity' => ['nullable', 'numeric', 'min:0', 'max:99999'],
            'consumables.*.unit_price' => ['nullable', 'numeric', 'min:0', 'max:99999999'],
            'consumables.*.is_billable' => ['nullable', 'boolean'],
            'consumables.*.note' => ['nullable', 'string', 'max:300'],
        ]);

        HealthOperationService::saveConsumables($operation, (array) $request->input('consumables', []));

        return back()->with('success', __('health.op_consumables_saved'));
    }

    /* ───────────────── catalogue & theatres ───────────────── */

    public function catalogue()
    {
        $this->require('operations.view');

        return view('health.operations.catalogue', [
            'procedures' => HealthProcedure::query()->with('department:id,name')->orderBy('name')->get(),
            'theatres' => HealthOperationTheatre::query()->with('branch:id,name')->orderBy('name')->get(),
            'departments' => HealthScopeService::selectableDepartments($this->user()),
            'branches' => $this->branches(),
            'anaesthesiaTypes' => HealthProcedure::ANAESTHESIA_TYPES,
            'mayManage' => $this->can('operations.manage'),
        ]);
    }

    public function storeProcedure(Request $request)
    {
        $this->require('operations.manage');
        $data = $this->validateProcedure($request);

        $data['company_id'] = $this->company()->id;
        $data['is_active'] = true;
        HealthProcedure::create($data);

        return redirect()->route('health.operations.catalogue')->with('success', __('health.procedure_created'));
    }

    public function updateProcedure(Request $request, $id)
    {
        $this->require('operations.manage');
        $procedure = HealthProcedure::query()->findOrFail($id);
        $procedure->fill($this->validateProcedure($request, $procedure->id))->save();

        return redirect()->route('health.operations.catalogue')->with('success', __('health.procedure_updated'));
    }

    public function toggleProcedure($id)
    {
        $this->require('operations.manage');
        $procedure = HealthProcedure::query()->findOrFail($id);
        $procedure->is_active = !$procedure->is_active;
        $procedure->save();

        return back()->with('success', __('health.saved'));
    }

    public function storeTheatre(Request $request)
    {
        $this->require('operations.manage');
        $data = $this->validateTheatre($request);

        if (!HealthScopeService::canAccessBranch($this->user(), $data['branch_id'] ?? null)) {
            return back()->withInput()->with('error', __('health.dept_branch_not_yours'));
        }

        $data['company_id'] = $this->company()->id;
        $data['is_active'] = true;
        HealthOperationTheatre::create($data);

        return redirect()->route('health.operations.catalogue')->with('success', __('health.theatre_created'));
    }

    public function updateTheatre(Request $request, $id)
    {
        $this->require('operations.manage');
        $theatre = $this->findTheatre($id);
        $data = $this->validateTheatre($request, $theatre->id);

        if (!HealthScopeService::canAccessBranch($this->user(), $data['branch_id'] ?? null)) {
            return back()->withInput()->with('error', __('health.dept_branch_not_yours'));
        }

        $theatre->fill($data)->save();

        return redirect()->route('health.operations.catalogue')->with('success', __('health.theatre_updated'));
    }

    public function toggleTheatre($id)
    {
        $this->require('operations.manage');
        $theatre = $this->findTheatre($id);

        // Retiring a theatre with a live list on it would hide bookings the
        // hospital is still expected to run.
        if ($theatre->is_active) {
            $booked = HealthOperation::query()
                ->where('health_operation_theatre_id', $theatre->id)
                ->whereIn('status', HealthOperation::BLOCKING_STATUSES)
                ->count();

            if ($booked > 0) {
                return back()->with('error', __('health.theatre_has_bookings', ['count' => $booked]));
            }
        }

        $theatre->is_active = !$theatre->is_active;
        $theatre->save();

        return back()->with('success', __('health.saved'));
    }

    /* ───────────────── internals ───────────────── */

    /**
     * A theatre is reached by id from a form post, so its own branch has to be
     * re-checked here: a manager confined to one site must not be able to
     * rename, retire or re-home another site's theatre by posting its id.
     */
    private function findTheatre($id): HealthOperationTheatre
    {
        $theatre = HealthOperationTheatre::query()->findOrFail($id);

        if (!HealthScopeService::canAccessBranch($this->user(), $theatre->branch_id)) {
            abort(403, __('health.denied_no_permission'));
        }

        return $theatre;
    }

    private function findOperation($id): HealthOperation
    {
        $query = HealthOperation::query();
        $this->scope($query);
        HealthRecordAccessService::hideConfidential($query, $this->user(), 'health_patient_id');

        return $query->findOrFail($id);
    }

    private function activeProcedures()
    {
        return HealthProcedure::query()
            ->where('is_active', true)
            ->orderBy('name')
            ->get(['id', 'name', 'base_price', 'is_package', 'package_price', 'default_anaesthesia', 'estimated_minutes', 'health_department_id']);
    }

    private function openAdmissions()
    {
        $query = HealthAdmission::query()
            ->whereIn('status', HealthAdmission::OPEN_STATUSES)
            ->with('patient:id,name,mrn')
            ->orderByDesc('id')
            ->limit(300);
        $this->scope($query);
        HealthRecordAccessService::hideConfidential($query, $this->user(), 'health_patient_id');

        return $query->get(['id', 'admission_no', 'health_patient_id', 'branch_id']);
    }

    private function statusCounts($operations): array
    {
        $counts = array_fill_keys(HealthOperation::STATUSES, 0);
        foreach ($operations as $operation) {
            if (array_key_exists($operation->status, $counts)) {
                $counts[$operation->status]++;
            }
        }

        return $counts;
    }

    private function validateSchedule(Request $request): array
    {
        return $request->validate([
            'health_patient_id' => ['required_without:health_admission_id', 'nullable', 'integer', 'exists:health_patients,id'],
            'health_admission_id' => ['nullable', 'integer', 'exists:health_admissions,id'],
            'health_procedure_id' => ['nullable', 'integer', 'exists:health_procedures,id'],
            'health_operation_theatre_id' => ['nullable', 'integer', 'exists:health_operation_theatres,id'],
            'title' => ['required_without:health_procedure_id', 'nullable', 'string', 'max:200'],
            'urgency' => ['required', Rule::in(HealthOperation::URGENCIES)],
            'scheduled_start' => ['nullable', 'date'],
            'scheduled_end' => ['nullable', 'date', 'after:scheduled_start'],
            'primary_surgeon_id' => ['nullable', 'integer', 'exists:health_doctors,id'],
            'anaesthetist_id' => ['nullable', 'integer', 'exists:health_doctors,id'],
            'anaesthesia_type' => ['nullable', Rule::in(HealthProcedure::ANAESTHESIA_TYPES)],
            'consent_reference' => ['nullable', 'string', 'max:120'],
            'price' => ['nullable', 'numeric', 'min:0', 'max:99999999'],
            'branch_id' => ['nullable', 'integer', 'exists:branches,id'],
            'health_department_id' => ['nullable', 'integer', 'exists:health_departments,id'],
        ]);
    }

    private function validateProcedure(Request $request, $ignoreId = null): array
    {
        $companyId = $this->company()->id;

        return $request->validate([
            'name' => ['required', 'string', 'max:200'],
            'code' => [
                'nullable', 'string', 'max:40',
                Rule::unique('health_procedures', 'code')
                    ->where(fn ($q) => $q->where('company_id', $companyId))
                    ->ignore($ignoreId),
            ],
            'category' => ['nullable', 'string', 'max:80'],
            'description' => ['nullable', 'string', 'max:1000'],
            'health_department_id' => ['nullable', 'integer', 'exists:health_departments,id'],
            'base_price' => ['required', 'numeric', 'min:0', 'max:99999999'],
            'is_package' => ['nullable', 'boolean'],
            'package_price' => ['nullable', 'numeric', 'min:0', 'max:99999999'],
            'package_includes' => ['nullable', 'string', 'max:1000'],
            'default_anaesthesia' => ['nullable', Rule::in(HealthProcedure::ANAESTHESIA_TYPES)],
            'estimated_minutes' => ['nullable', 'integer', 'min:0', 'max:1440'],
            'pre_op_checklist' => ['nullable', 'string', 'max:2000'],
        ]);
    }

    private function validateTheatre(Request $request, $ignoreId = null): array
    {
        $companyId = $this->company()->id;

        return $request->validate([
            'name' => ['required', 'string', 'max:120'],
            'code' => [
                'nullable', 'string', 'max:40',
                Rule::unique('health_operation_theatres', 'code')
                    ->where(fn ($q) => $q->where('company_id', $companyId))
                    ->ignore($ignoreId),
            ],
            'branch_id' => ['nullable', 'integer', 'exists:branches,id'],
            'turnaround_minutes' => ['required', 'integer', 'min:0', 'max:480'],
            'notes' => ['nullable', 'string', 'max:500'],
        ]);
    }
}

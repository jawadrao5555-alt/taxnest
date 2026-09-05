<?php

namespace App\Http\Controllers\Health;

use App\Models\HealthAdmission;
use App\Models\HealthAdmissionCharge;
use App\Models\HealthAdmissionPayment;
use App\Models\HealthBed;
use App\Models\HealthPatient;
use App\Models\HealthWard;
use App\Services\HealthIpdBillingService;
use App\Services\HealthIpdService;
use App\Services\HealthRecordAccessService;
use App\Services\HealthScopeService;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * The inpatient desk: the live bed board, the stays running on it, and every
 * charge-producing event attached to each one.
 *
 * Three capabilities meet on this screen and they are kept apart on purpose:
 *
 *   ipd.manage     move patients — admit, transfer, round, request discharge
 *   ipd.charge     post money — charges, reversals, advances
 *   ipd.discharge  clear the bill and let the patient out
 *
 * That split is why the accounts counter can reach a stay at all. Somebody
 * holding only the money capabilities never sees the clinical narrative:
 * `$maySeeClinical` is computed once here and every view honours it, so a
 * cashier taking an advance does not read a diagnosis on the way past.
 */
class HealthAdmissionController extends HealthPanelController
{
    /**
     * Bed board + the admissions running on it.
     *
     * Both on one screen because "which beds are free" and "who is in the ward"
     * are the same question asked from two ends, and a ward clerk should not
     * have to hold two tabs open to answer it.
     */
    public function index(Request $request)
    {
        $user = $this->user();

        $wardQuery = HealthWard::query()->where('is_active', true)->orderBy('name');
        $wards = $this->scope($wardQuery)->get();

        $bedQuery = HealthBed::query()
            ->where('is_active', true)
            ->with([
                'ward:id,name,type,gender_policy,daily_rate,nursing_daily_rate',
                'room:id,name,daily_rate,nursing_daily_rate',
                'admission:id,admission_no,health_patient_id,admitted_at,care_status,status',
                'admission.patient:id,name,mrn,gender,age_years',
            ])
            ->orderBy('code');
        HealthScopeService::applyBranchScope($bedQuery, $user);

        if ($request->filled('ward_id')) {
            $bedQuery->where('health_ward_id', (int) $request->query('ward_id'));
        }

        $beds = $bedQuery->get();

        $status = $request->query('status', 'open');
        $admissionQuery = HealthAdmission::query()
            ->with(['patient:id,name,mrn,gender,age_years,phone', 'doctor:id,name', 'bed:id,code', 'ward:id,name'])
            ->orderByDesc('id');
        $this->scope($admissionQuery);
        HealthRecordAccessService::hideConfidential($admissionQuery, $user, 'health_patient_id');

        if ($status === 'open') {
            $admissionQuery->whereIn('status', HealthAdmission::OPEN_STATUSES);
        } elseif ($status === 'requested') {
            $admissionQuery->where('status', HealthAdmission::STATUS_REQUESTED);
        } elseif (in_array($status, HealthAdmission::STATUSES, true)) {
            $admissionQuery->where('status', $status);
        }

        if ($request->filled('q')) {
            $term = trim((string) $request->query('q'));
            $admissionQuery->where(function ($q) use ($term) {
                $q->where('admission_no', 'like', "%{$term}%")
                    ->orWhereIn('health_patient_id', HealthPatient::query()
                        ->where(function ($p) use ($term) {
                            $p->where('name', 'like', "%{$term}%")
                                ->orWhere('mrn', 'like', "%{$term}%")
                                ->orWhere('phone', 'like', "%{$term}%");
                        })
                        ->limit(200)
                        ->pluck('id'));
            });
        }

        $admissions = $admissionQuery->paginate(30)->withQueryString();

        return view('health.ipd.index', [
            'beds' => $beds,
            'wards' => $wards,
            'admissions' => $admissions,
            'status' => $status,
            'occupancy' => $this->occupancy($beds),
            'mayManage' => $this->can('ipd.manage'),
            'mayCharge' => $this->can('ipd.charge'),
            'mayDischarge' => $this->can('ipd.discharge'),
            'mayManageWards' => $this->can('wards.manage'),
            'maySeeClinical' => $this->maySeeClinical(),
            'doctors' => $this->selectableDoctors(),
            'branches' => $this->branches(),
            'departments' => HealthScopeService::selectableDepartments($user),
        ]);
    }

    /** One stay: timeline, ledger, operations, and everything still owed. */
    public function show($id)
    {
        $admission = $this->findAdmission($id);

        $admission->load([
            'patient', 'doctor:id,name', 'bed:id,code,health_ward_id', 'ward:id,name',
            'branch:id,name', 'department:id,name',
        ]);

        $charges = $admission->charges()
            ->orderByDesc('charge_date')
            ->orderByDesc('id')
            ->get();

        $payments = $admission->payments()->orderByDesc('id')->get();

        $events = $admission->events()
            ->orderByDesc('occurred_at')
            ->orderByDesc('id')
            ->limit(200)
            ->get();

        $operations = $admission->operations()
            ->with(['surgeon:id,name', 'theatre:id,name'])
            ->orderByDesc('id')
            ->get();

        return view('health.ipd.show', [
            'admission' => $admission,
            'charges' => $charges,
            'payments' => $payments,
            'events' => $events,
            'operations' => $operations,
            'summary' => HealthIpdBillingService::summary($admission),
            'blockers' => HealthIpdBillingService::clearanceBlockers($admission),
            'assignableBeds' => $admission->isOpen() || $admission->status === HealthAdmission::STATUS_REQUESTED
                ? HealthIpdService::assignableBeds($admission, $this->user())
                : collect(),
            'mayManage' => $this->can('ipd.manage'),
            'mayCharge' => $this->can('ipd.charge'),
            'mayDischarge' => $this->can('ipd.discharge'),
            'maySeeClinical' => $this->maySeeClinical(),
            'chargeCategories' => HealthAdmissionCharge::MANUAL_CATEGORIES,
            'paymentMethods' => HealthAdmissionPayment::METHODS,
        ]);
    }

    /* ───────────────── lifecycle ───────────────── */

    public function store(Request $request)
    {
        $this->require('ipd.manage');

        $data = $request->validate([
            'health_patient_id' => ['required', 'integer', 'exists:health_patients,id'],
            'health_doctor_id' => ['nullable', 'integer', 'exists:health_doctors,id'],
            'health_visit_id' => ['nullable', 'integer'],
            'branch_id' => ['nullable', 'integer', 'exists:branches,id'],
            'health_department_id' => ['nullable', 'integer', 'exists:health_departments,id'],
            'admission_type' => ['required', Rule::in(HealthAdmission::TYPES)],
            'reason' => ['nullable', 'string', 'max:500'],
            'provisional_diagnosis' => ['nullable', 'string', 'max:500'],
            'estimated_days' => ['nullable', 'integer', 'min:0', 'max:365'],
            'estimated_cost' => ['nullable', 'numeric', 'min:0', 'max:99999999'],
            'deposit_required' => ['nullable', 'numeric', 'min:0', 'max:99999999'],
            'attendant_name' => ['nullable', 'string', 'max:150'],
            'attendant_phone' => ['nullable', 'string', 'max:32'],
            'attendant_relation' => ['nullable', 'string', 'max:60'],
            'payer_type' => ['required', Rule::in(HealthAdmission::PAYER_TYPES)],
            'payer_name' => ['nullable', 'string', 'max:150'],
            'payer_reference' => ['nullable', 'string', 'max:80'],
            'health_bed_id' => ['nullable', 'integer', 'exists:health_beds,id'],
        ]);

        if (!HealthScopeService::canAccessBranch($this->user(), $data['branch_id'] ?? null)) {
            return back()->withInput()->with('error', __('health.dept_branch_not_yours'));
        }

        // A patient the signed-in person cannot open must not become reachable
        // by admitting them: findOrFail through the scoped query, not by id.
        $patient = HealthPatient::query()->find($data['health_patient_id']);
        if (!$patient || !HealthRecordAccessService::canOpenClinical($this->user(), $patient, $this->company())) {
            return back()->withInput()->with('error', __('health.denied_no_permission'));
        }

        // One open stay per patient. Two live admissions for one person means
        // two ledgers, two bed-days and a discharge that clears only half.
        $alreadyIn = HealthAdmission::query()
            ->where('health_patient_id', $patient->id)
            ->whereIn('status', array_merge(HealthAdmission::OPEN_STATUSES, [HealthAdmission::STATUS_REQUESTED]))
            ->first();

        if ($alreadyIn) {
            return redirect()->route('health.ipd.show', $alreadyIn->id)
                ->with('error', __('health.adm_already_open', ['no' => $alreadyIn->admission_no]));
        }

        $data['company_id'] = $this->company()->id;
        $admission = HealthIpdService::request($data, $this->user());

        // "Admit straight into this bed" is the emergency path — the desk
        // should not have to save a request and then open it again.
        if (!empty($data['health_bed_id'])) {
            try {
                HealthIpdService::admit($admission, (int) $data['health_bed_id'], $this->user());
            } catch (\RuntimeException $e) {
                return redirect()->route('health.ipd.show', $admission->id)->with('error', $e->getMessage());
            }
        }

        return redirect()->route('health.ipd.show', $admission->id)
            ->with('success', __('health.adm_created', ['no' => $admission->admission_no]));
    }

    public function admit(Request $request, $id)
    {
        $this->require('ipd.manage');
        $admission = $this->findAdmission($id);

        $request->validate(['health_bed_id' => ['required', 'integer', 'exists:health_beds,id']]);

        try {
            HealthIpdService::admit($admission, (int) $request->input('health_bed_id'), $this->user());
        } catch (\RuntimeException $e) {
            return back()->with('error', $e->getMessage());
        }

        return back()->with('success', __('health.adm_admitted'));
    }

    public function reserve(Request $request, $id)
    {
        $this->require('ipd.manage');
        $admission = $this->findAdmission($id);

        $request->validate(['health_bed_id' => ['required', 'integer', 'exists:health_beds,id']]);

        try {
            HealthIpdService::reserveBed($admission, (int) $request->input('health_bed_id'), $this->user());
        } catch (\RuntimeException $e) {
            return back()->with('error', $e->getMessage());
        }

        return back()->with('success', __('health.bed_reserved'));
    }

    public function transfer(Request $request, $id)
    {
        $this->require('ipd.manage');
        $admission = $this->findAdmission($id);

        $request->validate([
            'health_bed_id' => ['required', 'integer', 'exists:health_beds,id'],
            'note' => ['nullable', 'string', 'max:300'],
        ]);

        try {
            HealthIpdService::transfer($admission, (int) $request->input('health_bed_id'), $this->user(), $request->input('note'));
        } catch (\RuntimeException $e) {
            return back()->with('error', $e->getMessage());
        }

        return back()->with('success', __('health.adm_transferred'));
    }

    public function recordCare(Request $request, $id)
    {
        $this->require('ipd.manage');
        $admission = $this->findAdmission($id);

        $request->validate([
            'care_status' => ['required', Rule::in(HealthAdmission::CARE_STATUSES)],
            'care_note' => ['nullable', 'string', 'max:2000'],
        ]);

        HealthIpdService::recordCare($admission, $this->user(), $request->input('care_status'), $request->input('care_note'));

        return back()->with('success', __('health.adm_care_saved'));
    }

    public function requestDischarge(Request $request, $id)
    {
        $this->require('ipd.manage');
        $admission = $this->findAdmission($id);

        $data = $request->validate([
            'discharge_type' => ['required', Rule::in(HealthAdmission::DISCHARGE_TYPES)],
            'final_diagnosis' => ['nullable', 'string', 'max:500'],
            'discharge_summary' => ['nullable', 'string', 'max:5000'],
            'discharge_advice' => ['nullable', 'string', 'max:5000'],
            'follow_up_date' => ['nullable', 'date'],
        ]);

        try {
            HealthIpdService::requestDischarge($admission, $this->user(), $data);
        } catch (\RuntimeException $e) {
            return back()->with('error', $e->getMessage());
        }

        return back()->with('success', __('health.adm_discharge_requested'));
    }

    /** Accounts signs the bill off. Separate from letting the patient out. */
    public function clear(Request $request, $id)
    {
        $this->require('ipd.discharge');
        $admission = $this->findAdmission($id);

        $data = $request->validate([
            'concession_amount' => ['nullable', 'numeric', 'min:0', 'max:99999999'],
            'concession_reason' => ['nullable', 'string', 'max:300'],
        ]);

        $concession = (float) ($data['concession_amount'] ?? 0);
        if ($concession > 0 && trim((string) ($data['concession_reason'] ?? '')) === '') {
            return back()->with('error', __('health.concession_needs_reason'));
        }

        HealthIpdService::clear($admission, $this->user(), $concession, $data['concession_reason'] ?? null);

        return back()->with('success', __('health.adm_cleared'));
    }

    public function discharge(Request $request, $id)
    {
        $this->require('ipd.discharge');
        $admission = $this->findAdmission($id);

        $data = $request->validate([
            'discharge_type' => ['nullable', Rule::in(HealthAdmission::DISCHARGE_TYPES)],
            'final_diagnosis' => ['nullable', 'string', 'max:500'],
            'discharge_summary' => ['nullable', 'string', 'max:5000'],
            'discharge_advice' => ['nullable', 'string', 'max:5000'],
            'follow_up_date' => ['nullable', 'date'],
            'force' => ['nullable', 'boolean'],
            'force_reason' => ['nullable', 'string', 'max:300'],
        ]);

        // Releasing against an unpaid bill is allowed — a death or an LAMA
        // cannot wait for a payment — but never silently: it needs a reason,
        // and the reason lands on the timeline.
        $force = (bool) ($data['force'] ?? false);
        if ($force && trim((string) ($data['force_reason'] ?? '')) === '') {
            return back()->with('error', __('health.discharge_force_needs_reason'));
        }

        try {
            HealthIpdService::discharge($admission, $this->user(), $data + ['force' => $force]);
        } catch (\RuntimeException $e) {
            return back()->with('error', $e->getMessage());
        }

        if ($force) {
            HealthIpdService::event($admission->refresh(), \App\Models\HealthAdmissionEvent::CLEARED, $this->user(), [
                'note' => $data['force_reason'],
                'meta' => ['forced_release' => true],
            ]);
        }

        return redirect()->route('health.ipd.show', $admission->id)->with('success', __('health.adm_discharged'));
    }

    public function cancel(Request $request, $id)
    {
        $this->require('ipd.manage');
        $admission = $this->findAdmission($id);

        $request->validate(['cancel_reason' => ['required', 'string', 'max:300']]);

        try {
            HealthIpdService::cancel($admission, $this->user(), $request->input('cancel_reason'));
        } catch (\RuntimeException $e) {
            return back()->with('error', $e->getMessage());
        }

        return back()->with('success', __('health.adm_cancelled'));
    }

    /* ───────────────── money ───────────────── */

    public function storeCharge(Request $request, $id)
    {
        $this->require('ipd.charge');
        $admission = $this->findAdmission($id);

        if (!$admission->isOpen() && $admission->status !== HealthAdmission::STATUS_REQUESTED) {
            return back()->with('error', __('health.charge_stay_closed'));
        }

        $data = $request->validate([
            // Room and nursing are absent from MANUAL_CATEGORIES on purpose:
            // those come from the bed the patient is actually in, and a
            // hand-typed room-day would sit next to the automatic one with no
            // way to tell which is real.
            'category' => ['required', Rule::in(HealthAdmissionCharge::MANUAL_CATEGORIES)],
            'description' => ['required', 'string', 'max:300'],
            'reference' => ['nullable', 'string', 'max:120'],
            'unit_price' => ['required', 'numeric', 'min:0', 'max:99999999'],
            'quantity' => ['required', 'numeric', 'min:0.01', 'max:99999'],
            'concession_amount' => ['nullable', 'numeric', 'min:0', 'max:99999999'],
            'concession_reason' => ['nullable', 'string', 'max:300'],
            'charge_date' => ['nullable', 'date'],
        ]);

        // Only somebody who may clear a bill may write a concession off it.
        if ((float) ($data['concession_amount'] ?? 0) > 0 && !$this->can('ipd.discharge')) {
            return back()->with('error', __('health.concession_not_allowed'));
        }

        HealthIpdBillingService::postCharge($admission, $data, $this->user());

        return back()->with('success', __('health.charge_posted'));
    }

    public function reverseCharge(Request $request, $id, $chargeId)
    {
        $this->require('ipd.charge');
        $admission = $this->findAdmission($id);

        $charge = HealthAdmissionCharge::query()
            ->where('health_admission_id', $admission->id)
            ->findOrFail($chargeId);

        $request->validate(['reversal_reason' => ['required', 'string', 'max:300']]);

        HealthIpdBillingService::reverseCharge($charge, $this->user(), $request->input('reversal_reason'));

        return back()->with('success', __('health.charge_reversed'));
    }

    public function storePayment(Request $request, $id)
    {
        $this->require('ipd.charge');
        $admission = $this->findAdmission($id);

        $data = $request->validate([
            'kind' => ['required', Rule::in(HealthAdmissionPayment::KINDS)],
            'amount' => ['required', 'numeric', 'min:0.01', 'max:99999999'],
            'method' => ['required', Rule::in(HealthAdmissionPayment::METHODS)],
            'reference' => ['nullable', 'string', 'max:120'],
            'note' => ['nullable', 'string', 'max:300'],
        ]);

        HealthIpdBillingService::recordPayment($admission, $data, $this->user());

        return back()->with('success', $data['kind'] === HealthAdmissionPayment::KIND_REFUND
            ? __('health.refund_recorded')
            : __('health.advance_recorded'));
    }

    /**
     * Bring the bed-days up to date by hand.
     *
     * The daily command does this for everybody overnight; this button is what
     * a ward clerk presses when the bill has to be right NOW.
     */
    public function runDailyCharges($id)
    {
        $this->require('ipd.charge');
        $admission = $this->findAdmission($id);

        $count = HealthIpdBillingService::postDailyCharges($admission, $this->user());

        return back()->with('success', __('health.daily_charges_run', ['count' => $count]));
    }

    /* ───────────────── internals ───────────────── */

    private function findAdmission($id): HealthAdmission
    {
        $query = HealthAdmission::query();
        $this->scope($query);
        HealthRecordAccessService::hideConfidential($query, $this->user(), 'health_patient_id');

        return $query->findOrFail($id);
    }

    /**
     * May this person read the clinical side of a stay?
     *
     * The money capabilities alone are not enough. An accountant clearing a
     * bill has no business reading the diagnosis on the way past, and the views
     * hide those fields rather than the controller refusing the whole screen.
     */
    private function maySeeClinical(): bool
    {
        return $this->can('ipd.manage') || $this->can('clinical.view') || $this->can('nursing.record');
    }

    /** Live bed counts for the board header. */
    private function occupancy($beds): array
    {
        $counts = [
            'total' => $beds->count(),
            'occupied' => 0,
            'available' => 0,
            'reserved' => 0,
            'cleaning' => 0,
            'blocked' => 0,
        ];

        foreach ($beds as $bed) {
            if (array_key_exists($bed->status, $counts)) {
                $counts[$bed->status]++;
            }
        }

        $counts['rate'] = $counts['total'] > 0
            ? round(($counts['occupied'] / $counts['total']) * 100, 1)
            : 0.0;

        return $counts;
    }
}

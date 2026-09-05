<?php

namespace App\Http\Controllers\Health;

use App\Models\HealthAppointment;
use App\Models\HealthPatient;
use App\Models\HealthPrescription;
use App\Models\HealthVisit;
use App\Services\HealthAccessService;
use App\Services\HealthPatientService;
use App\Services\HealthPlatformService;
use App\Services\HealthRecordAccessService;
use App\Services\HealthScopeService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Schema;
use Illuminate\Validation\Rule;

/**
 * Patient identity: register once, find forever.
 *
 * Registration is the one screen the whole product rests on. If reception
 * cannot find the file they made last month, they make a second one, and from
 * that moment the patient has two halves of a history and neither is complete.
 * So this controller spends most of its effort on FINDING rather than creating:
 * a search that matches on record number, name, phone or CNIC however it is
 * typed, and a duplicate check that puts the existing file in front of the desk
 * before a new one is opened.
 */
class HealthPatientController extends HealthPanelController
{
    private const PER_PAGE = 25;

    public function index(Request $request)
    {
        $query = HealthPatient::query()
            ->with(['branch:id,name'])
            // Departments do not apply to the patient register: a person is
            // registered with the ORGANISATION, not with a ward. Only the branch
            // boundary is meaningful here.
            ->orderByDesc('id');

        HealthScopeService::applyBranchScope($query, $this->user());
        HealthPatientService::applySearch($query, $request->query('q'));

        $status = $request->query('status', 'active');
        if ($status === 'active') {
            $query->where('is_active', true);
        } elseif ($status === 'archived') {
            $query->where('is_active', false);
        }

        $patients = $query->paginate(self::PER_PAGE)->withQueryString();

        return view('health.patients.index', [
            'patients' => $patients,
            'q' => (string) $request->query('q', ''),
            'status' => $status,
            'canManage' => $this->can('patients.manage'),
        ]);
    }

    public function create(Request $request)
    {
        $this->require('patients.manage');

        return view('health.patients.form', [
            'patient' => null,
            'branches' => $this->branches(),
            'defaultBranchId' => HealthPlatformService::activeBranchId() ?? HealthPlatformService::defaultBranchId($this->company()),
            'duplicates' => collect(),
        ]);
    }

    public function store(Request $request)
    {
        $this->require('patients.manage');

        $company = $this->company();
        $data = $this->validatePatient($request);

        if (!HealthScopeService::canAccessBranch($this->user(), $data['branch_id'] ?? null)) {
            return back()->withInput()->with('error', __('health.dept_branch_not_yours'));
        }

        $duplicates = HealthPatientService::findDuplicates((int) $company->id, $data);

        // A CNIC collision is refused outright — it is a national identifier, so
        // two active files carrying the same one are the same person, full stop.
        $hard = $duplicates->firstWhere('hard', true);
        if ($hard) {
            return back()->withInput()->with('error', __('health.patient_dup_cnic', [
                'mrn' => $hard['patient']->mrn,
                'name' => $hard['patient']->name,
            ]));
        }

        // Everything else is a warning the desk must actually look at once. The
        // form posts back with `confirm_new=1` when they have.
        if ($duplicates->isNotEmpty() && !$request->boolean('confirm_new')) {
            return back()->withInput()->with('health_duplicates', $duplicates->map(fn ($m) => [
                'id' => (int) $m['patient']->id,
                'mrn' => $m['patient']->mrn,
                'name' => $m['patient']->name,
                'phone' => $m['patient']->phone,
                'reason' => $m['reason'],
            ])->all());
        }

        $attributes = $this->attributesFrom($data);
        $attributes['registered_by'] = $this->user()?->id;
        $attributes['is_active'] = true;
        $attributes = $this->withConsentStamp($attributes, $data, null);

        $patient = HealthPatientService::register((int) $company->id, $attributes);

        return redirect()->route('health.patients.show', $patient->id)
            ->with('success', __('health.patient_registered', ['mrn' => $patient->mrn]));
    }

    public function edit($id)
    {
        $this->require('patients.manage');
        $patient = $this->findPatient($id);

        return view('health.patients.form', [
            'patient' => $patient,
            'branches' => $this->branches(),
            'defaultBranchId' => $patient->branch_id,
            'duplicates' => collect(),
        ]);
    }

    public function update(Request $request, $id)
    {
        $this->require('patients.manage');
        $patient = $this->findPatient($id);

        $data = $this->validatePatient($request, $patient->id);

        if (!HealthScopeService::canAccessBranch($this->user(), $data['branch_id'] ?? null)) {
            return back()->withInput()->with('error', __('health.dept_branch_not_yours'));
        }

        $duplicates = HealthPatientService::findDuplicates((int) $patient->company_id, $data, (int) $patient->id);
        $hard = $duplicates->firstWhere('hard', true);
        if ($hard) {
            return back()->withInput()->with('error', __('health.patient_dup_cnic', [
                'mrn' => $hard['patient']->mrn,
                'name' => $hard['patient']->name,
            ]));
        }

        $attributes = $this->withConsentStamp($this->attributesFrom($data), $data, $patient);
        $patient->fill($attributes);
        $patient->save();

        return redirect()->route('health.patients.show', $patient->id)
            ->with('success', __('health.patient_updated'));
    }

    /**
     * The patient file: demographics, consent, and the chronological history.
     *
     * The history is assembled from the encounters themselves rather than from
     * a separate event log. An event log would be a second source of truth for
     * "what happened to this patient", and the day the two disagree is the day
     * nobody trusts either.
     */
    public function show($id)
    {
        $patient = $this->findPatient($id);
        $user = $this->user();
        $company = $this->company();

        $canSeeClinical = HealthRecordAccessService::canOpenClinical($user, $patient, $company);

        $visits = collect();
        $prescriptions = collect();
        if ($canSeeClinical || $this->can('appointments.view')) {
            $visitQuery = HealthVisit::query()
                ->where('health_patient_id', $patient->id)
                ->with(['doctor:id,name,specialty', 'branch:id,name', 'department:id,name'])
                ->orderByDesc('visit_date')
                ->orderByDesc('id');

            HealthScopeService::applyBranchScope($visitQuery, $user);
            $visits = $visitQuery->limit(100)->get();
        }

        if ($canSeeClinical && Schema::hasTable('health_prescriptions')) {
            $prescriptions = HealthPrescription::query()
                ->where('health_patient_id', $patient->id)
                ->with(['doctor:id,name', 'items'])
                ->orderByDesc('id')
                ->limit(50)
                ->get();
        }

        $appointments = collect();
        if ($this->can('appointments.view')) {
            $apptQuery = HealthAppointment::query()
                ->where('health_patient_id', $patient->id)
                ->with(['doctor:id,name'])
                ->orderByDesc('appointment_date')
                ->orderByDesc('id');
            HealthScopeService::applyBranchScope($apptQuery, $user);
            $appointments = $apptQuery->limit(50)->get();
        }

        return view('health.patients.show', [
            'patient' => $patient,
            'visits' => $visits,
            'appointments' => $appointments,
            'prescriptions' => $prescriptions,
            'canSeeClinical' => $canSeeClinical,
            'canManage' => $this->can('patients.manage'),
            'canBook' => $this->can('appointments.manage'),
            'confidentialBlocked' => $patient->is_confidential
                && !$canSeeClinical
                && HealthAccessService::can($user, 'clinical.view', $company),
        ]);
    }

    /** Archive (never delete) — records are filed under this row forever. */
    public function toggleActive($id)
    {
        $this->require('patients.manage');
        $patient = $this->findPatient($id);

        $patient->is_active = !$patient->is_active;
        $patient->save();

        return back()->with('success', $patient->is_active
            ? __('health.patient_restored')
            : __('health.patient_archived'));
    }

    /**
     * Live duplicate lookup while reception is still typing.
     *
     * Same predicate the save path uses — a hint that disagreed with the rule
     * that actually blocks the save would train the desk to ignore it.
     */
    public function duplicates(Request $request)
    {
        $this->require('patients.manage');

        $matches = HealthPatientService::findDuplicates((int) $this->company()->id, [
            'name' => (string) $request->query('name', ''),
            'phone' => (string) $request->query('phone', ''),
            'cnic' => (string) $request->query('cnic', ''),
            'age_years' => $request->query('age_years'),
            'date_of_birth' => $request->query('date_of_birth'),
        ], $request->query('ignore_id') ? (int) $request->query('ignore_id') : null);

        return response()->json([
            'ok' => true,
            'matches' => $matches->map(fn ($m) => [
                // Live PDO hands ids back as strings; cast so the JS side can
                // compare them without surprises.
                'id' => (int) $m['patient']->id,
                'mrn' => (string) $m['patient']->mrn,
                'name' => (string) $m['patient']->name,
                'phone' => (string) ($m['patient']->phone ?? ''),
                'age' => (string) ($m['patient']->age_label ?? ''),
                'reason' => __(HealthPatientService::duplicateReasonKey($m['reason'])),
                'hard' => (bool) $m['hard'],
                'url' => route('health.patients.show', $m['patient']->id, false),
            ])->values()->all(),
        ]);
    }

    /* ───────────────────────────── helpers ───────────────────────────── */

    private function validatePatient(Request $request, ?int $ignoreId = null): array
    {
        return $request->validate([
            'name' => 'required|string|max:255',
            'guardian_name' => 'nullable|string|max:255',
            'gender' => ['nullable', Rule::in(HealthPatient::GENDERS)],
            'date_of_birth' => 'nullable|date|before_or_equal:today',
            'age_years' => 'nullable|integer|min:0|max:130',
            'age_months' => 'nullable|integer|min:0|max:11',
            'phone' => 'nullable|string|max:32',
            'alt_phone' => 'nullable|string|max:32',
            'email' => 'nullable|email|max:255',
            'cnic' => 'nullable|string|max:20',
            'address' => 'nullable|string|max:500',
            'city' => 'nullable|string|max:100',
            'blood_group' => ['nullable', Rule::in(HealthPatient::BLOOD_GROUPS)],
            'marital_status' => ['nullable', Rule::in(HealthPatient::MARITAL_STATUSES)],
            'allergies' => 'nullable|string|max:2000',
            'chronic_conditions' => 'nullable|string|max:2000',
            'emergency_contact_name' => 'nullable|string|max:255',
            'emergency_contact_phone' => 'nullable|string|max:32',
            'emergency_contact_relation' => 'nullable|string|max:60',
            'consent_treatment' => 'nullable|boolean',
            'consent_share_reports' => 'nullable|boolean',
            'consent_contact' => 'nullable|boolean',
            'is_confidential' => 'nullable|boolean',
            'notes' => 'nullable|string|max:2000',
            'branch_id' => [
                'nullable',
                Rule::exists('branches', 'id')->where(fn ($q) => $q->where('company_id', $this->company()?->id)),
            ],
        ]);
    }

    private function attributesFrom(array $data): array
    {
        return [
            'branch_id' => $data['branch_id'] ?? null,
            'name' => $data['name'],
            'guardian_name' => $data['guardian_name'] ?? null,
            'gender' => $data['gender'] ?? null,
            'date_of_birth' => $data['date_of_birth'] ?? null,
            'age_years' => $data['age_years'] ?? null,
            'age_months' => $data['age_months'] ?? null,
            'phone' => $data['phone'] ?? null,
            'phone_digits' => HealthPatient::normalizePhone($data['phone'] ?? null),
            'alt_phone' => $data['alt_phone'] ?? null,
            'email' => $data['email'] ?? null,
            'cnic' => HealthPatient::normalizeCnic($data['cnic'] ?? null),
            'address' => $data['address'] ?? null,
            'city' => $data['city'] ?? null,
            'blood_group' => $data['blood_group'] ?? null,
            'marital_status' => $data['marital_status'] ?? null,
            'allergies' => $data['allergies'] ?? null,
            'chronic_conditions' => $data['chronic_conditions'] ?? null,
            'emergency_contact_name' => $data['emergency_contact_name'] ?? null,
            'emergency_contact_phone' => $data['emergency_contact_phone'] ?? null,
            'emergency_contact_relation' => $data['emergency_contact_relation'] ?? null,
            'consent_treatment' => (bool) ($data['consent_treatment'] ?? false),
            'consent_share_reports' => (bool) ($data['consent_share_reports'] ?? false),
            'consent_contact' => (bool) ($data['consent_contact'] ?? false),
            'is_confidential' => (bool) ($data['is_confidential'] ?? false),
            'notes' => $data['notes'] ?? null,
        ];
    }

    /**
     * Stamp WHEN consent was taken and by WHOM, and only when it changed.
     *
     * "We had consent" is only defensible if the record says when it was given
     * and who took it. Re-stamping on every unrelated edit would destroy that,
     * so the stamp moves only when one of the three answers actually changes.
     */
    private function withConsentStamp(array $attributes, array $data, ?HealthPatient $existing): array
    {
        $changed = $existing === null;
        foreach (['consent_treatment', 'consent_share_reports', 'consent_contact'] as $field) {
            if ($existing !== null && (bool) $existing->{$field} !== (bool) $attributes[$field]) {
                $changed = true;
            }
        }

        $anyGiven = $attributes['consent_treatment'] || $attributes['consent_share_reports'] || $attributes['consent_contact'];

        if ($changed && $anyGiven) {
            $attributes['consent_recorded_at'] = now();
            $attributes['consent_recorded_by'] = $this->user()?->id;
        } elseif ($changed && !$anyGiven) {
            // Consent fully withdrawn — the old stamp no longer describes
            // anything and must not linger as if it did.
            $attributes['consent_recorded_at'] = null;
            $attributes['consent_recorded_by'] = null;
        }

        return $attributes;
    }

    /** Company-scoped and branch-scoped: a stray id must never open another desk's file. */
    protected function findPatient($id): HealthPatient
    {
        $query = HealthPatient::query()->with(['branch:id,name']);
        HealthScopeService::applyBranchScope($query, $this->user());

        return $query->findOrFail($id);
    }
}

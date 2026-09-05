<?php

namespace App\Http\Controllers\Health;

use App\Models\HealthAppointment;
use App\Models\HealthDoctor;
use App\Models\HealthPatient;
use App\Models\HealthVisit;
use App\Services\HealthOpdService;
use App\Services\HealthPatientService;
use App\Services\HealthPlatformService;
use App\Services\HealthScopeService;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Validation\Rule;

/**
 * The front desk: the day's diary, the walk-in token queue, and check-in.
 *
 * One screen covers both ways a patient reaches a doctor, because at the desk
 * they ARE one thing — a list of people waiting, in order. A booking made last
 * week and a token handed out two minutes ago sit in the same queue and go
 * through the same states, so they are the same row with a different `kind`.
 *
 * Check-in is where the encounter is born and the consultation fee is captured.
 * That is a reception act, not a clinical one, which is why the fee lives here
 * and not behind clinical.write.
 */
class HealthAppointmentController extends HealthPanelController
{
    public function index(Request $request)
    {
        $user = $this->user();

        $date = $this->resolveDate($request->query('date'));
        $doctorFilter = $request->query('doctor_id');
        $statusFilter = $request->query('status');

        $query = HealthAppointment::query()
            ->with(['patient:id,mrn,name,phone,gender,age_years,age_months,date_of_birth,is_confidential',
                    'doctor:id,name,specialty,room',
                    'visit:id,visit_no,status,fee_status,net_fee'])
            ->whereDate('appointment_date', $date)
            ->orderByRaw('CASE WHEN token_no IS NULL THEN 1 ELSE 0 END')
            ->orderBy('token_no')
            ->orderBy('appointment_time')
            ->orderBy('id');

        $this->scope($query);

        if ($doctorFilter) {
            $query->where('health_doctor_id', (int) $doctorFilter);
        }
        if ($statusFilter && in_array($statusFilter, HealthAppointment::STATUSES, true)) {
            $query->where('status', $statusFilter);
        }

        $appointments = $query->get();

        // Counters for the day, computed from the same rows the desk is looking
        // at — a tile that disagreed with the list under it would be worse than
        // no tile.
        $counts = [
            'total' => $appointments->count(),
            'waiting' => $appointments->whereIn('status', [HealthAppointment::STATUS_BOOKED, HealthAppointment::STATUS_CHECKED_IN])->count(),
            'in_consultation' => $appointments->where('status', HealthAppointment::STATUS_IN_CONSULTATION)->count(),
            'completed' => $appointments->where('status', HealthAppointment::STATUS_COMPLETED)->count(),
            'no_show' => $appointments->where('status', HealthAppointment::STATUS_NO_SHOW)->count(),
        ];

        return view('health.appointments.index', [
            'appointments' => $appointments,
            'date' => $date,
            'counts' => $counts,
            'doctors' => $this->selectableDoctors(),
            'doctorFilter' => $doctorFilter ? (int) $doctorFilter : null,
            'statusFilter' => $statusFilter,
            'canManage' => $this->can('appointments.manage'),
            'branches' => $this->branches(),
            'defaultBranchId' => HealthPlatformService::activeBranchId() ?? HealthPlatformService::defaultBranchId($this->company()),
            'departments' => HealthScopeService::selectableDepartments($user),
        ]);
    }

    /** Patient lookup for the booking form (same search the register uses). */
    public function searchPatients(Request $request)
    {
        $query = HealthPatient::query()->where('is_active', true)->orderByDesc('id');
        HealthScopeService::applyBranchScope($query, $this->user());
        HealthPatientService::applySearch($query, $request->query('q'));

        $patients = $query->limit(15)->get();

        return response()->json([
            'ok' => true,
            'patients' => $patients->map(fn (HealthPatient $p) => [
                'id' => (int) $p->id,
                'mrn' => (string) $p->mrn,
                'name' => (string) $p->name,
                'phone' => (string) ($p->phone ?? ''),
                'age' => (string) ($p->age_label ?? ''),
                'gender' => (string) ($p->gender ?? ''),
            ])->values()->all(),
        ]);
    }

    public function store(Request $request)
    {
        $this->require('appointments.manage');
        $company = $this->company();

        $data = $request->validate([
            'health_patient_id' => ['required', Rule::exists('health_patients', 'id')->where(fn ($q) => $q->where('company_id', $company->id))],
            'health_doctor_id' => ['required', Rule::exists('health_doctors', 'id')->where(fn ($q) => $q->where('company_id', $company->id))],
            'kind' => ['required', Rule::in(HealthAppointment::KINDS)],
            'appointment_date' => 'required|date',
            'appointment_time' => 'nullable|date_format:H:i',
            'reason' => 'nullable|string|max:500',
            'branch_id' => ['nullable', Rule::exists('branches', 'id')->where(fn ($q) => $q->where('company_id', $company->id))],
            'health_department_id' => ['nullable', Rule::exists('health_departments', 'id')->where(fn ($q) => $q->where('company_id', $company->id))],
            'check_in_now' => 'nullable|boolean',
        ]);

        if (!HealthScopeService::canAccessBranch($this->user(), $data['branch_id'] ?? null)) {
            return back()->withInput()->with('error', __('health.dept_branch_not_yours'));
        }
        if (!HealthScopeService::canAccessDepartment($this->user(), $data['health_department_id'] ?? null)) {
            return back()->withInput()->with('error', __('health.team_department_not_yours'));
        }

        $doctor = HealthDoctor::query()->findOrFail($data['health_doctor_id']);
        if (!$doctor->is_active) {
            return back()->withInput()->with('error', __('health.appt_doctor_inactive'));
        }

        // A scheduled booking needs a time; a walk-in is "now" by definition.
        if ($data['kind'] === HealthAppointment::KIND_SCHEDULED && empty($data['appointment_time'])) {
            return back()->withInput()->with('error', __('health.appt_time_required'));
        }

        // The same patient must not sit twice in the same doctor's queue on the
        // same day — that is a double-click, not two consultations, and it would
        // burn a second token and a second fee.
        $clash = HealthAppointment::query()
            ->where('health_patient_id', $data['health_patient_id'])
            ->where('health_doctor_id', $data['health_doctor_id'])
            ->whereDate('appointment_date', $data['appointment_date'])
            ->whereIn('status', HealthAppointment::OPEN_STATUSES)
            ->first();

        if ($clash) {
            return back()->withInput()->with('error', __('health.appt_duplicate_open'));
        }

        $data['company_id'] = $company->id;
        $appointment = HealthOpdService::book($data, $this->user());

        // A walk-in is standing at the counter. Checking them in as part of the
        // same action is the whole point of a walk-in; making the desk click
        // twice just produces rows nobody ever checks in.
        if ($data['kind'] === HealthAppointment::KIND_WALKIN || $request->boolean('check_in_now')) {
            if (Carbon::parse($appointment->appointment_date)->isToday()) {
                HealthOpdService::checkIn($appointment, $this->user());
            }
        }

        return redirect()
            ->route('health.appointments', ['date' => Carbon::parse($data['appointment_date'])->toDateString()])
            ->with('success', $appointment->token_no
                ? __('health.appt_token_issued', ['token' => $appointment->token_no])
                : __('health.appt_booked'));
    }

    /** Move a booking to another day, time or doctor. */
    public function reschedule(Request $request, $id)
    {
        $this->require('appointments.manage');
        $appointment = $this->findAppointment($id);
        $company = $this->company();

        if ($appointment->health_visit_id) {
            return back()->with('error', __('health.appt_already_checked_in'));
        }

        $data = $request->validate([
            'appointment_date' => 'required|date',
            'appointment_time' => 'nullable|date_format:H:i',
            'health_doctor_id' => ['required', Rule::exists('health_doctors', 'id')->where(fn ($q) => $q->where('company_id', $company->id))],
        ]);

        $appointment->appointment_date = $data['appointment_date'];
        $appointment->appointment_time = $data['appointment_time'] ?? null;
        // Moving to another doctor voids the old queue position: token 4 in Dr A's
        // list means nothing in Dr B's.
        if ((int) $appointment->health_doctor_id !== (int) $data['health_doctor_id']) {
            $appointment->health_doctor_id = (int) $data['health_doctor_id'];
            $appointment->token_no = null;
        }
        $appointment->status = HealthAppointment::STATUS_BOOKED;
        $appointment->no_show_at = null;
        $appointment->cancelled_at = null;
        $appointment->cancel_reason = null;
        $appointment->save();

        return redirect()
            ->route('health.appointments', ['date' => Carbon::parse($data['appointment_date'])->toDateString()])
            ->with('success', __('health.appt_rescheduled'));
    }

    /** The patient has arrived: token, encounter and fee, in one transaction. */
    public function checkIn(Request $request, $id)
    {
        $this->require('appointments.manage');
        $appointment = $this->findAppointment($id);

        if (in_array($appointment->status, [HealthAppointment::STATUS_CANCELLED, HealthAppointment::STATUS_COMPLETED], true)) {
            return back()->with('error', __('health.appt_not_checkin_able'));
        }

        $data = $request->validate([
            'visit_type' => ['nullable', Rule::in(HealthVisit::TYPES)],
            'fee_amount' => 'nullable|numeric|min:0|max:9999999',
            'concession_amount' => 'nullable|numeric|min:0|max:9999999',
            'concession_reason' => 'nullable|string|max:300',
        ]);

        $visit = HealthOpdService::checkIn($appointment, $this->user(), $data);

        return back()->with('success', __('health.appt_checked_in', [
            'token' => $appointment->fresh()->token_no ?? '—',
            'visit' => $visit->visit_no,
        ]));
    }

    public function cancel(Request $request, $id)
    {
        $this->require('appointments.manage');
        $appointment = $this->findAppointment($id);

        $data = $request->validate(['cancel_reason' => 'nullable|string|max:300']);

        HealthOpdService::cancel($appointment, $data['cancel_reason'] ?? null);

        return back()->with('success', __('health.appt_cancelled'));
    }

    public function noShow($id)
    {
        $this->require('appointments.manage');
        $appointment = $this->findAppointment($id);

        if (!HealthOpdService::markNoShow($appointment)) {
            return back()->with('error', __('health.appt_no_show_refused'));
        }

        return back()->with('success', __('health.appt_no_show_marked'));
    }

    /**
     * Record the consultation fee against the encounter.
     *
     * Reception's screen, not the doctor's: whoever takes the money records it,
     * and the record names them.
     */
    public function updateFee(Request $request, $visitId)
    {
        $this->require('appointments.manage');

        $query = HealthVisit::query();
        $this->scope($query);
        $visit = $query->findOrFail($visitId);

        $data = $request->validate([
            'fee_amount' => 'required|numeric|min:0|max:9999999',
            'concession_amount' => 'nullable|numeric|min:0|max:9999999',
            'concession_reason' => 'nullable|string|max:300',
            'fee_status' => ['required', Rule::in(HealthVisit::FEE_STATUSES)],
            'payment_method' => ['nullable', Rule::in(HealthVisit::PAYMENT_METHODS)],
        ]);

        HealthOpdService::applyFee($visit, $data, $this->user());

        return back()->with('success', __('health.fee_saved'));
    }

    /* ───────────────────────────── helpers ───────────────────────────── */

    private function resolveDate($value): string
    {
        try {
            return $value ? Carbon::parse($value)->toDateString() : now()->toDateString();
        } catch (\Throwable $e) {
            return now()->toDateString();
        }
    }

    private function findAppointment($id): HealthAppointment
    {
        $query = HealthAppointment::query();

        return $this->scope($query)->findOrFail($id);
    }
}

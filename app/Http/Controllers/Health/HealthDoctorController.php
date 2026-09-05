<?php

namespace App\Http\Controllers\Health;

use App\Models\HealthDepartment;
use App\Models\HealthDoctor;
use App\Models\HealthDoctorSlot;
use App\Models\User;
use App\Services\HealthPlatformService;
use App\Services\HealthScopeService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

/**
 * Practitioner profiles, their weekly sittings and their fee schedule.
 *
 * Gated on `doctors.manage`, deliberately NOT on `appointments.manage`. A
 * receptionist has to be able to fill the diary all day; they must not be able
 * to change what a consultation costs. Those are the same screen only in a
 * system that has never been audited.
 *
 * A profile is not a login. Visiting consultants who never sign in still need
 * to be booked, charged and prescribed for, so `user_id` is optional — and when
 * it IS set, that link is what lets the panel show a doctor their own queue.
 */
class HealthDoctorController extends HealthPanelController
{
    public function index()
    {
        $query = HealthDoctor::query()
            ->with(['branch:id,name', 'department:id,name', 'user:id,name', 'slots'])
            ->orderBy('name');

        $doctors = $this->scope($query)->get();

        return view('health.doctors.index', [
            'doctors' => $doctors,
            'branches' => $this->branches(),
            'departments' => HealthScopeService::selectableDepartments($this->user()),
            'linkableUsers' => $this->linkableUsers(),
            'weekdays' => HealthDoctorSlot::WEEKDAYS,
        ]);
    }

    public function store(Request $request)
    {
        $company = $this->company();
        $data = $this->validateDoctor($request);

        if (!HealthScopeService::canAccessBranch($this->user(), $data['branch_id'] ?? null)) {
            return back()->withInput()->with('error', __('health.dept_branch_not_yours'));
        }
        if (!HealthScopeService::canAccessDepartment($this->user(), $data['health_department_id'] ?? null)) {
            return back()->withInput()->with('error', __('health.team_department_not_yours'));
        }

        $data['company_id'] = $company->id;
        $data['is_active'] = true;
        HealthDoctor::create($data);

        return redirect()->route('health.doctors')->with('success', __('health.doctor_created'));
    }

    public function update(Request $request, $id)
    {
        $doctor = $this->findDoctor($id);
        $data = $this->validateDoctor($request, $doctor->id);

        if (!HealthScopeService::canAccessBranch($this->user(), $data['branch_id'] ?? null)) {
            return back()->withInput()->with('error', __('health.dept_branch_not_yours'));
        }
        if (!HealthScopeService::canAccessDepartment($this->user(), $data['health_department_id'] ?? null)) {
            return back()->withInput()->with('error', __('health.team_department_not_yours'));
        }

        $doctor->fill($data);
        $doctor->save();

        return redirect()->route('health.doctors')->with('success', __('health.doctor_updated'));
    }

    /**
     * Switch a practitioner off rather than delete them.
     *
     * Their name is on every consultation they ever recorded. Deleting the row
     * would leave those encounters attributed to nobody, which is exactly the
     * question an audit asks first.
     */
    public function toggleActive($id)
    {
        $doctor = $this->findDoctor($id);
        $doctor->is_active = !$doctor->is_active;
        $doctor->save();

        return back()->with('success', $doctor->is_active
            ? __('health.doctor_reactivated')
            : __('health.doctor_deactivated'));
    }

    /** Replace a doctor's whole weekly timetable in one save. */
    public function saveSlots(Request $request, $id)
    {
        $doctor = $this->findDoctor($id);

        $request->validate([
            'slots' => 'nullable|array|max:60',
            'slots.*.weekday' => 'required|integer|min:0|max:6',
            'slots.*.start_time' => 'required|date_format:H:i',
            'slots.*.end_time' => 'required|date_format:H:i|after:slots.*.start_time',
            'slots.*.branch_id' => [
                'nullable',
                Rule::exists('branches', 'id')->where(fn ($q) => $q->where('company_id', $this->company()?->id)),
            ],
            'slots.*.slot_minutes' => 'nullable|integer|min:5|max:240',
            'slots.*.max_tokens' => 'nullable|integer|min:0|max:500',
        ]);

        $rows = $request->input('slots', []);

        foreach ($rows as $row) {
            if (!HealthScopeService::canAccessBranch($this->user(), $row['branch_id'] ?? null)) {
                return back()->with('error', __('health.dept_branch_not_yours'));
            }
        }

        DB::transaction(function () use ($doctor, $rows) {
            HealthDoctorSlot::query()->where('health_doctor_id', $doctor->id)->delete();

            foreach ($rows as $row) {
                HealthDoctorSlot::create([
                    'company_id' => $doctor->company_id,
                    'health_doctor_id' => $doctor->id,
                    'branch_id' => $row['branch_id'] ?? null,
                    'weekday' => (int) $row['weekday'],
                    'start_time' => $row['start_time'],
                    'end_time' => $row['end_time'],
                    'slot_minutes' => $row['slot_minutes'] ?? null,
                    'max_tokens' => (int) ($row['max_tokens'] ?? 0),
                    'is_active' => true,
                ]);
            }
        });

        return redirect()->route('health.doctors')->with('success', __('health.doctor_slots_saved'));
    }

    /* ───────────────────────────── helpers ───────────────────────────── */

    private function validateDoctor(Request $request, ?int $ignoreId = null): array
    {
        $companyId = $this->company()?->id;

        return $request->validate([
            'name' => 'required|string|max:255',
            'specialty' => 'nullable|string|max:120',
            'qualification' => 'nullable|string|max:200',
            'registration_no' => 'nullable|string|max:60',
            'phone' => 'nullable|string|max:32',
            'email' => 'nullable|email|max:255',
            'gender' => 'nullable|string|max:10',
            'room' => 'nullable|string|max:60',
            'consultation_fee' => 'required|numeric|min:0|max:9999999',
            'follow_up_fee' => 'required|numeric|min:0|max:9999999',
            'follow_up_days' => 'required|integer|min:0|max:365',
            'slot_minutes' => 'required|integer|min:5|max:240',
            'branch_id' => [
                'nullable',
                Rule::exists('branches', 'id')->where(fn ($q) => $q->where('company_id', $companyId)),
            ],
            'health_department_id' => [
                'nullable',
                Rule::exists('health_departments', 'id')->where(fn ($q) => $q->where('company_id', $companyId)),
            ],
            'user_id' => [
                'nullable',
                Rule::exists('users', 'id')->where(fn ($q) => $q->where('company_id', $companyId)),
            ],
        ]);
    }

    /**
     * Staff accounts that can be linked to a practitioner profile.
     *
     * Only clinicians are offered: linking an accountant's login to a doctor
     * profile would hand them that doctor's clinical queue.
     */
    private function linkableUsers()
    {
        return User::where('company_id', $this->company()?->id)
            ->where('is_active', true)
            ->whereIn('health_role', ['health_doctor', 'health_owner', 'health_admin'])
            ->orderBy('name')
            ->get(['id', 'name', 'email', 'health_role']);
    }

    private function findDoctor($id): HealthDoctor
    {
        $query = HealthDoctor::query();

        return $this->scope($query)->findOrFail($id);
    }
}

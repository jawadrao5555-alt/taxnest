<?php

namespace App\Http\Controllers;

use App\Models\Branch;
use App\Models\Company;
use App\Models\HealthAttendanceCorrection;
use App\Models\HealthAttendanceDay;
use App\Models\HealthDepartment;
use App\Models\HealthHoliday;
use App\Models\HealthHrPolicy;
use App\Models\HealthLeaveRequest;
use App\Models\HealthLeaveType;
use App\Models\HealthRosterEntry;
use App\Models\HealthShift;
use App\Models\HealthStaffProfile;
use App\Models\PosBiometricDevice;
use App\Models\PosBiometricPunch;
use App\Models\PosBiometricUserMap;
use App\Services\HealthAccessService;
use App\Services\HealthAttendanceService;
use App\Services\HealthHrService;
use App\Services\HealthPlatformService;
use App\Services\HealthScopeService;
use App\Support\HealthPanel;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Validation\Rule;

/**
 * Healthcare HR — the records half.
 *
 * Employment records, work patterns (shifts), holidays, leave types, the
 * organisation's attendance policy and the biometric devices that feed it.
 * The attendance maths, rosters and leave decisions live in their own
 * controllers; this one owns the things that rarely change.
 *
 * ── The rule that shapes this whole controller ────────────────────────────
 *
 * An employment record is NOT an account. A doctor who already signs in has
 * one identity, and HR attaches paperwork to it — designation, posting,
 * supervisor, work pattern, salary inputs. There is deliberately no "create
 * staff" action here: that is the team screen's job, and duplicating it would
 * hand the organisation two people who are really one, drifting apart the first
 * time somebody is renamed or deactivated.
 */
class HealthHrController extends Controller
{
    private function user()
    {
        return Auth::guard(HealthPanel::GUARD)->user();
    }

    private function company(): ?Company
    {
        return Company::find(app('currentCompanyId'));
    }

    private function companyId(): int
    {
        return (int) app('currentCompanyId');
    }

    /** May this person change HR records (as opposed to only reading them)? */
    private function canManage(): bool
    {
        return HealthAccessService::can($this->user(), 'hr.manage', $this->company());
    }

    /** Every HR screen refuses politely rather than 500ing on a drifted box. */
    private function schemaGuard()
    {
        if (HealthHrService::schemaReady()) {
            return null;
        }

        return redirect()->route('health.dashboard')->with('error', __('health.hr_schema_missing'));
    }

    // ═══════════════════════ HUB ═══════════════════════

    /**
     * The HR desk landing: who is on duty right now, what is waiting for a
     * decision, and the shortcuts into every other HR screen.
     */
    public function index()
    {
        if ($redirect = $this->schemaGuard()) {
            return $redirect;
        }

        $company = $this->company();
        $companyId = $this->companyId();
        $user = $this->user();

        HealthHrService::policy($companyId);
        HealthHrService::ensureLeaveTypes($companyId);

        $staff = HealthPlatformService::staff($company);
        $profiles = HealthHrService::profilesFor($companyId, $staff->pluck('id')->all());

        $today = HealthHrService::attendanceDate(now(), HealthHrService::policy($companyId));

        // Today's derived days, so the hub shows the floor as it stands.
        $days = HealthAttendanceDay::withoutGlobalScopes()
            ->where('company_id', $companyId)
            ->whereDate('attendance_date', $today->toDateString())
            ->get()
            ->keyBy(fn (HealthAttendanceDay $day) => (int) $day->user_id);

        $counts = [
            'staff'        => $staff->count(),
            'active'       => 0,
            'on_duty'      => 0,
            'present'      => 0,
            'absent'       => 0,
            'leave'        => 0,
            'missed_punch' => 0,
        ];

        foreach ($staff as $member) {
            $profile = $profiles[(int) $member->id] ?? null;
            if ($profile === null || $profile->isWorking()) {
                $counts['active']++;
            }

            $day = $days->get((int) $member->id);
            if (!$day) {
                continue;
            }
            if ($day->is_open) {
                $counts['on_duty']++;
            }
            match ($day->status) {
                'present', 'on_call' => $counts['present']++,
                'absent'             => $counts['absent']++,
                'leave'              => $counts['leave']++,
                'missed_punch'       => $counts['missed_punch']++,
                default              => null,
            };
        }

        $pendingLeave = HealthLeaveRequest::withoutGlobalScopes()
            ->where('company_id', $companyId)->where('status', 'pending')->count();
        $pendingCorrections = HealthAttendanceCorrection::withoutGlobalScopes()
            ->where('company_id', $companyId)->where('status', 'pending')->count();

        // Unmapped biometric PINs: evidence arriving with nobody attached to it.
        $unmappedPins = 0;
        if (Schema::hasTable('pos_biometric_punches')) {
            $unmappedPins = PosBiometricPunch::query()
                ->where('company_id', $companyId)
                ->whereNull('user_id')
                ->distinct()
                ->count('device_pin');
        }

        return view('health.hr.index', [
            'company'            => $company,
            'counts'             => $counts,
            'today'              => $today,
            'pendingLeave'       => $pendingLeave,
            'pendingCorrections' => $pendingCorrections,
            'unmappedPins'       => $unmappedPins,
            'shiftCount'         => HealthShift::withoutGlobalScopes()->where('company_id', $companyId)->where('is_active', true)->count(),
            'rosterToday'        => HealthRosterEntry::withoutGlobalScopes()->where('company_id', $companyId)
                ->whereDate('duty_date', $today->toDateString())->count(),
            'canManage'          => $this->canManage(),
            'canAttendance'      => HealthAccessService::can($user, 'hr.attendance.view', $company),
            'canPayroll'         => HealthAccessService::can($user, 'hr.payroll.view', $company),
            'canApproveLeave'    => HealthAccessService::can($user, 'hr.leave.approve', $company),
        ]);
    }

    // ═══════════════════════ STAFF RECORDS ═══════════════════════

    public function staff(Request $request)
    {
        if ($redirect = $this->schemaGuard()) {
            return $redirect;
        }

        $company = $this->company();
        $companyId = $this->companyId();
        $user = $this->user();

        $staff = HealthPlatformService::staff($company);
        $profiles = HealthHrService::profilesFor($companyId, $staff->pluck('id')->all());

        $status = $request->query('status');
        if ($status && in_array($status, HealthStaffProfile::EMPLOYMENT_STATUSES, true)) {
            $staff = $staff->filter(function ($member) use ($profiles, $status) {
                $profile = $profiles[(int) $member->id] ?? null;

                return ($profile->employment_status ?? 'active') === $status;
            })->values();
        }

        $search = trim((string) $request->query('q', ''));
        if ($search !== '') {
            $needle = mb_strtolower($search);
            $staff = $staff->filter(function ($member) use ($profiles, $needle) {
                $profile = $profiles[(int) $member->id] ?? null;
                $haystack = mb_strtolower(implode(' ', array_filter([
                    $member->name, $member->email,
                    $profile->employee_code ?? null,
                    $profile->designation ?? null,
                ])));

                return str_contains($haystack, $needle);
            })->values();
        }

        return view('health.hr.staff', [
            'company'      => $company,
            'staff'        => $staff,
            'profiles'     => $profiles,
            'branches'     => HealthPlatformService::accessibleBranches(),
            'shifts'       => HealthShift::withoutGlobalScopes()->where('company_id', $companyId)
                ->orderBy('start_time')->get(),
            'allStaff'     => HealthPlatformService::staff($company),
            'types'        => HealthStaffProfile::EMPLOYMENT_TYPES,
            'statuses'     => HealthStaffProfile::EMPLOYMENT_STATUSES,
            'filterStatus' => $status,
            'search'       => $search,
            'canManage'    => $this->canManage(),
            // Salary inputs are a payroll field, not an HR-records field. Only
            // somebody who may read the payroll handoff sees or edits them.
            'canPay'       => HealthAccessService::can($user, 'hr.payroll.view', $company),
        ]);
    }

    /**
     * Save the employment record for one existing member.
     *
     * The user id comes from the URL and is checked against the organisation's
     * own staff list — never trusted from the form — so no amount of editing
     * the POST can attach a record to somebody in another company.
     */
    public function updateStaff(Request $request, $userId)
    {
        if ($redirect = $this->schemaGuard()) {
            return $redirect;
        }
        if (!$this->canManage()) {
            abort(403);
        }

        $company = $this->company();
        $companyId = $this->companyId();
        $userId = (int) $userId;

        $staff = HealthPlatformService::staff($company);
        $member = $staff->firstWhere('id', $userId);
        if (!$member) {
            return back()->with('error', __('health.hr_staff_not_found'));
        }

        $data = $request->validate([
            'employee_code'     => ['nullable', 'string', 'max:32', Rule::unique('health_staff_profiles', 'employee_code')
                ->where(fn ($q) => $q->where('company_id', $companyId))
                ->ignore($userId, 'user_id')],
            'designation'       => ['nullable', 'string', 'max:120'],
            'employment_type'   => ['required', Rule::in(HealthStaffProfile::EMPLOYMENT_TYPES)],
            'employment_status' => ['required', Rule::in(HealthStaffProfile::EMPLOYMENT_STATUSES)],
            'joined_on'         => ['nullable', 'date'],
            'left_on'           => ['nullable', 'date', 'after_or_equal:joined_on'],
            'branch_id'         => ['nullable', 'integer'],
            'supervisor_user_id' => ['nullable', 'integer'],
            'default_shift_id'  => ['nullable', 'integer'],
            'weekly_off_days'   => ['nullable', 'array'],
            'weekly_off_days.*' => ['integer', 'min:1', 'max:7'],
            'attendance_exempt' => ['nullable', 'boolean'],
            'overtime_eligible' => ['nullable', 'boolean'],
            'qualification'     => ['nullable', 'string', 'max:190'],
            'license_no'        => ['nullable', 'string', 'max:64'],
            'cnic'              => ['nullable', 'string', 'max:20'],
            'emergency_contact' => ['nullable', 'string', 'max:120'],
            'notes'             => ['nullable', 'string', 'max:500'],
            'basic_salary'      => ['nullable', 'numeric', 'min:0', 'max:99999999'],
            'overtime_hourly_rate' => ['nullable', 'numeric', 'min:0', 'max:999999'],
        ]);

        // Branch must be one this administrator may actually post people to —
        // and one of THIS company's. An owner reaches every branch of their own
        // organisation, so the access check alone would accept a stranger's id.
        if (!empty($data['branch_id']) && !$this->ownBranch($data['branch_id'])) {
            return back()->withInput()->with('error', __('health.dept_branch_not_yours'));
        }

        // A supervisor must be a colleague in the same organisation, and cannot
        // be the person themselves — a self-reporting loop breaks every
        // approval chain that walks it.
        if (!empty($data['supervisor_user_id'])) {
            $supervisorId = (int) $data['supervisor_user_id'];
            if ($supervisorId === $userId || !$staff->firstWhere('id', $supervisorId)) {
                return back()->withInput()->with('error', __('health.hr_supervisor_invalid'));
            }
        }

        if (!empty($data['default_shift_id'])) {
            $exists = HealthShift::withoutGlobalScopes()
                ->where('company_id', $companyId)
                ->where('id', (int) $data['default_shift_id'])
                ->exists();
            if (!$exists) {
                return back()->withInput()->with('error', __('health.hr_shift_not_found'));
            }
        }

        // Salary inputs stay untouched unless the actor may read payroll.
        if (!HealthAccessService::can($this->user(), 'hr.payroll.view', $this->company())) {
            unset($data['basic_salary'], $data['overtime_hourly_rate']);
        }

        $data['attendance_exempt'] = (bool) ($data['attendance_exempt'] ?? false);
        $data['overtime_eligible'] = (bool) ($data['overtime_eligible'] ?? false);
        $data['weekly_off_days'] = array_values(array_unique(array_map('intval', $data['weekly_off_days'] ?? [])));

        // "Left" without a date would silently keep the person on the roster.
        if ($data['employment_status'] === 'left' && empty($data['left_on'])) {
            $data['left_on'] = now()->toDateString();
        }
        if ($data['employment_status'] !== 'left') {
            $data['left_on'] = null;
        }

        $profile = HealthHrService::profile($companyId, $userId);
        $profile->fill($data)->save();
        HealthHrService::forget();

        return redirect()->route('health.hr.staff')->with('success', __('health.hr_staff_saved'));
    }

    // ═══════════════════════ SHIFTS & HOLIDAYS ═══════════════════════

    public function shifts()
    {
        if ($redirect = $this->schemaGuard()) {
            return $redirect;
        }

        $companyId = $this->companyId();
        HealthHrService::ensureLeaveTypes($companyId);

        return view('health.hr.shifts', [
            'company'    => $this->company(),
            'shifts'     => HealthShift::withoutGlobalScopes()->where('company_id', $companyId)
                ->orderBy('start_time')->get(),
            'holidays'   => HealthHoliday::withoutGlobalScopes()->where('company_id', $companyId)
                ->orderByDesc('holiday_date')->limit(120)->get(),
            'leaveTypes' => HealthLeaveType::withoutGlobalScopes()->where('company_id', $companyId)
                ->orderBy('name')->get(),
            'branches'   => HealthPlatformService::accessibleBranches(),
            'canManage'  => $this->canManage(),
        ]);
    }

    public function storeShift(Request $request)
    {
        return $this->saveShift($request, null);
    }

    public function updateShift(Request $request, $id)
    {
        return $this->saveShift($request, (int) $id);
    }

    private function saveShift(Request $request, ?int $id)
    {
        if ($redirect = $this->schemaGuard()) {
            return $redirect;
        }
        if (!$this->canManage()) {
            abort(403);
        }

        $companyId = $this->companyId();

        $data = $request->validate([
            'name'              => ['required', 'string', 'max:120'],
            'code'              => ['nullable', 'string', 'max:32', Rule::unique('health_shifts', 'code')
                ->where(fn ($q) => $q->where('company_id', $companyId))->ignore($id)],
            'start_time'        => ['required', 'date_format:H:i'],
            'end_time'          => ['required', 'date_format:H:i'],
            'second_start_time' => ['nullable', 'date_format:H:i'],
            'second_end_time'   => ['nullable', 'date_format:H:i', 'required_with:second_start_time'],
            'break_minutes'     => ['nullable', 'integer', 'min:0', 'max:600'],
            'grace_in_minutes'  => ['nullable', 'integer', 'min:0', 'max:240'],
            'grace_out_minutes' => ['nullable', 'integer', 'min:0', 'max:240'],
            'is_on_call'        => ['nullable', 'boolean'],
            'colour'            => ['nullable', 'string', 'max:16'],
            'is_active'         => ['nullable', 'boolean'],
        ]);

        // An end time at or before the start means the duty runs past midnight.
        // Stored, not re-derived: every punch-window query depends on it.
        $data['crosses_midnight'] = $this->wrapsMidnight(
            $data['second_start_time'] ? $data['start_time'] : $data['start_time'],
            $data['second_end_time'] ?: $data['end_time']
        );

        $data['is_on_call'] = (bool) ($data['is_on_call'] ?? false);
        $data['is_active'] = (bool) ($data['is_active'] ?? true);
        $data['break_minutes'] = (int) ($data['break_minutes'] ?? 0);
        $data['colour'] = $data['colour'] ?: 'teal';

        if ($id) {
            $shift = HealthShift::withoutGlobalScopes()
                ->where('company_id', $companyId)->findOrFail($id);
            $shift->fill($data)->save();
            $message = __('health.hr_shift_updated');
        } else {
            HealthShift::create($data + ['company_id' => $companyId]);
            $message = __('health.hr_shift_created');
        }

        return redirect()->route('health.hr.shifts')->with('success', $message);
    }

    private function wrapsMidnight(string $start, string $end): bool
    {
        return strtotime($end) <= strtotime($start);
    }

    /**
     * Deactivate rather than delete: rosters, attendance days and employment
     * records all point at the shift, and removing it would orphan history.
     */
    public function toggleShift($id)
    {
        if ($redirect = $this->schemaGuard()) {
            return $redirect;
        }
        if (!$this->canManage()) {
            abort(403);
        }

        $shift = HealthShift::withoutGlobalScopes()
            ->where('company_id', $this->companyId())->findOrFail((int) $id);
        $shift->is_active = !$shift->is_active;
        $shift->save();

        return back()->with('success', $shift->is_active
            ? __('health.hr_shift_activated')
            : __('health.hr_shift_deactivated'));
    }

    public function storeHoliday(Request $request)
    {
        if ($redirect = $this->schemaGuard()) {
            return $redirect;
        }
        if (!$this->canManage()) {
            abort(403);
        }

        $data = $request->validate([
            'name'         => ['required', 'string', 'max:120'],
            'holiday_date' => ['required', 'date'],
            'branch_id'    => ['nullable', 'integer'],
            'is_paid'      => ['nullable', 'boolean'],
            'notes'        => ['nullable', 'string', 'max:255'],
        ]);

        if (!empty($data['branch_id']) && !$this->ownBranch($data['branch_id'])) {
            return back()->with('error', __('health.dept_branch_not_yours'));
        }

        $data['is_paid'] = (bool) ($data['is_paid'] ?? true);
        $data['holiday_date'] = Carbon::parse($data['holiday_date'])->toDateString();

        HealthHoliday::create($data + ['company_id' => $this->companyId()]);

        return back()->with('success', __('health.hr_holiday_created'));
    }

    public function destroyHoliday($id)
    {
        if ($redirect = $this->schemaGuard()) {
            return $redirect;
        }
        if (!$this->canManage()) {
            abort(403);
        }

        $holiday = HealthHoliday::withoutGlobalScopes()
            ->where('company_id', $this->companyId())->findOrFail((int) $id);

        // Removing a holiday changes what those days mean. A locked month has
        // already been paid on the old answer, so it stays as it was.
        $date = Carbon::parse($holiday->holiday_date);
        if (HealthAttendanceService::isMonthLocked($this->companyId(), (int) $date->year, (int) $date->month)) {
            return back()->with('error', __('health.hr_month_locked'));
        }

        $holiday->delete();

        return back()->with('success', __('health.hr_holiday_deleted'));
    }

    public function storeLeaveType(Request $request)
    {
        return $this->saveLeaveType($request, null);
    }

    public function updateLeaveType(Request $request, $id)
    {
        return $this->saveLeaveType($request, (int) $id);
    }

    private function saveLeaveType(Request $request, ?int $id)
    {
        if ($redirect = $this->schemaGuard()) {
            return $redirect;
        }
        if (!$this->canManage()) {
            abort(403);
        }

        $companyId = $this->companyId();

        $data = $request->validate([
            'name'              => ['required', 'string', 'max:120'],
            'code'              => ['nullable', 'string', 'max:20', Rule::unique('health_leave_types', 'code')
                ->where(fn ($q) => $q->where('company_id', $companyId))->ignore($id)],
            'annual_quota_days' => ['nullable', 'numeric', 'min:0', 'max:365'],
            'is_paid'           => ['nullable', 'boolean'],
            'requires_approval' => ['nullable', 'boolean'],
            'is_active'         => ['nullable', 'boolean'],
        ]);

        $data['is_paid'] = (bool) ($data['is_paid'] ?? false);
        $data['requires_approval'] = (bool) ($data['requires_approval'] ?? true);
        $data['is_active'] = (bool) ($data['is_active'] ?? true);
        $data['annual_quota_days'] = (float) ($data['annual_quota_days'] ?? 0);

        if ($id) {
            $type = HealthLeaveType::withoutGlobalScopes()
                ->where('company_id', $companyId)->findOrFail($id);
            $type->fill($data)->save();
            $message = __('health.hr_leave_type_updated');
        } else {
            HealthLeaveType::create($data + ['company_id' => $companyId]);
            $message = __('health.hr_leave_type_created');
        }

        return redirect()->route('health.hr.shifts')->with('success', $message);
    }

    // ═══════════════════════ POLICY ═══════════════════════

    public function policy()
    {
        if ($redirect = $this->schemaGuard()) {
            return $redirect;
        }

        return view('health.hr.policy', [
            'company'   => $this->company(),
            'policy'    => HealthHrService::policy($this->companyId()),
            'statuses'  => HealthHrPolicy::MISSED_PUNCH_STATUSES,
            'canManage' => $this->canManage(),
            // The geofence needs a centre, so the sites are edited right beside
            // the switch that enforces them.
            'branches'  => HealthPlatformService::accessibleBranches(),
            'geoReady'  => Schema::hasColumn('health_hr_policies', 'geo_latitude'),
        ]);
    }

    public function updatePolicy(Request $request)
    {
        if ($redirect = $this->schemaGuard()) {
            return $redirect;
        }
        if (!$this->canManage()) {
            abort(403);
        }

        $data = $request->validate([
            'business_day_start'     => ['required', 'date_format:H:i'],
            'grace_in_minutes'       => ['required', 'integer', 'min:0', 'max:240'],
            'grace_out_minutes'      => ['required', 'integer', 'min:0', 'max:240'],
            'half_day_minutes'       => ['required', 'integer', 'min:30', 'max:1440'],
            'full_day_minutes'       => ['required', 'integer', 'min:60', 'max:1440'],
            'overtime_enabled'       => ['nullable', 'boolean'],
            'min_overtime_minutes'   => ['required', 'integer', 'min:0', 'max:480'],
            'missed_punch_status'    => ['required', Rule::in(HealthHrPolicy::MISSED_PUNCH_STATUSES)],
            'weekly_off_days'        => ['nullable', 'array'],
            'weekly_off_days.*'      => ['integer', 'min:1', 'max:7'],
            'biometric_enabled'      => ['nullable', 'boolean'],
            'web_checkin_enabled'    => ['nullable', 'boolean'],
            'mobile_checkin_enabled' => ['nullable', 'boolean'],
            'session_punch_enabled'  => ['nullable', 'boolean'],
            'geo_required'           => ['nullable', 'boolean'],
            'geo_radius_m'           => ['required', 'integer', 'min:25', 'max:5000'],
            'geo_latitude'           => ['nullable', 'numeric', 'between:-90,90'],
            'geo_longitude'          => ['nullable', 'numeric', 'between:-180,180'],
            'cross_branch_allowed'   => ['nullable', 'boolean'],
            'sites'                  => ['nullable', 'array'],
            'sites.*.latitude'       => ['nullable', 'numeric', 'between:-90,90'],
            'sites.*.longitude'      => ['nullable', 'numeric', 'between:-180,180'],
            'sites.*.geo_radius_m'   => ['nullable', 'integer', 'min:25', 'max:5000'],
        ]);

        $sites = $data['sites'] ?? [];
        unset($data['sites']);

        // A half-entered coordinate is not a site. Refuse it rather than
        // storing a centre that measures from the equator.
        foreach (array_merge([['latitude' => $data['geo_latitude'] ?? null, 'longitude' => $data['geo_longitude'] ?? null]], array_values($sites)) as $site) {
            $hasLat = ($site['latitude'] ?? null) !== null && $site['latitude'] !== '';
            $hasLng = ($site['longitude'] ?? null) !== null && $site['longitude'] !== '';
            if ($hasLat !== $hasLng) {
                return back()->withInput()->with('error', __('health.hr_geo_pair_required'));
            }
        }

        if ((int) $data['half_day_minutes'] >= (int) $data['full_day_minutes']) {
            return back()->withInput()->with('error', __('health.hr_half_day_too_long'));
        }

        foreach ([
            'overtime_enabled', 'biometric_enabled', 'web_checkin_enabled',
            'mobile_checkin_enabled', 'session_punch_enabled', 'geo_required',
            'cross_branch_allowed',
        ] as $flag) {
            $data[$flag] = (bool) ($data[$flag] ?? false);
        }
        $data['weekly_off_days'] = array_values(array_unique(array_map('intval', $data['weekly_off_days'] ?? [])));
        $data['business_day_start'] = $data['business_day_start'] . ':00';

        if (!Schema::hasColumn('health_hr_policies', 'geo_latitude')) {
            unset($data['geo_latitude'], $data['geo_longitude']);
        } else {
            $data['geo_latitude'] = ($data['geo_latitude'] ?? '') === '' ? null : $data['geo_latitude'];
            $data['geo_longitude'] = ($data['geo_longitude'] ?? '') === '' ? null : $data['geo_longitude'];
        }

        // Turning the geofence on without a single site would leave every punch
        // refused, which reads to the hospital as a broken clock-in button.
        if ($data['geo_required'] && !$this->anySiteConfigured($sites, $data)) {
            return back()->withInput()->with('error', __('health.hr_geo_needs_site'));
        }

        $policy = HealthHrService::policy($this->companyId());
        $policy->fill($data)->save();
        $this->saveSites($sites);
        HealthHrService::forget();

        return redirect()->route('health.hr.policy')->with('success', __('health.hr_policy_saved'));
    }

    /** A branch of this company that this user may also reach. */
    private function ownBranch($branchId): bool
    {
        $branchId = (int) $branchId;
        if ($branchId <= 0) {
            return false;
        }

        $ours = Branch::withoutGlobalScopes()
            ->where('company_id', $this->companyId())
            ->where('id', $branchId)
            ->exists();

        return $ours && HealthScopeService::canAccessBranch($this->user(), $branchId);
    }

    /** Is at least one geofence centre configured (organisation or branch)? */
    private function anySiteConfigured(array $sites, array $data): bool
    {
        if (($data['geo_latitude'] ?? null) !== null && ($data['geo_longitude'] ?? null) !== null) {
            return true;
        }

        foreach ($sites as $site) {
            if (($site['latitude'] ?? '') !== '' && ($site['longitude'] ?? '') !== '') {
                return true;
            }
        }

        // A coordinate somebody saved earlier still counts.
        if (Schema::hasTable('branches') && Schema::hasColumn('branches', 'latitude')) {
            return Branch::withoutGlobalScopes()
                ->where('company_id', $this->companyId())
                ->whereNotNull('latitude')
                ->whereNotNull('longitude')
                ->exists();
        }

        return false;
    }

    /** Store per-branch geofence centres, only for branches this user may see. */
    private function saveSites(array $sites): void
    {
        if (!$sites || !Schema::hasTable('branches') || !Schema::hasColumn('branches', 'latitude')) {
            return;
        }

        foreach ($sites as $branchId => $site) {
            $branchId = (int) $branchId;
            if ($branchId <= 0 || !HealthScopeService::canAccessBranch($this->user(), $branchId)) {
                continue;
            }

            $branch = Branch::withoutGlobalScopes()
                ->where('company_id', $this->companyId())
                ->where('id', $branchId)
                ->first();

            if (!$branch) {
                continue;
            }

            $branch->forceFill([
                'latitude'     => ($site['latitude'] ?? '') === '' ? null : $site['latitude'],
                'longitude'    => ($site['longitude'] ?? '') === '' ? null : $site['longitude'],
                'geo_radius_m' => ($site['geo_radius_m'] ?? '') === '' ? null : (int) $site['geo_radius_m'],
            ])->save();
        }
    }

    // ═══════════════════════ BIOMETRIC DEVICES ═══════════════════════

    /**
     * The healthcare view of the shared biometric integration.
     *
     * The devices, the PIN map and the ADMS push endpoint are the SAME plumbing
     * the retail panels use — a hospital that already owns a ZKTeco clock does
     * not need a second one, and we do not need a second protocol
     * implementation to keep in step. What healthcare adds is the mirror that
     * pulls those punches onto its own attendance timeline.
     */
    public function devices()
    {
        if ($redirect = $this->schemaGuard()) {
            return $redirect;
        }
        if (!Schema::hasTable('pos_biometric_devices')) {
            return redirect()->route('health.hr')->with('error', __('health.hr_biometric_unavailable'));
        }

        $company = $this->company();
        $companyId = $this->companyId();

        $devices = PosBiometricDevice::query()->where('company_id', $companyId)->orderBy('id')->get();
        $staff = HealthPlatformService::staff($company);

        $maps = [];
        if (Schema::hasTable('pos_biometric_user_map')) {
            $maps = PosBiometricUserMap::query()
                ->where('company_id', $companyId)
                ->get()
                ->groupBy('device_id')
                ->map(fn ($rows) => $rows->values())
                ->all();
        }

        $unmappedPins = [];
        if (Schema::hasTable('pos_biometric_punches')) {
            $unmappedPins = PosBiometricPunch::query()
                ->where('company_id', $companyId)
                ->whereNull('user_id')
                ->whereNotNull('device_pin')
                ->select('device_pin', DB::raw('COUNT(*) as hits'), DB::raw('MAX(punched_at) as last_seen'))
                ->groupBy('device_pin')
                ->orderByDesc('hits')
                ->limit(50)
                ->get();
        }

        return view('health.hr.devices', [
            'company'      => $company,
            'devices'      => $devices,
            'maps'         => $maps,
            'staff'        => $staff,
            'profiles'     => HealthHrService::profilesFor($companyId, $staff->pluck('id')->all()),
            'unmappedPins' => $unmappedPins,
            'policy'       => HealthHrService::policy($companyId),
            'canManage'    => $this->canManage(),
        ]);
    }

    public function storeDevice(Request $request)
    {
        if ($redirect = $this->schemaGuard()) {
            return $redirect;
        }
        if (!$this->canManage()) {
            abort(403);
        }

        $companyId = $this->companyId();

        $data = $request->validate([
            'label'     => ['required', 'string', 'max:120'],
            'device_sn' => ['nullable', 'string', 'max:64', Rule::unique('pos_biometric_devices', 'device_sn')],
        ]);

        PosBiometricDevice::create([
            'company_id' => $companyId,
            'label'      => $data['label'],
            'device_sn'  => $data['device_sn'] ?: null,
            'push_token' => PosBiometricDevice::generateToken(),
            'is_active'  => true,
        ]);

        return back()->with('success', __('health.hr_device_added'));
    }

    public function toggleDevice($id)
    {
        if ($redirect = $this->schemaGuard()) {
            return $redirect;
        }
        if (!$this->canManage()) {
            abort(403);
        }

        $device = PosBiometricDevice::query()
            ->where('company_id', $this->companyId())->findOrFail((int) $id);
        $device->is_active = !$device->is_active;
        $device->save();

        return back()->with('success', __('health.hr_device_saved'));
    }

    /**
     * Attach a device PIN to a member, then back-fill the evidence that arrived
     * before anybody got round to mapping it.
     */
    public function mapPin(Request $request)
    {
        if ($redirect = $this->schemaGuard()) {
            return $redirect;
        }
        if (!$this->canManage()) {
            abort(403);
        }

        $companyId = $this->companyId();
        $data = $request->validate([
            'device_pin' => ['required', 'string', 'max:32'],
            'user_id'    => ['required', 'integer'],
            'device_id'  => ['nullable', 'integer'],
        ]);

        $staff = HealthPlatformService::staff($this->company());
        if (!$staff->firstWhere('id', (int) $data['user_id'])) {
            return back()->with('error', __('health.hr_staff_not_found'));
        }

        $pin = trim($data['device_pin']);

        DB::transaction(function () use ($companyId, $pin, $data) {
            PosBiometricUserMap::query()->updateOrCreate(
                ['company_id' => $companyId, 'device_pin' => $pin],
                ['user_id' => (int) $data['user_id'], 'device_id' => $data['device_id'] ?? null]
            );

            // Evidence already on file now knows who it belongs to. The punch
            // rows are not edited into something else — they only gain the
            // identity they were always missing.
            PosBiometricPunch::query()
                ->where('company_id', $companyId)
                ->where('device_pin', $pin)
                ->whereNull('user_id')
                ->update(['user_id' => (int) $data['user_id']]);
        });

        // Re-mirror so the healthcare timeline picks up the newly-owned punches
        // (updateOrCreate on the source ref: a repair, never a duplicate).
        $from = now()->copy()->subDays(60)->startOfDay();
        HealthAttendanceService::mirrorBiometric($companyId, $from, now());
        HealthAttendanceService::recompute($companyId, [(int) $data['user_id']], $from, now());

        return back()->with('success', __('health.hr_pin_mapped'));
    }

    /**
     * Pull device punches onto the healthcare timeline and re-derive the days.
     *
     * Runs on demand rather than on a schedule so a hospital with no cron still
     * gets its attendance, and is safe to press twice: the mirror keys on the
     * source row, so a re-run repairs a gap instead of doubling a day.
     */
    public function syncDevices(Request $request)
    {
        if ($redirect = $this->schemaGuard()) {
            return $redirect;
        }
        if (!$this->canManage()) {
            abort(403);
        }

        $companyId = $this->companyId();
        $days = max(1, min(90, (int) $request->input('days', 14)));
        $from = now()->copy()->subDays($days)->startOfDay();

        $mirrored = HealthAttendanceService::mirrorBiometric($companyId, $from, now());
        $mirrored += HealthAttendanceService::mirrorSessions($companyId, $from, now());

        $staff = HealthPlatformService::staff($this->company());
        HealthAttendanceService::recompute(
            $companyId,
            $staff->pluck('id')->map(fn ($id) => (int) $id)->all(),
            HealthHrService::attendanceDate($from, HealthHrService::policy($companyId)),
            HealthHrService::attendanceDate(now(), HealthHrService::policy($companyId))
        );

        return back()->with('success', __('health.hr_sync_done', ['count' => $mirrored]));
    }
}

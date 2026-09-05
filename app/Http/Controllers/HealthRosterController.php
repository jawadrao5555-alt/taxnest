<?php

namespace App\Http\Controllers;

use App\Models\Branch;
use App\Models\Company;
use App\Models\HealthDepartment;
use App\Models\HealthRosterEntry;
use App\Models\HealthShift;
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
use Illuminate\Validation\Rule;

/**
 * Duty rosters — who is on, where, and whether the floor is actually covered.
 *
 * ── One row per person per day, always ────────────────────────────────────
 *
 * A split duty is ONE roster row pointing at a two-span shift, never two rows.
 * That is not a storage nicety: coverage is counted by rows, so the moment a
 * person can occupy two rows on one date, "3 nurses on nights" silently becomes
 * 4 and the ward is short-staffed on paper it says is fine.
 *
 * ── Coverage is the point ─────────────────────────────────────────────────
 *
 * A roster nobody reads is a spreadsheet. The grid answers the question a duty
 * manager actually has at 6pm — "is tonight covered in every department?" — so
 * coverage is computed alongside the grid, per date and per department, and
 * gaps are shown rather than left to be noticed.
 */
class HealthRosterController extends Controller
{
    /** How many days a roster view may span in one screen. */
    private const MAX_SPAN_DAYS = 42;

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

    private function canManage(): bool
    {
        return HealthAccessService::can($this->user(), 'hr.manage', $this->company());
    }

    private function schemaGuard()
    {
        if (HealthHrService::schemaReady()) {
            return null;
        }

        return redirect()->route('health.dashboard')->with('error', __('health.hr_schema_missing'));
    }

    public function index(Request $request)
    {
        if ($redirect = $this->schemaGuard()) {
            return $redirect;
        }

        $company = $this->company();
        $companyId = $this->companyId();
        $user = $this->user();

        // Default view: the current week, Monday-first, because that is how a
        // duty roster is published.
        $start = $request->query('from')
            ? Carbon::parse($request->query('from'))->startOfDay()
            : now()->copy()->startOfWeek(Carbon::MONDAY);
        $span = (int) $request->query('days', 7);
        $span = max(1, min(self::MAX_SPAN_DAYS, $span));
        $end = $start->copy()->addDays($span - 1);

        $dates = [];
        for ($date = $start->copy(); $date->lte($end); $date->addDay()) {
            $dates[] = $date->copy();
        }

        $staff = HealthHrService::rosterableStaff($company);
        $profiles = HealthHrService::profilesFor($companyId, $staff->pluck('id')->all());

        // Filters: a ward sister rosters her own department, not the hospital.
        $departmentId = (int) $request->query('department_id', 0);
        $branchId = (int) $request->query('branch_id', 0);

        $departments = HealthScopeService::selectableDepartments($user);
        $shifts = HealthShift::withoutGlobalScopes()
            ->where('company_id', $companyId)
            ->orderBy('start_time')
            ->get();

        $entries = [];
        $rows = HealthRosterEntry::withoutGlobalScopes()
            ->where('company_id', $companyId)
            ->whereBetween('duty_date', [$start->toDateString(), $end->toDateString()])
            ->get();
        foreach ($rows as $entry) {
            $entries[(int) $entry->user_id][Carbon::parse($entry->duty_date)->toDateString()] = $entry;
        }

        // Filtering by department keeps a person whose posting matches OR who
        // has a rostered entry in that department inside the window.
        if ($departmentId > 0) {
            $staff = $staff->filter(function ($member) use ($entries, $departmentId, $companyId) {
                foreach ($entries[(int) $member->id] ?? [] as $entry) {
                    if ((int) $entry->health_department_id === $departmentId) {
                        return true;
                    }
                }

                return DB::table('health_department_user')
                    ->where('user_id', $member->id)
                    ->where('health_department_id', $departmentId)
                    ->exists();
            })->values();
        }

        if ($branchId > 0) {
            $staff = $staff->filter(function ($member) use ($profiles, $branchId) {
                $profile = $profiles[(int) $member->id] ?? null;

                return $profile && (int) $profile->branch_id === $branchId;
            })->values();
        }

        return view('health.hr.roster', [
            'company'      => $company,
            'dates'        => $dates,
            'start'        => $start,
            'end'          => $end,
            'span'         => $span,
            'staff'        => $staff,
            'profiles'     => $profiles,
            'entries'      => $entries,
            'shifts'       => $shifts,
            'departments'  => $departments,
            'branches'     => HealthPlatformService::accessibleBranches(),
            'departmentId' => $departmentId,
            'branchId'     => $branchId,
            'coverage'     => $this->coverage($companyId, $dates, $departments),
            'types'        => HealthRosterEntry::TYPES,
            'canManage'    => $this->canManage(),
        ]);
    }

    /**
     * Per-date coverage, split by department and by shift.
     *
     * "Unassigned" is a real bucket, not a rounding error: a nurse rostered
     * without a department still shows up on the floor, and hiding her would
     * make the coverage read worse than reality.
     */
    private function coverage(int $companyId, array $dates, $departments): array
    {
        if (!$dates) {
            return [];
        }

        $first = $dates[0]->toDateString();
        $last = end($dates)->toDateString();

        $rows = HealthRosterEntry::withoutGlobalScopes()
            ->where('company_id', $companyId)
            ->whereBetween('duty_date', [$first, $last])
            // On-call is fetched alongside the covering types but counted
            // separately: a consultant who is reachable from home is not a body
            // on the ward, and folding the two together would make a thin night
            // read as fully staffed.
            ->whereIn('entry_type', array_merge(HealthRosterEntry::COVERING_TYPES, ['on_call']))
            ->get();

        $names = collect($departments)->keyBy('id');

        $out = [];
        foreach ($dates as $date) {
            $out[$date->toDateString()] = ['total' => 0, 'on_call' => 0, 'departments' => []];
        }

        foreach ($rows as $entry) {
            $key = Carbon::parse($entry->duty_date)->toDateString();
            if (!isset($out[$key])) {
                continue;
            }

            if ($entry->entry_type === 'on_call') {
                $out[$key]['on_call']++;
                continue;
            }

            $out[$key]['total']++;

            $deptId = $entry->health_department_id ? (int) $entry->health_department_id : 0;
            $label = $deptId && $names->has($deptId)
                ? $names->get($deptId)->name
                : __('health.hr_unassigned');

            $out[$key]['departments'][$label] = ($out[$key]['departments'][$label] ?? 0) + 1;
        }

        return $out;
    }

    /** Set (or clear) one cell of the grid. */
    public function store(Request $request)
    {
        if ($redirect = $this->schemaGuard()) {
            return $redirect;
        }
        if (!$this->canManage()) {
            abort(403);
        }

        $companyId = $this->companyId();

        $data = $request->validate([
            'user_id'              => ['required', 'integer'],
            'duty_date'            => ['required', 'date'],
            'entry_type'           => ['required', Rule::in(HealthRosterEntry::TYPES)],
            'health_shift_id'      => ['nullable', 'integer'],
            'branch_id'            => ['nullable', 'integer'],
            'health_department_id' => ['nullable', 'integer'],
            'notes'                => ['nullable', 'string', 'max:255'],
        ]);

        $date = Carbon::parse($data['duty_date'])->startOfDay();
        if ($error = $this->guardWrite((int) $data['user_id'], $date, $data)) {
            return back()->with('error', $error);
        }

        $this->writeEntry($companyId, (int) $data['user_id'], $date, $data);

        HealthAttendanceService::recompute($companyId, [(int) $data['user_id']], $date, $date);

        if ($request->expectsJson()) {
            return response()->json(['ok' => true]);
        }

        return back()->with('success', __('health.hr_roster_saved'));
    }

    /**
     * Publish a pattern across a range — the way a roster is really built.
     *
     * Weekly off days and existing approved leave are skipped unless the duty
     * manager explicitly says otherwise, because the common mistake is not a
     * missing shift, it is rostering somebody who is already on leave and only
     * finding out on the night.
     */
    public function bulk(Request $request)
    {
        if ($redirect = $this->schemaGuard()) {
            return $redirect;
        }
        if (!$this->canManage()) {
            abort(403);
        }

        $companyId = $this->companyId();

        $data = $request->validate([
            'user_ids'             => ['required', 'array', 'min:1'],
            'user_ids.*'           => ['integer'],
            'from'                 => ['required', 'date'],
            'to'                   => ['required', 'date', 'after_or_equal:from'],
            'entry_type'           => ['required', Rule::in(HealthRosterEntry::TYPES)],
            'health_shift_id'      => ['nullable', 'integer'],
            'branch_id'            => ['nullable', 'integer'],
            'health_department_id' => ['nullable', 'integer'],
            'weekdays'             => ['nullable', 'array'],
            'weekdays.*'           => ['integer', 'min:1', 'max:7'],
            'skip_off_days'        => ['nullable', 'boolean'],
            'overwrite'            => ['nullable', 'boolean'],
            'notes'                => ['nullable', 'string', 'max:255'],
        ]);

        $from = Carbon::parse($data['from'])->startOfDay();
        $to = Carbon::parse($data['to'])->startOfDay();

        if ($from->diffInDays($to) > 186) {
            return back()->with('error', __('health.hr_roster_range_too_long'));
        }

        $staff = HealthHrService::rosterableStaff($this->company());
        $userIds = array_values(array_filter(
            array_map('intval', $data['user_ids']),
            fn (int $id) => (bool) $staff->firstWhere('id', $id)
        ));
        if (!$userIds) {
            return back()->with('error', __('health.hr_staff_not_found'));
        }

        // The pattern itself is checked once, before a single row is written.
        if ($error = $this->guardPattern($data)) {
            return back()->with('error', $error);
        }

        // One branch was chosen for everybody, so a person who may not be
        // rostered there stops the whole run rather than being dropped from it
        // in silence — the duty manager has to see who cannot go.
        foreach ($userIds as $userId) {
            if ($this->crossBranchBlocked($userId, $data)) {
                return back()->with('error', __('health.hr_cross_branch_blocked'));
            }
        }

        $policy = HealthHrService::policy($companyId);
        $profiles = HealthHrService::profilesFor($companyId, $userIds);
        $weekdays = array_map('intval', $data['weekdays'] ?? []);
        $skipOff = (bool) ($data['skip_off_days'] ?? true);
        $overwrite = (bool) ($data['overwrite'] ?? false);

        $written = 0;
        $skipped = 0;

        foreach ($userIds as $userId) {
            $profile = $profiles[$userId] ?? null;
            $offDays = HealthHrService::offDays($profile, $policy);

            for ($date = $from->copy(); $date->lte($to); $date->addDay()) {
                if ($weekdays && !in_array($date->dayOfWeekIso, $weekdays, true)) {
                    continue;
                }
                if ($skipOff && in_array($date->dayOfWeekIso, $offDays, true)) {
                    $skipped++;
                    continue;
                }
                if (HealthAttendanceService::isMonthLocked($companyId, (int) $date->year, (int) $date->month)) {
                    $skipped++;
                    continue;
                }

                $existing = HealthRosterEntry::withoutGlobalScopes()
                    ->where('company_id', $companyId)
                    ->where('user_id', $userId)
                    ->whereDate('duty_date', $date->toDateString())
                    ->first();

                if ($existing && !$overwrite) {
                    $skipped++;
                    continue;
                }

                $this->writeEntry($companyId, $userId, $date->copy(), $data);
                $written++;
            }
        }

        HealthAttendanceService::recompute($companyId, $userIds, $from, $to);

        return back()->with('success', __('health.hr_roster_bulk_done', [
            'written' => $written,
            'skipped' => $skipped,
        ]));
    }

    /** Remove roster entries in a range (the days fall back to the default pattern). */
    public function clear(Request $request)
    {
        if ($redirect = $this->schemaGuard()) {
            return $redirect;
        }
        if (!$this->canManage()) {
            abort(403);
        }

        $companyId = $this->companyId();

        $data = $request->validate([
            'user_ids'   => ['required', 'array', 'min:1'],
            'user_ids.*' => ['integer'],
            'from'       => ['required', 'date'],
            'to'         => ['required', 'date', 'after_or_equal:from'],
        ]);

        $from = Carbon::parse($data['from'])->startOfDay();
        $to = Carbon::parse($data['to'])->startOfDay();
        $userIds = array_map('intval', $data['user_ids']);

        // A locked month has been paid on what its roster said. Clearing it
        // would restate hours nobody can re-approve.
        if (HealthAttendanceService::lockedMonthInRange($companyId, $from, $to)) {
            return back()->with('error', __('health.hr_month_locked'));
        }

        $removed = HealthRosterEntry::withoutGlobalScopes()
            ->where('company_id', $companyId)
            ->whereIn('user_id', $userIds)
            ->whereBetween('duty_date', [$from->toDateString(), $to->toDateString()])
            ->delete();

        HealthAttendanceService::recompute($companyId, $userIds, $from, $to);

        return back()->with('success', __('health.hr_roster_cleared', ['count' => $removed]));
    }

    /**
     * Shared checks for a single-cell write.
     *
     * @return string|null an error message, or null when the write may proceed
     */
    private function guardWrite(int $userId, Carbon $date, array $data): ?string
    {
        if (HealthAttendanceService::isMonthLocked($this->companyId(), (int) $date->year, (int) $date->month)) {
            return __('health.hr_month_locked');
        }

        if (!HealthHrService::rosterableStaff($this->company())->firstWhere('id', $userId)) {
            return __('health.hr_staff_not_found');
        }

        if ($error = $this->guardPattern($data)) {
            return $error;
        }

        if ($this->crossBranchBlocked($userId, $data)) {
            return __('health.hr_cross_branch_blocked');
        }

        return null;
    }

    /**
     * The checks that belong to the PATTERN rather than to one person: the
     * branch, the department and the shift a row is about to point at.
     *
     * Bulk publish writes the same pattern for many people over many days, so
     * these are answered once for the whole run — but they must be answered.
     * Roster rows carry no foreign keys, so an id nobody checked is stored
     * happily and only surfaces later as a duty that resolves to nothing.
     *
     * @return string|null an error message, or null when the pattern is sound
     */
    private function guardPattern(array $data): ?string
    {
        // Company FIRST, then access. An owner may reach every branch of their
        // OWN company, so the access check alone would happily accept somebody
        // else's branch id — and the roster row keeps no foreign key to catch
        // it later.
        if (!empty($data['branch_id'])) {
            $ours = Branch::withoutGlobalScopes()
                ->where('company_id', $this->companyId())
                ->where('id', (int) $data['branch_id'])
                ->exists();

            if (!$ours || !HealthScopeService::canAccessBranch($this->user(), $data['branch_id'])) {
                return __('health.dept_branch_not_yours');
            }
        }

        if (!empty($data['health_department_id'])) {
            $ours = HealthDepartment::withoutGlobalScopes()
                ->where('company_id', $this->companyId())
                ->where('id', (int) $data['health_department_id'])
                ->exists();

            if (!$ours || !HealthScopeService::canAccessDepartment($this->user(), $data['health_department_id'])) {
                return __('health.hr_department_not_yours');
            }
        }

        if (!empty($data['health_shift_id'])) {
            $exists = HealthShift::withoutGlobalScopes()
                ->where('company_id', $this->companyId())
                ->where('id', (int) $data['health_shift_id'])
                ->exists();

            if (!$exists) {
                return __('health.hr_shift_not_found');
            }
        }

        return null;
    }

    /**
     * Cross-branch off means a person is rostered at their own posting and
     * nowhere else. Refusing the write is what makes the switch real: the
     * calculation can only ever FLAG a cross-branch day after the fact.
     */
    private function crossBranchBlocked(int $userId, array $data): bool
    {
        if (empty($data['branch_id'])) {
            return false;
        }

        $policy = HealthHrService::policy($this->companyId());
        if ($policy->cross_branch_allowed) {
            return false;
        }

        $profile = HealthHrService::profile($this->companyId(), $userId, false);
        $homeBranchId = $profile && $profile->branch_id ? (int) $profile->branch_id : null;

        return $homeBranchId !== null && (int) $data['branch_id'] !== $homeBranchId;
    }

    /** Write one roster row. A shift is only kept for the types that have one. */
    private function writeEntry(int $companyId, int $userId, Carbon $date, array $data): void
    {
        $type = $data['entry_type'];
        // ?? throughout: a nullable field that was simply not posted is absent
        // from the validated data, and reading it as if it were there turns a
        // perfectly ordinary "off day" write into a 500.
        $shiftId = in_array($type, ['shift', 'on_call'], true)
            ? (($data['health_shift_id'] ?? null) ?: null)
            : null;

        // A shift entry with no shift chosen is meaningless; treat it as "off"
        // rather than storing a row that rosters nobody to nothing.
        if ($type === 'shift' && !$shiftId) {
            $type = 'off';
        }

        HealthRosterEntry::withoutGlobalScopes()->updateOrCreate(
            [
                'company_id' => $companyId,
                'user_id'    => $userId,
                'duty_date'  => $date->toDateString(),
            ],
            [
                'entry_type'           => $type,
                'health_shift_id'      => $shiftId,
                'branch_id'            => ($data['branch_id'] ?? null) ?: null,
                'health_department_id' => ($data['health_department_id'] ?? null) ?: null,
                'notes'                => $data['notes'] ?? null,
                'created_by'           => $this->user()->id ?? null,
            ]
        );
    }
}

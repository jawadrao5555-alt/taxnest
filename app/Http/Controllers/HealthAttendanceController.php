<?php

namespace App\Http\Controllers;

use App\Models\Company;
use App\Models\HealthAttendanceCorrection;
use App\Models\HealthAttendanceDay;
use App\Models\HealthAttendancePunch;
use App\Models\HealthShift;
use App\Services\HealthAccessService;
use App\Services\HealthAttendanceService;
use App\Services\HealthHrService;
use App\Services\HealthPayrollService;
use App\Services\HealthPlatformService;
use App\Services\HealthScopeService;
use App\Support\HealthPanel;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\Rule;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * The attendance desk: the day's timeline, the correction trail, the monthly
 * reports and the payroll handoff.
 *
 * ── Three separate permissions, on purpose ────────────────────────────────
 *
 *   hr.attendance.view     read the floor
 *   hr.attendance.correct  ASK for a punch to be fixed
 *   hr.attendance.approve  DECIDE, and lock the month
 *
 * Splitting "ask" from "decide" is the whole audit story. Anyone who can raise
 * a correction leaves a reason; somebody else signs it off; the original punch,
 * its device and its timestamp are never edited. A month is locked once, by a
 * named person, and the totals payroll used are snapshotted at that instant.
 */
class HealthAttendanceController extends Controller
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

    private function can(string $capability): bool
    {
        return HealthAccessService::can($this->user(), $capability, $this->company());
    }

    private function schemaGuard()
    {
        if (HealthHrService::schemaReady()) {
            return null;
        }

        return redirect()->route('health.dashboard')->with('error', __('health.hr_schema_missing'));
    }

    // ═══════════════════════ DAILY TIMELINE ═══════════════════════

    public function index(Request $request)
    {
        if ($redirect = $this->schemaGuard()) {
            return $redirect;
        }

        $company = $this->company();
        $companyId = $this->companyId();
        $policy = HealthHrService::policy($companyId);

        $date = $request->query('date')
            ? Carbon::parse($request->query('date'))->startOfDay()
            : HealthHrService::attendanceDate(now(), $policy);

        $staff = HealthPlatformService::staff($company);
        $profiles = HealthHrService::profilesFor($companyId, $staff->pluck('id')->all());

        $days = HealthAttendanceDay::withoutGlobalScopes()
            ->where('company_id', $companyId)
            ->whereDate('attendance_date', $date->toDateString())
            ->get()
            ->keyBy(fn (HealthAttendanceDay $day) => (int) $day->user_id);

        $shifts = HealthShift::withoutGlobalScopes()->where('company_id', $companyId)->get()->keyBy('id');

        $status = $request->query('status');
        $rows = [];
        foreach ($staff as $member) {
            $day = $days->get((int) $member->id);
            if ($status && $status !== 'all' && ($day->status ?? 'absent') !== $status) {
                continue;
            }
            $rows[] = [
                'user'    => $member,
                'profile' => $profiles[(int) $member->id] ?? null,
                'day'     => $day,
                'shift'   => $day && $day->health_shift_id ? $shifts->get($day->health_shift_id) : null,
            ];
        }

        $tally = ['present' => 0, 'absent' => 0, 'leave' => 0, 'missed_punch' => 0, 'on_duty' => 0, 'late' => 0];
        foreach ($days as $day) {
            match ($day->status) {
                'present', 'on_call' => $tally['present']++,
                'absent'             => $tally['absent']++,
                'leave'              => $tally['leave']++,
                'missed_punch'       => $tally['missed_punch']++,
                default              => null,
            };
            if ($day->is_open) {
                $tally['on_duty']++;
            }
            if ((int) $day->late_minutes > 0) {
                $tally['late']++;
            }
        }

        return view('health.hr.attendance', [
            'company'      => $company,
            'date'         => $date,
            'rows'         => $rows,
            'tally'        => $tally,
            'statuses'     => HealthAttendanceDay::STATUSES,
            'status'       => $status,
            'monthLocked'  => HealthAttendanceService::isMonthLocked($companyId, (int) $date->year, (int) $date->month),
            'canCorrect'   => $this->can('hr.attendance.correct'),
            'canApprove'   => $this->can('hr.attendance.approve'),
            'canManage'    => $this->can('hr.manage'),
            'pendingCount' => HealthAttendanceCorrection::withoutGlobalScopes()
                ->where('company_id', $companyId)->where('status', 'pending')->count(),
        ]);
    }

    /** One person, one day: the evidence and what was derived from it. */
    public function day(Request $request, $userId, $date)
    {
        if ($redirect = $this->schemaGuard()) {
            return $redirect;
        }

        $companyId = $this->companyId();
        $userId = (int) $userId;
        $date = Carbon::parse($date)->startOfDay();

        $staff = HealthPlatformService::staff($this->company());
        $member = $staff->firstWhere('id', $userId);
        if (!$member) {
            return redirect()->route('health.hr.attendance')->with('error', __('health.hr_staff_not_found'));
        }

        $policy = HealthHrService::policy($companyId);

        $day = HealthAttendanceDay::withoutGlobalScopes()
            ->where('company_id', $companyId)
            ->where('user_id', $userId)
            ->whereDate('attendance_date', $date->toDateString())
            ->first();

        // Show the whole surrounding window, disregarded rows included: the
        // point of an evidence timeline is that nothing disappears from it.
        $windowStart = HealthHrService::dayStart($date, $policy)->subHours(6);
        $windowEnd = $windowStart->copy()->addHours(36);

        $punches = HealthAttendancePunch::withoutGlobalScopes()
            ->where('company_id', $companyId)
            ->where('user_id', $userId)
            ->whereBetween('punched_at', [$windowStart, $windowEnd])
            ->orderBy('punched_at')
            ->get();

        $corrections = HealthAttendanceCorrection::withoutGlobalScopes()
            ->where('company_id', $companyId)
            ->where('user_id', $userId)
            ->whereDate('attendance_date', $date->toDateString())
            ->orderByDesc('id')
            ->get();

        return view('health.hr.attendance-day', [
            'company'     => $this->company(),
            'member'      => $member,
            'profile'     => HealthHrService::profile($companyId, $userId, false),
            'date'        => $date,
            'day'         => $day,
            'shift'       => $day && $day->health_shift_id
                ? HealthShift::withoutGlobalScopes()->where('company_id', $companyId)->find($day->health_shift_id)
                : null,
            'punches'     => $punches,
            'corrections' => $corrections,
            'monthLocked' => HealthAttendanceService::isMonthLocked($companyId, (int) $date->year, (int) $date->month),
            'canCorrect'  => $this->can('hr.attendance.correct'),
            'canApprove'  => $this->can('hr.attendance.approve'),
        ]);
    }

    /** Re-derive a window from the evidence that is on file right now. */
    public function recompute(Request $request)
    {
        if ($redirect = $this->schemaGuard()) {
            return $redirect;
        }
        if (!$this->can('hr.attendance.correct')) {
            abort(403);
        }

        $companyId = $this->companyId();
        $data = $request->validate([
            'from'    => ['nullable', 'date'],
            'to'      => ['nullable', 'date'],
            'user_id' => ['nullable', 'integer'],
        ]);

        $from = Carbon::parse($data['from'] ?? now()->toDateString())->startOfDay();
        $to = Carbon::parse($data['to'] ?? $from->toDateString())->startOfDay();
        if ($from->diffInDays($to) > 186) {
            return back()->with('error', __('health.hr_roster_range_too_long'));
        }

        $staff = HealthPlatformService::staff($this->company());
        $userIds = !empty($data['user_id'])
            ? [(int) $data['user_id']]
            : $staff->pluck('id')->map(fn ($id) => (int) $id)->all();

        $written = HealthAttendanceService::recompute($companyId, $userIds, $from, $to);

        return back()->with('success', __('health.hr_recomputed', ['count' => $written]));
    }

    // ═══════════════════════ CORRECTIONS ═══════════════════════

    public function corrections(Request $request)
    {
        if ($redirect = $this->schemaGuard()) {
            return $redirect;
        }

        $companyId = $this->companyId();
        $status = $request->query('status', 'pending');

        $query = HealthAttendanceCorrection::withoutGlobalScopes()
            ->where('company_id', $companyId)
            ->orderByDesc('id');

        if ($status !== 'all' && in_array($status, HealthAttendanceCorrection::STATUSES, true)) {
            $query->where('status', $status);
        }

        $corrections = $query->limit(400)->get();
        $staff = HealthPlatformService::staff($this->company());

        return view('health.hr.corrections', [
            'company'     => $this->company(),
            'corrections' => $corrections,
            'names'       => $staff->keyBy(fn ($member) => (int) $member->id),
            'staff'       => $staff,
            'statuses'    => HealthAttendanceCorrection::STATUSES,
            'types'       => HealthAttendanceCorrection::TYPES,
            'status'      => $status,
            'canCorrect'  => $this->can('hr.attendance.correct'),
            'canApprove'  => $this->can('hr.attendance.approve'),
        ]);
    }

    /** Raise a correction. A reason is mandatory — that is the whole record. */
    public function storeCorrection(Request $request)
    {
        if ($redirect = $this->schemaGuard()) {
            return $redirect;
        }
        if (!$this->can('hr.attendance.correct')) {
            abort(403);
        }

        $companyId = $this->companyId();
        $data = $this->validateCorrection($request, true);

        $staff = HealthPlatformService::staff($this->company());
        if (!$staff->firstWhere('id', (int) $data['user_id'])) {
            return back()->withInput()->with('error', __('health.hr_staff_not_found'));
        }

        $result = $this->createCorrection($companyId, (int) $data['user_id'], $data);
        if (is_string($result)) {
            return back()->withInput()->with('error', $result);
        }

        return back()->with('success', __('health.hr_correction_created'));
    }

    /**
     * Approve or reject. Approving applies the change and re-derives the day;
     * the request row keeps who asked, who decided, when, and why.
     */
    public function reviewCorrection(Request $request, $id)
    {
        if ($redirect = $this->schemaGuard()) {
            return $redirect;
        }
        if (!$this->can('hr.attendance.approve')) {
            abort(403);
        }

        $companyId = $this->companyId();
        $correction = HealthAttendanceCorrection::withoutGlobalScopes()
            ->where('company_id', $companyId)->findOrFail((int) $id);

        $data = $request->validate([
            'decision'    => ['required', Rule::in(['approved', 'rejected'])],
            'review_note' => ['nullable', 'string', 'max:500'],
        ]);

        if ($correction->status !== 'pending') {
            return back()->with('error', __('health.hr_correction_already_reviewed'));
        }

        $date = Carbon::parse($correction->attendance_date);
        if (HealthAttendanceService::isMonthLocked($companyId, (int) $date->year, (int) $date->month)) {
            return back()->with('error', __('health.hr_month_locked'));
        }

        // Nobody signs off their own correction. If the only person who can
        // approve is the person who asked, the trail proves nothing.
        if ((int) $correction->requested_by === (int) $this->user()->id
            && !HealthAccessService::isOwner($this->user())) {
            return back()->with('error', __('health.hr_correction_self_approve'));
        }

        $correction->forceFill([
            'status'      => $data['decision'],
            'reviewed_by' => $this->user()->id,
            'reviewed_at' => now(),
            'review_note' => $data['review_note'] ?? null,
        ])->save();

        if ($data['decision'] === 'approved') {
            HealthAttendanceService::applyCorrection($correction);

            return back()->with('success', __('health.hr_correction_approved'));
        }

        return back()->with('success', __('health.hr_correction_rejected'));
    }

    /** @return array<string,mixed> */
    public function validateCorrection(Request $request, bool $withUser): array
    {
        $rules = [
            'attendance_date'   => ['required', 'date'],
            'type'              => ['required', Rule::in(HealthAttendanceCorrection::TYPES)],
            'punch_at'          => ['nullable', 'date'],
            'direction'         => ['nullable', Rule::in(['in', 'out'])],
            'target_punch_id'   => ['nullable', 'integer'],
            'requested_status'  => ['nullable', Rule::in(HealthAttendanceDay::STATUSES)],
            'requested_minutes' => ['nullable', 'integer', 'min:0', 'max:1440'],
            'reason'            => ['required', 'string', 'min:3', 'max:500'],
        ];
        if ($withUser) {
            $rules['user_id'] = ['required', 'integer'];
        }

        return $request->validate($rules);
    }

    /**
     * Create a pending correction, or return an error string.
     *
     * @return HealthAttendanceCorrection|string
     */
    public function createCorrection(int $companyId, int $userId, array $data)
    {
        $date = Carbon::parse($data['attendance_date'])->startOfDay();

        if (HealthAttendanceService::isMonthLocked($companyId, (int) $date->year, (int) $date->month)) {
            return __('health.hr_month_locked');
        }

        // Each type needs its own field filled in; a half-empty request would
        // be approved into a change nobody can describe.
        switch ($data['type']) {
            case 'add_punch':
                if (empty($data['punch_at']) || empty($data['direction'])) {
                    return __('health.hr_correction_needs_punch');
                }
                break;
            case 'disregard_punch':
                if (empty($data['target_punch_id'])) {
                    return __('health.hr_correction_needs_target');
                }
                $exists = HealthAttendancePunch::withoutGlobalScopes()
                    ->where('company_id', $companyId)
                    ->where('user_id', $userId)
                    ->where('id', (int) $data['target_punch_id'])
                    ->exists();
                if (!$exists) {
                    return __('health.hr_correction_needs_target');
                }
                break;
            case 'set_status':
                if (empty($data['requested_status'])) {
                    return __('health.hr_correction_needs_status');
                }
                break;
            case 'set_hours':
                if (!isset($data['requested_minutes'])) {
                    return __('health.hr_correction_needs_minutes');
                }
                break;
        }

        return HealthAttendanceCorrection::create([
            'company_id'        => $companyId,
            'user_id'           => $userId,
            'attendance_date'   => $date->toDateString(),
            'type'              => $data['type'],
            'punch_at'          => $data['punch_at'] ?? null,
            'direction'         => $data['direction'] ?? null,
            'target_punch_id'   => $data['target_punch_id'] ?? null,
            'requested_status'  => $data['requested_status'] ?? null,
            'requested_minutes' => $data['requested_minutes'] ?? null,
            'reason'            => $data['reason'],
            'status'            => 'pending',
            'requested_by'      => $this->user()->id ?? null,
        ]);
    }

    // ═══════════════════════ REPORTS ═══════════════════════

    public function reports(Request $request)
    {
        if ($redirect = $this->schemaGuard()) {
            return $redirect;
        }

        $company = $this->company();
        $companyId = $this->companyId();

        [$year, $month] = $this->period($request);
        $staff = HealthPlatformService::staff($company);
        $totals = HealthPayrollService::monthlyTotals($companyId, $year, $month, $staff);

        $start = Carbon::create($year, $month, 1)->startOfDay();
        $end = $start->copy()->endOfMonth();

        // The per-day matrix: one row per person, one cell per date. This is
        // the sheet a hospital actually posts on the wall.
        $matrix = [];
        foreach (HealthAttendanceDay::withoutGlobalScopes()
            ->where('company_id', $companyId)
            ->whereBetween('attendance_date', [$start->toDateString(), $end->toDateString()])
            ->get() as $day) {
            $matrix[(int) $day->user_id][Carbon::parse($day->attendance_date)->day] = $day;
        }

        // Department roll-up, so a matron can see which ward is short.
        $departments = HealthScopeService::selectableDepartments($this->user());
        $byDepartment = [];
        foreach ($departments as $department) {
            $byDepartment[(int) $department->id] = [
                'name' => $department->name, 'present' => 0, 'absent' => 0,
                'leave' => 0, 'late' => 0, 'overtime_minutes' => 0,
            ];
        }
        $byDepartment[0] = [
            'name' => __('health.hr_unassigned'), 'present' => 0, 'absent' => 0,
            'leave' => 0, 'late' => 0, 'overtime_minutes' => 0,
        ];

        foreach ($matrix as $days) {
            foreach ($days as $day) {
                $key = $day->health_department_id ? (int) $day->health_department_id : 0;
                if (!isset($byDepartment[$key])) {
                    $key = 0;
                }
                match ($day->status) {
                    'present', 'on_call' => $byDepartment[$key]['present']++,
                    'absent'             => $byDepartment[$key]['absent']++,
                    'leave'              => $byDepartment[$key]['leave']++,
                    default              => null,
                };
                if ((int) $day->late_minutes > 0) {
                    $byDepartment[$key]['late']++;
                }
                $byDepartment[$key]['overtime_minutes'] += (int) $day->overtime_minutes;
            }
        }

        return view('health.hr.reports', [
            'company'      => $company,
            'year'         => $year,
            'month'        => $month,
            'start'        => $start,
            'end'          => $end,
            'staff'        => $staff,
            'totals'       => $totals,
            'matrix'       => $matrix,
            'byDepartment' => $byDepartment,
            'lock'         => HealthPayrollService::lock($companyId, $year, $month),
            'canPayroll'   => $this->can('hr.payroll.view'),
        ]);
    }

    /** Monthly summary as CSV. Available whether or not the month is locked. */
    public function exportReport(Request $request): StreamedResponse
    {
        abort_unless(HealthHrService::schemaReady(), 404);

        $companyId = $this->companyId();
        [$year, $month] = $this->period($request);

        $staff = HealthPlatformService::staff($this->company());
        $totals = HealthPayrollService::monthlyTotals($companyId, $year, $month, $staff);

        // This export lives on the attendance permission, so it carries the
        // money columns ONLY for somebody who also holds the payroll one.
        // Otherwise an attendance-only manager could read every salary in the
        // hospital just by asking for the CSV the screen already hides.
        return $this->csv(
            HealthPayrollService::exportRows($totals, $this->can('hr.payroll.view')),
            sprintf('attendance-%04d-%02d.csv', $year, $month)
        );
    }

    // ═══════════════════════ PAYROLL HANDOFF ═══════════════════════

    public function payroll(Request $request)
    {
        if ($redirect = $this->schemaGuard()) {
            return $redirect;
        }

        $companyId = $this->companyId();
        [$year, $month] = $this->period($request);

        $staff = HealthPlatformService::staff($this->company());
        $lock = HealthPayrollService::lock($companyId, $year, $month);
        $locked = $lock && $lock->isActive();

        // Unapproved corrections are the reason a month should not be locked
        // yet: locking with one open bakes a number somebody is disputing.
        $start = Carbon::create($year, $month, 1)->startOfDay();
        $pending = HealthAttendanceCorrection::withoutGlobalScopes()
            ->where('company_id', $companyId)
            ->where('status', 'pending')
            ->whereBetween('attendance_date', [$start->toDateString(), $start->copy()->endOfMonth()->toDateString()])
            ->count();

        return view('health.hr.payroll', [
            'company'    => $this->company(),
            'year'       => $year,
            'month'      => $month,
            'start'      => $start,
            'totals'     => HealthPayrollService::monthlyTotals($companyId, $year, $month, $staff),
            'lock'       => $lock,
            'locked'     => $locked,
            'pending'    => $pending,
            'lockedBy'   => $lock && $lock->locked_by ? $staff->firstWhere('id', (int) $lock->locked_by) : null,
            'canApprove' => $this->can('hr.attendance.approve'),
        ]);
    }

    public function lock(Request $request)
    {
        if ($redirect = $this->schemaGuard()) {
            return $redirect;
        }
        if (!$this->can('hr.attendance.approve')) {
            abort(403);
        }

        $companyId = $this->companyId();
        [$year, $month] = $this->period($request);

        $note = $request->validate(['note' => ['nullable', 'string', 'max:255']])['note'] ?? null;

        $start = Carbon::create($year, $month, 1)->startOfDay();
        if ($start->isFuture()) {
            return back()->with('error', __('health.hr_lock_future'));
        }

        $pending = HealthAttendanceCorrection::withoutGlobalScopes()
            ->where('company_id', $companyId)
            ->where('status', 'pending')
            ->whereBetween('attendance_date', [$start->toDateString(), $start->copy()->endOfMonth()->toDateString()])
            ->exists();

        if ($pending) {
            return back()->with('error', __('health.hr_lock_pending_corrections'));
        }

        $staff = HealthPlatformService::staff($this->company());

        // Derive one last time, so the snapshot is of current evidence rather
        // than whatever was last computed.
        HealthAttendanceService::recompute(
            $companyId,
            $staff->pluck('id')->map(fn ($id) => (int) $id)->all(),
            $start,
            $start->copy()->endOfMonth()
        );

        HealthPayrollService::lockMonth($companyId, $year, $month, (int) $this->user()->id, $staff, $note);

        return back()->with('success', __('health.hr_month_locked_done'));
    }

    public function unlock(Request $request)
    {
        if ($redirect = $this->schemaGuard()) {
            return $redirect;
        }
        if (!$this->can('hr.attendance.approve')) {
            abort(403);
        }

        $companyId = $this->companyId();
        [$year, $month] = $this->period($request);

        if (!HealthPayrollService::unlockMonth($companyId, $year, $month, (int) $this->user()->id)) {
            return back()->with('error', __('health.hr_month_not_locked'));
        }

        return back()->with('success', __('health.hr_month_unlocked'));
    }

    /**
     * Export the payroll input.
     *
     * Refuses an unlocked month. Payroll built on numbers that are still moving
     * is the single failure this whole lock exists to prevent — an export that
     * quietly hands over a draft is worse than no export.
     */
    public function exportPayroll(Request $request)
    {
        abort_unless(HealthHrService::schemaReady(), 404);

        $companyId = $this->companyId();
        [$year, $month] = $this->period($request);

        $lock = HealthPayrollService::lock($companyId, $year, $month);
        if (!$lock || !$lock->isActive()) {
            return back()->with('error', __('health.hr_export_needs_lock'));
        }

        $staff = HealthPlatformService::staff($this->company());
        $totals = HealthPayrollService::monthlyTotals($companyId, $year, $month, $staff);

        return $this->csv(
            HealthPayrollService::exportRows($totals),
            sprintf('payroll-input-%04d-%02d.csv', $year, $month)
        );
    }

    // ═══════════════════════ helpers ═══════════════════════

    /** @return array{0:int,1:int} */
    private function period(Request $request): array
    {
        $raw = (string) $request->query('month', $request->input('month', ''));
        if (preg_match('/^(\d{4})-(\d{1,2})$/', $raw, $m)) {
            return [(int) $m[1], max(1, min(12, (int) $m[2]))];
        }

        return [(int) now()->year, (int) now()->month];
    }

    private function csv(array $rows, string $filename): StreamedResponse
    {
        return response()->streamDownload(function () use ($rows) {
            $out = fopen('php://output', 'w');
            // BOM: Excel on a Pakistani desktop otherwise mangles Urdu names.
            fwrite($out, "\xEF\xBB\xBF");
            foreach ($rows as $row) {
                fputcsv($out, $row);
            }
            fclose($out);
        }, $filename, ['Content-Type' => 'text/csv; charset=UTF-8']);
    }
}

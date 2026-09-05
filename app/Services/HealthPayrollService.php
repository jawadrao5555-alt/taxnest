<?php

namespace App\Services;

use App\Models\HealthAttendanceDay;
use App\Models\HealthAttendanceLock;
use App\Models\HealthLeaveRequest;
use App\Models\HealthLeaveType;
use App\Models\HealthStaffProfile;
use App\Models\User;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

/**
 * Monthly attendance summaries, the lock that approves them, and the payroll
 * handoff that reads them.
 *
 * ── What "feeds payroll" means here ───────────────────────────────────────
 *
 * This class produces payroll INPUTS: payable days, leave split by paid and
 * unpaid, worked hours, approved overtime hours, late and absence counts, and
 * — only when the organisation stored a basic salary — an indicative gross.
 * It files nothing with anybody and computes no statutory deduction. That is
 * out of scope on purpose; the plan asks for attendance to feed payroll, not
 * for this product to become a payroll filer.
 *
 * ── Why the lock exists ───────────────────────────────────────────────────
 *
 * Attendance days are DERIVED, so they change whenever a punch, a roster or a
 * policy changes. Payroll cannot be built on something that moves. Locking a
 * month stamps every day in it, snapshots the per-staff totals into the lock
 * row, and is the only state in which the handoff will export. Unlocking is
 * recorded with its own stamp, so "who reopened March, and when" always has an
 * answer.
 */
class HealthPayrollService
{
    /**
     * Per-staff totals for a month.
     *
     * Reads the LOCK SNAPSHOT when the month is locked, so a later policy
     * change can never quietly restate a month that has already been paid.
     * Falls back to a live computation while the month is still open.
     *
     * @param  Collection<int,User>  $staff
     * @return array<int,array<string,mixed>> keyed by user id
     */
    public static function monthlyTotals(int $companyId, int $year, int $month, Collection $staff): array
    {
        $lock = self::lock($companyId, $year, $month);

        if ($lock && $lock->isActive() && is_array($lock->totals) && $lock->totals !== []) {
            return self::rehydrate($lock->totals, $staff);
        }

        return self::computeTotals($companyId, $year, $month, $staff);
    }

    /**
     * Build the totals from the derived days. Public so the lock can snapshot
     * exactly what the screen was showing at the moment it was approved.
     *
     * @param  Collection<int,User>  $staff
     */
    public static function computeTotals(int $companyId, int $year, int $month, Collection $staff): array
    {
        $userIds = $staff->pluck('id')->map(fn ($id) => (int) $id)->all();
        if (!$userIds || !HealthHrService::schemaReady()) {
            return [];
        }

        $start = Carbon::create($year, $month, 1)->startOfDay();
        $end = $start->copy()->endOfMonth();

        $rows = [];
        foreach ($staff as $member) {
            $rows[(int) $member->id] = self::emptyRow($member);
        }

        $profiles = HealthHrService::profilesFor($companyId, $userIds);
        $paidLeaveTypes = HealthLeaveType::withoutGlobalScopes()
            ->where('company_id', $companyId)
            ->get()
            ->keyBy('id');

        $days = HealthAttendanceDay::withoutGlobalScopes()
            ->where('company_id', $companyId)
            ->whereIn('user_id', $userIds)
            ->whereBetween('attendance_date', [$start->toDateString(), $end->toDateString()])
            ->get();

        // Which leave requests are paid — decided by the leave TYPE, so an
        // unpaid-leave day never lands in the payable bucket.
        $leavePaid = [];
        foreach (HealthLeaveRequest::withoutGlobalScopes()
            ->where('company_id', $companyId)
            ->whereIn('user_id', $userIds)
            ->where('status', 'approved')
            ->get() as $request) {
            $type = $request->health_leave_type_id
                ? $paidLeaveTypes->get($request->health_leave_type_id)
                : null;
            $leavePaid[(int) $request->id] = $type ? (bool) $type->is_paid : true;
        }

        foreach ($days as $day) {
            $userId = (int) $day->user_id;
            if (!isset($rows[$userId])) {
                continue;
            }

            $row = &$rows[$userId];
            $row['worked_minutes'] += (int) $day->worked_minutes;
            $row['overtime_minutes'] += (int) $day->overtime_minutes;
            $row['late_minutes'] += (int) $day->late_minutes;
            $row['early_leave_minutes'] += (int) $day->early_leave_minutes;

            if ((int) $day->late_minutes > 0) {
                $row['late_days']++;
            }
            if ((int) $day->early_leave_minutes > 0) {
                $row['early_leave_days']++;
            }
            if ($day->cross_branch) {
                $row['cross_branch_days']++;
            }

            switch ($day->status) {
                case 'present':
                    $row['present_days']++;
                    $row['payable_days'] += 1;
                    break;
                case 'on_call':
                    $row['on_call_days']++;
                    $row['payable_days'] += 1;
                    break;
                case 'half_day':
                    $row['half_days']++;
                    $row['payable_days'] += 0.5;
                    break;
                case 'leave':
                    $isPaid = $day->leave_request_id
                        ? ($leavePaid[(int) $day->leave_request_id] ?? true)
                        : true;
                    if ($isPaid) {
                        $row['paid_leave_days']++;
                        $row['payable_days'] += 1;
                    } else {
                        $row['unpaid_leave_days']++;
                    }
                    break;
                case 'holiday':
                    $row['holiday_days']++;
                    $row['payable_days'] += 1;
                    break;
                case 'weekly_off':
                    $row['off_days']++;
                    $row['payable_days'] += 1;
                    break;
                case 'exempt':
                    $row['exempt_days']++;
                    $row['payable_days'] += 1;
                    break;
                case 'missed_punch':
                    $row['missed_punch_days']++;
                    // Deliberately NOT payable on its own: a missed punch is an
                    // open question, and the answer is a correction, not a guess.
                    break;
                default:
                    $row['absent_days']++;
                    break;
            }
            unset($row);
        }

        // ── Indicative gross from the stored payroll inputs ──
        $daysInMonth = (int) $start->daysInMonth;
        foreach ($rows as $userId => &$row) {
            /** @var HealthStaffProfile|null $profile */
            $profile = $profiles[$userId] ?? null;
            $row['employee_code'] = $profile?->employee_code;
            $row['designation'] = $profile?->designation;
            $row['employment_status'] = $profile?->employment_status ?? 'active';
            $row['basic_salary'] = $profile?->basic_salary !== null ? (float) $profile->basic_salary : null;
            $row['overtime_rate'] = $profile?->overtime_hourly_rate !== null
                ? (float) $profile->overtime_hourly_rate : null;

            if ($row['basic_salary'] !== null && $daysInMonth > 0) {
                $perDay = $row['basic_salary'] / $daysInMonth;
                $row['basic_earned'] = round($perDay * $row['payable_days'], 2);
                $row['overtime_pay'] = $row['overtime_rate'] !== null
                    ? round(($row['overtime_minutes'] / 60) * $row['overtime_rate'], 2)
                    : 0.0;
                $row['gross'] = round($row['basic_earned'] + $row['overtime_pay'], 2);
            }
        }
        unset($row);

        return $rows;
    }

    /** The shape every total row has, so a screen never reads a missing key. */
    private static function emptyRow(User $member): array
    {
        return [
            'user_id'             => (int) $member->id,
            'name'                => $member->name,
            'employee_code'       => null,
            'designation'         => null,
            'employment_status'   => 'active',
            'present_days'        => 0,
            'half_days'           => 0,
            'absent_days'         => 0,
            'paid_leave_days'     => 0,
            'unpaid_leave_days'   => 0,
            'holiday_days'        => 0,
            'off_days'            => 0,
            'on_call_days'        => 0,
            'exempt_days'         => 0,
            'missed_punch_days'   => 0,
            'late_days'           => 0,
            'early_leave_days'    => 0,
            'cross_branch_days'   => 0,
            'payable_days'        => 0.0,
            'worked_minutes'      => 0,
            'overtime_minutes'    => 0,
            'late_minutes'        => 0,
            'early_leave_minutes' => 0,
            'basic_salary'        => null,
            'overtime_rate'       => null,
            'basic_earned'        => null,
            'overtime_pay'        => null,
            'gross'               => null,
        ];
    }

    /**
     * Read a snapshot back, keeping the current staff list's names but the
     * snapshot's numbers — a person renamed after the lock still reads right.
     *
     * @param  Collection<int,User>  $staff
     */
    private static function rehydrate(array $snapshot, Collection $staff): array
    {
        $names = $staff->keyBy(fn (User $member) => (int) $member->id);
        $rows = [];

        foreach ($snapshot as $userId => $row) {
            $userId = (int) $userId;
            if (!is_array($row)) {
                continue;
            }
            $member = $names->get($userId);
            $row['user_id'] = $userId;
            $row['name'] = $member->name ?? ($row['name'] ?? '');
            $rows[$userId] = $row;
        }

        return $rows;
    }

    public static function lock(int $companyId, int $year, int $month): ?HealthAttendanceLock
    {
        if (!HealthHrService::schemaReady()) {
            return null;
        }

        return HealthAttendanceLock::withoutGlobalScopes()
            ->where('company_id', $companyId)
            ->where('period_year', $year)
            ->where('period_month', $month)
            ->first();
    }

    /**
     * Approve a month: freeze every derived day in it and snapshot the totals.
     *
     * @param  Collection<int,User>  $staff
     */
    public static function lockMonth(int $companyId, int $year, int $month, int $lockedBy, Collection $staff, ?string $note = null): ?HealthAttendanceLock
    {
        if (!HealthHrService::schemaReady()) {
            return null;
        }

        $totals = self::computeTotals($companyId, $year, $month, $staff);

        $start = Carbon::create($year, $month, 1)->startOfDay();
        $end = $start->copy()->endOfMonth();

        HealthAttendanceDay::withoutGlobalScopes()
            ->where('company_id', $companyId)
            ->whereBetween('attendance_date', [$start->toDateString(), $end->toDateString()])
            ->update(['is_locked' => true]);

        $lock = self::lock($companyId, $year, $month);
        if (!$lock) {
            $lock = new HealthAttendanceLock([
                'company_id'   => $companyId,
                'period_year'  => $year,
                'period_month' => $month,
            ]);
        }

        $lock->forceFill([
            'company_id'   => $companyId,
            'period_year'  => $year,
            'period_month' => $month,
            'locked_by'    => $lockedBy,
            'locked_at'    => now(),
            'note'         => $note,
            'totals'       => $totals,
            'unlocked_by'  => null,
            'unlocked_at'  => null,
        ])->save();

        return $lock;
    }

    /** Reopen a month. The snapshot stays on the row as the paid-out record. */
    public static function unlockMonth(int $companyId, int $year, int $month, int $unlockedBy): bool
    {
        $lock = self::lock($companyId, $year, $month);
        if (!$lock || !$lock->isActive()) {
            return false;
        }

        $start = Carbon::create($year, $month, 1)->startOfDay();
        $end = $start->copy()->endOfMonth();

        HealthAttendanceDay::withoutGlobalScopes()
            ->where('company_id', $companyId)
            ->whereBetween('attendance_date', [$start->toDateString(), $end->toDateString()])
            ->update(['is_locked' => false]);

        $lock->forceFill([
            'unlocked_by' => $unlockedBy,
            'unlocked_at' => now(),
        ])->save();

        return true;
    }

    /**
     * The payroll handoff as CSV rows (header first).
     *
     * @return array<int,array<int,string|int|float>>
     */
    public static function exportRows(array $totals, bool $withPay = true): array
    {
        $head = [
            'Employee Code', 'Name', 'Designation', 'Employment Status',
            'Present', 'Half Day', 'Absent', 'Paid Leave', 'Unpaid Leave',
            'Holiday', 'Weekly Off', 'On Call', 'Exempt', 'Missed Punch',
            'Late Days', 'Early Leave Days', 'Cross Branch Days',
            'Payable Days', 'Worked Hours', 'Overtime Hours',
        ];
        if ($withPay) {
            array_push($head, 'Basic Salary', 'Basic Earned', 'Overtime Pay', 'Gross');
        }

        $out = [$head];

        foreach ($totals as $row) {
            $line = [
                (string) ($row['employee_code'] ?? ''),
                (string) ($row['name'] ?? ''),
                (string) ($row['designation'] ?? ''),
                (string) ($row['employment_status'] ?? ''),
                (int) $row['present_days'],
                (int) $row['half_days'],
                (int) $row['absent_days'],
                (int) $row['paid_leave_days'],
                (int) $row['unpaid_leave_days'],
                (int) $row['holiday_days'],
                (int) $row['off_days'],
                (int) $row['on_call_days'],
                (int) $row['exempt_days'],
                (int) $row['missed_punch_days'],
                (int) $row['late_days'],
                (int) $row['early_leave_days'],
                (int) $row['cross_branch_days'],
                (float) $row['payable_days'],
                round(((int) $row['worked_minutes']) / 60, 2),
                round(((int) $row['overtime_minutes']) / 60, 2),
            ];

            // Compensation is a separate permission from attendance. An
            // attendance-only manager gets the same sheet with the money
            // columns absent — not blanked, absent.
            if ($withPay) {
                array_push(
                    $line,
                    $row['basic_salary'] ?? '',
                    $row['basic_earned'] ?? '',
                    $row['overtime_pay'] ?? '',
                    $row['gross'] ?? ''
                );
            }

            $out[] = $line;
        }

        return $out;
    }
}

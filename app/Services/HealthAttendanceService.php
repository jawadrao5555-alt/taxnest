<?php

namespace App\Services;

use App\Models\HealthAttendanceCorrection;
use App\Models\HealthAttendanceDay;
use App\Models\HealthAttendanceLock;
use App\Models\HealthAttendancePunch;
use App\Models\HealthHoliday;
use App\Models\HealthHrPolicy;
use App\Models\HealthLeaveRequest;
use App\Models\HealthRosterEntry;
use App\Models\HealthShift;
use App\Models\HealthStaffProfile;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Healthcare attendance — capture, normalise, calculate.
 *
 * ── The two halves ────────────────────────────────────────────────────────
 *
 * CAPTURE writes evidence into one table, health_attendance_punches, whatever
 * it came from: a biometric device push (mirrored out of the shared
 * pos_biometric_punches ingest so the existing hardware integration is reused,
 * not duplicated), a web check-in, a mobile check-in, a CSV import, an optional
 * panel-session mirror, or a manual punch created by an APPROVED correction.
 * Mirrors are idempotent on (company, source, source_ref), so re-running one
 * repairs a gap instead of doubling a day.
 *
 * CALCULATION never writes evidence. It reads the roster (what was supposed to
 * happen) and the counted punches (what did), and derives one summary row per
 * person per day. Because the summary is derived, a policy fix repairs history
 * on its own — and because a locked month refuses recomputation, a fix can
 * never rewrite a month payroll already went out on.
 *
 * ── Why punches are assigned to a day the way they are ────────────────────
 *
 * A hospital never sleeps, so there is no hour at which "the day rolls over"
 * for everybody. Days are therefore computed in ASCENDING order per person and
 * each punch is consumed by the FIRST day whose duty window contains it. A
 * night nurse's 07:40 punch-out is consumed by last night's shift, so the
 * morning day that follows does not also count it. Without that, every
 * overnight shift would be paid twice and the morning would look like a
 * mysterious extra hour.
 */
class HealthAttendanceService
{
    /** How long before a shift starts we still accept an arrival punch. */
    private const EARLY_WINDOW_MINUTES = 240;

    /** How long after a shift ends we still accept a departure punch. */
    private const LATE_WINDOW_MINUTES = 360;

    /** A day with worked minutes below the half-day threshold. */
    public const FLAG_SHORT_DAY = 'short_day';

    // ═══════════════════════ CAPTURE ═══════════════════════

    /**
     * Write one punch into the timeline.
     *
     * Idempotent whenever a source_ref is given: the mirror can be re-run for
     * any window without duplicating, and a re-run also back-fills user_id on
     * rows whose biometric PIN was mapped after the fact.
     */
    public static function recordPunch(array $data): ?HealthAttendancePunch
    {
        if (!HealthHrService::schemaReady()) {
            return null;
        }

        $companyId = (int) ($data['company_id'] ?? 0);
        $punchedAt = $data['punched_at'] ?? null;
        if ($companyId <= 0 || !$punchedAt) {
            return null;
        }

        $payload = [
            'company_id'           => $companyId,
            'user_id'              => $data['user_id'] ?? null,
            'punched_at'           => $punchedAt instanceof Carbon ? $punchedAt : Carbon::parse($punchedAt),
            'direction'            => in_array($data['direction'] ?? null, HealthAttendancePunch::DIRECTIONS, true)
                ? $data['direction'] : 'unknown',
            'source'               => in_array($data['source'] ?? null, HealthAttendancePunch::SOURCES, true)
                ? $data['source'] : 'web',
            'source_ref'           => $data['source_ref'] ?? null,
            'branch_id'            => $data['branch_id'] ?? null,
            'health_department_id' => $data['health_department_id'] ?? null,
            'device_id'            => $data['device_id'] ?? null,
            'device_pin'           => $data['device_pin'] ?? null,
            'latitude'             => $data['latitude'] ?? null,
            'longitude'            => $data['longitude'] ?? null,
            'ip'                   => $data['ip'] ?? null,
            'note'                 => $data['note'] ?? null,
            'recorded_by'          => $data['recorded_by'] ?? null,
            'correction_id'        => $data['correction_id'] ?? null,
        ];

        if ($payload['source_ref']) {
            $keys = [
                'company_id' => $companyId,
                'source'     => $payload['source'],
                'source_ref' => $payload['source_ref'],
            ];

            return HealthAttendancePunch::withoutGlobalScopes()
                ->updateOrCreate($keys, $payload);
        }

        return HealthAttendancePunch::create($payload);
    }

    /**
     * Copy biometric device punches into the healthcare timeline.
     *
     * The device ingest itself (ADMS push / serial handshake / CSV import) is
     * shared platform plumbing and stays exactly where it is. This mirror is
     * the seam: healthcare reads the device evidence, it never re-implements
     * the protocol.
     *
     * @return int rows mirrored
     */
    public static function mirrorBiometric(int $companyId, Carbon $from, Carbon $to): int
    {
        if (!HealthHrService::schemaReady() || !Schema::hasTable('pos_biometric_punches')) {
            return 0;
        }

        $policy = HealthHrService::policy($companyId);
        if (!$policy || !$policy->biometric_enabled) {
            return 0;
        }

        $rows = DB::table('pos_biometric_punches')
            ->where('company_id', $companyId)
            ->whereBetween('punched_at', [$from, $to])
            ->orderBy('punched_at')
            ->get();

        $mirrored = 0;
        foreach ($rows as $row) {
            $direction = match ($row->punch_type ?? null) {
                'check_in'  => 'in',
                'check_out' => 'out',
                default     => 'unknown',
            };

            $saved = self::recordPunch([
                'company_id' => $companyId,
                'user_id'    => $row->user_id ?? null,
                'punched_at' => $row->punched_at,
                'direction'  => $direction,
                'source'     => 'biometric',
                'source_ref' => 'pos_punch:' . $row->id,
                'device_id'  => $row->device_id ?? null,
                'device_pin' => $row->device_pin ?? null,
            ]);

            if ($saved) {
                $mirrored++;
            }
        }

        return $mirrored;
    }

    /**
     * Optional: treat a panel sign-in / sign-out as attendance evidence.
     *
     * OFF by default and clearly labelled on the timeline, because a login is
     * weak evidence — a doctor can open the panel from home. Small clinics with
     * no device still want it, so it is a policy switch rather than a removal.
     *
     * @return int rows mirrored
     */
    public static function mirrorSessions(int $companyId, Carbon $from, Carbon $to): int
    {
        if (!HealthHrService::schemaReady() || !Schema::hasTable('pos_user_sessions')) {
            return 0;
        }

        $policy = HealthHrService::policy($companyId);
        if (!$policy || !$policy->session_punch_enabled) {
            return 0;
        }

        $sessions = DB::table('pos_user_sessions')
            ->where('company_id', $companyId)
            ->whereBetween('login_at', [$from, $to])
            ->orderBy('login_at')
            ->get();

        $mirrored = 0;
        foreach ($sessions as $session) {
            if (self::recordPunch([
                'company_id' => $companyId,
                'user_id'    => $session->user_id ?? null,
                'punched_at' => $session->login_at,
                'direction'  => 'in',
                'source'     => 'session',
                'source_ref' => 'session-in:' . $session->id,
            ])) {
                $mirrored++;
            }

            if (!empty($session->logout_at)) {
                if (self::recordPunch([
                    'company_id' => $companyId,
                    'user_id'    => $session->user_id ?? null,
                    'punched_at' => $session->logout_at,
                    'direction'  => 'out',
                    'source'     => 'session',
                    'source_ref' => 'session-out:' . $session->id,
                ])) {
                    $mirrored++;
                }
            }
        }

        return $mirrored;
    }

    /**
     * What the self-service button should do next: 'in' or 'out'.
     *
     * Derived from the last counted punch inside the person's current duty
     * window, so a night nurse who came on at 20:00 is still "on duty" at 02:00
     * rather than being offered a fresh check-in.
     */
    public static function nextDirection(int $companyId, int $userId): string
    {
        if (!HealthHrService::schemaReady()) {
            return 'in';
        }

        $policy = HealthHrService::policy($companyId);
        $since = now()->copy()->subHours(20);

        $punches = HealthAttendancePunch::withoutGlobalScopes()
            ->where('company_id', $companyId)
            ->where('user_id', $userId)
            ->whereNull('disregarded_at')
            ->where('punched_at', '>=', $since)
            ->orderBy('punched_at')
            ->get(['direction', 'punched_at']);

        // Replay the same pairing the calculation uses — a lone "unknown" from
        // a device must not flip the button into a state the maths disagrees with.
        $open = false;
        foreach ($punches as $punch) {
            $direction = $punch->direction;
            if ($direction === 'in' || ($direction === 'unknown' && !$open)) {
                $open = true;
            } elseif ($direction === 'out' || ($direction === 'unknown' && $open)) {
                $open = false;
            }
        }

        unset($policy);

        return $open ? 'out' : 'in';
    }

    // ═══════════════════════ CALCULATION ═══════════════════════

    /**
     * Recompute every attendance day in a window for the given staff.
     *
     * @param  array<int>  $userIds
     * @return int days written
     */
    public static function recompute(int $companyId, array $userIds, Carbon $from, Carbon $to): int
    {
        if (!HealthHrService::schemaReady() || !$userIds) {
            return 0;
        }

        $policy = HealthHrService::policy($companyId);
        if (!$policy) {
            return 0;
        }

        $from = $from->copy()->startOfDay();
        $to = $to->copy()->startOfDay();
        if ($to->lt($from)) {
            [$from, $to] = [$to, $from];
        }

        $context = self::loadContext($companyId, $userIds, $from, $to, $policy);
        $written = 0;

        foreach ($userIds as $userId) {
            $userId = (int) $userId;
            if ($userId <= 0) {
                continue;
            }

            // Ascending, so an overnight shift consumes tomorrow's early
            // punches before tomorrow gets a chance to count them.
            $consumed = [];

            // A BOUNDED recompute starts mid-history, so the day before the
            // window is not in the loop — and the night shift that ended this
            // morning would hand its checkout to today. Claim the previous
            // duty day's punches first, without writing anything: the day that
            // owns a punch owns it whether or not it is being recomputed.
            self::computeDay($companyId, $userId, $from->copy()->subDay(), $policy, $context, $consumed, true);

            for ($date = $from->copy(); $date->lte($to); $date->addDay()) {
                if (self::computeDay($companyId, $userId, $date->copy(), $policy, $context, $consumed)) {
                    $written++;
                }
            }
        }

        return $written;
    }

    /** Recompute a single person's single day. */
    public static function recomputeDay(int $companyId, int $userId, Carbon $date): bool
    {
        return self::recompute($companyId, [$userId], $date, $date) > 0;
    }

    /**
     * Everything the loop needs, loaded once.
     *
     * Production runs with strict lazy loading and these loops touch thousands
     * of rows: one query per lookup table, never one per day.
     */
    private static function loadContext(int $companyId, array $userIds, Carbon $from, Carbon $to, HealthHrPolicy $policy): array
    {
        $windowFrom = $from->copy()->subDay();
        $windowTo = $to->copy()->addDays(2);

        $profiles = HealthHrService::profilesFor($companyId, $userIds);

        $shifts = HealthShift::withoutGlobalScopes()
            ->where('company_id', $companyId)
            ->get()
            ->keyBy('id')
            ->all();

        $roster = [];
        foreach (HealthRosterEntry::withoutGlobalScopes()
            ->where('company_id', $companyId)
            ->whereIn('user_id', $userIds)
            ->whereBetween('duty_date', [$windowFrom->toDateString(), $windowTo->toDateString()])
            ->get() as $entry) {
            $roster[(int) $entry->user_id][Carbon::parse($entry->duty_date)->toDateString()] = $entry;
        }

        $leave = [];
        foreach (HealthLeaveRequest::withoutGlobalScopes()
            ->where('company_id', $companyId)
            ->whereIn('user_id', $userIds)
            ->where('status', 'approved')
            ->where('start_date', '<=', $windowTo->toDateString())
            ->where('end_date', '>=', $windowFrom->toDateString())
            ->get() as $request) {
            $leave[(int) $request->user_id][] = $request;
        }

        $holidays = [];
        foreach (HealthHoliday::withoutGlobalScopes()
            ->where('company_id', $companyId)
            ->whereBetween('holiday_date', [$windowFrom->toDateString(), $windowTo->toDateString()])
            ->get() as $holiday) {
            $holidays[Carbon::parse($holiday->holiday_date)->toDateString()][] = $holiday;
        }

        // Every counted punch in the window, grouped by person and sorted.
        $punches = [];
        foreach (HealthAttendancePunch::withoutGlobalScopes()
            ->where('company_id', $companyId)
            ->whereIn('user_id', $userIds)
            ->whereNull('disregarded_at')
            ->whereBetween('punched_at', [
                $windowFrom->copy()->startOfDay(),
                $windowTo->copy()->endOfDay(),
            ])
            ->orderBy('punched_at')
            ->get() as $punch) {
            $punches[(int) $punch->user_id][] = $punch;
        }

        $locks = HealthAttendanceLock::withoutGlobalScopes()
            ->where('company_id', $companyId)
            ->whereNull('unlocked_at')
            ->get()
            ->mapWithKeys(fn (HealthAttendanceLock $lock) => [
                sprintf('%04d-%02d', $lock->period_year, $lock->period_month) => true,
            ])
            ->all();

        return compact('profiles', 'shifts', 'roster', 'leave', 'holidays', 'punches', 'locks', 'policy');
    }

    /**
     * Derive one day. Returns false when the day was skipped (locked / frozen).
     *
     * @param  array<int,bool>  $consumed   punch ids already claimed by an earlier day
     * @param  bool             $claimOnly  claim this day's punches and write nothing
     */
    private static function computeDay(
        int $companyId,
        int $userId,
        Carbon $date,
        HealthHrPolicy $policy,
        array $context,
        array &$consumed,
        bool $claimOnly = false
    ): bool {
        /** @var HealthStaffProfile|null $profile */
        $profile = $context['profiles'][$userId] ?? null;
        $schedule = self::resolveSchedule($userId, $date, $policy, $context, $profile);

        /** @var HealthShift|null $shift */
        $shift = $schedule['shift'];
        $flags = [];

        // ── The duty window this day may claim punches from ──
        [$windowStart, $windowEnd, $shiftStart, $shiftEnd] = self::window($date, $shift, $policy);
        $windowEnd = self::capAtNextDuty($userId, $date, $windowEnd, $shiftEnd, $policy, $context, $profile);

        // Claiming happens BEFORE any early return on purpose. A day that is
        // locked, frozen, or merely being seeded still OWNS every punch inside
        // its own duty window — handing the night shift's checkout to the next
        // morning would turn a completed shift into tomorrow's missed punch.
        $mine = [];
        foreach ($context['punches'][$userId] ?? [] as $punch) {
            $id = (int) $punch->id;
            if (isset($consumed[$id])) {
                continue;
            }
            $at = $punch->punched_at instanceof Carbon ? $punch->punched_at : Carbon::parse($punch->punched_at);
            if ($at->betweenIncluded($windowStart, $windowEnd)) {
                $mine[] = $punch;
                $consumed[$id] = true;
            }
        }

        if ($claimOnly) {
            return false;
        }

        $key = $date->format('Y-m');
        if (!empty($context['locks'][$key])) {
            return false;
        }

        $existing = HealthAttendanceDay::withoutGlobalScopes()
            ->where('company_id', $companyId)
            ->where('user_id', $userId)
            ->whereDate('attendance_date', $date->toDateString())
            ->first();

        // An approved override froze this day on purpose. Recompute must not
        // quietly undo a decision somebody signed off on.
        if ($existing && ($existing->is_locked || $existing->is_manual)) {
            return false;
        }

        // A span nobody closed is capped at the rostered END, not at the far
        // edge of the claim window: somebody who forgot to punch out gets
        // credit up to the duty they were scheduled for and no further. Only a
        // person with no shift at all falls back to the window.
        $paired = self::pair($mine, $shiftEnd ?: $windowEnd);
        $workedMinutes = $paired['minutes'];

        // A single unbroken span means nobody punched out for their break, so
        // the unpaid break comes off. Two spans means they already did.
        if ($shift && $paired['spans'] <= 1 && (int) $shift->break_minutes > 0 && $workedMinutes > (int) $shift->break_minutes) {
            $workedMinutes -= (int) $shift->break_minutes;
        }

        $scheduledMinutes = $shift ? $shift->scheduledMinutes() : 0;
        $graceIn = (int) ($shift?->grace_in_minutes ?? $policy->grace_in_minutes);
        $graceOut = (int) ($shift?->grace_out_minutes ?? $policy->grace_out_minutes);

        $lateMinutes = 0;
        $earlyMinutes = 0;
        if ($shift && $shiftStart && $paired['first_in']) {
            $allowed = $shiftStart->copy()->addMinutes($graceIn);
            if ($paired['first_in']->gt($allowed)) {
                // Later date FIRST would give a negative: diffInMinutes is
                // signed, so the earlier instant always calls it.
                $lateMinutes = (int) $allowed->diffInMinutes($paired['first_in']);
                $flags[] = HealthAttendanceDay::FLAG_LATE;
            }
        }
        if ($shift && $shiftEnd && $paired['last_out'] && !$paired['open']) {
            $allowed = $shiftEnd->copy()->subMinutes($graceOut);
            if ($paired['last_out']->lt($allowed)) {
                $earlyMinutes = (int) $paired['last_out']->diffInMinutes($allowed);
                $flags[] = HealthAttendanceDay::FLAG_EARLY_LEAVE;
            }
        }

        // ── Overtime ──
        $overtime = 0;
        $overtimeEligible = $profile === null || $profile->overtime_eligible;
        // Never on an open span: the extra minutes there are an estimate off a
        // missing punch, and an estimate must not turn into an overtime claim.
        if ($policy->overtime_enabled && $overtimeEligible && $scheduledMinutes > 0 && !$paired['open']) {
            $extra = $workedMinutes - $scheduledMinutes;
            if ($extra >= (int) $policy->min_overtime_minutes && $extra > 0) {
                $overtime = $extra;
                $flags[] = HealthAttendanceDay::FLAG_OVERTIME;
            }
        }

        // ── Status ──
        $status = self::resolveStatus(
            $schedule,
            $profile,
            $policy,
            $paired,
            $workedMinutes,
            $scheduledMinutes,
            $graceIn,
            $graceOut,
            $flags
        );

        if ($shift?->crosses_midnight) {
            $flags[] = HealthAttendanceDay::FLAG_OVERNIGHT;
        }
        if ($shift?->hasSecondSpan()) {
            $flags[] = HealthAttendanceDay::FLAG_SPLIT;
        }
        if (!$shift && $paired['count'] > 0) {
            $flags[] = HealthAttendanceDay::FLAG_UNSCHEDULED;
        }
        if ($paired['open']) {
            $flags[] = HealthAttendanceDay::FLAG_OPEN_SPAN;
        }

        // ── Cross-branch: worked somewhere other than the home posting ──
        $dutyBranchId = $schedule['branch_id'];
        $crossBranch = false;
        $homeBranchId = $profile?->branch_id ? (int) $profile->branch_id : null;
        if ($homeBranchId && $dutyBranchId && (int) $dutyBranchId !== $homeBranchId) {
            $crossBranch = true;
        }
        foreach ($mine as $punch) {
            if ($punch->branch_id && $homeBranchId && (int) $punch->branch_id !== $homeBranchId) {
                $crossBranch = true;
                $dutyBranchId = $dutyBranchId ?: (int) $punch->branch_id;
            }
        }
        if ($crossBranch) {
            $flags[] = HealthAttendanceDay::FLAG_CROSS_BRANCH;
        }

        // The row is found by whereDate above and reused here on purpose. An
        // updateOrCreate() keyed on the date string would miss a stored
        // "2026-03-31 00:00:00" and try to insert a duplicate against the
        // (company, user, date) unique index.
        $row = $existing ?: new HealthAttendanceDay([
            'company_id'      => $companyId,
            'user_id'         => $userId,
            'attendance_date' => $date->toDateString(),
        ]);

        $row->fill(
            [
                'health_shift_id'      => $shift?->id,
                'branch_id'            => $dutyBranchId,
                'health_department_id' => $schedule['department_id'],
                'shift_start'          => $shiftStart,
                'shift_end'            => $shiftEnd,
                'first_in'             => $paired['first_in'],
                'last_out'             => $paired['open'] ? null : $paired['last_out'],
                'scheduled_minutes'    => $scheduledMinutes,
                'worked_minutes'       => max(0, $workedMinutes),
                'break_minutes'        => (int) ($shift->break_minutes ?? 0),
                'late_minutes'         => $lateMinutes,
                'early_leave_minutes'  => $earlyMinutes,
                'overtime_minutes'     => $overtime,
                'status'               => $status,
                'exceptions'           => array_values(array_unique($flags)),
                'punch_count'          => $paired['count'],
                'is_open'              => $paired['open'],
                'cross_branch'         => $crossBranch,
                'leave_request_id'     => $schedule['leave_request_id'],
                'is_manual'            => false,
                'computed_at'          => now(),
                'is_locked'            => false,
            ]
        )->save();

        return true;
    }

    /**
     * Stop a duty window swallowing the NEXT duty's arrival.
     *
     * The claim window deliberately runs hours past the rostered end so a late
     * checkout still lands on the day it belongs to. On its own that is far too
     * greedy: a night shift ending 08:00 would keep claiming until 14:00 and
     * eat the 09:00 arrival of whoever is on the morning after — leaving a
     * completed morning duty looking like a missed punch.
     *
     * So the window is cut at the next duty's START, and never earlier than
     * this day's own rostered end (otherwise the next shift's generous EARLY
     * window would rob the night of its own checkout). One second before, so
     * the arrival itself always belongs to the day it arrives for.
     */
    private static function capAtNextDuty(
        int $userId,
        Carbon $date,
        Carbon $windowEnd,
        ?Carbon $shiftEnd,
        HealthHrPolicy $policy,
        array $context,
        ?HealthStaffProfile $profile
    ): Carbon {
        $next = $date->copy()->addDay();
        $nextSchedule = self::resolveSchedule($userId, $next, $policy, $context, $profile);

        /** @var HealthShift|null $nextShift */
        $nextShift = $nextSchedule['shift'];
        if (!$nextShift) {
            return $windowEnd;
        }

        [, , $nextStart] = self::window($next, $nextShift, $policy);
        if (!$nextStart) {
            return $windowEnd;
        }

        $cap = $shiftEnd && $shiftEnd->gt($nextStart) ? $shiftEnd->copy() : $nextStart->copy()->subSecond();

        return $cap->lt($windowEnd) ? $cap : $windowEnd;
    }

    /**
     * What this person was supposed to be doing on this date.
     *
     * Precedence, most explicit first: a roster row someone actually wrote →
     * approved leave → a holiday for their branch → their weekly off → their
     * default work pattern → nothing scheduled.
     */
    public static function resolveSchedule(
        int $userId,
        Carbon $date,
        HealthHrPolicy $policy,
        array $context,
        ?HealthStaffProfile $profile
    ): array {
        $iso = $date->toDateString();
        $result = [
            'type'             => 'unscheduled',
            'shift'            => null,
            'branch_id'        => $profile?->branch_id ? (int) $profile->branch_id : null,
            'department_id'    => null,
            'leave_request_id' => null,
            'holiday_paid'     => true,
        ];

        /** @var HealthRosterEntry|null $entry */
        $entry = $context['roster'][$userId][$iso] ?? null;
        if ($entry) {
            $result['type'] = $entry->entry_type;
            $result['branch_id'] = $entry->branch_id ? (int) $entry->branch_id : $result['branch_id'];
            $result['department_id'] = $entry->health_department_id ? (int) $entry->health_department_id : null;
            if ($entry->health_shift_id) {
                $result['shift'] = $context['shifts'][(int) $entry->health_shift_id] ?? null;
            }
            if (in_array($entry->entry_type, ['shift', 'on_call'], true)) {
                return $result;
            }
        }

        // Approved leave beats a rostered shift — the roster was written first.
        foreach ($context['leave'][$userId] ?? [] as $request) {
            $start = Carbon::parse($request->start_date)->startOfDay();
            $end = Carbon::parse($request->end_date)->startOfDay();
            if ($date->betweenIncluded($start, $end)) {
                $result['type'] = 'leave';
                $result['shift'] = null;
                $result['leave_request_id'] = (int) $request->id;

                return $result;
            }
        }

        if ($result['type'] === 'leave' || $result['type'] === 'holiday' || $result['type'] === 'off') {
            return $result;
        }

        // Holidays: organisation-wide (branch_id NULL) or this person's branch.
        foreach ($context['holidays'][$iso] ?? [] as $holiday) {
            $branchId = $holiday->branch_id ? (int) $holiday->branch_id : null;
            if ($branchId === null || $branchId === $result['branch_id']) {
                $result['type'] = 'holiday';
                $result['shift'] = null;
                $result['holiday_paid'] = (bool) $holiday->is_paid;

                return $result;
            }
        }

        if (in_array($date->dayOfWeekIso, HealthHrService::offDays($profile, $policy), true)) {
            $result['type'] = 'off';
            $result['shift'] = null;

            return $result;
        }

        if ($profile?->default_shift_id) {
            $shift = $context['shifts'][(int) $profile->default_shift_id] ?? null;
            if ($shift && $shift->is_active) {
                $result['type'] = 'shift';
                $result['shift'] = $shift;
            }
        }

        return $result;
    }

    /**
     * The instants a duty date may claim punches between.
     *
     * @return array{0:Carbon,1:Carbon,2:?Carbon,3:?Carbon}
     */
    private static function window(Carbon $date, ?HealthShift $shift, HealthHrPolicy $policy): array
    {
        $dayStart = HealthHrService::dayStart($date, $policy);

        if (!$shift) {
            return [$dayStart, $dayStart->copy()->addDay(), null, null];
        }

        $shiftStart = $date->copy()->startOfDay()
            ->setTimeFromTimeString(HealthShift::hhmm($shift->start_time) . ':00');

        $endTime = $shift->hasSecondSpan() ? $shift->second_end_time : $shift->end_time;
        $shiftEnd = $date->copy()->startOfDay()
            ->setTimeFromTimeString(HealthShift::hhmm($endTime) . ':00');
        if ($shiftEnd->lte($shiftStart)) {
            $shiftEnd->addDay();
        }

        return [
            $shiftStart->copy()->subMinutes(self::EARLY_WINDOW_MINUTES),
            $shiftEnd->copy()->addMinutes(self::LATE_WINDOW_MINUTES),
            $shiftStart,
            $shiftEnd,
        ];
    }

    /**
     * Pair in/out punches into worked spans.
     *
     * Rules, all of them chosen because a real device produces this:
     *  - A duplicate consecutive "in" is ignored; the EARLIEST arrival stands.
     *  - An "out" with nothing open is ignored — it is somebody else's tail.
     *  - A device that reports no direction alternates, starting with "in".
     *  - A span still open at the end of the window counts up to now (never
     *    into the future) and flags the day, rather than paying a full shift
     *    to somebody who simply forgot to punch out.
     *
     * @param  array<HealthAttendancePunch>  $punches
     */
    private static function pair(array $punches, Carbon $windowEnd): array
    {
        $minutes = 0;
        $spans = 0;
        $open = null;
        $firstIn = null;
        $lastOut = null;

        foreach ($punches as $punch) {
            $at = $punch->punched_at instanceof Carbon ? $punch->punched_at : Carbon::parse($punch->punched_at);
            $direction = $punch->direction;

            $isIn = $direction === 'in' || ($direction === 'unknown' && $open === null);

            if ($isIn) {
                if ($open === null) {
                    $open = $at;
                    $firstIn = $firstIn ?? $at;
                }
                // else: duplicate arrival — the earliest one already stands.
                continue;
            }

            if ($open === null) {
                continue; // orphan departure
            }

            $minutes += max(0, $open->diffInMinutes($at));
            $spans++;
            $lastOut = $at;
            $open = null;
        }

        $stillOpen = $open !== null;
        if ($stillOpen) {
            $cutoff = now()->lt($windowEnd) ? now() : $windowEnd;
            if ($cutoff->gt($open)) {
                $minutes += $open->diffInMinutes($cutoff);
                $spans++;
            }
            $lastOut = $cutoff;
        }

        return [
            'minutes'  => (int) $minutes,
            'spans'    => $spans,
            'open'     => $stillOpen,
            'first_in' => $firstIn,
            'last_out' => $lastOut,
            'count'    => count($punches),
        ];
    }

    /** The one place a day's status word is decided. */
    private static function resolveStatus(
        array $schedule,
        ?HealthStaffProfile $profile,
        HealthHrPolicy $policy,
        array $paired,
        int $workedMinutes,
        int $scheduledMinutes,
        int $graceIn,
        int $graceOut,
        array &$flags
    ): string {
        if ($profile && $profile->attendance_exempt) {
            return 'exempt';
        }

        if ($schedule['type'] === 'leave') {
            return 'leave';
        }

        // A holiday or weekly off that somebody actually worked is recorded as
        // worked — the payroll handoff needs to see it to pay for it.
        if (in_array($schedule['type'], ['holiday', 'off'], true)) {
            if ($paired['count'] === 0 || $workedMinutes <= 0) {
                return $schedule['type'] === 'holiday' ? 'holiday' : 'weekly_off';
            }
            $flags[] = HealthAttendanceDay::FLAG_OVERTIME;
        }

        if ($paired['count'] === 0) {
            return $schedule['type'] === 'on_call' ? 'on_call' : 'absent';
        }

        // An odd punch count (or a span still hanging open) means the evidence
        // is incomplete. The organisation decides what that costs.
        if ($paired['open'] || $paired['count'] % 2 === 1) {
            $flags[] = HealthAttendanceDay::FLAG_MISSED_PUNCH;

            $missed = $policy->missed_punch_status;
            if (in_array($missed, HealthHrPolicy::MISSED_PUNCH_STATUSES, true) && $missed !== 'missed_punch') {
                return $missed;
            }

            return 'missed_punch';
        }

        $target = $scheduledMinutes > 0
            ? min($scheduledMinutes, (int) $policy->full_day_minutes)
            : (int) $policy->full_day_minutes;
        $threshold = max(1, $target - $graceIn - $graceOut);

        if ($workedMinutes >= $threshold) {
            return $schedule['type'] === 'on_call' ? 'on_call' : 'present';
        }

        if ($workedMinutes < (int) $policy->half_day_minutes) {
            $flags[] = self::FLAG_SHORT_DAY;
        }

        return 'half_day';
    }

    // ═══════════════════════ CORRECTIONS ═══════════════════════

    /**
     * Apply an approved correction, then recompute the day it touched.
     *
     * Nothing here edits or deletes evidence: add_punch writes a NEW manual
     * punch stamped with this correction, disregard_punch marks an existing
     * punch as not-counted while leaving it on the timeline, and the two
     * override types freeze the derived day with is_manual so the recompute
     * stops arguing with the person who approved it.
     */
    public static function applyCorrection(HealthAttendanceCorrection $correction): bool
    {
        if (!HealthHrService::schemaReady() || $correction->status !== 'approved') {
            return false;
        }

        $companyId = (int) $correction->company_id;
        $userId = (int) $correction->user_id;
        $date = Carbon::parse($correction->attendance_date)->startOfDay();

        if (self::isMonthLocked($companyId, (int) $date->year, (int) $date->month)) {
            return false;
        }

        switch ($correction->type) {
            case 'add_punch':
                if (!$correction->punch_at) {
                    return false;
                }
                self::recordPunch([
                    'company_id'    => $companyId,
                    'user_id'       => $userId,
                    'punched_at'    => $correction->punch_at,
                    'direction'     => $correction->direction ?: 'unknown',
                    'source'        => 'manual',
                    'source_ref'    => 'correction:' . $correction->id,
                    'note'          => $correction->reason,
                    'recorded_by'   => $correction->reviewed_by ?: $correction->requested_by,
                    'correction_id' => $correction->id,
                ]);
                break;

            case 'disregard_punch':
                $punch = HealthAttendancePunch::withoutGlobalScopes()
                    ->where('company_id', $companyId)
                    ->where('id', $correction->target_punch_id)
                    ->first();
                if (!$punch) {
                    return false;
                }
                $punch->forceFill([
                    'disregarded_at'            => now(),
                    'disregarded_by'            => $correction->reviewed_by,
                    'disregarded_correction_id' => $correction->id,
                    'disregard_reason'          => $correction->reason,
                ])->save();
                break;

            case 'set_status':
            case 'set_hours':
                // Recompute first so the frozen row starts from real evidence,
                // then stamp the approved override on top of it.
                self::recompute($companyId, [$userId], $date, $date);

                $day = HealthAttendanceDay::withoutGlobalScopes()
                    ->where('company_id', $companyId)
                    ->where('user_id', $userId)
                    ->whereDate('attendance_date', $date->toDateString())
                    ->first();

                if (!$day) {
                    $day = new HealthAttendanceDay([
                        'company_id'      => $companyId,
                        'user_id'         => $userId,
                        'attendance_date' => $date->toDateString(),
                    ]);
                }

                if ($correction->type === 'set_status'
                    && in_array($correction->requested_status, HealthAttendanceDay::STATUSES, true)) {
                    $day->status = $correction->requested_status;
                }
                if ($correction->type === 'set_hours' && $correction->requested_minutes !== null) {
                    $day->worked_minutes = (int) $correction->requested_minutes;
                }

                $flags = $day->flags();
                $flags[] = HealthAttendanceDay::FLAG_CORRECTED;
                $day->exceptions = array_values(array_unique($flags));
                $day->is_manual = true;
                $day->correction_id = $correction->id;
                $day->computed_at = now();
                $day->save();

                $correction->forceFill(['applied_at' => now()])->save();

                return true;
        }

        // add_punch / disregard_punch: let the maths re-derive the day.
        self::recompute($companyId, [$userId], $date, $date);
        $correction->forceFill(['applied_at' => now()])->save();

        return true;
    }

    // ═══════════════════════ LOCKING ═══════════════════════

    public static function isMonthLocked(int $companyId, int $year, int $month): bool
    {
        if (!HealthHrService::schemaReady()) {
            return false;
        }

        return HealthAttendanceLock::withoutGlobalScopes()
            ->where('company_id', $companyId)
            ->where('period_year', $year)
            ->where('period_month', $month)
            ->whereNull('unlocked_at')
            ->exists();
    }

    /**
     * The first locked month a date range touches, or null.
     *
     * Ranges are walked by CALENDAR MONTH START, never by adding a month to
     * the range's own first day: 31 Jan + 1 month lands on 28 Feb, so a
     * 31 Jan → 1 Feb range walked that way never looks at February at all and
     * a locked month would be edited straight through.
     *
     * @return string|null "YYYY-MM"
     */
    public static function lockedMonthInRange(int $companyId, Carbon $from, Carbon $to): ?string
    {
        if ($to->lt($from)) {
            [$from, $to] = [$to, $from];
        }

        $cursor = $from->copy()->startOfMonth();
        $stop = $to->copy()->startOfMonth();

        while ($cursor->lte($stop)) {
            if (self::isMonthLocked($companyId, (int) $cursor->year, (int) $cursor->month)) {
                return $cursor->format('Y-m');
            }
            // Safe here, and only here: the cursor is always the 1st.
            $cursor->addMonthNoOverflow();
        }

        return null;
    }

    /** @return array<string,HealthAttendanceLock> "YYYY-MM" => lock */
    public static function locks(int $companyId): array
    {
        if (!HealthHrService::schemaReady()) {
            return [];
        }

        return HealthAttendanceLock::withoutGlobalScopes()
            ->where('company_id', $companyId)
            ->orderByDesc('period_year')
            ->orderByDesc('period_month')
            ->get()
            ->mapWithKeys(fn (HealthAttendanceLock $lock) => [
                sprintf('%04d-%02d', $lock->period_year, $lock->period_month) => $lock,
            ])
            ->all();
    }
}

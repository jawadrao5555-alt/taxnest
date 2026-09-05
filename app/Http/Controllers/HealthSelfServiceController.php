<?php

namespace App\Http\Controllers;

use App\Models\Company;
use App\Models\HealthAttendanceCorrection;
use App\Models\HealthAttendanceDay;
use App\Models\HealthAttendancePunch;
use App\Models\HealthLeaveRequest;
use App\Models\HealthLeaveType;
use App\Models\HealthRosterEntry;
use App\Models\HealthShift;
use App\Services\HealthAttendanceService;
use App\Services\HealthHrService;
use App\Services\HealthPlatformService;
use App\Support\HealthPanel;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Auth;

/**
 * "My Duty" — what a member of staff can do about their OWN attendance.
 *
 * ── Why this lives outside /health/hr ─────────────────────────────────────
 *
 * Everything under /health/hr requires an HR capability. But a nurse is not HR
 * and still has to punch in; an auditor is read-only over other people's
 * records and still works shifts. Putting self-service behind an HR capability
 * would either lock the whole hospital out of its own clock or force us to hand
 * every nurse an HR permission — and the second one is how a "read-only" role
 * quietly becomes able to edit somebody else's month.
 *
 * So: these routes are gated by the HR MODULE only, and every single one of
 * them is hard-wired to the signed-in user's own id. There is no user_id
 * parameter anywhere in this controller, which is the only way to be sure a
 * crafted POST cannot punch somebody else in.
 */
class HealthSelfServiceController extends Controller
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

    private function schemaGuard()
    {
        if (HealthHrService::schemaReady()) {
            return null;
        }

        return redirect()->route('health.dashboard')->with('error', __('health.hr_schema_missing'));
    }

    public function attendance(Request $request)
    {
        if ($redirect = $this->schemaGuard()) {
            return $redirect;
        }

        $company = $this->company();
        $companyId = $this->companyId();
        $user = $this->user();
        $userId = (int) $user->id;

        $policy = HealthHrService::policy($companyId);
        HealthHrService::ensureLeaveTypes($companyId);

        $today = HealthHrService::attendanceDate(now(), $policy);
        $from = $today->copy()->subDays(29);

        $days = HealthAttendanceDay::withoutGlobalScopes()
            ->where('company_id', $companyId)
            ->where('user_id', $userId)
            ->whereBetween('attendance_date', [$from->toDateString(), $today->toDateString()])
            ->orderByDesc('attendance_date')
            ->get();

        // Today's own evidence, so the person can see what the clock recorded
        // rather than only the verdict it produced.
        $punches = HealthAttendancePunch::withoutGlobalScopes()
            ->where('company_id', $companyId)
            ->where('user_id', $userId)
            ->whereBetween('punched_at', [
                HealthHrService::dayStart($today, $policy),
                HealthHrService::dayStart($today, $policy)->addDay(),
            ])
            ->orderBy('punched_at')
            ->get();

        // The next two weeks of their own roster.
        $rosterEnd = $today->copy()->addDays(13);
        $roster = HealthRosterEntry::withoutGlobalScopes()
            ->where('company_id', $companyId)
            ->where('user_id', $userId)
            ->whereBetween('duty_date', [$today->toDateString(), $rosterEnd->toDateString()])
            ->orderBy('duty_date')
            ->get();

        $shifts = HealthShift::withoutGlobalScopes()
            ->where('company_id', $companyId)->get()->keyBy('id');

        $monthTotals = [
            'present'  => 0,
            'absent'   => 0,
            'leave'    => 0,
            'late'     => 0,
            'worked'   => 0,
            'overtime' => 0,
        ];
        $monthStart = $today->copy()->startOfMonth();
        foreach ($days as $day) {
            if (Carbon::parse($day->attendance_date)->lt($monthStart)) {
                continue;
            }
            match ($day->status) {
                'present', 'on_call' => $monthTotals['present']++,
                'absent'             => $monthTotals['absent']++,
                'leave'              => $monthTotals['leave']++,
                default              => null,
            };
            if ((int) $day->late_minutes > 0) {
                $monthTotals['late']++;
            }
            $monthTotals['worked'] += (int) $day->worked_minutes;
            $monthTotals['overtime'] += (int) $day->overtime_minutes;
        }

        return view('health.hr.my-attendance', [
            'company'       => $company,
            'today'         => $today,
            'policy'        => $policy,
            'profile'       => HealthHrService::profile($companyId, $userId, false),
            'days'          => $days,
            'punches'       => $punches,
            'roster'        => $roster,
            'shifts'        => $shifts,
            'monthTotals'   => $monthTotals,
            'nextDirection' => HealthAttendanceService::nextDirection($companyId, $userId),
            'leaveTypes'    => HealthLeaveType::withoutGlobalScopes()->where('company_id', $companyId)
                ->where('is_active', true)->orderBy('name')->get(),
            'myLeave'       => HealthLeaveRequest::withoutGlobalScopes()
                ->where('company_id', $companyId)->where('user_id', $userId)
                ->orderByDesc('id')->limit(30)->get(),
            'myCorrections' => HealthAttendanceCorrection::withoutGlobalScopes()
                ->where('company_id', $companyId)->where('user_id', $userId)
                ->orderByDesc('id')->limit(30)->get(),
            'monthLocked'   => HealthAttendanceService::isMonthLocked($companyId, (int) $today->year, (int) $today->month),
        ]);
    }

    /**
     * Check in or out from the panel or the phone.
     *
     * The DIRECTION is decided by the server from the person's own timeline,
     * not by the button that was pressed: a double-tap, a stale tab or a phone
     * that retried a request must not be able to close a shift that is still
     * running or open a second one.
     */
    public function punch(Request $request)
    {
        if ($redirect = $this->schemaGuard()) {
            return $redirect;
        }

        $companyId = $this->companyId();
        $userId = (int) $this->user()->id;
        $policy = HealthHrService::policy($companyId);

        $data = $request->validate([
            'channel'   => ['nullable', 'in:web,mobile'],
            'latitude'  => ['nullable', 'numeric', 'between:-90,90'],
            'longitude' => ['nullable', 'numeric', 'between:-180,180'],
            'note'      => ['nullable', 'string', 'max:255'],
        ]);

        $channel = $data['channel'] ?? 'web';

        if ($channel === 'web' && !$policy->web_checkin_enabled) {
            return $this->punchResponse($request, false, __('health.hr_checkin_web_off'));
        }
        if ($channel === 'mobile' && !$policy->mobile_checkin_enabled) {
            return $this->punchResponse($request, false, __('health.hr_checkin_mobile_off'));
        }

        $profile = HealthHrService::profile($companyId, $userId, false);
        if ($profile && $profile->attendance_exempt) {
            return $this->punchResponse($request, false, __('health.hr_checkin_exempt'));
        }
        if ($profile && !$profile->isWorking()) {
            return $this->punchResponse($request, false, __('health.hr_checkin_not_working'));
        }

        $homeBranchId = $profile && $profile->branch_id ? (int) $profile->branch_id : null;
        $atBranchId = (int) (app('currentBranchId') ?: 0) ?: $homeBranchId;

        // Cross-branch: the switch decides whether somebody may clock in
        // anywhere other than their own posting. A device punch is evidence and
        // is never refused, but a person tapping a button is asking permission.
        if (!$policy->cross_branch_allowed && $homeBranchId && $atBranchId && $atBranchId !== $homeBranchId) {
            return $this->punchResponse($request, false, __('health.hr_checkin_wrong_branch'));
        }

        if ($policy->geo_required) {
            if (empty($data['latitude']) || empty($data['longitude'])) {
                return $this->punchResponse($request, false, __('health.hr_checkin_needs_location'));
            }

            // A phone can send any coordinates it likes, so "location required"
            // means nothing without a centre to measure from. If nobody has set
            // the site up, the punch is REFUSED and says why — a geofence that
            // silently passes everybody is worse than no geofence.
            $fence = HealthHrService::geofence($companyId, $atBranchId, $policy);
            if (!$fence) {
                return $this->punchResponse($request, false, __('health.hr_checkin_no_site'));
            }

            $metres = HealthHrService::metresBetween(
                (float) $data['latitude'],
                (float) $data['longitude'],
                $fence['lat'],
                $fence['lng']
            );

            if ($metres > $fence['radius']) {
                return $this->punchResponse($request, false, __('health.hr_checkin_too_far', [
                    'distance' => number_format($metres),
                    'radius'   => number_format($fence['radius']),
                ]));
            }
        }

        $today = HealthHrService::attendanceDate(now(), $policy);
        if (HealthAttendanceService::isMonthLocked($companyId, (int) $today->year, (int) $today->month)) {
            return $this->punchResponse($request, false, __('health.hr_month_locked'));
        }

        $direction = HealthAttendanceService::nextDirection($companyId, $userId);

        // A second press within a minute is the same press. Recording it would
        // open and close a zero-length span and flag the day for no reason.
        $recent = HealthAttendancePunch::withoutGlobalScopes()
            ->where('company_id', $companyId)
            ->where('user_id', $userId)
            ->whereNull('disregarded_at')
            ->where('punched_at', '>=', now()->copy()->subMinute())
            ->exists();

        if ($recent) {
            return $this->punchResponse($request, false, __('health.hr_checkin_too_soon'));
        }

        HealthAttendanceService::recordPunch([
            'company_id' => $companyId,
            'user_id'    => $userId,
            'punched_at' => now(),
            'direction'  => $direction,
            'source'     => $channel,
            'branch_id'  => app('currentBranchId') ?: ($profile->branch_id ?? null),
            'latitude'   => $data['latitude'] ?? null,
            'longitude'  => $data['longitude'] ?? null,
            'ip'         => $request->ip(),
            'note'       => $data['note'] ?? null,
            'recorded_by' => $userId,
        ]);

        HealthAttendanceService::recompute($companyId, [$userId], $today, $today);

        return $this->punchResponse($request, true, $direction === 'in'
            ? __('health.hr_checked_in')
            : __('health.hr_checked_out'));
    }

    private function punchResponse(Request $request, bool $ok, string $message)
    {
        if ($request->expectsJson()) {
            return response()->json(['ok' => $ok, 'message' => $message], $ok ? 200 : 422);
        }

        return back()->with($ok ? 'success' : 'error', $message);
    }

    /** Request leave for yourself. The approver is somebody else, always. */
    public function storeLeave(Request $request, HealthLeaveController $leaveController)
    {
        if ($redirect = $this->schemaGuard()) {
            return $redirect;
        }

        $data = $leaveController->validateRequest($request, false);
        $result = $leaveController->createRequest($this->companyId(), (int) $this->user()->id, $data);

        if (is_string($result)) {
            return back()->withInput()->with('error', $result);
        }

        return back()->with('success', __('health.hr_leave_submitted'));
    }

    /**
     * Withdraw your own pending leave request.
     *
     * Same decision as the HR desk's cancel — the guard there already refuses
     * anything that is not your own pending request — but reachable from the
     * self-service path, which is the only HR URL ordinary staff can open.
     */
    public function cancelLeave(Request $request, $id, HealthLeaveController $leaveController)
    {
        if ($redirect = $this->schemaGuard()) {
            return $redirect;
        }

        $leave = HealthLeaveRequest::withoutGlobalScopes()
            ->where('company_id', $this->companyId())
            ->where('user_id', (int) $this->user()->id)
            ->findOrFail((int) $id);

        return $leaveController->cancel($request, $leave->id);
    }

    /** Ask for your own attendance to be fixed. Approval is somebody else's. */
    public function storeCorrection(Request $request, HealthAttendanceController $attendanceController)
    {
        if ($redirect = $this->schemaGuard()) {
            return $redirect;
        }

        $data = $attendanceController->validateCorrection($request, false);

        // Self-service may only ask for the two evidence-shaped fixes. Setting
        // your own status or your own hours is an override, and an override you
        // can request for yourself is one nudge away from an override you grant
        // yourself — HR raises those.
        if (!in_array($data['type'], ['add_punch', 'disregard_punch'], true)) {
            return back()->withInput()->with('error', __('health.hr_correction_self_type'));
        }

        $result = $attendanceController->createCorrection(
            $this->companyId(),
            (int) $this->user()->id,
            $data
        );

        if (is_string($result)) {
            return back()->withInput()->with('error', $result);
        }

        return back()->with('success', __('health.hr_correction_submitted'));
    }
}

<?php

namespace App\Http\Controllers;

use App\Models\Company;
use App\Models\HealthLeaveRequest;
use App\Models\HealthLeaveType;
use App\Services\HealthAccessService;
use App\Services\HealthAttendanceService;
use App\Services\HealthHrService;
use App\Services\HealthPlatformService;
use App\Support\HealthPanel;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\Rule;

/**
 * Leave requests and their approval trail.
 *
 * ── Approved leave outranks the roster ────────────────────────────────────
 *
 * The roster is written weeks ahead; leave is approved after. So when both
 * exist for a date, leave wins and the attendance calculation records the day
 * as leave rather than an absence against a shift nobody expected them on.
 *
 * ── Paid or unpaid is the leave TYPE's decision ───────────────────────────
 *
 * The payroll handoff never guesses from the request. It reads the type, so
 * changing "unpaid leave" from unpaid to paid is one edit in one place and
 * every month that has not been locked follows it.
 */
class HealthLeaveController extends Controller
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

    private function canApprove(): bool
    {
        return HealthAccessService::can($this->user(), 'hr.leave.approve', $this->company());
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
        HealthHrService::ensureLeaveTypes($companyId);

        $status = $request->query('status', 'pending');
        $userId = (int) $request->query('user_id', 0);
        $month = $request->query('month') ?: now()->format('Y-m');
        [$year, $monthNo] = array_map('intval', explode('-', $month . '-1'));

        $query = HealthLeaveRequest::withoutGlobalScopes()
            ->where('company_id', $companyId)
            ->orderByDesc('start_date')
            ->orderByDesc('id');

        if ($status !== 'all' && in_array($status, HealthLeaveRequest::STATUSES, true)) {
            $query->where('status', $status);
        }
        if ($userId > 0) {
            $query->where('user_id', $userId);
        }
        if ($request->query('month')) {
            $start = Carbon::create($year, max(1, min(12, $monthNo)), 1)->startOfDay();
            $end = $start->copy()->endOfMonth();
            $query->where('start_date', '<=', $end->toDateString())
                ->where('end_date', '>=', $start->toDateString());
        }

        $requests = $query->limit(400)->get();

        $staff = HealthPlatformService::staff($company);
        $names = $staff->keyBy(fn ($member) => (int) $member->id);

        // Yearly balance per person and type — quota minus what is already
        // approved this calendar year, so an approver decides with the number
        // in front of them instead of from memory.
        $balances = $this->balances($companyId, $requests->pluck('user_id')->map(fn ($id) => (int) $id)->unique()->all());

        return view('health.hr.leave', [
            'company'     => $company,
            'requests'    => $requests,
            'names'       => $names,
            'staff'       => $staff,
            'leaveTypes'  => HealthLeaveType::withoutGlobalScopes()->where('company_id', $companyId)
                ->orderBy('name')->get(),
            'statuses'    => HealthLeaveRequest::STATUSES,
            'status'      => $status,
            'userId'      => $userId,
            'month'       => $request->query('month'),
            'balances'    => $balances,
            'canApprove'  => $this->canApprove(),
            'canManage'   => HealthAccessService::can($this->user(), 'hr.manage', $this->company()),
        ]);
    }

    /**
     * Approved days already taken this year, per user and leave type.
     *
     * @return array<int,array<int,float>>
     */
    private function balances(int $companyId, array $userIds): array
    {
        if (!$userIds) {
            return [];
        }

        $yearStart = now()->copy()->startOfYear()->toDateString();
        $yearEnd = now()->copy()->endOfYear()->toDateString();

        $out = [];
        foreach (HealthLeaveRequest::withoutGlobalScopes()
            ->where('company_id', $companyId)
            ->whereIn('user_id', $userIds)
            ->where('status', 'approved')
            ->where('start_date', '>=', $yearStart)
            ->where('start_date', '<=', $yearEnd)
            ->get() as $row) {
            $typeId = (int) ($row->health_leave_type_id ?? 0);
            $out[(int) $row->user_id][$typeId] = ($out[(int) $row->user_id][$typeId] ?? 0) + (float) $row->days;
        }

        return $out;
    }

    /** HR files a request on somebody's behalf. Self-service has its own path. */
    public function store(Request $request)
    {
        if ($redirect = $this->schemaGuard()) {
            return $redirect;
        }
        if (!HealthAccessService::can($this->user(), 'hr.manage', $this->company())) {
            abort(403);
        }

        $companyId = $this->companyId();
        $data = $this->validateRequest($request, true);

        $staff = HealthPlatformService::staff($this->company());
        if (!$staff->firstWhere('id', (int) $data['user_id'])) {
            return back()->withInput()->with('error', __('health.hr_staff_not_found'));
        }

        $leave = $this->createRequest($companyId, (int) $data['user_id'], $data);
        if (is_string($leave)) {
            return back()->withInput()->with('error', $leave);
        }

        return redirect()->route('health.hr.leave')->with('success', __('health.hr_leave_created'));
    }

    /**
     * Approve or reject, with the note kept forever.
     *
     * Approving recomputes the affected days immediately, so the attendance
     * screen and the roster agree the moment the decision is made rather than
     * whenever somebody next presses recompute.
     */
    public function review(Request $request, $id)
    {
        if ($redirect = $this->schemaGuard()) {
            return $redirect;
        }
        if (!$this->canApprove()) {
            abort(403);
        }

        $companyId = $this->companyId();
        $leave = HealthLeaveRequest::withoutGlobalScopes()
            ->where('company_id', $companyId)->findOrFail((int) $id);

        $data = $request->validate([
            'decision'    => ['required', Rule::in(['approved', 'rejected'])],
            'review_note' => ['nullable', 'string', 'max:500'],
        ]);

        if ($leave->status !== 'pending') {
            return back()->with('error', __('health.hr_leave_already_reviewed'));
        }

        $start = Carbon::parse($leave->start_date)->startOfDay();
        $end = Carbon::parse($leave->end_date)->startOfDay();

        if ($this->rangeTouchesLockedMonth($companyId, $start, $end)) {
            return back()->with('error', __('health.hr_month_locked'));
        }

        // Nobody approves their own leave. The whole point of the trail is that
        // a second person looked at it.
        if ((int) $leave->user_id === (int) $this->user()->id
            && !HealthAccessService::isOwner($this->user())) {
            return back()->with('error', __('health.hr_leave_self_approve'));
        }

        $leave->forceFill([
            'status'      => $data['decision'],
            'reviewed_by' => $this->user()->id,
            'reviewed_at' => now(),
            'review_note' => $data['review_note'] ?? null,
        ])->save();

        HealthAttendanceService::recompute($companyId, [(int) $leave->user_id], $start, $end);

        return back()->with('success', $data['decision'] === 'approved'
            ? __('health.hr_leave_approved')
            : __('health.hr_leave_rejected'));
    }

    /** Withdraw a request. The row stays, its status becomes cancelled. */
    public function cancel(Request $request, $id)
    {
        if ($redirect = $this->schemaGuard()) {
            return $redirect;
        }

        $companyId = $this->companyId();
        $leave = HealthLeaveRequest::withoutGlobalScopes()
            ->where('company_id', $companyId)->findOrFail((int) $id);

        $isOwnRequest = (int) $leave->user_id === (int) $this->user()->id;
        if (!$isOwnRequest && !$this->canApprove()) {
            abort(403);
        }
        // A member may withdraw their own request while it is still pending;
        // undoing an APPROVED leave is an approver's decision.
        if ($isOwnRequest && !$this->canApprove() && $leave->status !== 'pending') {
            return back()->with('error', __('health.hr_leave_already_reviewed'));
        }
        if (in_array($leave->status, ['cancelled', 'rejected'], true)) {
            return back()->with('error', __('health.hr_leave_already_reviewed'));
        }

        $start = Carbon::parse($leave->start_date)->startOfDay();
        $end = Carbon::parse($leave->end_date)->startOfDay();

        if ($this->rangeTouchesLockedMonth($companyId, $start, $end)) {
            return back()->with('error', __('health.hr_month_locked'));
        }

        $leave->forceFill([
            'status'      => 'cancelled',
            'reviewed_by' => $this->user()->id,
            'reviewed_at' => now(),
        ])->save();

        HealthAttendanceService::recompute($companyId, [(int) $leave->user_id], $start, $end);

        return back()->with('success', __('health.hr_leave_cancelled'));
    }

    // ═══════════════════════ shared with self-service ═══════════════════════

    /** @return array<string,mixed> */
    public function validateRequest(Request $request, bool $withUser): array
    {
        $rules = [
            'health_leave_type_id' => ['required', 'integer'],
            'start_date'           => ['required', 'date'],
            'end_date'             => ['required', 'date', 'after_or_equal:start_date'],
            'is_half_day'          => ['nullable', 'boolean'],
            'reason'               => ['required', 'string', 'max:500'],
        ];
        if ($withUser) {
            $rules['user_id'] = ['required', 'integer'];
        }

        return $request->validate($rules);
    }

    /**
     * Create a pending request, or return an error string.
     *
     * @return HealthLeaveRequest|string
     */
    public function createRequest(int $companyId, int $userId, array $data)
    {
        $type = HealthLeaveType::withoutGlobalScopes()
            ->where('company_id', $companyId)
            ->where('is_active', true)
            ->find((int) $data['health_leave_type_id']);

        if (!$type) {
            return __('health.hr_leave_type_missing');
        }

        $start = Carbon::parse($data['start_date'])->startOfDay();
        $end = Carbon::parse($data['end_date'])->startOfDay();

        if ($start->diffInDays($end) > 365) {
            return __('health.hr_roster_range_too_long');
        }
        if ($this->rangeTouchesLockedMonth($companyId, $start, $end)) {
            return __('health.hr_month_locked');
        }

        // Overlap check: two approved leaves on one day would double-count in
        // the payroll handoff and make the person's balance nonsense.
        $overlap = HealthLeaveRequest::withoutGlobalScopes()
            ->where('company_id', $companyId)
            ->where('user_id', $userId)
            ->whereIn('status', ['pending', 'approved'])
            ->where('start_date', '<=', $end->toDateString())
            ->where('end_date', '>=', $start->toDateString())
            ->exists();

        if ($overlap) {
            return __('health.hr_leave_overlap');
        }

        $isHalf = (bool) ($data['is_half_day'] ?? false);
        $days = $start->diffInDays($end) + 1;
        if ($isHalf && $days === 1) {
            $days = 0.5;
        }

        $leave = HealthLeaveRequest::create([
            'company_id'           => $companyId,
            'user_id'              => $userId,
            'health_leave_type_id' => $type->id,
            'start_date'           => $start->toDateString(),
            'end_date'             => $end->toDateString(),
            'days'                 => $days,
            'is_half_day'          => $isHalf && $days === 0.5,
            'reason'               => $data['reason'],
            'status'               => 'pending',
            'created_by'           => $this->user()->id ?? null,
        ]);

        // A type the organisation marked as needing no approval is granted on
        // the spot — with the auto-approval recorded, not hidden.
        if (!$type->requires_approval) {
            $leave->forceFill([
                'status'      => 'approved',
                'reviewed_at' => now(),
                'review_note' => __('health.hr_leave_auto_approved'),
            ])->save();

            HealthAttendanceService::recompute($companyId, [$userId], $start, $end);
        }

        return $leave;
    }

    private function rangeTouchesLockedMonth(int $companyId, Carbon $start, Carbon $end): bool
    {
        return HealthAttendanceService::lockedMonthInRange($companyId, $start, $end) !== null;
    }
}

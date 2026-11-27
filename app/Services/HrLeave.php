<?php

namespace App\Services;

use App\Models\HrEmployee;
use App\Models\HrLeaveRequest;
use App\Support\Hr\LeaveAndIncidentRules;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

final class HrLeave
{
    public function __construct(private HrWorkspaceAccess $access, private HrPolicySettings $policies)
    {
    }

    public function list(int $organizationId, int $actorId, bool $ownOnly)
    {
        $this->access->membership($organizationId, $actorId, $ownOnly ? 'self.leave' : 'leave.approve');
        $employees = fn ($query) => $query->where('organization_id', $organizationId);

        return HrLeaveRequest::query()->where('organization_id', $organizationId)->whereHas('employee', $employees)
            ->with(['employee' => $employees])->when($ownOnly, fn ($query) => $query->where('requested_by', $actorId))
            ->latest('id')->paginate(25)->withQueryString();
    }

    public function balance(int $organizationId, int $actorId): ?array
    {
        $membership = $this->access->membership($organizationId, $actorId, 'self.leave');
        $employee = HrEmployee::query()->where('organization_id', $organizationId)->where('status', 'active')
            ->where('user_id', $actorId)->first();
        if (!$employee) {
            return null;
        }
        $today = CarbonImmutable::now($membership->organization->timezone);
        $year = $today->format('Y');
        $policy = $this->policies->effectiveOn($organizationId, $today->toDateString());
        $quota = (int) ($policy?->rules['annual_leave_days'] ?? 0);

        return ['year' => $year, 'remaining' => LeaveAndIncidentRules::remaining($quota, $this->approvedDays($organizationId, $employee->id, $year))];
    }

    public function submit(int $organizationId, int $actorId, array $attributes): HrLeaveRequest
    {
        $this->access->membership($organizationId, $actorId, 'self.leave');
        $data = validator($attributes, [
            'request_key' => ['required', 'uuid'],
            'starts_on' => ['required', 'date_format:Y-m-d'],
            'ends_on' => ['required', 'date_format:Y-m-d', 'after_or_equal:starts_on'],
            'category' => ['required', 'in:annual,unpaid'],
            'emergency' => ['sometimes', 'boolean'],
            'employee_note' => ['nullable', 'string', 'max:1000'],
        ])->validate();

        return DB::transaction(function () use ($organizationId, $actorId, $data) {
            $this->access->membership($organizationId, $actorId, 'self.leave', lock: true);
            $employee = HrEmployee::query()->where('organization_id', $organizationId)
                ->where('user_id', $actorId)->where('status', 'active')->first();
            if (!$employee) {
                throw ValidationException::withMessages(['starts_on' => 'Ask your HR administrator to link your employee profile.']);
            }
            $details = array_replace($data, [
                'request_key' => strtolower($data['request_key']),
                'emergency' => (bool) ($data['emergency'] ?? false),
                'employee_note' => $data['employee_note'] ?? null,
            ]);
            $requests = HrLeaveRequest::query()->where('organization_id', $organizationId);
            $existing = (clone $requests)->where('request_key', $details['request_key'])->first();
            if ($existing) {
                if ((int) $existing->requested_by === $actorId && (int) $existing->employee_id === (int) $employee->id
                    && $existing->only(array_keys($details)) == $details) {
                    return $existing;
                }
                throw ValidationException::withMessages(['request_key' => 'This request key was already used.']);
            }
            if ($details['category'] === 'annual' && substr($details['starts_on'], 0, 4) !== substr($details['ends_on'], 0, 4)) {
                throw ValidationException::withMessages(['ends_on' => 'Submit annual leave separately for each calendar year.']);
            }
            $overlaps = $this->overlaps($requests, $details['starts_on'], $details['ends_on'])->where('employee_id', $employee->id)
                ->whereIn('status', ['requested', 'approved'])->exists();
            if ($overlaps) {
                throw ValidationException::withMessages(['starts_on' => 'This period overlaps an existing leave request.']);
            }

            return HrLeaveRequest::create($details + [
                'organization_id' => $organizationId, 'employee_id' => $employee->id, 'requested_by' => $actorId,
            ]);
        }, 3);
    }

    public function decide(int $organizationId, int $actorId, int $requestId, array $attributes): HrLeaveRequest
    {
        $this->access->membership($organizationId, $actorId, 'leave.approve');
        $data = validator($attributes, [
            'decision' => ['required', 'in:approved,declined'],
            'decision_note' => ['nullable', 'string', 'max:1000'],
        ])->validate();

        return DB::transaction(function () use ($organizationId, $actorId, $requestId, $data) {
            $this->access->membership($organizationId, $actorId, 'leave.approve', lock: true);
            $leave = HrLeaveRequest::query()->where('organization_id', $organizationId)->lockForUpdate()->findOrFail($requestId);
            if ((int) $leave->requested_by === $actorId) {
                abort(403, 'Leave needs a separate reviewer.');
            }
            if ($leave->status === $data['decision'] && (int) $leave->decided_by === $actorId
                && $leave->decision_note === ($data['decision_note'] ?? null)) {
                return $leave;
            }
            if ($leave->status !== 'requested') {
                throw ValidationException::withMessages(['decision' => 'This leave request has already been decided.']);
            }

            $policy = null;
            if ($data['decision'] === 'approved' && $leave->category === 'annual') {
                $policy = $this->policies->effectiveOn($organizationId, $leave->starts_on);
                $quota = (int) ($policy?->rules['annual_leave_days'] ?? 0);
                $used = $this->approvedDays($organizationId, $leave->employee_id, substr($leave->starts_on, 0, 4));
                if (LeaveAndIncidentRules::remaining($quota, $used) < $this->days($leave->starts_on, $leave->ends_on)) {
                    throw ValidationException::withMessages(['decision' => 'This request exceeds the available annual leave balance.']);
                }
            }
            $leave->update([
                'status' => $data['decision'], 'decided_by' => $actorId, 'decision_note' => $data['decision_note'] ?? null,
                'decided_at_utc' => now('UTC'), 'policy_version_id' => $policy?->id,
            ]);

            return $leave;
        }, 3);
    }

    public function cancel(int $organizationId, int $actorId, int $requestId): void
    {
        DB::transaction(function () use ($organizationId, $actorId, $requestId) {
            $this->access->membership($organizationId, $actorId, 'self.leave', lock: true);
            $leave = HrLeaveRequest::query()->where('organization_id', $organizationId)->where('requested_by', $actorId)
                ->lockForUpdate()->findOrFail($requestId);
            if ($leave->status === 'cancelled') {
                return;
            }
            if ($leave->status !== 'requested') {
                throw ValidationException::withMessages(['decision' => 'Only pending requests can be cancelled.']);
            }
            $leave->update(['status' => 'cancelled', 'decided_by' => $actorId, 'decided_at_utc' => now('UTC')]);
        }, 3);
    }

    private function approvedDays(int $organizationId, int $employeeId, string $year): int
    {
        $from = "$year-01-01";
        $to = "$year-12-31";
        $approved = HrLeaveRequest::query()->where('organization_id', $organizationId)->where('employee_id', $employeeId)
            ->where('category', 'annual')->where('status', 'approved');

        return $this->overlaps($approved, $from, $to)->get(['starts_on', 'ends_on'])
            ->sum(fn ($row) => $this->days(max($from, $row->starts_on), min($to, $row->ends_on)));
    }

    private function overlaps(Builder $query, string $from, string $to): Builder
    {
        return $query->where('starts_on', '<=', $to)->where('ends_on', '>=', $from);
    }

    private function days(string $start, string $end): int
    {
        return (int) CarbonImmutable::parse($start, 'UTC')->diffInDays(CarbonImmutable::parse($end, 'UTC')) + 1;
    }
}

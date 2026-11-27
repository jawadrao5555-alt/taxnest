<?php

namespace App\Services;

use App\Models\HrFieldDutyRequest;
use App\Models\HrPolicyVersion;
use App\Models\HrShiftAssignment;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

final class HrFieldDuty
{
    public function __construct(private HrWorkspaceAccess $access, private HrPolicySettings $policies)
    {
    }

    public function requests(int $organizationId, int $actorId, bool $team)
    {
        $this->access->membership($organizationId, $actorId, $team ? 'attendance.approve' : 'self.attendance');
        $employeeScope = fn ($query) => $query->where('organization_id', $organizationId);
        $shiftScope = fn ($query) => $query->where('organization_id', $organizationId)->whereHas('employee', $employeeScope);

        return HrFieldDutyRequest::query()->where('organization_id', $organizationId)->whereHas('shift', $shiftScope)
            ->with(['shift' => $shiftScope, 'shift.employee' => $employeeScope])
            ->when(!$team, fn ($query) => $query->where('requested_by', $actorId))->latest('id')->paginate(25);
    }

    public function requestableShift(int $organizationId, int $actorId, int $shiftId): array
    {
        $this->access->membership($organizationId, $actorId, 'self.attendance');
        $shift = $this->employeeShift($organizationId, $actorId, $shiftId);
        $hasActiveRequest = $shift->fieldDutyRequests()->where('organization_id', $organizationId)
            ->whereIn('status', ['requested', 'approved'])->exists();

        return [$shift, !$hasActiveRequest && $this->requestIsEnabled($shift)];
    }

    public function requestIsEnabled(HrShiftAssignment $shift): bool
    {
        return $this->currentPolicyFor($shift) !== null;
    }

    public function submit(int $organizationId, int $actorId, int $shiftId, array $attributes): HrFieldDutyRequest
    {
        $this->access->membership($organizationId, $actorId, 'self.attendance');
        $data = validator($attributes, ['assignment_note' => ['required', 'string', 'max:500'], 'request_key' => ['required', 'uuid']])->validate();

        return DB::transaction(function () use ($organizationId, $actorId, $shiftId, $data) {
            $this->access->membership($organizationId, $actorId, 'self.attendance', lock: true);
            $shift = $this->employeeShift($organizationId, $actorId, $shiftId, lock: true);
            $key = strtolower($data['request_key']);
            $existing = HrFieldDutyRequest::query()->where('organization_id', $organizationId)->where('request_key', $key)->first();
            if ($existing) {
                if ((int) $existing->shift_id === $shiftId && (int) $existing->requested_by === $actorId && $existing->assignment_note === $data['assignment_note']) {
                    return $existing;
                }
                throw ValidationException::withMessages(['request_key' => 'This request key was already used.']);
            }
            $policy = $this->currentPolicyFor($shift);
            if (!$policy) {
                throw ValidationException::withMessages(['assignment_note' => 'Field-duty requests are unavailable for this shift.']);
            }
            if ($shift->fieldDutyRequests()->where('organization_id', $organizationId)->whereIn('status', ['requested', 'approved'])->exists()) {
                throw ValidationException::withMessages(['assignment_note' => 'This shift already has an active field-duty request.']);
            }

            return $shift->fieldDutyRequests()->create([
                'organization_id' => $organizationId, 'requested_by' => $actorId, 'request_key' => $key,
                'policy_version_id' => $policy->id, 'assignment_note' => $data['assignment_note'],
                'starts_at_utc' => $shift->starts_at_utc, 'ends_at_utc' => $shift->ends_at_utc, 'timezone' => $shift->timezone,
            ]);
        }, 3);
    }

    public function review(int $organizationId, int $actorId, int $requestId, array $attributes): HrFieldDutyRequest
    {
        $this->access->membership($organizationId, $actorId, 'attendance.approve');
        $data = validator($attributes, [
            'decision' => ['required', 'in:approved,declined'],
            'decision_note' => ['nullable', 'string', 'max:500'],
        ])->validate();

        return DB::transaction(function () use ($organizationId, $actorId, $requestId, $data) {
            $this->access->membership($organizationId, $actorId, 'attendance.approve', lock: true);
            $request = HrFieldDutyRequest::query()->where('organization_id', $organizationId)
                ->whereHas('shift', fn ($query) => $query->where('organization_id', $organizationId))
                ->lockForUpdate()->findOrFail($requestId);
            abort_if((int) $request->requested_by === $actorId, 403, 'Field duty needs a separate reviewer.');
            if ($request->status === $data['decision'] && (int) $request->decided_by === $actorId
                && $request->decision_note === ($data['decision_note'] ?? null)) {
                return $request;
            }
            if ($request->status !== 'requested') {
                throw ValidationException::withMessages(['decision' => 'This field-duty request has already been decided.']);
            }
            $request->update([
                'status' => $data['decision'], 'decided_by' => $actorId,
                'decision_note' => $data['decision_note'] ?? null, 'decided_at_utc' => now('UTC'),
            ]);

            return $request;
        }, 3);
    }

    private function employeeShift(int $organizationId, int $actorId, int $shiftId, bool $lock = false): HrShiftAssignment
    {
        return HrShiftAssignment::query()->where('organization_id', $organizationId)
            ->whereHas('employee', fn ($query) => $query->where('organization_id', $organizationId)->where('status', 'active')->where('user_id', $actorId))
            ->when($lock, fn ($query) => $query->lockForUpdate())->findOrFail($shiftId);
    }

    private function currentPolicyFor(HrShiftAssignment $shift): ?HrPolicyVersion
    {
        if (CarbonImmutable::now('UTC') >= CarbonImmutable::parse($shift->ends_at_utc, 'UTC')) {
            return null;
        }
        $policy = $this->policies->effectiveOn($shift->organization_id, $shift->work_date);

        return ($policy?->rules['manual_field_duty'] ?? false) === true ? $policy : null;
    }
}

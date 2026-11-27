<?php

namespace App\Services;

use App\Models\HrAttendancePunch;
use App\Models\HrEmployee;
use App\Models\HrOrganization;
use App\Models\HrShiftAssignment;
use App\Support\Hr\AttendanceRules;
use Carbon\CarbonImmutable;
use DateTimeZone;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

final class HrAttendance
{
    public function __construct(private HrWorkspaceAccess $access, private HrPolicySettings $policies)
    {
    }

    public function schedule(int $organizationId, int $actorId, array $attributes): HrShiftAssignment
    {
        $this->access->membership($organizationId, $actorId, 'attendance.approve');
        $data = validator($attributes, [
            'employee_id' => ['required', 'integer', 'min:1'],
            'work_date' => ['required', 'date_format:Y-m-d'],
            'starts_at' => ['required', 'date_format:H:i'],
            'ends_at' => ['required', 'date_format:H:i', 'different:starts_at'],
        ])->validate();

        return DB::transaction(function () use ($organizationId, $actorId, $data) {
            $membership = $this->access->membership($organizationId, $actorId, 'attendance.approve', lock: true);
            $employee = HrEmployee::query()->where('organization_id', $organizationId)->where('status', 'active')
                ->findOrFail($data['employee_id']);
            $organization = $membership->organization;
            $start = $this->localTime($organization, $data['work_date'], $data['starts_at']);
            $endDate = $data['ends_at'] < $data['starts_at']
                ? $start->addDay()->toDateString() : $data['work_date'];
            $end = $this->localTime($organization, $endDate, $data['ends_at']);
            $policy = $this->policies->effectiveOn($organizationId, $data['work_date']);
            $rules = validator($policy?->rules ?? [], [
                'grace_minutes' => ['sometimes', 'integer', 'min:0', 'max:1440'],
                'absent_after_minutes' => ['sometimes', 'integer', 'min:0', 'max:1440'],
            ])->validate();
            $grace = (int) ($rules['grace_minutes'] ?? 5);
            $absentAfter = (int) ($rules['absent_after_minutes'] ?? 30);
            if ($absentAfter < $grace || $end <= $start) {
                throw ValidationException::withMessages(['starts_at' => 'The assigned shift or attendance policy has invalid times.']);
            }

            $attributes = [
                'starts_at_utc' => $start->utc()->format('Y-m-d H:i:s'),
                'ends_at_utc' => $end->utc()->format('Y-m-d H:i:s'),
                'timezone' => $organization->timezone,
                'grace_minutes' => $grace,
                'absent_after_minutes' => $absentAfter,
                'policy_version_id' => $policy?->id,
            ];
            $shift = HrShiftAssignment::query()->where('organization_id', $organizationId)
                ->where('employee_id', $employee->id)->where('work_date', $data['work_date'])->lockForUpdate()->first();
            if ($shift && $shift->starts_at_utc === $attributes['starts_at_utc']
                && $shift->ends_at_utc === $attributes['ends_at_utc'] && $shift->timezone === $attributes['timezone']) {
                return $shift;
            }
            if ($shift && ($shift->punches()->where('organization_id', $organizationId)->exists()
                || CarbonImmutable::parse($shift->starts_at_utc, 'UTC') <= CarbonImmutable::now('UTC'))) {
                throw ValidationException::withMessages(['work_date' => 'A shift that has started or has punches cannot be replaced.']);
            }
            if ($shift && $shift->fieldDutyRequests()->where('organization_id', $organizationId)
                ->whereIn('status', ['requested', 'approved'])->exists()) {
                throw ValidationException::withMessages(['work_date' => 'A shift with a pending or approved field-duty request cannot be replaced.']);
            }

            $hasOverlap = HrShiftAssignment::query()->where('organization_id', $organizationId)
                ->where('employee_id', $employee->id)->when($shift, fn ($query) => $query->whereKeyNot($shift->id))
                ->where('starts_at_utc', '<', $attributes['ends_at_utc'])
                ->where('ends_at_utc', '>', $attributes['starts_at_utc'])->exists();
            if ($hasOverlap) {
                throw ValidationException::withMessages(['starts_at' => 'This shift overlaps another assignment for the employee.']);
            }
            if ($shift) {
                $shift->update($attributes);

                return $shift;
            }

            return HrShiftAssignment::create($attributes + [
                'organization_id' => $organizationId, 'employee_id' => $employee->id,
                'work_date' => $data['work_date'], 'created_by' => $actorId,
            ]);
        }, 3);
    }

    public function punch(int $organizationId, int $actorId, int $shiftId, string $action, string $requestKey): HrAttendancePunch
    {
        validator(['action' => $action, 'request_key' => $requestKey], [
            'action' => ['required', 'in:check_in,check_out'],
            'request_key' => ['required', 'uuid'],
        ])->validate();
        $requestKey = strtolower($requestKey);

        return DB::transaction(function () use ($organizationId, $actorId, $shiftId, $action, $requestKey) {
            $this->access->membership($organizationId, $actorId, 'self.attendance', lock: true);
            $shift = HrShiftAssignment::query()->where('organization_id', $organizationId)
                ->whereHas('employee', fn ($query) => $query->where('organization_id', $organizationId)
                    ->where('status', 'active')->where('user_id', $actorId))
                ->lockForUpdate()->findOrFail($shiftId);
            $prior = HrAttendancePunch::query()->where('organization_id', $organizationId)
                ->where('request_key', $requestKey)->first();
            if ($prior) {
                if ((int) $prior->shift_id !== $shiftId || (int) $prior->actor_user_id !== $actorId || $prior->action !== $action) {
                    throw ValidationException::withMessages(['request_key' => 'Attendance request key already used for another action.']);
                }

                return $prior;
            }
            if ($shift->punches()->where('organization_id', $organizationId)->where('action', $action)->exists()) {
                throw ValidationException::withMessages(['action' => 'This attendance action has already been recorded.']);
            }

            $now = CarbonImmutable::now('UTC');
            $end = CarbonImmutable::parse($shift->ends_at_utc, 'UTC');
            if ($action === 'check_in' && ($now->setTimezone($shift->timezone)->toDateString() < $shift->work_date || $now >= $end)) {
                throw ValidationException::withMessages(['action' => 'Check in during the assigned shift day and before the shift ends.']);
            }
            if ($action === 'check_out' && (!AttendanceRules::canCheckout($now, $end)
                || !$shift->punches()->where('organization_id', $organizationId)->where('action', 'check_in')->exists())) {
                throw ValidationException::withMessages(['action' => 'Check out after the shift ends and an arrival is recorded.']);
            }

            return HrAttendancePunch::create([
                'organization_id' => $organizationId, 'shift_id' => $shiftId, 'actor_user_id' => $actorId,
                'request_key' => $requestKey, 'action' => $action, 'occurred_at_utc' => $now->format('Y-m-d H:i:s'),
            ]);
        }, 3);
    }

    public function report(int $organizationId, int $actorId, bool $ownOnly, string $from, string $to)
    {
        $this->access->membership($organizationId, $actorId, $ownOnly ? 'self.attendance' : 'attendance.read');
        $employees = fn ($query) => $query->where('organization_id', $organizationId)
            ->when($ownOnly, fn ($query) => $query->where('user_id', $actorId));

        return HrShiftAssignment::query()->where('organization_id', $organizationId)
            ->whereHas('employee', $employees)->whereBetween('work_date', [$from, $to])
            ->with(['employee' => $employees, 'punches' => fn ($query) => $query->where('organization_id', $organizationId)->orderBy('id')])
            ->withExists([
                'fieldDutyRequests as field_duty_approved' => fn ($query) => $query->where('organization_id', $organizationId)->where('status', 'approved'),
                'fieldDutyRequests as field_duty_pending' => fn ($query) => $query->where('organization_id', $organizationId)->where('status', 'requested'),
            ])
            ->orderByDesc('work_date')->orderBy('id')->paginate(25)->withQueryString();
    }

    public function status(HrShiftAssignment $shift): string
    {
        $start = CarbonImmutable::parse($shift->starts_at_utc, 'UTC');
        $arrival = $shift->punches->firstWhere('action', 'check_in');
        if (!$arrival) {
            return CarbonImmutable::now('UTC') > $start->addMinutes($shift->absent_after_minutes)
                ? 'No arrival recorded' : 'Awaiting check-in';
        }
        $classification = AttendanceRules::classify(CarbonImmutable::parse($arrival->occurred_at_utc, 'UTC'),
            $start, $shift->grace_minutes, $shift->absent_after_minutes);

        return match ($classification['status']) {
            'on_time' => 'On time',
            'late' => 'Late by '.$classification['late_minutes'].' min',
            default => 'Arrival after absence cutoff',
        };
    }

    private function localTime(HrOrganization $organization, string $date, string $time): CarbonImmutable
    {
        $local = CarbonImmutable::createFromFormat('!Y-m-d H:i', "$date $time", new DateTimeZone($organization->timezone));
        if (!$local || $local->format('Y-m-d H:i') !== "$date $time") {
            throw ValidationException::withMessages(['starts_at' => 'This local time is unavailable in the workspace timezone.']);
        }

        return $local;
    }
}

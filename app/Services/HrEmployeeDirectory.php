<?php

namespace App\Services;

use App\Models\HrEmployee;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

final class HrEmployeeDirectory
{
    public function __construct(private HrWorkspaceAccess $access)
    {
    }

    public function list(int $organizationId, int $actorUserId, int $perPage = 25)
    {
        $this->access->membership($organizationId, $actorUserId, 'employee.read');
        return HrEmployee::query()->where('organization_id', $organizationId)
            ->orderBy('employee_number')->paginate(min(100, max(1, $perPage)), pageName: 'employees_page');
    }

    public function find(int $organizationId, int $actorUserId, int $employeeId): HrEmployee
    {
        $this->access->membership($organizationId, $actorUserId, 'employee.read');
        return HrEmployee::query()->where('organization_id', $organizationId)->findOrFail($employeeId);
    }

    public function create(int $organizationId, int $actorUserId, array $attributes): HrEmployee
    {
        $this->access->membership($organizationId, $actorUserId, 'employee.write');
        $data = validator($attributes, [
            'employee_number' => ['required','string','max:64'],
            'full_name' => ['required','string','max:200'],
            'father_name' => ['nullable','string','max:200'],
            'email' => ['nullable','email','max:255'],
            'phone' => ['nullable','string','max:40'],
            'designation' => ['nullable','string','max:150'],
            'joined_on' => ['nullable','date'],
            'user_id' => ['nullable','integer','min:1'],
        ])->validate();

        return DB::transaction(function () use ($organizationId, $actorUserId, $data) {
            $this->access->membership($organizationId, $actorUserId, 'employee.write', lock: true);
            if (HrEmployee::query()->where('organization_id', $organizationId)->where('employee_number', $data['employee_number'])->exists()) {
                throw ValidationException::withMessages(['employee_number' => 'Employee number already exists in this organization.']);
            }
            $this->validateLinkedUser($organizationId, $data['user_id'] ?? null);
            return HrEmployee::create($data + ['organization_id' => $organizationId]);
        }, 3);
    }

    public function update(int $organizationId, int $actorUserId, int $employeeId, array $attributes): HrEmployee
    {
        $this->access->membership($organizationId, $actorUserId, 'employee.write');
        $data = validator($attributes, [
            'full_name' => ['sometimes','required','string','max:200'],
            'father_name' => ['nullable','string','max:200'],
            'email' => ['nullable','email','max:255'],
            'phone' => ['nullable','string','max:40'],
            'designation' => ['nullable','string','max:150'],
            'status' => ['sometimes','in:active,inactive'],
            'user_id' => ['nullable','integer','min:1'],
        ])->validate();
        return DB::transaction(function () use ($organizationId, $actorUserId, $employeeId, $data) {
            $this->access->membership($organizationId, $actorUserId, 'employee.write', lock: true);
            $employee = HrEmployee::query()->where('organization_id', $organizationId)->lockForUpdate()->findOrFail($employeeId);
            if (array_key_exists('user_id', $data) && (int) $data['user_id'] !== (int) $employee->user_id) {
                if ($employee->user_id && \App\Models\HrShiftAssignment::query()->where('organization_id', $organizationId)
                    ->where('employee_id', $employeeId)->exists()) {
                    throw ValidationException::withMessages(['user_id' => 'An account linked to a shift history cannot be replaced.']);
                }
                $this->validateLinkedUser($organizationId, $data['user_id']);
            }
            $employee->update($data);

            return $employee->refresh();
        }, 3);
    }

    private function validateLinkedUser(int $organizationId, mixed $userId): void
    {
        if ($userId === null) {
            return;
        }

        try {
            $membership = $this->access->membership($organizationId, (int) $userId, 'workspace.read');
        } catch (\Illuminate\Auth\Access\AuthorizationException) {
            throw ValidationException::withMessages(['user_id' => 'Choose an active workspace member.']);
        }

        if (HrEmployee::query()->where('organization_id', $organizationId)->where('user_id', $membership->user_id)->exists()) {
            throw ValidationException::withMessages(['user_id' => 'This workspace member already has an employee profile.']);
        }
    }
}

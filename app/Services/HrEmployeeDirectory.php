<?php

namespace App\Services;

use App\Models\HrEmployee;
use App\Models\HrOrganizationMembership;
use App\Support\Hr\TenantAccess;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

final class HrEmployeeDirectory
{
    private function authorize(int $organizationId, int $actorUserId, string $permission): void
    {
        $membership = HrOrganizationMembership::query()
            ->where('organization_id', $organizationId)
            ->where('user_id', $actorUserId)
            ->first();
        if (!$membership || !TenantAccess::allows($organizationId, (int) $membership->organization_id, $membership->role, $permission, (bool) $membership->active)) {
            throw new AuthorizationException('HR organization access denied.');
        }
    }

    public function list(int $organizationId, int $actorUserId, int $perPage = 25)
    {
        $this->authorize($organizationId, $actorUserId, 'employee.read');
        return HrEmployee::query()->where('organization_id', $organizationId)
            ->orderBy('employee_number')->paginate(min(100, max(1, $perPage)));
    }

    public function find(int $organizationId, int $actorUserId, int $employeeId): HrEmployee
    {
        $this->authorize($organizationId, $actorUserId, 'employee.read');
        return HrEmployee::query()->where('organization_id', $organizationId)->findOrFail($employeeId);
    }

    public function create(int $organizationId, int $actorUserId, array $attributes): HrEmployee
    {
        $this->authorize($organizationId, $actorUserId, 'employee.write');
        $data = validator($attributes, [
            'employee_number' => ['required','string','max:64'],
            'full_name' => ['required','string','max:200'],
            'father_name' => ['nullable','string','max:200'],
            'email' => ['nullable','email','max:255'],
            'phone' => ['nullable','string','max:40'],
            'designation' => ['nullable','string','max:150'],
            'joined_on' => ['nullable','date'],
        ])->validate();

        return DB::transaction(function () use ($organizationId, $data) {
            if (HrEmployee::query()->where('organization_id', $organizationId)->where('employee_number', $data['employee_number'])->exists()) {
                throw ValidationException::withMessages(['employee_number' => 'Employee number already exists in this organization.']);
            }
            return HrEmployee::create($data + ['organization_id' => $organizationId]);
        });
    }

    public function update(int $organizationId, int $actorUserId, int $employeeId, array $attributes): HrEmployee
    {
        $this->authorize($organizationId, $actorUserId, 'employee.write');
        $employee = HrEmployee::query()->where('organization_id', $organizationId)->findOrFail($employeeId);
        $data = validator($attributes, [
            'full_name' => ['sometimes','required','string','max:200'],
            'father_name' => ['nullable','string','max:200'],
            'email' => ['nullable','email','max:255'],
            'phone' => ['nullable','string','max:40'],
            'designation' => ['nullable','string','max:150'],
            'status' => ['sometimes','in:active,inactive'],
        ])->validate();
        $employee->update($data);
        return $employee->refresh();
    }
}

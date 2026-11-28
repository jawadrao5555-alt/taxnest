<?php

namespace App\Services;

use App\Models\HrEmployee;
use App\Models\HrEmployeeInvitation;
use App\Models\HrOrganization;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

final class HrEmployeeInvitations
{
    public function __construct(private HrWorkspaceAccess $access)
    {
    }

    public function forEmployee(int $organizationId, int $employeeId, int $issuerId): array
    {
        $membership = $this->access->membership($organizationId, $issuerId, 'employee.invite');

        return [
            'workspace' => $membership->organization,
            'employee' => $membership->organization->employees()->findOrFail($employeeId),
            'invitation' => $this->employeeInvitations($organizationId, $employeeId)->pending()->latest('id')->first(),
        ];
    }

    public function issue(int $organizationId, int $employeeId, int $issuerId, int $userId): string
    {
        return DB::transaction(function () use ($organizationId, $employeeId, $issuerId, $userId) {
            $organization = $this->access->membership($organizationId, $issuerId, 'employee.invite', lock: true)->organization;
            $employee = $organization->employees()->lockForUpdate()->findOrFail($employeeId);
            if ($employee->user_id || $employee->status !== 'active') {
                throw ValidationException::withMessages(['user_id' => 'Choose an active employee without a linked account.']);
            }
            if (!$this->availableUser($organization, $userId)) {
                throw ValidationException::withMessages(['user_id' => 'Choose an active account in the linked company that has no workspace membership or employee profile.']);
            }
            if (HrEmployeeInvitation::query()->where('organization_id', $organizationId)->pending()
                ->where(fn ($query) => $query->where('employee_id', $employeeId)->orWhere('user_id', $userId))->exists()) {
                throw ValidationException::withMessages(['user_id' => 'An invitation is already pending for this employee or account.']);
            }

            $token = bin2hex(random_bytes(32));
            HrEmployeeInvitation::create([
                'organization_id' => $organizationId,
                'employee_id' => $employeeId,
                'user_id' => $userId,
                'issued_by' => $issuerId,
                'token_hash' => hash('sha256', $token),
                'expires_at' => now()->addHours(48),
            ]);

            return $token;
        }, 3);
    }

    public function revoke(int $organizationId, int $employeeId, int $issuerId, int $invitationId): void
    {
        DB::transaction(function () use ($organizationId, $employeeId, $issuerId, $invitationId) {
            $this->access->membership($organizationId, $issuerId, 'employee.invite', lock: true);
            $invitation = $this->employeeInvitations($organizationId, $employeeId)->pending()->lockForUpdate()->findOrFail($invitationId);
            $invitation->update(['revoked_at' => now(), 'revoked_by' => $issuerId]);
        }, 3);
    }

    public function pending(string $token): HrEmployeeInvitation
    {
        return HrEmployeeInvitation::query()->pending()->where('token_hash', hash('sha256', $token))->firstOrFail();
    }

    public function accept(string $token, string $email, string $password, ?string $code): ?int
    {
        $requested = $this->pending($token);

        return DB::transaction(function () use ($requested, $email, $password, $code) {
            try {
                $organization = $this->access->membership(
                    $requested->organization_id, (int) $requested->issued_by, 'employee.invite', lock: true,
                )->organization;
            } catch (AuthorizationException) {
                return null;
            }

            $invitation = HrEmployeeInvitation::query()->pending()->lockForUpdate()->findOrFail($requested->id);
            $employee = $organization->employees()->lockForUpdate()->findOrFail($invitation->employee_id);
            $user = $this->availableUser($organization, $invitation->user_id);
            if (!$user || $employee->user_id || $employee->status !== 'active'
                || Str::lower((string) $user->email) !== Str::lower($email) || !Hash::check($password, $user->password)) {
                return null;
            }
            if (TwoStepLogin::credential($user) && (!$code || !TwoStepLogin::consume($user, $code))) {
                return null;
            }

            $organization->memberships()->create(['user_id' => $user->id, 'role' => 'employee', 'active' => true]);
            $employee->update(['user_id' => $user->id]);
            $invitation->update(['accepted_at' => now()]);

            return (int) $organization->id;
        }, 3);
    }

    private function employeeInvitations(int $organizationId, int $employeeId): Builder
    {
        return HrEmployeeInvitation::query()->where('organization_id', $organizationId)->where('employee_id', $employeeId);
    }

    private function availableUser(HrOrganization $organization, int $userId): ?User
    {
        $company = $organization->linkedCompany()->first();
        if (!$company?->isActive()
            || $organization->memberships()->where('user_id', $userId)->exists()
            || $organization->employees()->where('user_id', $userId)->exists()) {
            return null;
        }

        return $company->users()->whereKey($userId)->where('is_active', true)->whereNotNull('email')->where('email', '<>', '')->lockForUpdate()->first();
    }
}

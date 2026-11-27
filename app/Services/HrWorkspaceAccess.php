<?php

namespace App\Services;

use App\Models\HrOrganization;
use App\Models\HrOrganizationMembership;
use App\Support\Hr\TenantAccess;
use Illuminate\Auth\Access\AuthorizationException;

final class HrWorkspaceAccess
{
    public function membership(int $organizationId, int $userId, string $capability, bool $lock = false): HrOrganizationMembership
    {
        $organization = HrOrganization::query()->whereKey($organizationId)->where('status', 'active')
            ->when($lock, fn ($query) => $query->lockForUpdate())->first();

        if (!$organization) {
            throw new AuthorizationException('HR workspace access denied.');
        }

        $membership = HrOrganizationMembership::query()
            ->where('organization_id', $organizationId)->where('user_id', $userId)
            ->whereHas('user', fn ($query) => $query->where('is_active', true))
            ->when($lock, fn ($query) => $query->lockForUpdate())->first();

        if (!$membership || !TenantAccess::allows($organizationId, (int) $membership->organization_id,
            $membership->role, $capability, $membership->active)) {
            throw new AuthorizationException('HR workspace access denied.');
        }

        return $membership->setRelation('organization', $organization);
    }
}

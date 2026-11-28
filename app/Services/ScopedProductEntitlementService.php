<?php

namespace App\Services;

use App\Models\AdminAuditLog;
use App\Models\AdminUser;
use App\Models\Company;
use App\Models\HrOrganization;
use App\Models\ScopedProductEntitlement;
use DateTimeInterface;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

final class ScopedProductEntitlementService
{
    /**
     * The calling product must authorize access to the given scope first.
     * Entitlements govern purchased product capabilities, not user permissions.
     */
    public function allows(
        Company|HrOrganization $scope,
        string $product,
        string $module,
        ?DateTimeInterface $at = null,
    ): bool {
        if (!$this->validKey($product) || !$this->validKey($module) || !$this->isActive($this->persistedScope($scope))) {
            return false;
        }

        $epoch = ($at ?? now())->getTimestamp();

        return $this->scopeQuery($scope)
            ->where('product_key', $product)
            ->where('module_key', $module)
            ->where('starts_at_epoch', '<=', $epoch)
            ->where('ends_at_epoch', '>', $epoch)
            ->where(fn (Builder $query) => $query->whereNull('revoked_at_epoch')->orWhere('revoked_at_epoch', '>', $epoch))
            ->exists();
    }

    public function grant(
        Company|HrOrganization $scope,
        string $product,
        string $module,
        string $sourceReference,
        DateTimeInterface $startsAt,
        DateTimeInterface $endsAt,
    ): ScopedProductEntitlement {
        $actor = AdminUser::requireBillingAdministrator();
        $start = $startsAt->getTimestamp();
        $end = $endsAt->getTimestamp();

        if (!$this->validKey($product) || !$this->validKey($module)) {
            throw ValidationException::withMessages(['entitlement' => 'Use stable lowercase product and module keys.']);
        }

        if (preg_match('/\A[a-z0-9][a-z0-9._:\/-]{0,99}\z/', $sourceReference) !== 1) {
            throw ValidationException::withMessages(['source_reference' => 'Enter a stable lowercase source reference.']);
        }

        if ($start < 0 || $end <= $start) {
            throw ValidationException::withMessages(['period' => 'The grant requires a start time before its end time.']);
        }

        return DB::transaction(function () use ($scope, $product, $module, $sourceReference, $start, $end, $actor) {
            // Serialize grants and revocations for the same native scope.
            if (!$this->isActive($this->persistedScope($scope, lock: true))) {
                throw ValidationException::withMessages(['scope' => 'Select an active product installation or HR workspace.']);
            }

            $existing = $this->scopeQuery($scope)
                ->where('product_key', $product)
                ->where('module_key', $module)
                ->where('source_reference', $sourceReference)
                ->first();
            if ($existing) {
                if ($existing->starts_at_epoch !== $start || $existing->ends_at_epoch !== $end) {
                    throw ValidationException::withMessages(['source_reference' => 'This source reference already records a different term.']);
                }

                return $existing;
            }

            $grant = ScopedProductEntitlement::create([
                ...$this->scopeKey($scope),
                'product_key' => $product,
                'module_key' => $module,
                'source_reference' => $sourceReference,
                'starts_at_epoch' => $start,
                'ends_at_epoch' => $end,
            ]);
            $this->audit($actor, $grant, 'scoped_entitlement_granted');

            return $grant;
        }, 3);
    }

    public function revoke(Company|HrOrganization $scope, int $grantId): void
    {
        $actor = AdminUser::requireBillingAdministrator();

        DB::transaction(function () use ($scope, $grantId, $actor) {
            // An administrator can revoke a grant even while its scope is inactive.
            if (!$this->persistedScope($scope, lock: true)) {
                throw ValidationException::withMessages(['scope' => 'Select an existing product installation or HR workspace.']);
            }

            $grant = $this->scopeQuery($scope)->lockForUpdate()->findOrFail($grantId);
            if ($grant->revoked_at_epoch !== null) {
                return;
            }

            $grant->update(['revoked_at_epoch' => now()->getTimestamp()]);
            $this->audit($actor, $grant, 'scoped_entitlement_revoked');
        }, 3);
    }

    private function scopeQuery(Company|HrOrganization $scope): Builder
    {
        return ScopedProductEntitlement::query()->where($this->scopeKey($scope));
    }

    private function scopeKey(Company|HrOrganization $scope): array
    {
        return [
            'scope_type' => $scope instanceof Company ? 'company_installation' : 'hr_workspace',
            'scope_id' => (int) $scope->getKey(),
        ];
    }

    private function persistedScope(Company|HrOrganization $scope, bool $lock = false): Company|HrOrganization|null
    {
        if (!$scope->exists || (int) $scope->getKey() <= 0) {
            return null;
        }

        return $scope->newQuery()->when($lock, fn (Builder $query) => $query->lockForUpdate())->find($scope->getKey());
    }

    private function isActive(Company|HrOrganization|null $current): bool
    {
        return $current instanceof Company
            ? $current->status === 'approved' && $current->isActive()
            : $current instanceof HrOrganization && $current->status === 'active';
    }

    private function validKey(string $key): bool
    {
        return preg_match('/\A[a-z][a-z0-9_-]{0,47}\z/', $key) === 1;
    }

    private function audit(AdminUser $actor, ScopedProductEntitlement $grant, string $action): void
    {
        AdminAuditLog::log((int) $actor->id, $action, 'ScopedProductEntitlement', (int) $grant->id, [
            'scope_type' => $grant->scope_type,
            'scope_id' => $grant->scope_id,
            'product_key' => $grant->product_key,
            'module_key' => $grant->module_key,
        ]);
    }
}

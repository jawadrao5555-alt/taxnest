<?php

namespace App\Models\Scopes;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Scope;

/**
 * Defense-in-depth tenant isolation.
 *
 * Applies a `company_id = currentCompanyId` filter to every query on the
 * model when the request has bound `currentCompanyId` in the container
 * (done by App\Http\Middleware\CompanyIsolation for authenticated tenant users).
 *
 * Intentionally a no-op when:
 *   - Running in CLI / queue jobs (no tenant context — jobs filter explicitly)
 *   - Admin guard active (admin routes don't run CompanyIsolation, so currentCompanyId is unbound)
 *   - currentCompanyId not bound for any other reason
 *   - Authenticated user has role `super_admin` (platform-level user with intentional cross-company access,
 *     e.g. InvoiceController checks `role !== 'super_admin'` for cross-tenant guards)
 *
 * Bypass per-query with `Model::withoutGlobalScope(CompanyScope::class)`.
 */
class CompanyScope implements Scope
{
    public function apply(Builder $builder, Model $model): void
    {
        if (!app()->bound('currentCompanyId')) {
            return;
        }

        // Super admins (platform owners) intentionally bypass tenant isolation —
        // matches the existing `role !== 'super_admin'` guards in controllers.
        if (auth()->check() && (auth()->user()->role ?? null) === 'super_admin') {
            return;
        }

        $companyId = app('currentCompanyId');
        if (!$companyId) {
            return;
        }

        $builder->where($model->getTable() . '.company_id', $companyId);
    }
}

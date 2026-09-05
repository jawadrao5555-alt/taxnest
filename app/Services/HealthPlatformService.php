<?php

namespace App\Services;

use App\Models\Branch;
use App\Models\Company;
use App\Models\PricingPlan;
use App\Models\Subscription;
use App\Models\User;
use App\Support\HealthPanel;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;

/**
 * The seam between the Nest ERPS Healthcare vertical and the shared TaxNest platform.
 *
 * Healthcare code must NOT reach into company, branch, subscription,
 * notification, file-storage or FBR internals directly. It asks this class
 * instead, and this class delegates to the services the rest of the product
 * already uses. Two things follow from that:
 *
 *  - Healthcare gets the proven behaviour (tenant isolation, branch context,
 *    trial handling, approval gating) for free instead of a second
 *    implementation that slowly drifts.
 *  - When a platform service changes, exactly one healthcare-facing file has to
 *    be re-read to know whether the panel is affected.
 *
 * Nothing here invents functionality. Where a shared capability is not wired up
 * for healthcare yet (FBR submission has no healthcare document type until a
 * billing module exists), the method reports readiness honestly and refuses to
 * pretend.
 */
class HealthPlatformService
{
    /* ─────────────────────────── Company ─────────────────────────── */

    /** Is this a healthcare company at all? */
    public static function isHealthcareCompany(?Company $company): bool
    {
        // Tolerant of the value rows held before the Nest ERPS umbrella
        // existed — see NestErps::PRODUCT_TYPES.
        return $company !== null
            && HealthPanel::isProductType($company->product_type ?? null);
    }

    /**
     * The healthcare vertical's BILLABLE DOCUMENTS — what a usage-capped grant
     * measures for a Nest ERPS organisation (registered as this vertical's
     * `billable` entry in NestErps::VERTICALS).
     *
     * Outpatient visits and pharmacy sales are the two documents this vertical
     * actually issues to a patient. Both are counted defensively: a database
     * without the module's tables (minimal test schema, deploy-before-migrate)
     * must return a number, never throw on a billing screen.
     */
    public static function billableCount(int $companyId, $since = null): int
    {
        $count = 0;

        foreach (['health_visits', 'health_pharmacy_sales'] as $table) {
            try {
                if (!Schema::hasTable($table)) {
                    continue;
                }
                $count += (int) \Illuminate\Support\Facades\DB::table($table)
                    ->where('company_id', $companyId)
                    ->when($since, fn ($q) => $q->where('created_at', '>=', $since))
                    ->count();
            } catch (\Throwable $e) {
                // A missing column or table is not a billing failure.
            }
        }

        return $count;
    }

    /** The company behind the current healthcare request (never a lazy load). */
    public static function currentCompany(): ?Company
    {
        $id = app()->bound('currentCompanyId') ? app('currentCompanyId') : null;
        if (!$id) {
            $user = auth()->guard(HealthPanel::GUARD)->user();
            $id = $user->company_id ?? null;
        }

        return $id ? Company::find($id) : null;
    }

    /**
     * Organisation type, normalised. Drives the module defaults and the labels
     * the panel prints ("Clinic" vs "Hospital").
     */
    public static function orgType(?Company $company): string
    {
        return HealthPanel::normalizeOrgType($company->health_org_type ?? null);
    }

    /* ─────────────────────────── Branches ────────────────────────── */

    /** The platform's branch context — healthcare never keeps its own. */
    public static function branchContext(): BranchContextService
    {
        return app(BranchContextService::class);
    }

    public static function activeBranchId(): ?int
    {
        try {
            return self::branchContext()->getActiveBranchId();
        } catch (\Throwable $e) {
            return null;
        }
    }

    /** Branches this person may switch between, from the shared service. */
    public static function accessibleBranches()
    {
        try {
            return self::branchContext()->accessibleBranches();
        } catch (\Throwable $e) {
            return collect();
        }
    }

    /** The head office / first active branch — used as a safe default posting. */
    public static function defaultBranchId(?Company $company): ?int
    {
        if (!$company) {
            return null;
        }

        try {
            $id = Branch::withoutGlobalScopes()
                ->where('company_id', $company->id)
                ->where('is_active', true)
                ->orderByDesc('is_head_office')
                ->orderBy('id')
                ->value('id');

            return $id ? (int) $id : null;
        } catch (\Throwable $e) {
            return null;
        }
    }

    /* ───────────────────────── Subscription ──────────────────────── */

    /** Start the standard platform trial for a brand-new healthcare company. */
    public static function ensureTrial(int $companyId, int $days = 3): void
    {
        try {
            TrialSubscriptionService::ensureTrial($companyId, HealthPanel::PRODUCT_TYPE, $days);
        } catch (\Throwable $e) {
            // A missing trial must never block a signup — admin can assign one.
            \Illuminate\Support\Facades\Log::warning('Healthcare trial assignment failed', [
                'company_id' => $companyId,
                'error' => $e->getMessage(),
            ]);
        }
    }

    /** The live subscription row, or null. */
    public static function activeSubscription(?Company $company): ?Subscription
    {
        if (!$company) {
            return null;
        }

        try {
            // The live-row flag on this table is `active` — there is no
            // is_active column, and querying one throws.
            return Subscription::where('company_id', $company->id)
                ->where('active', true)
                ->with('pricingPlan')
                ->latest('id')
                ->first();
        } catch (\Throwable $e) {
            return null;
        }
    }

    public static function activePlan(?Company $company): ?PricingPlan
    {
        $subscription = self::activeSubscription($company);
        if (!$subscription) {
            return null;
        }

        return $subscription->relationLoaded('pricingPlan')
            ? $subscription->getRelation('pricingPlan')
            : PricingPlan::find($subscription->pricing_plan_id);
    }

    /** Every healthcare package still on the shelf, cheapest first. */
    public static function sellablePlans()
    {
        try {
            return PricingPlan::whereIn('product_type', \App\Support\NestErps::PRODUCT_TYPES)
                ->where('is_trial', false)
                ->orderBy('price')
                ->get()
                ->filter(fn (PricingPlan $plan) => PlanSellabilityService::isSellable($plan))
                ->values();
        } catch (\Throwable $e) {
            return collect();
        }
    }

    /**
     * How many departments the package allows (-1 = unlimited). A package with
     * no explicit limit is unlimited rather than zero — a missing column must
     * never stop an organisation creating its first department.
     */
    public static function departmentLimit(?Company $company): int
    {
        $plan = self::activePlan($company);
        if (!$plan) {
            return -1;
        }

        try {
            if (!Schema::hasColumn('pricing_plans', 'health_department_limit')) {
                return -1;
            }
        } catch (\Throwable $e) {
            return -1;
        }

        $limit = $plan->getAttributeValue('health_department_limit');

        return $limit === null ? -1 : (int) $limit;
    }

    /* ──────────────────────── Notifications ──────────────────────── */

    /**
     * The company's undismissed platform notifications, reusing the same table
     * every other panel reads. Returns an empty collection on a box where the
     * table has not landed yet rather than 500-ing the dashboard.
     */
    public static function notifications(?Company $company, int $limit = 5)
    {
        if (!$company) {
            return collect();
        }

        try {
            if (!Schema::hasTable('notifications')) {
                return collect();
            }

            // The platform's notifications table marks a read row with `read`
            // (there is no dismissed_at column) — reading the wrong one would
            // silently show the panel nothing at all.
            return \Illuminate\Support\Facades\DB::table('notifications')
                ->where('company_id', $company->id)
                ->where('read', false)
                ->orderByDesc('created_at')
                ->limit($limit)
                ->get();
        } catch (\Throwable $e) {
            return collect();
        }
    }

    /* ─────────────────────────── Files ───────────────────────────── */

    /**
     * Where a healthcare company's uploads live. Company-scoped on purpose:
     * the hard-delete purge removes the whole directory in one call, exactly
     * like the audit-pack directory.
     */
    public static function fileDirectory(int $companyId, string $bucket = 'general'): string
    {
        $bucket = preg_replace('/[^a-z0-9_\-]/i', '', $bucket) ?: 'general';

        return 'healthcare/company_' . $companyId . '/' . $bucket;
    }

    /** Store an uploaded file on the private disk, under the company's tree. */
    public static function storeFile(int $companyId, $file, string $bucket = 'general'): ?string
    {
        try {
            return $file->store(self::fileDirectory($companyId, $bucket), 'local') ?: null;
        } catch (\Throwable $e) {
            \Illuminate\Support\Facades\Log::warning('Healthcare file store failed', [
                'company_id' => $companyId,
                'error' => $e->getMessage(),
            ]);

            return null;
        }
    }

    /** Remove everything a healthcare company ever uploaded (hard delete). */
    public static function purgeFiles(int $companyId): void
    {
        try {
            Storage::disk('local')->deleteDirectory('healthcare/company_' . $companyId);
        } catch (\Throwable $e) {
            \Illuminate\Support\Facades\Log::warning('Healthcare file purge failed', [
                'company_id' => $companyId,
                'error' => $e->getMessage(),
            ]);
        }
    }

    /* ──────────────────────────── FBR ────────────────────────────── */

    /**
     * Whether this organisation is configured to file with FBR at all.
     *
     * Healthcare billing is out of scope for the foundation, so this reports
     * READINESS only — it never submits and never claims a document was filed.
     * When a healthcare billing module arrives it will call the platform's
     * existing FBR services through this same seam, so the credentials, the
     * environment switch and the token handling stay in one place.
     *
     * @return array{configured:bool, environment:?string, registration_no:?string, reason:?string}
     */
    public static function fbrReadiness(?Company $company): array
    {
        if (!$company) {
            return ['configured' => false, 'environment' => null, 'registration_no' => null, 'reason' => 'no_company'];
        }

        $registration = $company->fbr_registration_no ?? ($company->ntn ?? null);
        $environment = $company->fbr_environment ?? null;

        if (!$registration) {
            return ['configured' => false, 'environment' => $environment, 'registration_no' => null, 'reason' => 'missing_registration'];
        }

        if (!$environment) {
            return ['configured' => false, 'environment' => null, 'registration_no' => $registration, 'reason' => 'missing_environment'];
        }

        return ['configured' => true, 'environment' => $environment, 'registration_no' => $registration, 'reason' => null];
    }

    /* ─────────────────────────── People ──────────────────────────── */

    /** The organisation's healthcare staff accounts (owner first). */
    public static function staff(?Company $company)
    {
        if (!$company) {
            return collect();
        }

        try {
            $query = User::where('company_id', $company->id);
            if (Schema::hasColumn('users', 'health_role')) {
                $query->where(function ($q) {
                    $q->whereNotNull('health_role')->orWhere('role', 'company_admin');
                });
            }

            return $query->orderByRaw("CASE WHEN role = 'company_admin' THEN 0 ELSE 1 END")
                ->orderBy('name')
                ->get();
        } catch (\Throwable $e) {
            return collect();
        }
    }
}

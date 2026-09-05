<?php

namespace App\Services;

use App\Models\Company;
use App\Support\HealthPanel;
use Illuminate\Support\Facades\Schema;

/**
 * Healthcare module capabilities — ONE answer to "is this module on?".
 *
 * A module is reachable only when BOTH sides agree:
 *   1. the package the organisation bought lists it (pricing_plans.health_modules), and
 *   2. the owner switched it on (companies.health_modules).
 *
 * That is deliberately the same shape PosFeatureService uses: the owner's own
 * choice is stored untouched, and the plan masks it at READ time. A clinic that
 * upgrades to the hospital package therefore gets its previously-configured
 * modules back without re-ticking anything, and a downgrade never destroys the
 * owner's configuration.
 *
 * Nothing here is retail-aware: PRA POS / FBR POS / Digital Invoice companies
 * never reach this class, and a healthcare company never reaches theirs.
 */
class HealthModuleService
{
    /**
     * Every healthcare module, in display order.
     *
     * ADDING ONE? It must also appear in MODULE_CAPABILITIES (even as an empty
     * list) and get its lang keys in lang/{en,rur,ur}/health.php — capabilities
     * of an unlisted module can never be granted, and an unlisted key is
     * silently dropped by normalize().
     */
    public const MODULES = ['opd', 'pharmacy', 'ipd', 'lab', 'accounts', 'hr'];

    /**
     * Capabilities that belong to each module. A capability whose module is OFF
     * is unreachable for EVERY role, including the owner — that is what makes
     * "owners enable only the modules their organisation uses" real rather than
     * cosmetic.
     *
     * CORE capabilities have no module: patient registration, staff, branches,
     * departments and settings are what the panel IS, not an optional add-on.
     */
    public const CORE_CAPABILITIES = [
        'dashboard.view',
        'patients.view',
        'patients.manage',
        'reports.view',
        'departments.manage',
        'staff.manage',
        'settings.manage',
        'audit.view',
    ];

    public const MODULE_CAPABILITIES = [
        'opd' => [
            'appointments.view', 'appointments.manage',
            // Practitioner profiles carry the fee schedule, so they are NOT
            // part of "book an appointment": a receptionist must be able to fill
            // the diary without being able to change what a consultation costs.
            'doctors.manage',
            'clinical.view', 'clinical.write', 'nursing.record',
        ],
        'pharmacy' => [
            'pharmacy.view', 'pharmacy.dispense', 'pharmacy.manage',
        ],
        // Inpatient AND the operation theatre. They are one module because a
        // theatre without a ward to admit into is not a product anybody buys,
        // and splitting them would let an owner switch off the half that pays
        // for the other.
        'ipd' => [
            'ipd.view', 'ipd.manage',
            // Posting money onto a stay and signing a patient out are separate
            // from running the ward: the sister moves the patient, accounts
            // clears the bill, and neither should be able to do the other's job
            // by accident.
            'ipd.charge', 'ipd.discharge',
            // Ward, room and bed setup carries the DAY RATE, so it is separated
            // from day-to-day admitting for the same reason doctors.manage is
            // separated from appointments.manage.
            'wards.manage',
            'operations.view', 'operations.manage',
        ],
        'lab' => [
            'lab.view', 'lab.collect', 'lab.result',
        ],
        'accounts' => [
            'billing.view', 'billing.charge', 'accounts.view', 'accounts.manage',
        ],
        'hr' => [
            // Staff records, work patterns, rosters, holidays, leave types.
            'hr.view', 'hr.manage',
            // The attendance half is split from the records half on purpose: a
            // duty manager who fixes rosters and reads the floor's attendance
            // has no business seeing anybody's salary, and the person who
            // approves a correction is not automatically the person who
            // approves the leave that caused it.
            'hr.attendance.view', 'hr.attendance.correct', 'hr.attendance.approve',
            'hr.leave.approve',
            // Reads the payroll handoff (payable days, overtime, indicative
            // gross). Nothing here files anything with anybody.
            'hr.payroll.view',
        ],
    ];

    /**
     * Presentation metadata. Labels/descriptions are lang KEYS, never literal
     * English — the panel ships en / rur / ur from day one.
     */
    public const MODULE_META = [
        'opd'      => ['icon' => '🩺', 'colour' => 'sky'],
        'pharmacy' => ['icon' => '💊', 'colour' => 'emerald'],
        'ipd'      => ['icon' => '🛏️', 'colour' => 'indigo'],
        'lab'      => ['icon' => '🔬', 'colour' => 'violet'],
        'accounts' => ['icon' => '🧾', 'colour' => 'amber'],
        'hr'       => ['icon' => '👥', 'colour' => 'rose'],
    ];

    /**
     * What a brand-new organisation of each type starts with. A single-doctor
     * clinic should not have to switch six modules OFF before it can work.
     */
    public const ORG_TYPE_DEFAULTS = [
        'clinic'   => ['opd', 'accounts'],
        'hospital' => ['opd', 'pharmacy', 'ipd', 'lab', 'accounts', 'hr'],
        'lab'      => ['lab', 'accounts'],
        'pharmacy' => ['pharmacy', 'accounts'],
    ];

    /** Modules a package with no explicit list is assumed to sell. */
    public const PLAN_FALLBACK_MODULES = ['opd', 'accounts'];

    /** Per-request memo: company_id => enabled module keys. */
    protected static array $enabledCache = [];

    /** Per-request memo: company_id => modules the plan permits. */
    protected static array $planCache = [];

    public static function moduleLabelKey(string $module): string
    {
        return 'health.module_' . $module;
    }

    public static function moduleDescriptionKey(string $module): string
    {
        return 'health.module_' . $module . '_desc';
    }

    /** Drop unknown keys, de-duplicate, keep MODULES order. */
    public static function normalize($modules): array
    {
        // Decode more than once on purpose: a row written before the cast was
        // respected holds JSON inside JSON, and a single pass would hand back
        // a string and quietly read as "no modules at all".
        $passes = 0;
        while (is_string($modules) && $passes++ < 3) {
            $decoded = json_decode($modules, true);
            if (!is_string($decoded) && !is_array($decoded)) {
                $modules = [];
                break;
            }
            $modules = $decoded;
        }
        if (!is_array($modules)) {
            return [];
        }

        return array_values(array_filter(
            self::MODULES,
            fn (string $key) => in_array($key, $modules, true)
        ));
    }

    /** Defaults for an organisation type (unknown type → clinic). */
    public static function defaultsForOrgType(?string $orgType): array
    {
        $orgType = HealthPanel::normalizeOrgType($orgType);

        return self::ORG_TYPE_DEFAULTS[$orgType] ?? self::ORG_TYPE_DEFAULTS['clinic'];
    }

    /**
     * The modules the company's package sells.
     *
     * Internal accounts and companies on an active trial see everything — the
     * same "evaluate before buying" rule the POS plan gates use. A company with
     * no active subscription at all falls back to the small-clinic set rather
     * than to nothing, so a lapsed renewal never locks an organisation out of
     * its own patient desk.
     */
    public static function planModules(?Company $company): array
    {
        if (!$company) {
            return self::PLAN_FALLBACK_MODULES;
        }

        $key = (int) $company->id;
        if (array_key_exists($key, self::$planCache)) {
            return self::$planCache[$key];
        }

        $modules = self::resolvePlanModules($company);

        return self::$planCache[$key] = $modules;
    }

    protected static function resolvePlanModules(Company $company): array
    {
        try {
            if ((bool) ($company->is_internal_account ?? false)) {
                return self::MODULES;
            }

            $subscription = $company->relationLoaded('activeSubscription')
                ? $company->getRelation('activeSubscription')
                // `active`, not `is_active` — this table has no is_active column.
                : \App\Models\Subscription::where('company_id', $company->id)
                    ->where('active', true)
                    ->with('pricingPlan')
                    ->latest('id')
                    ->first();

            if (!$subscription) {
                return self::PLAN_FALLBACK_MODULES;
            }

            $plan = $subscription->relationLoaded('pricingPlan')
                ? $subscription->getRelation('pricingPlan')
                : \App\Models\PricingPlan::find($subscription->pricing_plan_id);

            if (!$plan) {
                return self::PLAN_FALLBACK_MODULES;
            }

            // A trial evaluates the whole product.
            if ((bool) ($plan->is_trial ?? false)) {
                return self::MODULES;
            }

            if (!Schema::hasColumn('pricing_plans', 'health_modules')) {
                return self::PLAN_FALLBACK_MODULES;
            }

            $listed = self::normalize($plan->health_modules ?? null);

            return $listed ?: self::PLAN_FALLBACK_MODULES;
        } catch (\Throwable $e) {
            // Never let plan resolution take the panel down.
            return self::PLAN_FALLBACK_MODULES;
        }
    }

    /** Does the package sell this module, regardless of the owner's switch? */
    public static function planAllows(?Company $company, string $module): bool
    {
        return in_array($module, self::planModules($company), true);
    }

    /**
     * The owner's OWN stored set (unmasked). NULL column → org-type defaults,
     * so a company created before this feature still has a sane panel.
     */
    public static function companyModules(?Company $company): array
    {
        if (!$company) {
            return [];
        }

        try {
            if (!Schema::hasColumn('companies', 'health_modules')) {
                return self::defaultsForOrgType($company->health_org_type ?? null);
            }
        } catch (\Throwable $e) {
            return self::defaultsForOrgType($company->health_org_type ?? null);
        }

        $stored = $company->getAttributeValue('health_modules');
        if ($stored === null || $stored === '') {
            return self::defaultsForOrgType($company->health_org_type ?? null);
        }

        return self::normalize($stored);
    }

    /**
     * The modules actually reachable right now: owner's set ∩ plan's set.
     * This is what navigation, route gating and capability resolution read.
     */
    public static function enabled(?Company $company): array
    {
        if (!$company) {
            return [];
        }

        $key = (int) $company->id;
        if (array_key_exists($key, self::$enabledCache)) {
            return self::$enabledCache[$key];
        }

        $enabled = array_values(array_intersect(
            self::companyModules($company),
            self::planModules($company)
        ));

        return self::$enabledCache[$key] = $enabled;
    }

    public static function isEnabled(?Company $company, string $module): bool
    {
        return in_array($module, self::enabled($company), true);
    }

    /**
     * Persist the owner's choice. Unknown keys are dropped and modules the
     * package does not sell are refused — the switch must never claim to have
     * turned on something the organisation cannot use.
     *
     * @return array the modules actually stored
     */
    public static function setForCompany(Company $company, array $modules): array
    {
        $wanted = array_values(array_intersect(
            self::normalize($modules),
            self::planModules($company)
        ));

        if (Schema::hasColumn('companies', 'health_modules')) {
            // The column is cast to 'array' on the model — assigning a JSON
            // STRING here would store JSON inside JSON and every later read
            // would come back as one meaningless string.
            $company->health_modules = $wanted;
            if (Schema::hasColumn('companies', 'health_setup_completed')) {
                $company->health_setup_completed = true;
            }
            $company->save();
        }

        self::forget($company->id);

        return $wanted;
    }

    /**
     * Capabilities reachable for this company: core plus every enabled
     * module's own list. A capability outside this set is denied to everyone.
     */
    public static function availableCapabilities(?Company $company): array
    {
        $capabilities = self::CORE_CAPABILITIES;

        foreach (self::enabled($company) as $module) {
            $capabilities = array_merge($capabilities, self::MODULE_CAPABILITIES[$module] ?? []);
        }

        return array_values(array_unique($capabilities));
    }

    /** Which module owns a capability (null = core). */
    public static function moduleForCapability(string $capability): ?string
    {
        foreach (self::MODULE_CAPABILITIES as $module => $capabilities) {
            if (in_array($capability, $capabilities, true)) {
                return $module;
            }
        }

        return null;
    }

    /** Every capability the product knows about (core + all modules). */
    public static function allCapabilities(): array
    {
        $capabilities = self::CORE_CAPABILITIES;
        foreach (self::MODULE_CAPABILITIES as $list) {
            $capabilities = array_merge($capabilities, $list);
        }

        return array_values(array_unique($capabilities));
    }

    public static function forget(?int $companyId = null): void
    {
        if ($companyId === null) {
            self::$enabledCache = [];
            self::$planCache = [];

            return;
        }

        unset(self::$enabledCache[$companyId], self::$planCache[$companyId]);
    }
}

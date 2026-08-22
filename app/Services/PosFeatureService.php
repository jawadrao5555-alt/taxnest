<?php

namespace App\Services;

use App\Models\Company;

class PosFeatureService
{
    public const ALL_FLAGS = [
        'kot', 'tables', 'kitchen', 'kitchen_notes', 'recipes',
        'inventory', 'delivery', 'barcode', 'prescription',
        'service_jobs', 'customer_profile', 'bulk_pricing',
        'multi_branch', 'customer_loyalty',
    ];

    /**
     * Plan-gated Restaurant & Kitchen flags (Jul 2026): available on plans
     * with pricing_plans.restaurant_enabled — Business and above since
     * Aug 2026 (business_gains_kitchen_analytics migration) — active
     * admin overrides, or internal accounts. Masked OFF at runtime for
     * everyone else — stored feature_flags stay untouched so a later plan
     * upgrade restores the shop's previous kitchen configuration.
     */
    public const RESTAURANT_FLAGS = ['kot', 'tables', 'kitchen', 'kitchen_notes', 'recipes'];

    /** Per-request cache: company_id => bool */
    protected static array $restaurantAllowedCache = [];

    /** company_id => [plan_column => bool] cache for plan feature gates. */
    protected static array $planGateCache = [];

    /**
     * Plan-gated premium features (Aug 2026 package matrix). Each key is a
     * boolean column on pricing_plans. Access logic mirrors the Restaurant
     * module: internal accounts and active admin overrides always pass, an
     * active trial passes (evaluate-before-buying), otherwise the active
     * plan's column decides.
     */
    public const PLAN_GATES = ['deals_enabled', 'riders_enabled', 'hazri_enabled', 'analytics_enabled', 'reports_enabled', 'rider_tracking_enabled', 'custom_access_enabled', 'qr_menu_enabled', 'offline_enabled', 'excel_enabled', 'khata_enabled', 'loyalty_enabled', 'kot_enabled', 'caller_id_enabled', 'whatsapp_enabled'];

    public const FLAG_META = [
        'kot' => [
            'label' => 'KOT (Kitchen Order Tickets)',
            'description' => 'Send orders to kitchen printer/screen instantly. Track order status from POS.',
            'icon' => '🧾',
            'category' => 'restaurant',
        ],
        'kitchen' => [
            'label' => 'Kitchen Display Screen (KDS)',
            'description' => 'Live kitchen screen showing pending orders, prep status, ready-for-pickup queue.',
            'icon' => '📺',
            'category' => 'restaurant',
        ],
        'kitchen_notes' => [
            'label' => 'Kitchen Notes',
            'description' => 'Cashier can add per-item notes (e.g. "no onions", "extra spicy") visible to kitchen.',
            'icon' => '📝',
            'category' => 'restaurant',
        ],
        'tables' => [
            'label' => 'Table Management',
            'description' => 'Floor map with tables, seat counts, table assignments, dine-in order tracking.',
            'icon' => '🍽️',
            'category' => 'restaurant',
        ],
        'recipes' => [
            'label' => 'Recipes / BOM',
            'description' => 'Define ingredients per dish. Auto-deduct stock on sale. Real-time cost-of-sale.',
            'icon' => '👨‍🍳',
            'category' => 'restaurant',
        ],
        'inventory' => [
            'label' => 'Inventory Tracking',
            'description' => 'Stock counts, low-stock alerts, OUT badges, block sale of out-of-stock items.',
            'icon' => '📦',
            'category' => 'inventory',
        ],
        'barcode' => [
            'label' => 'Barcode Scanning',
            'description' => 'Scan product barcodes from any USB/Bluetooth scanner — instant cart add.',
            'icon' => '📊',
            'category' => 'inventory',
        ],
        'bulk_pricing' => [
            'label' => 'Bulk / Tier Pricing',
            'description' => 'Quantity-based price tiers (e.g. 1-9 = Rs 100, 10+ = Rs 90). Wholesale-friendly.',
            'icon' => '💼',
            'category' => 'inventory',
        ],
        'multi_branch' => [
            'label' => 'Multi-Branch / Outlets',
            'description' => 'Manage multiple physical stores from one account. Per-branch reports.',
            'icon' => '🏢',
            'category' => 'inventory',
        ],
        'delivery' => [
            'label' => 'Delivery / Takeaway',
            'description' => 'Capture delivery address, rider assignment, delivery charges as line item.',
            'icon' => '🛵',
            'category' => 'sales',
        ],
        'customer_profile' => [
            'label' => 'Customer Profiles',
            'description' => 'Save customer name, phone, address. Search past orders. Required for loyalty/delivery.',
            'icon' => '👤',
            'category' => 'customer',
        ],
        'customer_loyalty' => [
            'label' => 'Loyalty Points / Rewards',
            'description' => 'Earn points per purchase. Redeem on future orders. Configurable rules.',
            'icon' => '⭐',
            'category' => 'customer',
        ],
        'prescription' => [
            'label' => 'Prescription (Pharmacy)',
            'description' => 'Capture doctor name, prescription image, drug schedule for pharmacy compliance.',
            'icon' => '💊',
            'category' => 'specialty',
        ],
        'service_jobs' => [
            'label' => 'Service Jobs (Salon/Workshop)',
            'description' => 'Book appointments, track service duration, assign staff per service.',
            'icon' => '💇',
            'category' => 'specialty',
        ],
    ];

    public const CATEGORY_META = [
        'restaurant' => [
            'label' => 'Restaurant & Kitchen',
            'description' => 'Table service, kitchen workflow, recipe-based stock',
            'icon' => '🍽️',
            'color' => 'orange',
        ],
        'inventory' => [
            'label' => 'Inventory & Stock',
            'description' => 'Track stock, scan barcodes, manage outlets',
            'icon' => '📦',
            'color' => 'blue',
        ],
        'sales' => [
            'label' => 'Sales Tools',
            'description' => 'Delivery, takeaway, channel-based features',
            'icon' => '💰',
            'color' => 'emerald',
        ],
        'customer' => [
            'label' => 'Customers & CRM',
            'description' => 'Customer profiles, loyalty, retention',
            'icon' => '👥',
            'color' => 'purple',
        ],
        'specialty' => [
            'label' => 'Industry Specialty',
            'description' => 'Pharmacy compliance, salon bookings, service jobs',
            'icon' => '🎯',
            'color' => 'pink',
        ],
    ];

    public const CATEGORY_DEFAULTS = [
        'restaurant' => [
            'kot' => true, 'tables' => true, 'kitchen' => true,
            'kitchen_notes' => true, 'recipes' => true, 'inventory' => true,
            'delivery' => true, 'customer_profile' => true,
        ],
        'cafe' => [
            'kot' => true, 'kitchen' => true, 'kitchen_notes' => true,
            'recipes' => true, 'inventory' => true,
            'customer_profile' => true, 'customer_loyalty' => true,
        ],
        'quick_service' => [
            'kot' => true, 'kitchen' => true, 'recipes' => true,
            'inventory' => true, 'delivery' => true,
            'customer_profile' => true,
        ],
        'retail' => [
            'barcode' => true, 'inventory' => true, 'customer_profile' => true,
        ],
        'pharmacy' => [
            'barcode' => true, 'inventory' => true,
            'prescription' => true, 'delivery' => true, 'customer_profile' => true,
        ],
        'salon' => [
            'tables' => true, 'service_jobs' => true,
            'customer_profile' => true, 'customer_loyalty' => true,
        ],
        'grocery' => [
            'barcode' => true, 'inventory' => true, 'delivery' => true,
        ],
        'wholesale' => [
            'inventory' => true, 'bulk_pricing' => true,
            'customer_profile' => true, 'multi_branch' => true,
        ],
        'hybrid_cafe_retail' => [
            'barcode' => true, 'inventory' => true,
            'kot' => true, 'kitchen' => true,
            'customer_profile' => true, 'customer_loyalty' => true,
        ],
    ];

    public const PRESET_META = [
        'restaurant' => [
            'label' => 'Restaurant Dine-in',
            'description' => 'Table service, KOT, KDS, recipes, dine-in & delivery',
            'icon' => '🍽️',
            'badge' => 'Most Popular',
            'color' => 'orange',
        ],
        'cafe' => [
            'label' => 'Cafe / Coffee Shop',
            'description' => 'KOT, recipes, customer loyalty — no tables required',
            'icon' => '☕',
            'badge' => null,
            'color' => 'amber',
        ],
        'quick_service' => [
            'label' => 'Quick Service / Dhaba',
            'description' => 'Fast counter service, KOT, delivery — minimal overhead',
            'icon' => '🥡',
            'badge' => null,
            'color' => 'red',
        ],
        'retail' => [
            'label' => 'Retail Store',
            'description' => 'Barcode scanning, inventory, customer database',
            'icon' => '🛒',
            'badge' => 'Most Popular',
            'color' => 'blue',
        ],
        'pharmacy' => [
            'label' => 'Pharmacy / Medical',
            'description' => 'Prescription tracking, batch/expiry, compliance-ready',
            'icon' => '💊',
            'badge' => null,
            'color' => 'green',
        ],
        'salon' => [
            'label' => 'Salon / Spa',
            'description' => 'Service jobs, staff bookings, loyalty rewards',
            'icon' => '💇',
            'badge' => null,
            'color' => 'pink',
        ],
        'grocery' => [
            'label' => 'Grocery / Mart',
            'description' => 'Barcode-heavy, inventory + delivery support',
            'icon' => '🥬',
            'badge' => null,
            'color' => 'lime',
        ],
        'wholesale' => [
            'label' => 'Wholesale / Distributor',
            'description' => 'Bulk pricing tiers, multi-branch, B2B customers',
            'icon' => '📦',
            'badge' => null,
            'color' => 'indigo',
        ],
        'hybrid_cafe_retail' => [
            'label' => 'Hybrid (Cafe + Retail)',
            'description' => 'Coffee shop with retail counter — best of both',
            'icon' => '🌟',
            'badge' => 'New',
            'color' => 'purple',
        ],
    ];

    public const DEPENDENCIES = [
        'kot' => ['kitchen'],
        'recipes' => ['inventory'],
        'delivery' => ['customer_profile'],
        'prescription' => ['customer_profile'],
        'customer_loyalty' => ['customer_profile'],
    ];

    public static function forCompany(?Company $company): object
    {
        if (!$company) {
            return self::flagsToObject(self::baseDefaults());
        }

        // Business-category modes are retired: every feature is an individual
        // per-company toggle. Existing companies were snapshot-migrated so
        // feature_flags holds their full resolved set (see
        // 2026_07_03_180000_snapshot_pos_feature_flags migration).
        $overrides = is_array($company->feature_flags) ? $company->feature_flags : [];
        $flags = array_merge(self::baseDefaults(), $overrides);

        // Plan gating: mask restaurant flags OFF when the company's plan
        // doesn't include the Restaurant module. Masking happens BEFORE
        // dependency resolution so children of masked parents drop too.
        if (!self::restaurantAllowed($company)) {
            foreach (self::RESTAURANT_FLAGS as $flag) {
                $flags[$flag] = false;
            }
        }

        return self::flagsToObject(self::resolve($flags));
    }

    /**
     * Raw stored value of ONE feature flag — no restaurant masking, no
     * dependency resolution.
     *
     * forCompany() masks every RESTAURANT_FLAG (kot/tables/kitchen/
     * kitchen_notes/recipes) OFF unless the plan carries restaurant_enabled.
     * FBR POS plans never do, yet the FBR panel legitimately owns a few of
     * those switches under its own gates (Store Slip rides
     * kitchen_printer_enabled + plan kot_enabled; per-item Store notes ride
     * this raw flag + the same plan gate). Reading the raw flag keeps the FBR
     * side independent of the PRA restaurant module instead of forcing
     * restaurant_enabled onto FBR plans.
     *
     * PRA call sites must keep using forCompany() — masking is deliberate there.
     */
    /**
     * FBR Store Slip — the SHOP's own master switch (companies.kitchen_printer_enabled).
     *
     * These three live here rather than on FbrPosController because the silent
     * Desktop Agent asks the same questions with NO logged-in user: it fetches a
     * job by agent key, so an auth-based gate would silently pass. A slip queued
     * while the feature was on must not print after the owner switches it off,
     * so the agent re-asks at RENDER time, not at queue time.
     *
     * Missing column = PROD schema drift; fail OPEN so slips keep printing.
     */
    public static function fbrSlipSwitchOn(?Company $company): bool
    {
        if (!\Illuminate\Support\Facades\Schema::hasColumn('companies', 'kitchen_printer_enabled')) {
            return true;
        }
        return (bool) ($company?->kitchen_printer_enabled ?? false);
    }

    /** Store Slip allowed right now: the package AND the shop's own switch. */
    public static function fbrStoreSlipOn(?Company $company): bool
    {
        return self::planAllows($company, 'kot_enabled') && self::fbrSlipSwitchOn($company);
    }

    /**
     * Per-item Store note allowed right now. The note rides ON the slip, so the
     * slip must be on too. Read RAW: kitchen_notes is a RESTAURANT_FLAG and every
     * fbrpos plan ships restaurant_enabled=0, so forCompany() would mask it off
     * forever.
     */
    public static function fbrStoreNotesOn(?Company $company): bool
    {
        return self::fbrStoreSlipOn($company) && self::rawFlag($company, 'kitchen_notes');
    }

    /**
     * Caller ID popup live on the sale screen RIGHT NOW.
     *
     * Two things must both be true: the shop's own switch (companies.caller_id_enabled)
     * AND the plan/add-on gate. The APIs already refuse a plan-locked shop
     * (PosCallerIdController::planLocked), but the sale screens used to bake the
     * raw column — so a downgraded shop still saw a dead call-back button and a
     * popup poller that could only ever get 403s. One resolver, used by both
     * universal screens and by their boot fingerprints.
     */
    public static function callerIdLive(?Company $company): bool
    {
        return (bool) ($company?->caller_id_enabled ?? false)
            && self::planAllows($company, 'caller_id_enabled');
    }

    public static function rawFlag(?Company $company, string $flag): bool
    {
        if (!$company) {
            return false;
        }
        $flags = is_array($company->feature_flags) ? $company->feature_flags : [];
        return (bool) ($flags[$flag] ?? (self::baseDefaults()[$flag] ?? false));
    }

    /**
     * Does this company's plan include the Restaurant & Kitchen module?
     *  - Internal accounts: always yes.
     *  - Active admin override (lifetime / temporary): yes.
     *  - Otherwise: the active plan's restaurant_enabled column decides.
     */
    public static function restaurantAllowed(?Company $company): bool
    {
        if (!$company) {
            return false;
        }
        if (array_key_exists($company->id, self::$restaurantAllowedCache)) {
            return self::$restaurantAllowedCache[$company->id];
        }

        return self::$restaurantAllowedCache[$company->id]
            = self::restaurantAccessSource($company) !== null;
    }

    /**
     * WHY the company has (or doesn't have) the Restaurant module.
     * Returns 'internal' | 'override' | 'plan' | 'trial' | null (no access).
     * 'trial' means access exists ONLY because of an active trial — it will
     * disappear the moment the trial expires (mask returns automatically).
     */
    public static function restaurantAccessSource(?Company $company): ?string
    {
        if (!$company) {
            return null;
        }
        if ($company->is_internal_account) {
            return 'internal';
        }
        $sub = \App\Services\PlanLimitService::getActiveSubscription($company->id);
        if ($sub) {
            // Same rule as planAllows(): a grant that sits on a real package
            // gives that package's Restaurant answer, nothing more.
            if ($sub->hasActiveOverride() && self::overrideGrantsEverything($sub)) {
                return 'override';
            }
            if ($sub->pricingPlan && $sub->pricingPlan->restaurant_enabled) {
                return $sub->hasActiveOverride() ? 'override' : 'plan';
            }
            if ($sub->hasActiveOverride()) {
                return null;
            }
            if ($sub->isTrialActive()) {
                // Owner decision (Jul 2026): active-trial companies get the
                // Restaurant module so they can evaluate it before buying.
                // When the trial expires the mask returns automatically —
                // stored feature_flags are never touched.
                return 'trial';
            }
        }
        return null;
    }

    /**
     * Does this access grant open EVERYTHING, or only its package?
     *
     * A grant is package-scoped as soon as it sits on a real paid package —
     * the admin picks one while granting temporary access, or the company
     * already had one. With only a Trial row or no plan at all there is no
     * package to read, so the grant stays blanket (the historical behaviour
     * every existing partner / internal grant relies on).
     */
    private static function overrideGrantsEverything(\App\Models\Subscription $sub): bool
    {
        $plan = $sub->pricingPlan;

        return !$plan || (bool) $plan->is_trial;
    }

    /** Clear per-request gate caches (tests / admin plan flips mid-request). */
    public static function flushGateCaches(): void
    {
        self::$planGateCache = [];
        self::$restaurantAllowedCache = [];
        \App\Services\PosAddonService::flushCache();
    }

    /**
     * Does this company's plan include the given premium feature column?
     * Same source hierarchy as the Restaurant module: internal → override →
     * plan column → active trial → PAID ADD-ON. Missing column (pre-migration
     * PROD window) fails OPEN so a lagging migrate never locks paying users out.
     *
     * The paid add-on grant (Aug 2026) is resolved HERE, inside the one gate the
     * whole app already calls, so a bought feature can never need a second —
     * and therefore bypassable — entitlement check at the call site.
     */
    public static function planAllows(?Company $company, string $planColumn): bool
    {
        if (!in_array($planColumn, self::PLAN_GATES, true)) {
            return true;
        }
        if (!$company) {
            return false;
        }
        if (isset(self::$planGateCache[$company->id][$planColumn])) {
            return self::$planGateCache[$company->id][$planColumn];
        }

        $allowed = false;
        if ($company->is_internal_account) {
            $allowed = true;
        } else {
            try {
                $sub = \App\Services\PlanLimitService::getActiveSubscription($company->id);
                if ($sub) {
                    if ($sub->hasActiveOverride()) {
                        // An override waives the PAYMENT, not the package.
                        // Owner rule (Aug 2026): the shop gets exactly the
                        // package the grant sits on — the one the admin picked
                        // while granting, or the one the company already had.
                        // A grant with no real package behind it (Trial row or
                        // no plan at all) keeps the old blanket access: there
                        // is nothing to read, and legacy partner/internal
                        // grants must never lose access overnight.
                        $allowed = self::overrideGrantsEverything($sub)
                            || !\Illuminate\Support\Facades\Schema::hasColumn('pricing_plans', $planColumn)
                            || !empty($sub->pricingPlan->{$planColumn});
                    } elseif ($sub->pricingPlan) {
                        if (!\Illuminate\Support\Facades\Schema::hasColumn('pricing_plans', $planColumn)) {
                            $allowed = true; // fail open until migration lands
                        } elseif (!empty($sub->pricingPlan->{$planColumn})) {
                            $allowed = true;
                        }
                    }
                    if (!$allowed && $sub->isTrialActive()) {
                        $allowed = true; // trial companies evaluate everything
                    }
                }
            } catch (\Illuminate\Database\QueryException $e) {
                // Fail OPEN only for SCHEMA-LAG faults (missing table/column:
                // mid-migration PROD window, minimal test schemas) — same
                // convention as the missing-column branch above. Any other DB
                // error must propagate, or a real production fault would
                // silently bypass every PRA + FBR premium gate.
                $msg = $e->getMessage();
                $schemaLag = str_contains($msg, 'no such table')
                    || str_contains($msg, 'no such column')
                    || str_contains($msg, 'Base table or view not found')
                    || str_contains($msg, 'Unknown column');
                if (!$schemaLag) {
                    throw $e;
                }
                \Illuminate\Support\Facades\Log::warning('planAllows fail-open (schema lag)', [
                    'company_id' => $company->id,
                    'column' => $planColumn,
                    'error' => $msg,
                ]);
                $allowed = true;
            }
        }

        // Paid add-on: a Business+ PRA shop can buy a catalogue feature
        // instead of upgrading its package. Checked last — it only ever ADDS
        // access, never removes what the package already grants.
        if (!$allowed) {
            $allowed = self::addonAllows($company, $planColumn);
        }

        return self::$planGateCache[$company->id][$planColumn] = $allowed;
    }

    /**
     * Verified, unexpired paid add-on for this gate?
     *
     * Deliberately fails CLOSED (unlike the plan-column branch above): a
     * missing pos_addons table means nobody has bought anything yet, so the
     * package answer already stands. Never let an add-on lookup fault break a
     * gate that the plan itself was about to decide.
     */
    protected static function addonAllows(?Company $company, string $planColumn): bool
    {
        try {
            return \App\Services\PosAddonService::allowsGate($company, $planColumn);
        } catch (\Throwable $e) {
            \Illuminate\Support\Facades\Log::warning('POS add-on gate lookup failed', [
                'company_id' => $company?->id,
                'column' => $planColumn,
                'error' => $e->getMessage(),
            ]);
            return false;
        }
    }

    /**
     * Did this company's Restaurant access lapse because its trial ended?
     * True only when: no current access AND the subscription's trial has
     * expired AND the company had restaurant flags stored (i.e. they actually
     * used/enabled kitchen features during the trial).
     */
    public static function restaurantLostToTrialExpiry(?Company $company): bool
    {
        if (!$company || self::restaurantAllowed($company)) {
            return false;
        }
        // CheckTrialExpiryJob deactivates expired-trial subscriptions, so the
        // active-only lookup misses them — fall back to the company's most
        // recent subscription when no active one exists.
        $sub = \App\Services\PlanLimitService::getActiveSubscription($company->id)
            ?? \App\Models\Subscription::where('company_id', $company->id)
                ->orderByDesc('id')
                ->first();
        if (!$sub || !$sub->isTrialExpired()) {
            return false;
        }
        $stored = is_array($company->feature_flags) ? $company->feature_flags : [];
        foreach (self::RESTAURANT_FLAGS as $flag) {
            if (!empty($stored[$flag])) {
                return true;
            }
        }
        return false;
    }

    public static function defaultsForCategory(string $category): array
    {
        $defaults = self::CATEGORY_DEFAULTS[$category] ?? [];
        return array_merge(self::baseDefaults(), $defaults);
    }

    public static function categories(): array
    {
        return array_keys(self::CATEGORY_DEFAULTS);
    }

    public static function flagMeta(string $flag): array
    {
        return self::FLAG_META[$flag] ?? [
            'label' => str_replace('_', ' ', ucwords($flag, '_')),
            'description' => '',
            'icon' => '⚙️',
            'category' => 'sales',
        ];
    }

    public static function presetMeta(string $preset): array
    {
        return self::PRESET_META[$preset] ?? [
            'label' => ucwords(str_replace('_', ' ', $preset)),
            'description' => '',
            'icon' => '🏪',
            'badge' => null,
            'color' => 'gray',
        ];
    }

    public static function categoryMeta(string $category): array
    {
        return self::CATEGORY_META[$category] ?? [
            'label' => ucfirst($category),
            'description' => '',
            'icon' => '📂',
            'color' => 'gray',
        ];
    }

    /**
     * Returns flags grouped by their category.
     * [
     *   'restaurant' => ['kot', 'kitchen', ...],
     *   'inventory'  => ['barcode', 'inventory', ...],
     *   ...
     * ]
     */
    public static function flagsByCategory(): array
    {
        $grouped = [];
        foreach (self::ALL_FLAGS as $flag) {
            $cat = self::flagMeta($flag)['category'];
            $grouped[$cat] ??= [];
            $grouped[$cat][] = $flag;
        }
        // Preserve category order from CATEGORY_META
        $ordered = [];
        foreach (array_keys(self::CATEGORY_META) as $cat) {
            if (!empty($grouped[$cat])) {
                $ordered[$cat] = $grouped[$cat];
            }
        }
        return $ordered;
    }

    /**
     * Dependency labels for UI tooltips: ['kot' => ['kitchen'], 'recipes' => ['inventory'], ...]
     */
    public static function dependencies(): array
    {
        return self::DEPENDENCIES;
    }

    /**
     * Public dependency normalizer. Applies DEPENDENCIES so a child flag can
     * never persist ON while a required parent is OFF. Mirrors the wizard's
     * client-side resolveDeps() so a malformed / JS-disabled POST still stores
     * canonical, self-consistent flags.
     */
    public static function normalize(array $flags): array
    {
        return self::resolve($flags);
    }

    protected static function baseDefaults(): array
    {
        return array_fill_keys(self::ALL_FLAGS, false);
    }

    protected static function resolve(array $flags): array
    {
        foreach (self::DEPENDENCIES as $child => $parents) {
            if (!empty($flags[$child])) {
                foreach ($parents as $parent) {
                    if (empty($flags[$parent])) {
                        $flags[$child] = false;
                    }
                }
            }
        }
        return $flags;
    }

    protected static function flagsToObject(array $flags): object
    {
        $obj = new \stdClass();
        foreach (self::ALL_FLAGS as $key) {
            $obj->{$key} = (bool) ($flags[$key] ?? false);
        }
        $obj->_all = $flags;
        return $obj;
    }
}

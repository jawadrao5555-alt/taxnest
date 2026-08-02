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
     * Plan-gated Restaurant & Kitchen flags (Jul 2026): available only on
     * Pro / Unlimited POS plans (pricing_plans.restaurant_enabled), active
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
    public const PLAN_GATES = ['deals_enabled', 'riders_enabled', 'hazri_enabled', 'analytics_enabled', 'reports_enabled', 'rider_tracking_enabled', 'custom_access_enabled', 'qr_menu_enabled', 'offline_enabled'];

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
            if ($sub->hasActiveOverride()) {
                return 'override';
            }
            if ($sub->pricingPlan && $sub->pricingPlan->restaurant_enabled) {
                return 'plan';
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

    /** Clear per-request gate caches (tests / admin plan flips mid-request). */
    public static function flushGateCaches(): void
    {
        self::$planGateCache = [];
        self::$restaurantAllowedCache = [];
    }

    /**
     * Does this company's plan include the given premium feature column?
     * Same source hierarchy as the Restaurant module: internal → override →
     * plan column → active trial. Missing column (pre-migration PROD window)
     * fails OPEN so a lagging migrate never locks paying users out.
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
            $sub = \App\Services\PlanLimitService::getActiveSubscription($company->id);
            if ($sub) {
                if ($sub->hasActiveOverride()) {
                    $allowed = true;
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
        }

        return self::$planGateCache[$company->id][$planColumn] = $allowed;
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

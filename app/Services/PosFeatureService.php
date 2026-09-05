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

    /**
     * PRA business categories.
     *
     * PRA (Punjab Revenue Authority) can only tax SERVICES — the Punjab Sales
     * Tax on Services Act 2012 s.2(38) defines a service as "anything which is
     * not goods". Supply of goods is federal (Sales Tax Act 1990) and belongs
     * to the FBR panel, which carries the goods categories.
     *
     * So every category offered here must be a service business. Categories
     * that sell goods (retail, pharmacy, grocery, wholesale, ...) live in
     * LEGACY_CATEGORIES below: still resolvable for shops that were signed up
     * on them before this split, never offered to anybody new.
     */
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
        // No 'tables': a salon books staff and chairs through service jobs, and
        // floor tables would switch the shop into restaurant mode (see
        // restaurantModeFrom()) and hand it a kitchen it does not have.
        'salon' => [
            'service_jobs' => true,
            'customer_profile' => true, 'customer_loyalty' => true,
        ],
        'hotel' => [
            'tables' => true, 'kot' => true, 'kitchen' => true,
            'service_jobs' => true, 'multi_branch' => true,
            'customer_profile' => true, 'customer_loyalty' => true,
        ],
        'marquee' => [
            'tables' => true, 'kot' => true, 'kitchen' => true,
            'service_jobs' => true, 'customer_profile' => true,
        ],
        'catering' => [
            'kitchen' => true, 'recipes' => true, 'inventory' => true,
            'service_jobs' => true, 'delivery' => true,
            'customer_profile' => true,
        ],
        'laundry' => [
            'service_jobs' => true, 'delivery' => true,
            'customer_profile' => true, 'customer_loyalty' => true,
        ],
        'gym' => [
            'service_jobs' => true,
            'customer_profile' => true, 'customer_loyalty' => true,
        ],
        'workshop' => [
            'service_jobs' => true, 'inventory' => true,
            'customer_profile' => true,
        ],
    ];

    /**
     * Goods categories retired from the PRA panel (they belong to FBR).
     *
     * Kept ONLY so shops that were signed up on them keep a real label and a
     * working preset in Customize. categories() never returns these, so no new
     * shop can pick one.
     */
    public const LEGACY_CATEGORIES = [
        // The catch-all. Neither panel OFFERS it, but registration falls back to
        // it when no type was picked, so it needs a preset of its own — without
        // one a plain shop used to resolve to 'restaurant' and get a kitchen it
        // never asked for. Deliberately bare: a shop nobody classified starts
        // simple and switches modules on from Customize.
        'general' => [
            'customer_profile' => true,
        ],
        'retail' => [
            'barcode' => true, 'inventory' => true, 'customer_profile' => true,
        ],
        'pharmacy' => [
            'barcode' => true, 'inventory' => true,
            'prescription' => true, 'delivery' => true, 'customer_profile' => true,
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

        // These five were never Customize presets — they only ever arrived as a
        // registration pos_type. resolveCategory() falls back to pos_type when a
        // pre-split shop has no business_category, so they need a real preset
        // here or such a shop would resolve to nothing.
        'clothing' => [
            'barcode' => true, 'inventory' => true, 'customer_profile' => true,
        ],
        'electronics' => [
            'barcode' => true, 'inventory' => true,
            'customer_profile' => true, 'multi_branch' => true,
        ],
        'hardware' => [
            'barcode' => true, 'inventory' => true, 'bulk_pricing' => true,
        ],
        'autoparts' => [
            'barcode' => true, 'inventory' => true, 'customer_profile' => true,
        ],
        'bakery' => [
            'barcode' => true, 'inventory' => true, 'kitchen' => true,
            'recipes' => true, 'customer_profile' => true,
        ],
    ];

    public const PRESET_META = [
        'general' => [
            'label' => 'General Business',
            'description' => 'Simple billing to start with — switch modules on as you need them.',
            'icon' => '🏪',
            'badge' => null,
            'color' => 'gray',
        ],
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
        'salon' => [
            'label' => 'Salon / Spa',
            'description' => 'Service jobs, staff bookings, loyalty rewards',
            'icon' => '💇',
            'badge' => null,
            'color' => 'pink',
        ],
        'hotel' => [
            'label' => 'Hotel / Guest House',
            'description' => 'Rooms, in-house dining, multi-branch, guest loyalty',
            'icon' => '🏨',
            'badge' => 'New',
            'color' => 'cyan',
        ],
        'marquee' => [
            'label' => 'Marriage Hall / Marquee',
            'description' => 'Event bookings, hall service, kitchen & catering',
            'icon' => '🎪',
            'badge' => 'New',
            'color' => 'rose',
        ],
        'catering' => [
            'label' => 'Caterer / Catering',
            'description' => 'Order-based cooking, recipes, delivery & event jobs',
            'icon' => '🍲',
            'badge' => 'New',
            'color' => 'amber',
        ],
        'laundry' => [
            'label' => 'Laundry / Dry Cleaning',
            'description' => 'Job tickets, pickup & delivery, customer loyalty',
            'icon' => '🧺',
            'badge' => 'New',
            'color' => 'sky',
        ],
        'gym' => [
            'label' => 'Gym / Fitness Club',
            'description' => 'Memberships, service jobs, loyalty rewards',
            'icon' => '🏋️',
            'badge' => 'New',
            'color' => 'emerald',
        ],
        'workshop' => [
            'label' => 'Auto Workshop / Service Station',
            'description' => 'Job cards, parts consumption, customer vehicles',
            'icon' => '🔩',
            'badge' => 'New',
            'color' => 'slate',
        ],

        // ---- Retired goods categories (FBR panel owns these) ----
        // Never offered. Present only so a shop signed up on one before the
        // split still sees a real label instead of a raw slug.
        'retail' => [
            'label' => 'Retail Store',
            'description' => 'Goods retail — belongs to the FBR panel',
            'icon' => '🛒',
            'badge' => null,
            'color' => 'blue',
        ],
        'pharmacy' => [
            'label' => 'Pharmacy / Medical',
            'description' => 'Goods retail — belongs to the FBR panel',
            'icon' => '💊',
            'badge' => null,
            'color' => 'green',
        ],
        'grocery' => [
            'label' => 'Grocery / Mart',
            'description' => 'Goods retail — belongs to the FBR panel',
            'icon' => '🥬',
            'badge' => null,
            'color' => 'lime',
        ],
        'wholesale' => [
            'label' => 'Wholesale / Distributor',
            'description' => 'Goods supply — belongs to the FBR panel',
            'icon' => '📦',
            'badge' => null,
            'color' => 'indigo',
        ],
        'hybrid_cafe_retail' => [
            'label' => 'Hybrid (Cafe + Retail)',
            'description' => 'Part goods retail — the retail half belongs to FBR',
            'icon' => '🌟',
            'badge' => null,
            'color' => 'purple',
        ],
        'clothing' => [
            'label' => 'Clothing / Garments',
            'description' => 'Goods retail — belongs to the FBR panel',
            'icon' => '👕',
            'badge' => null,
            'color' => 'pink',
        ],
        'electronics' => [
            'label' => 'Electronics',
            'description' => 'Goods retail — belongs to the FBR panel',
            'icon' => '🔌',
            'badge' => null,
            'color' => 'cyan',
        ],
        'hardware' => [
            'label' => 'Hardware / Building Material',
            'description' => 'Goods retail — belongs to the FBR panel',
            'icon' => '🔧',
            'badge' => null,
            'color' => 'stone',
        ],
        'autoparts' => [
            'label' => 'Auto Parts',
            'description' => 'Goods retail — belongs to the FBR panel',
            'icon' => '🛞',
            'badge' => null,
            'color' => 'zinc',
        ],
        'bakery' => [
            'label' => 'Bakery',
            'description' => 'Counter sale of goods — belongs to the FBR panel',
            'icon' => '🥐',
            'badge' => null,
            'color' => 'amber',
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

    /** Is this gate sold ONLY as a paid add-on, i.e. in no package at all? */
    private static function isAddonOnlyGate(string $planColumn): bool
    {
        foreach (\App\Services\PosAddonPricingService::ADDONS as $addon) {
            if (($addon['gate'] ?? null) === $planColumn) {
                return true;
            }
        }

        return false;
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
                        // Add-on-only features (Caller ID, WhatsApp Bill, Rider
                        // Tracking) belong to NO PRA package, so package-scoping
                        // them would just switch them off for every granted
                        // shop with no admin way to hand them back — free
                        // access keeps them open, exactly like a trial does.
                        // PRA POS only: the catalogue is PRA's, and other
                        // products' packages own these columns themselves.
                        $allowed = self::overrideGrantsEverything($sub)
                            // (null product_type = a legacy PRA row; FBR/DI
                            // companies always carry their own type)
                            || (($company->product_type ?? 'pos') === 'pos' && self::isAddonOnlyGate($planColumn))
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
        $defaults = self::allCategoryDefaults()[$category] ?? [];
        return array_merge(self::baseDefaults(), $defaults);
    }

    /**
     * Which categories each panel OFFERS.
     *
     * PRA taxes services only (Punjab Sales Tax on Services Act 2012, s.2(38):
     * a service is "anything which is not goods"), so its panel offers service
     * businesses. Supply of goods is federal (Sales Tax Act 1990), so the FBR
     * panel carries the goods businesses — plus restaurant and salon, because
     * FBR POS is used outside Punjab (e.g. Islamabad Capital Territory) where
     * those services are federal cases too.
     */
    public const PANEL_CATEGORIES = [
        'pra' => [
            'restaurant', 'cafe', 'quick_service', 'salon', 'hotel',
            'marquee', 'catering', 'laundry', 'gym', 'workshop',
        ],
        // Goods file with the FBR. 'restaurant' and 'salon' stay on this list on
        // purpose: the FBR panel also serves ICT, where those services are a
        // FEDERAL case (ICT Tax on Services Ordinance 2001), not a PRA one.
        // 'wholesale' is deliberately absent — it is a resolvable legacy preset,
        // not something the FBR signup page has ever offered.
        'fbr' => [
            'retail', 'pharmacy', 'grocery', 'clothing', 'electronics',
            'hardware', 'autoparts', 'bakery',
            'restaurant', 'salon',
        ],
    ];

    /**
     * Categories a shop may CHOOSE on one panel. Defaults to PRA.
     */
    public static function categories(?string $panel = null): array
    {
        return self::PANEL_CATEGORIES[$panel ?? 'pra'] ?? self::PANEL_CATEGORIES['pra'];
    }

    /**
     * Every category we can still RESOLVE, including the retired goods ones
     * that pre-split shops are sitting on.
     */
    public static function allCategoryDefaults(): array
    {
        return self::CATEGORY_DEFAULTS + self::LEGACY_CATEGORIES;
    }

    /** Does this slug resolve to a real preset (any panel)? */
    public static function isKnownCategory(?string $category): bool
    {
        return $category !== null && array_key_exists($category, self::allCategoryDefaults());
    }

    /**
     * Which panel a company belongs to — 'fbr' for the federal goods panel,
     * 'pra' for the Punjab services panel. product_type is the discriminator
     * the auth middleware itself uses (PosAuth vs FbrPosAuth).
     */
    public static function panelFor(?Company $company): string
    {
        return ($company?->product_type === 'fbrpos') ? 'fbr' : 'pra';
    }

    /** The categories a company's own panel offers, plus its own if off-panel. */
    public static function categoriesForCompany(?Company $company): array
    {
        $list    = self::categories(self::panelFor($company));
        $current = self::resolveCategory($company);

        if (!in_array($current, $list, true)) {
            $list[] = $current;
        }
        return $list;
    }

    /**
     * Is this company sitting on a category its own panel does not offer?
     *
     * True for a PRA shop registered on a goods category before the
     * services/goods split. Nothing is switched off for it — the page just
     * tells it where that kind of business belongs.
     */
    public static function isOffPanelCategory(?Company $company): bool
    {
        return !in_array(
            self::resolveCategory($company),
            self::categories(self::panelFor($company)),
            true
        );
    }

    /**
     * Restaurant mode is a FEATURE, not an identity.
     *
     * companies.restaurant_mode used to be welded to "you registered as a
     * restaurant", which left a hotel or a marquee without a kitchen and a
     * restaurant unable to drop one. It now follows the shop's own kitchen
     * switches — exactly the way inventory_enabled follows the inventory flag —
     * so every write path that touches feature_flags must re-derive it.
     */
    public static function restaurantModeFrom(array $flags): bool
    {
        return (bool) ($flags['kitchen'] ?? false)
            || (bool) ($flags['kot'] ?? false)
            || (bool) ($flags['tables'] ?? false);
    }

    public static function isLegacyCategory(?string $category): bool
    {
        return $category !== null && array_key_exists($category, self::LEGACY_CATEGORIES);
    }

    /**
     * The category a company is actually on — the single place that decides it.
     *
     * business_category is the stored answer, but shops registered before the
     * services/goods split only ever had their choice written to pos_type, so a
     * null category falls back to that. Anything we cannot resolve to a real
     * preset lands on restaurant: the PRA panel's default is a service, never a
     * retail shop, because PRA cannot tax goods at all.
     */
    public static function resolveCategory(?Company $company): string
    {
        if (!$company) {
            return 'restaurant';
        }

        $known = self::allCategoryDefaults();

        $stored = $company->business_category;
        if (is_string($stored) && isset($known[$stored])) {
            return $stored;
        }

        $posType = $company->pos_type ?? null;
        if (is_string($posType) && isset($known[$posType])) {
            return $posType;
        }

        return 'restaurant';
    }

    /**
     * What a brand-new company starts on, from the type it picked at signup.
     *
     * A shop used to register and then land on an EMPTY POS, because the
     * category only reached feature_flags if the owner walked the Customize
     * wizard. The choice is made once now, so it has to configure the shop:
     *   - business_category   the stored answer for every preset consumer
     *   - feature_flags       that category's own modules, already on
     *   - inventory_enabled   the master switch mirroring the inventory flag
     *   - restaurant_mode     the master bit mirroring the kitchen flags
     *
     * Every column is hasColumn-guarded: signup must never 500 on a deployment
     * whose migrations have not fully landed (see the PROD schema-drift rule).
     * 'general' is the catch-all for a shop that picked nothing; it stores a
     * bare preset rather than nothing at all, so the shop resolves to itself
     * instead of silently reading as a restaurant.
     */
    public static function registrationAttributes(?string $posType): array
    {
        if (!self::isKnownCategory($posType)) {
            return [];
        }

        $defaults = self::defaultsForCategory($posType);
        $columns  = [
            'business_category' => $posType,
            'feature_flags'     => $defaults,
            'inventory_enabled' => (bool) ($defaults['inventory'] ?? false),
            'restaurant_mode'   => self::restaurantModeFrom($defaults),
        ];

        return array_filter(
            $columns,
            fn ($column) => \Illuminate\Support\Facades\Schema::hasColumn('companies', $column),
            ARRAY_FILTER_USE_KEY
        );
    }

    /**
     * The master COLUMNS that must be rewritten whenever feature_flags are.
     *
     * Two switches have a column of their own beside the flag map, and a write
     * path that touches the map without rewriting them leaves the shop with two
     * contradictory answers (the old inventory dual-switch trap). Anything that
     * persists feature_flags must merge this in.
     *
     * restaurant_mode is derived on the PRA panel ONLY. On the FBR panel the
     * raw 'kitchen' flag means something else entirely (per-item Store notes on
     * the store slip), so deriving there would put an FBR retailer into
     * restaurant mode the moment it switched Store notes on.
     */
    public static function masterSwitches(array $flags, string $panel = 'pra'): array
    {
        $columns = ['inventory_enabled' => (bool) ($flags['inventory'] ?? false)];

        if ($panel === 'pra') {
            $columns['restaurant_mode'] = self::restaurantModeFrom($flags);
        }

        return array_filter(
            $columns,
            fn ($column) => \Illuminate\Support\Facades\Schema::hasColumn('companies', $column),
            ARRAY_FILTER_USE_KEY
        );
    }

    /** Same, resolved from the company's own panel. */
    public static function masterSwitchesFor(?Company $company, array $flags): array
    {
        return self::masterSwitches($flags, self::panelFor($company));
    }

    /**
     * Is this shop sitting on a category that belongs to the OTHER regulator?
     *
     * Only true when the category is genuinely offered by the other panel — a
     * catch-all like 'general' is nobody's, so it must not raise the notice.
     */
    public static function belongsToOtherPanel(?Company $company): bool
    {
        if (!$company) {
            return false;
        }

        $panel   = self::panelFor($company);
        $other   = $panel === 'pra' ? 'fbr' : 'pra';
        $current = self::resolveCategory($company);

        return !in_array($current, self::categories($panel), true)
            && in_array($current, self::categories($other), true);
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

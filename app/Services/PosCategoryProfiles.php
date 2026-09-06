<?php

namespace App\Services;

use App\Models\Company;

/**
 * Category profiles — the ONE place that says what kind of shop a business
 * category is (Task 1582).
 *
 * A category picked at signup used to do exactly one thing: seed the default
 * module flags. From then on the product was category-blind — a laundry saw
 * kitchen tickets, a consultant was offered delivery riders, a pharmacy saw
 * dine-in tables, and every placeholder talked about chicken burgers.
 *
 * Each profile answers four questions for its category:
 *   modules   — which module flags AND plan-gated modules BELONG to this kind
 *               of shop (on top of a common core every shop gets). Anything
 *               outside this set is hidden — not shown as a locked upsell —
 *               unless a SaaS admin granted it or it was grandfathered.
 *   family    — the vocabulary family: food_service / goods_retail / pharmacy
 *               / services (or general = unclassified, nothing hidden). The
 *               family picks the item noun, examples, units and defaults.
 *   audiences — which What's New / tutorial audience families reach it.
 *   defaults  — default unit, sale-screen grid label, default order type,
 *               first-run checklist order, dashboard tile order.
 *
 * Adding a category WITHOUT a profile fails PosCategoryProfilesTest.
 * PosFeatureService is the only reader; call it, not this class, from
 * controllers and views.
 */
class PosCategoryProfiles
{
    public const FAMILIES = ['food_service', 'goods_retail', 'pharmacy', 'services', 'general'];

    /** Audience families an admin can target (general = unclassified, gets all). */
    public const AUDIENCE_FAMILIES = ['all', 'food_service', 'goods_retail', 'pharmacy', 'services'];

    /**
     * Modules EVERY shop gets, whatever it sells: billing, customers,
     * reports, day-close, team, receipts and the switches that ride on them.
     * Plan/add-on gates still apply on top — relevance never grants a plan.
     */
    public const CORE_MODULES = [
        'customer_profile', 'multi_branch',
        'reports_enabled', 'analytics_enabled', 'excel_enabled', 'offline_enabled',
        'custom_access_enabled', 'hazri_enabled', 'whatsapp_enabled',
        'caller_id_enabled', 'khata_enabled',
    ];

    /**
     * Family module sets (added to CORE_MODULES). Per-category rows below may
     * add to these; a category's own signup defaults are ALWAYS included
     * (the test enforces relevant ⊇ defaults, so a preset can never switch
     * on something its own profile hides).
     */
    public const FAMILY_MODULES = [
        'food_service' => [
            'kot', 'tables', 'kitchen', 'kitchen_notes', 'recipes', 'inventory',
            'delivery', 'customer_loyalty',
            'deals_enabled', 'riders_enabled', 'rider_tracking_enabled',
            'qr_menu_enabled', 'loyalty_enabled', 'kot_enabled',
        ],
        'goods_retail' => [
            'barcode', 'inventory', 'bulk_pricing', 'delivery', 'customer_loyalty',
            'deals_enabled', 'riders_enabled', 'rider_tracking_enabled', 'loyalty_enabled',
        ],
        'pharmacy' => [
            'pharmacy', 'prescription', 'batch_expiry', 'loose_sale',
            'barcode', 'inventory', 'delivery', 'customer_loyalty',
            'pharmacy_enabled', 'riders_enabled', 'rider_tracking_enabled', 'loyalty_enabled',
        ],
        'services' => [
            'service_jobs',
        ],
        // Unclassified: nothing is hidden. Resolved at runtime to every module.
        'general' => [],
    ];

    /**
     * Panel-specific additions. On the FBR panel the "kitchen" family of
     * switches is the STORE SLIP (a slip for the store boy, plan column
     * kot_enabled, per-item store note on the raw kitchen_notes flag), which
     * belongs to goods shops there — and never to a PRA goods shop, where the
     * same flags would read as a kitchen.
     */
    public const PANEL_FAMILY_MODULES = [
        'fbr' => [
            'goods_retail' => ['kot_enabled', 'kitchen_notes'],
        ],
    ];

    /**
     * Per-category profile. Keys:
     *   family    required
     *   modules   extra modules on top of the family set (optional)
     *   audiences audience families besides the primary family (optional)
     *   examples  3-4 brand-neutral item names (optional; family fallback)
     *   unit      default unit code (optional; family fallback)
     *   order     default order type on the sale screen (optional)
     */
    public const PROFILES = [
        // ---- Food service (PRA + FBR restaurant) ----
        'restaurant'    => ['family' => 'food_service', 'order' => 'dine_in',
            'examples' => ['Chicken Karahi (Full)', 'Zinger Burger', 'Fries (Large)', 'Cold Drink 500ml']],
        'cafe'          => ['family' => 'food_service', 'order' => 'takeaway',
            'examples' => ['Cappuccino', 'Chocolate Brownie', 'Club Sandwich', 'Iced Latte']],
        'quick_service' => ['family' => 'food_service', 'order' => 'takeaway',
            'examples' => ['Zinger Burger', 'Chicken Roll', 'Fries (Large)', 'Cold Drink 500ml']],
        'hotel'         => ['family' => 'food_service', 'modules' => ['service_jobs'], 'order' => 'dine_in',
            'examples' => ['Room Service Breakfast', 'Chicken Karahi (Full)', 'Mineral Water 1.5L', 'Laundry Service']],
        'marquee'       => ['family' => 'food_service', 'modules' => ['service_jobs'], 'order' => 'dine_in',
            'examples' => ['Per Head Dinner', 'Hall Booking', 'Stage Decoration', 'Cold Drink 500ml']],
        'catering'      => ['family' => 'food_service', 'modules' => ['service_jobs'], 'order' => 'delivery',
            'examples' => ['Chicken Biryani (Per Head)', 'Beef Karahi (Degh)', 'Naan (Dozen)', 'Cold Drink 500ml']],

        // ---- Goods retail (FBR; legacy on PRA) ----
        'retail'        => ['family' => 'goods_retail',
            'examples' => ['Sugar 1kg', 'Cooking Oil 1L', 'Washing Powder 1kg', 'Notebook (Large)']],
        'grocery'       => ['family' => 'goods_retail',
            'examples' => ['Sugar 1kg', 'Cooking Oil 1L', 'Basmati Rice 5kg', 'Washing Powder 1kg']],
        'wholesale'     => ['family' => 'goods_retail',
            'examples' => ['Sugar 50kg Bag', 'Cooking Oil (Carton)', 'Soap (Dozen)', 'Rice 25kg Bag']],
        'clothing'      => ['family' => 'goods_retail',
            'examples' => ['Lawn Suit 3pc', 'Kurta (Medium)', 'Jeans 32', 'Dupatta']],
        'electronics'   => ['family' => 'goods_retail',
            'examples' => ['LED Bulb 12W', 'USB Cable 1m', 'Phone Charger 20W', 'Wireless Earbuds']],
        'hardware'      => ['family' => 'goods_retail',
            'examples' => ['Cement Bag 50kg', 'PVC Pipe 1 inch', 'Paint 4L', 'Screws (100 pcs)']],
        'autoparts'     => ['family' => 'goods_retail',
            'examples' => ['Brake Pads (Front)', 'Engine Oil 4L', 'Air Filter', 'Spark Plug']],
        'bakery'        => ['family' => 'goods_retail', 'audiences' => ['food_service'],
            'modules' => ['kitchen', 'recipes', 'kot', 'kitchen_notes'],
            'examples' => ['Chocolate Cake 2lb', 'Fresh Bread', 'Chicken Patties', 'Cookies 500g']],
        'hybrid_cafe_retail' => ['family' => 'food_service', 'audiences' => ['goods_retail'],
            'modules' => ['barcode', 'bulk_pricing'],
            'examples' => ['Cappuccino', 'Club Sandwich', 'Mineral Water 500ml', 'Cookies 500g']],

        // ---- Pharmacy ----
        'pharmacy'      => ['family' => 'pharmacy',
            'examples' => ['Paracetamol 500mg Tablet', 'Cough Syrup 120ml', 'Vitamin C Tablets', 'Antiseptic Cream 30g']],

        // ---- Services (PRA; salon also on FBR) ----
        'salon'         => ['family' => 'services', 'modules' => ['customer_loyalty', 'loyalty_enabled'],
            'examples' => ['Haircut', 'Hair Colour', 'Facial', 'Manicure']],
        'gym'           => ['family' => 'services', 'modules' => ['customer_loyalty', 'loyalty_enabled'],
            'examples' => ['Monthly Membership', 'Personal Training Session', 'Day Pass', 'Locker Rent']],
        'laundry'       => ['family' => 'services', 'modules' => ['customer_loyalty', 'loyalty_enabled', 'delivery', 'riders_enabled', 'rider_tracking_enabled'],
            'examples' => ['Shirt Wash & Iron', 'Suit Dry Clean', 'Bedsheet Wash', 'Curtain Cleaning']],
        'workshop'      => ['family' => 'services', 'modules' => ['inventory', 'barcode'],
            'examples' => ['Oil Change', 'Brake Service', 'Engine Tuning', 'Wheel Alignment']],
        'courier'       => ['family' => 'services', 'modules' => ['customer_loyalty', 'loyalty_enabled', 'delivery', 'riders_enabled', 'rider_tracking_enabled'],
            'examples' => ['Parcel up to 1kg', 'Express Delivery', 'Document Envelope', 'Bulky Parcel']],
        'photography'   => ['family' => 'services',
            'examples' => ['Portrait Session', 'Event Coverage (Per Hour)', 'Photo Album', 'Passport Photos']],
        'event_management' => ['family' => 'services', 'modules' => ['inventory'],
            'examples' => ['Stage Decoration', 'Sound System (Per Day)', 'Event Planning', 'Lighting Setup']],
        'travel_agent'  => ['family' => 'services',
            'examples' => ['Air Ticket Booking', 'Visa Processing', 'Hotel Reservation', 'Tour Package']],
        'rent_a_car'    => ['family' => 'services', 'modules' => ['customer_loyalty', 'loyalty_enabled'],
            'examples' => ['Sedan (Per Day)', 'Van with Driver', 'Airport Pickup', 'Weekly Rental']],
        'property_dealer' => ['family' => 'services',
            'examples' => ['Sale Commission', 'Rent Agreement', 'Property Valuation', 'Documentation Fee']],
        'advertising'   => ['family' => 'services',
            'examples' => ['Billboard (Per Month)', 'Social Media Campaign', 'Flex Banner Design', 'Radio Spot']],
        'it_services'   => ['family' => 'services',
            'examples' => ['Website Development', 'Monthly Maintenance', 'Domain & Hosting', 'Software Support']],
        'security_services' => ['family' => 'services',
            'examples' => ['Security Guard (Per Month)', 'CCTV Monitoring', 'Event Security', 'Patrol Service']],
        'clinic'        => ['family' => 'services', 'modules' => ['inventory'],
            'examples' => ['Consultation', 'Follow-up Visit', 'Blood Pressure Check', 'Wound Dressing']],
        'education'     => ['family' => 'services',
            'examples' => ['Monthly Tuition', 'Admission Fee', 'Exam Fee', 'Study Material']],
        'consultant'    => ['family' => 'services',
            'examples' => ['Consultation (Per Hour)', 'Project Report', 'Monthly Retainer', 'Site Visit']],
        'architect'     => ['family' => 'services',
            'examples' => ['Floor Plan Design', '3D Elevation', 'Site Supervision', 'Interior Consultation']],
        'construction'  => ['family' => 'services', 'modules' => ['inventory'],
            'examples' => ['Labour (Per Day)', 'Brick Work (Per Sq Ft)', 'Plaster Work', 'Site Supervision']],
        'manpower'      => ['family' => 'services',
            'examples' => ['Labour Supply (Per Day)', 'Skilled Worker (Per Month)', 'Recruitment Fee', 'Overtime Hours']],
        'cargo'         => ['family' => 'services', 'modules' => ['riders_enabled', 'rider_tracking_enabled'],
            'examples' => ['Cargo up to 50kg', 'Container Freight', 'Door Delivery', 'Packing Service']],
        'warehouse'     => ['family' => 'services', 'modules' => ['inventory', 'barcode'],
            'examples' => ['Storage (Per Pallet)', 'Loading Service', 'Cold Storage (Per Day)', 'Handling Charges']],
        'cleaning'      => ['family' => 'services', 'modules' => ['inventory'],
            'examples' => ['Home Deep Cleaning', 'Sofa Cleaning', 'Office Cleaning (Per Visit)', 'Water Tank Cleaning']],
        'repair_service' => ['family' => 'services', 'modules' => ['inventory', 'barcode'],
            'examples' => ['Screen Replacement', 'AC Service', 'Motor Rewinding', 'Diagnosis Fee']],
        'printing'      => ['family' => 'services', 'modules' => ['inventory'],
            'examples' => ['Visiting Cards (1000)', 'Flex Banner (Per Sq Ft)', 'Photocopy (Per Page)', 'Wedding Cards']],
        'media_production' => ['family' => 'services',
            'examples' => ['Video Shoot (Per Day)', 'Editing (Per Minute)', 'Voice Over', 'Drone Footage']],
        'entertainment' => ['family' => 'services', 'modules' => ['customer_loyalty', 'loyalty_enabled'],
            'examples' => ['Entry Ticket', 'Family Package', 'Game Token (10)', 'Birthday Booking']],
        'financial_services' => ['family' => 'services',
            'examples' => ['Advisory Fee', 'Tax Filing', 'Account Opening', 'Monthly Bookkeeping']],
        'equipment_rental' => ['family' => 'services', 'modules' => ['inventory', 'barcode', 'riders_enabled', 'rider_tracking_enabled'],
            'examples' => ['Generator (Per Day)', 'Scaffolding (Per Week)', 'Concrete Mixer', 'Delivery Charges']],
        'tailoring'     => ['family' => 'services', 'modules' => ['inventory', 'customer_loyalty', 'loyalty_enabled'],
            'examples' => ['Shalwar Kameez Stitching', 'Suit Alteration', 'Blouse Stitching', 'Buttons & Lining']],
        'other_service' => ['family' => 'services'],

        // ---- Unclassified ----
        'general'       => ['family' => 'general'],
    ];

    /** Family-level fallbacks for vocabulary and defaults. */
    public const FAMILY_DEFAULTS = [
        'food_service' => [
            'examples' => ['Chicken Karahi (Full)', 'Zinger Burger', 'Fries (Large)', 'Cold Drink 500ml'],
            'unit' => 'NOS', 'units' => ['NOS', 'PCS', 'KGS', 'LTR', 'PKT'],
            'fbr_unit' => 'U', 'fbr_units' => ['U', 'PCS', 'KG', 'GM', 'LTR', 'ML', 'PKT', 'DOZ', 'BOX'],
            'order' => 'dine_in', 'grid' => 'menu', 'sample_category' => 'Fast Food',
            'prices' => [450, 350, 250, 90],
            'checklist' => ['products', 'receipt', 'kitchen', 'team', 'first_sale'],
            'tiles' => ['sales', 'orders', 'kitchen', 'items', 'customers'],
        ],
        'goods_retail' => [
            'examples' => ['Sugar 1kg', 'Cooking Oil 1L', 'Washing Powder 1kg', 'Notebook (Large)'],
            'unit' => 'PCS', 'units' => ['PCS', 'KGS', 'LTR', 'PKT', 'BOX', 'MTR', 'NOS'],
            'fbr_unit' => 'PCS', 'fbr_units' => ['PCS', 'U', 'KG', 'GM', 'LTR', 'ML', 'MTR', 'PKT', 'DOZ', 'BOX', 'CTN', 'BAG', 'BTL', 'SET'],
            'order' => 'walk_in', 'grid' => 'products', 'sample_category' => 'Grocery',
            'prices' => [180, 620, 350, 120],
            'checklist' => ['products', 'stock', 'receipt', 'team', 'first_sale'],
            'tiles' => ['sales', 'items', 'stock', 'customers', 'khata'],
        ],
        'pharmacy' => [
            'examples' => ['Paracetamol 500mg Tablet', 'Cough Syrup 120ml', 'Vitamin C Tablets', 'Antiseptic Cream 30g'],
            'unit' => 'STRIP', 'units' => ['STRIP', 'TAB', 'BTL', 'PCS', 'PKT', 'BOX', 'NOS'],
            'fbr_unit' => 'U', 'fbr_units' => ['U', 'PCS', 'PKT', 'BTL', 'BOX', 'CTN', 'ML', 'GM'],
            'order' => 'walk_in', 'grid' => 'medicines', 'sample_category' => 'Tablets',
            'prices' => [45, 180, 260, 150],
            'checklist' => ['products', 'stock', 'receipt', 'team', 'first_sale'],
            'tiles' => ['sales', 'items', 'expiry', 'customers', 'khata'],
        ],
        'services' => [
            'examples' => ['Consultation', 'Service Visit', 'Monthly Package', 'Repair Job'],
            'unit' => 'NOS', 'units' => ['NOS', 'PCS'],
            'fbr_unit' => 'U', 'fbr_units' => ['U', 'PCS'],
            'order' => 'walk_in', 'grid' => 'services', 'sample_category' => 'Services',
            'prices' => [1500, 800, 5000, 1200],
            'checklist' => ['products', 'receipt', 'team', 'first_sale'],
            'tiles' => ['sales', 'items', 'customers', 'khata'],
        ],
        'general' => [
            'examples' => ['Item A', 'Item B', 'Item C', 'Item D'],
            'unit' => 'NOS', 'units' => ['NOS', 'PCS', 'KGS', 'LTR', 'MTR', 'PKT', 'BOX'],
            'fbr_unit' => 'U', 'fbr_units' => ['U', 'PCS', 'KG', 'GM', 'LTR', 'ML', 'MTR', 'PKT', 'DOZ', 'BOX', 'CTN', 'BAG', 'BTL', 'SET'],
            'order' => 'walk_in', 'grid' => 'products', 'sample_category' => 'General',
            'prices' => [250, 400, 150, 90],
            'checklist' => ['products', 'receipt', 'team', 'first_sale'],
            'tiles' => ['sales', 'items', 'customers', 'khata'],
        ],
    ];

    /** Every module key a profile may reference: module flags + plan gates. */
    public static function knownModules(): array
    {
        return array_values(array_unique(array_merge(PosFeatureService::ALL_FLAGS, PosFeatureService::PLAN_GATES)));
    }

    /** Every category that must carry a profile (both panel lists + legacy). */
    public static function requiredCategories(): array
    {
        $all = array_keys(PosFeatureService::allCategoryDefaults());
        foreach (PosFeatureService::PANEL_CATEGORIES as $list) {
            $all = array_merge($all, $list);
        }
        return array_values(array_unique($all));
    }

    public static function has(?string $category): bool
    {
        return $category !== null && array_key_exists($category, self::PROFILES);
    }

    public static function family(?string $category): string
    {
        return self::PROFILES[$category]['family'] ?? 'general';
    }

    /** Audience families this category belongs to (general = every family). */
    public static function audiences(?string $category): array
    {
        $family = self::family($category);
        if ($family === 'general') {
            return array_values(array_diff(self::AUDIENCE_FAMILIES, ['all']));
        }
        return array_values(array_unique(array_merge([$family], self::PROFILES[$category]['audiences'] ?? [])));
    }

    /**
     * The module keys that BELONG to a category on a panel: core + family set +
     * panel additions + the category's own extras + its own signup defaults.
     * 'general' (unclassified) resolves to every known module — nothing hidden.
     */
    public static function modules(?string $category, string $panel = 'pra'): array
    {
        $family = self::family($category);
        if ($family === 'general') {
            return self::knownModules();
        }
        $set = array_merge(
            self::CORE_MODULES,
            self::FAMILY_MODULES[$family] ?? [],
            self::PANEL_FAMILY_MODULES[$panel][$family] ?? [],
            self::PROFILES[$category]['modules'] ?? [],
            array_keys(array_filter(PosFeatureService::allCategoryDefaults()[$category] ?? []))
        );
        $known = self::knownModules();
        return array_values(array_unique(array_intersect($set, $known)));
    }

    /** The resolved profile record for one category (family fallbacks applied). */
    public static function profile(?string $category, string $panel = 'pra'): array
    {
        $family = self::family($category);
        $fam = self::FAMILY_DEFAULTS[$family];
        $own = self::PROFILES[$category] ?? [];
        $examples = array_values($own['examples'] ?? $fam['examples']);
        $prices = $fam['prices'];

        $isFbr = $panel === 'fbr';
        return [
            'category' => $category ?? 'general',
            'family' => $family,
            'audiences' => self::audiences($category),
            'modules' => self::modules($category, $panel),
            'examples' => $examples,
            'unit' => $own['unit'] ?? ($isFbr ? $fam['fbr_unit'] : $fam['unit']),
            'units' => $isFbr ? $fam['fbr_units'] : $fam['units'],
            'order' => $own['order'] ?? $fam['order'],
            'grid' => $fam['grid'],
            'sample_category' => $fam['sample_category'],
            'prices' => $prices,
            'checklist' => $fam['checklist'],
            'tiles' => $fam['tiles'],
        ];
    }
}

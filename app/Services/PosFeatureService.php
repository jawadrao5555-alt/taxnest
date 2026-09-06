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
        // Pharmacy Mode (Task 1558) — 'pharmacy' is the master child flag that
        // companies.pharmacy_mode mirrors; the other two are its own children.
        'pharmacy', 'batch_expiry', 'loose_sale',
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

    /**
     * Plan-gated Pharmacy flags (Task 1558) — the exact same shape as
     * RESTAURANT_FLAGS, for the same reason.
     *
     * 'pharmacy' is the master: companies.pharmacy_mode mirrors it through
     * masterSwitches(), so the column can never drift away from the flag map.
     * The other three only mean anything underneath it, so forCompany() masks
     * ALL of them off when the package does not carry pharmacy_enabled —
     * stored configuration survives untouched, ready for an upgrade.
     */
    public const PHARMACY_FLAGS = ['pharmacy', 'prescription', 'batch_expiry', 'loose_sale'];

    /** Per-request cache: company_id => bool */
    protected static array $restaurantAllowedCache = [];

    /** Per-request cache: company_id => bool (Pharmacy module, Task 1558) */
    protected static array $pharmacyAllowedCache = [];

    /** company_id => [plan_column => bool] cache for plan feature gates. */
    protected static array $planGateCache = [];

    /**
     * Plan-gated premium features (Aug 2026 package matrix). Each key is a
     * boolean column on pricing_plans. Access logic mirrors the Restaurant
     * module: internal accounts and active admin overrides always pass, an
     * active trial passes (evaluate-before-buying), otherwise the active
     * plan's column decides.
     */
    public const PLAN_GATES = ['deals_enabled', 'riders_enabled', 'hazri_enabled', 'analytics_enabled', 'reports_enabled', 'rider_tracking_enabled', 'custom_access_enabled', 'qr_menu_enabled', 'offline_enabled', 'excel_enabled', 'khata_enabled', 'loyalty_enabled', 'kot_enabled', 'caller_id_enabled', 'whatsapp_enabled', 'pharmacy_enabled'];

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
        // Pharmacy Mode (Task 1558) is an FBR-panel module: goods, not services.
        // The 'panel' key keeps it out of the PRA wizard's own catalogue — a
        // salon's settings page must not carry another panel's business mode.
        // ('prescription' deliberately has no panel key: it predates this task
        // and has always been offered on both panels.)
        'pharmacy' => [
            'label' => 'Pharmacy / Medical Store Mode',
            'description' => 'Medicine catalogue (salt, strength, schedule), batch & expiry stock, expiry claims and pharmacy reports.',
            'icon' => '⚕️',
            'category' => 'specialty',
            'panel' => 'fbrpos',
        ],
        'prescription' => [
            'label' => 'Prescription (Pharmacy)',
            'description' => 'Capture doctor name, prescription image, drug schedule for pharmacy compliance.',
            'icon' => '💊',
            'category' => 'specialty',
        ],
        'batch_expiry' => [
            'label' => 'Batch & Expiry Tracking',
            'description' => 'Receive stock batch-wise with an expiry date; the counter sells the shortest-dated batch first.',
            'icon' => '📅',
            'category' => 'specialty',
            'panel' => 'fbrpos',
        ],
        'loose_sale' => [
            'label' => 'Loose / Broken Strip Sale',
            'description' => 'Sell single tablets out of a strip or box without the stock count drifting.',
            'icon' => '✂️',
            'category' => 'specialty',
            'panel' => 'fbrpos',
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

        // ---- Second Schedule service families added Sep 2026 ----
        // None of these is a food business, so NOT ONE of them may switch on
        // kot / kitchen / kitchen_notes / recipes / tables: restaurantModeFrom()
        // reads exactly those switches, and a courier or a property dealer
        // landing in restaurant mode would be handed a kitchen, KOT tickets and
        // a floor map it can never use.
        'courier' => [
            'service_jobs' => true, 'delivery' => true,
            'customer_profile' => true, 'customer_loyalty' => true,
            'multi_branch' => true,
        ],
        'photography' => [
            'service_jobs' => true, 'customer_profile' => true,
        ],
        'event_management' => [
            'service_jobs' => true, 'inventory' => true,
            'customer_profile' => true,
        ],
        'travel_agent' => [
            'service_jobs' => true, 'customer_profile' => true,
            'multi_branch' => true,
        ],
        'rent_a_car' => [
            'service_jobs' => true, 'customer_profile' => true,
            'customer_loyalty' => true,
        ],
        'property_dealer' => [
            'service_jobs' => true, 'customer_profile' => true,
        ],
        'advertising' => [
            'service_jobs' => true, 'customer_profile' => true,
        ],
        'it_services' => [
            'service_jobs' => true, 'customer_profile' => true,
        ],
        'security_services' => [
            'service_jobs' => true, 'customer_profile' => true,
            'multi_branch' => true,
        ],

        // ---- Remaining PRA service families added Sep 2026 ----
        // Same hard rule as the block above: NOT ONE of these is a food
        // business, so none may switch on kot / kitchen / kitchen_notes /
        // recipes / tables. restaurantModeFrom() reads exactly those switches,
        // and a clinic or a cargo agent handed a kitchen, KOT tickets and a
        // floor map is precisely the bug this task exists to close.
        // The modules are assembled from the EXISTING switch set only:
        // service_jobs for appointment/job trades, inventory where the trade
        // consumes parts or materials, delivery where goods actually move,
        // multi_branch for multi-site trades.
        'clinic' => [
            'service_jobs' => true, 'inventory' => true,
            'customer_profile' => true,
        ],
        'education' => [
            'service_jobs' => true, 'customer_profile' => true,
            'multi_branch' => true,
        ],
        'consultant' => [
            'service_jobs' => true, 'customer_profile' => true,
        ],
        'architect' => [
            'service_jobs' => true, 'customer_profile' => true,
        ],
        'construction' => [
            'service_jobs' => true, 'inventory' => true,
            'customer_profile' => true,
        ],
        'manpower' => [
            'service_jobs' => true, 'customer_profile' => true,
            'multi_branch' => true,
        ],
        'cargo' => [
            'service_jobs' => true, 'delivery' => true,
            'customer_profile' => true, 'multi_branch' => true,
        ],
        'warehouse' => [
            'service_jobs' => true, 'inventory' => true,
            'customer_profile' => true, 'multi_branch' => true,
        ],
        'cleaning' => [
            'service_jobs' => true, 'inventory' => true,
            'customer_profile' => true,
        ],
        'repair_service' => [
            'service_jobs' => true, 'inventory' => true,
            'customer_profile' => true,
        ],
        'printing' => [
            'service_jobs' => true, 'inventory' => true,
            'customer_profile' => true,
        ],
        'media_production' => [
            'service_jobs' => true, 'customer_profile' => true,
        ],
        'entertainment' => [
            'service_jobs' => true, 'customer_profile' => true,
            'customer_loyalty' => true,
        ],
        'financial_services' => [
            'service_jobs' => true, 'customer_profile' => true,
        ],
        'equipment_rental' => [
            'service_jobs' => true, 'inventory' => true,
            'delivery' => true, 'customer_profile' => true,
        ],
        'tailoring' => [
            'service_jobs' => true, 'inventory' => true,
            'customer_profile' => true, 'customer_loyalty' => true,
        ],
        // The honest "my business is not on the list" card. A service business
        // we have no family for must be able to say so instead of picking a
        // trade it does not run. Deliberately bare — it starts simple and
        // switches modules on from Customize.
        'other_service' => [
            'service_jobs' => true, 'customer_profile' => true,
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
        // Pharmacy Mode (Task 1558): the signup preset finally switches on
        // something real. 'pharmacy' is the master flag masterSwitches() mirrors
        // into companies.pharmacy_mode, and batch/expiry + prescription are the
        // two things a medical store cannot open without.
        'pharmacy' => [
            'barcode' => true, 'inventory' => true,
            'pharmacy' => true, 'batch_expiry' => true, 'loose_sale' => true,
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
        // Wording widened Sep 2026: a beauty clinic or a massage & pedicure
        // centre is the same trade, but its owner could not tell that from a
        // card that only said "Salon / Spa".
        'salon' => [
            'label' => 'Salon / Spa / Beauty Clinic',
            'description' => 'Beauty, massage & pedicure jobs, staff bookings, loyalty rewards',
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
        // Clubs used to hide inside this preset with no mention of their own —
        // a club owner had no way to tell that this was their card.
        'marquee' => [
            'label' => 'Marriage Hall / Marquee / Club',
            'description' => 'Event bookings, hall & club service, kitchen & catering',
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
            'label' => 'Auto Workshop / Service Station / Car Wash',
            'description' => 'Job cards, service station & car wash work, parts, customer vehicles',
            'icon' => '🔩',
            'badge' => 'New',
            'color' => 'slate',
        ],

        // ---- Second Schedule service families added Sep 2026 ----
        'courier' => [
            'label' => 'Courier / Express Cargo',
            'description' => 'Booking jobs, express cargo, pickup & delivery, branches, loyalty',
            'icon' => '📦',
            'badge' => 'New',
            'color' => 'orange',
        ],
        'photography' => [
            'label' => 'Photography / Videography Studio',
            'description' => 'Photo & video shoot bookings, session duration, customer profiles',
            'icon' => '📸',
            'badge' => 'New',
            'color' => 'violet',
        ],
        'event_management' => [
            'label' => 'Event Management',
            'description' => 'Event jobs, equipment stock, customer profiles',
            'icon' => '🎉',
            'badge' => 'New',
            'color' => 'fuchsia',
        ],
        'travel_agent' => [
            'label' => 'Travel Agent / Tour Operator',
            'description' => 'Tour & ticket jobs, multi-office, customer profiles',
            'icon' => '✈️',
            'badge' => 'New',
            'color' => 'sky',
        ],
        'rent_a_car' => [
            'label' => 'Rent A Car',
            'description' => 'Vehicle hire jobs, customer profiles, loyalty rewards',
            'icon' => '🚗',
            'badge' => 'New',
            'color' => 'teal',
        ],
        'property_dealer' => [
            'label' => 'Property Dealer / Real Estate',
            'description' => 'Dealing & commission jobs, customer profiles',
            'icon' => '🏘️',
            'badge' => 'New',
            'color' => 'lime',
        ],
        'advertising' => [
            'label' => 'Advertising Agent',
            'description' => 'Campaign jobs, client profiles, service billing',
            'icon' => '📣',
            'badge' => 'New',
            'color' => 'red',
        ],
        'it_services' => [
            'label' => 'IT Services / Call Centre',
            'description' => 'Software house, call centre & outsourcing projects, assigned staff',
            'icon' => '💻',
            'badge' => 'New',
            'color' => 'indigo',
        ],
        'security_services' => [
            'label' => 'Security Services / Guards',
            'description' => 'Guard duty jobs, multi-site, client profiles',
            'icon' => '🛡️',
            'badge' => 'New',
            'color' => 'zinc',
        ],

        // ---- Remaining PRA service families added Sep 2026 ----
        'clinic' => [
            'label' => 'Clinic / Diagnostic Lab',
            'description' => 'Patient visits, test & procedure jobs, consumable stock',
            'icon' => '🩺',
            'badge' => 'New',
            'color' => 'emerald',
        ],
        'education' => [
            'label' => 'Education & Training',
            'description' => 'School, academy, tuition & coaching fees, multi-campus',
            'icon' => '📚',
            'badge' => 'New',
            'color' => 'blue',
        ],
        'consultant' => [
            'label' => 'Professional Consultant',
            'description' => 'Tax, legal, accounts, audit & management assignments',
            'icon' => '📑',
            'badge' => 'New',
            'color' => 'slate',
        ],
        'architect' => [
            'label' => 'Architect & Engineering',
            'description' => 'Design, survey & interior assignments billed as jobs',
            'icon' => '📐',
            'badge' => 'New',
            'color' => 'cyan',
        ],
        'construction' => [
            'label' => 'Construction Contractor',
            'description' => 'Contract jobs, material consumption, client profiles',
            'icon' => '🏗️',
            'badge' => 'New',
            'color' => 'amber',
        ],
        'manpower' => [
            'label' => 'Manpower & Recruitment',
            'description' => 'Labour supply, staffing & recruitment jobs, multi-site',
            'icon' => '🧑‍🤝‍🧑',
            'badge' => 'New',
            'color' => 'indigo',
        ],
        'cargo' => [
            'label' => 'Cargo & Logistics',
            'description' => 'Freight, clearing & movers jobs, pickup, branches',
            'icon' => '🚚',
            'badge' => 'New',
            'color' => 'orange',
        ],
        'warehouse' => [
            'label' => 'Warehouse & Cold Storage',
            'description' => 'Storage & yard jobs, stored stock, multi-site',
            'icon' => '🏬',
            'badge' => 'New',
            'color' => 'stone',
        ],
        'cleaning' => [
            'label' => 'Cleaning & Pest Control',
            'description' => 'Janitorial, fumigation & sanitation jobs, supplies stock',
            'icon' => '🧹',
            'badge' => 'New',
            'color' => 'teal',
        ],
        'repair_service' => [
            'label' => 'Repair & Maintenance',
            'description' => 'Mobile, electronics, AC & appliance job cards with parts',
            'icon' => '🛠️',
            'badge' => 'New',
            'color' => 'zinc',
        ],
        'printing' => [
            'label' => 'Printing & Graphics',
            'description' => 'Print orders, design jobs, paper & ink stock',
            'icon' => '🖨️',
            'badge' => 'New',
            'color' => 'purple',
        ],
        'media_production' => [
            'label' => 'Media Production / Studio',
            'description' => 'Video, recording, sound & lights bookings',
            'icon' => '🎬',
            'badge' => 'New',
            'color' => 'violet',
        ],
        'entertainment' => [
            'label' => 'Entertainment & Gaming',
            'description' => 'Cinema, gaming zone, play area & snooker club entries',
            'icon' => '🎮',
            'badge' => 'New',
            'color' => 'fuchsia',
        ],
        'financial_services' => [
            'label' => 'Financial & Agency Services',
            'description' => 'Insurance, money exchange, brokerage & commission work',
            'icon' => '🏦',
            'badge' => 'New',
            'color' => 'lime',
        ],
        'equipment_rental' => [
            'label' => 'Equipment & Machinery Rental',
            'description' => 'Generator, machinery & event equipment hire with delivery',
            'icon' => '⚙️',
            'badge' => 'New',
            'color' => 'sky',
        ],
        'tailoring' => [
            'label' => 'Tailoring & Boutique',
            'description' => 'Stitching orders, dress design jobs, cloth & trimming stock',
            'icon' => '🧵',
            'badge' => 'New',
            'color' => 'rose',
        ],
        'other_service' => [
            'label' => 'Other Service Business',
            'description' => 'Any service not listed above — start simple, add modules later',
            'icon' => '🗂️',
            'badge' => 'New',
            'color' => 'gray',
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
        // Pharmacy Mode (Task 1558). Batch/expiry and loose sale are stock
        // behaviour, so they cannot exist without inventory tracking, and both
        // are meaningless outside the pharmacy module itself.
        'batch_expiry' => ['pharmacy', 'inventory'],
        'loose_sale' => ['pharmacy'],
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

        // Pharmacy Mode (Task 1558): same masking rule, same reason. A shop
        // whose package does not carry the module loses the master flag AND
        // every child underneath it, so no pharmacy screen, menu entry or
        // batch-aware billing path can be reached — while the stored setup
        // waits intact for the day the package covers it again.
        if (!self::pharmacyAllowed($company)) {
            foreach (self::PHARMACY_FLAGS as $flag) {
                $flags[$flag] = false;
            }
        }

        // Category profile (Task 1582): a flag that does not BELONG to this kind
        // of shop — and was neither admin-granted nor grandfathered — is masked
        // OFF exactly like a plan-locked module, so every existing caller
        // (nav, gates, sale screens, middleware) inherits "hidden = unreachable"
        // without a second check. Stored configuration survives untouched.
        foreach (self::ALL_FLAGS as $flag) {
            if (!empty($flags[$flag]) && !self::moduleRelevant($company, $flag)) {
                $flags[$flag] = false;
            }
        }

        return self::flagsToObject(self::resolve($flags));
    }

    /* ------------------------------------------------------------------
     |  Category profiles — ONE availability predicate (Task 1582)
     | ------------------------------------------------------------------ */

    /** Per-request cache: company_id => [module => bool] relevance. */
    protected static array $relevanceCache = [];

    /** Plan-gate labels for admin/wizard surfaces (flags use FLAG_META). */
    public const GATE_META = [
        'deals_enabled' => ['label' => 'Deals & Combos', 'icon' => '🎁'],
        'riders_enabled' => ['label' => 'Delivery Riders', 'icon' => '🛵'],
        'rider_tracking_enabled' => ['label' => 'Rider LIVE Tracking', 'icon' => '📍'],
        'hazri_enabled' => ['label' => 'Staff Hazri (Attendance)', 'icon' => '🕒'],
        'analytics_enabled' => ['label' => 'Business Analytics', 'icon' => '📈'],
        'reports_enabled' => ['label' => 'Reports & Exports', 'icon' => '📊'],
        'custom_access_enabled' => ['label' => 'Team Custom Access', 'icon' => '🔐'],
        'qr_menu_enabled' => ['label' => 'Public QR Menu', 'icon' => '📱'],
        'offline_enabled' => ['label' => 'Offline Mode', 'icon' => '📴'],
        'excel_enabled' => ['label' => 'Excel Import / Export', 'icon' => '📗'],
        'khata_enabled' => ['label' => 'Khata (Customer Credit)', 'icon' => '📒'],
        'loyalty_enabled' => ['label' => 'Loyalty Points', 'icon' => '⭐'],
        'kot_enabled' => ['label' => 'Kitchen / Store Slip Printing', 'icon' => '🖨️'],
        'caller_id_enabled' => ['label' => 'Caller ID Popup', 'icon' => '📞'],
        'whatsapp_enabled' => ['label' => 'WhatsApp Bill', 'icon' => '💬'],
        'pharmacy_enabled' => ['label' => 'Pharmacy Module', 'icon' => '💊'],
    ];

    /** Human label + icon for ANY module key (flag or plan gate). */
    public static function moduleMeta(string $key): array
    {
        if (in_array($key, self::ALL_FLAGS, true)) {
            $m = self::flagMeta($key);
            return ['label' => $m['label'], 'icon' => $m['icon'] ?? '⚙️', 'kind' => 'flag'];
        }
        $m = self::GATE_META[$key] ?? ['label' => str_replace('_', ' ', ucwords($key, '_')), 'icon' => '⚙️'];
        return $m + ['kind' => 'gate'];
    }

    /**
     * The category whose PROFILE this shop lives under.
     *
     * Same lookup as resolveCategory() (business_category, then pos_type) but
     * a shop nobody classified lands on 'general' — the "hide nothing" profile
     * — rather than on the restaurant preset. Hiding is a stronger act than
     * labelling: a pre-category FBR grocery must never lose its barcode screen
     * because a display fallback called it a restaurant.
     */
    public static function profileCategory(?Company $company): string
    {
        if (!$company) {
            return 'general';
        }
        $known = self::allCategoryDefaults();
        $stored = $company->business_category;
        if (is_string($stored) && isset($known[$stored]) && PosCategoryProfiles::has($stored)) {
            return $stored;
        }
        $posType = $company->pos_type ?? null;
        if (is_string($posType) && isset($known[$posType]) && PosCategoryProfiles::has($posType)) {
            return $posType;
        }
        return 'general';
    }

    /** The resolved profile record (modules, family, examples, defaults) for a shop. */
    public static function profile(?Company $company): array
    {
        return PosCategoryProfiles::profile(self::profileCategory($company), self::panelFor($company));
    }

    /** Vocabulary family: food_service / goods_retail / pharmacy / services / general. */
    public static function familyFor(?Company $company): string
    {
        return PosCategoryProfiles::family(self::profileCategory($company));
    }

    /** Audience families (What's New / tutorials) that reach this shop. */
    public static function audiencesFor(?Company $company): array
    {
        return PosCategoryProfiles::audiences(self::profileCategory($company));
    }

    /** Does an audience-family value ('all' or a family) reach this shop? */
    public static function audienceMatches(?Company $company, ?string $audienceFamily): bool
    {
        $a = $audienceFamily ?: 'all';
        return $a === 'all' || in_array($a, self::audiencesFor($company), true);
    }

    /** Modules that BELONG to the shop's own category (no extras). */
    public static function categoryModules(?Company $company): array
    {
        return PosCategoryProfiles::modules(self::profileCategory($company), self::panelFor($company));
    }

    /** Whether the extras column has landed — before that the predicate stays dormant. */
    protected static ?bool $extrasColumn = null;

    /** Tests only: sticky override — pretend the extras column does / does not exist (null = ask the schema). */
    protected static ?bool $extrasColumnAssumed = null;

    public static function assumeExtrasColumn(?bool $exists): void
    {
        self::$extrasColumnAssumed = $exists;
        self::$extrasColumn = null;
        self::$relevanceCache = [];
        self::$planGateCache = [];
    }

    public static function extrasColumnExists(): bool
    {
        if (self::$extrasColumnAssumed !== null) {
            return self::$extrasColumnAssumed;
        }
        if (self::$extrasColumn === null) {
            try {
                self::$extrasColumn = \Illuminate\Support\Facades\Schema::hasColumn('companies', 'pos_module_extras');
            } catch (\Throwable $e) {
                self::$extrasColumn = false;
            }
        }
        return self::$extrasColumn;
    }

    /**
     * Modules this shop carries OUTSIDE its category, keyed by module:
     *   ['riders_enabled' => ['source' => 'admin'|'grandfathered', 'reason' => ?, 'by' => ?, 'at' => ?]]
     */
    public static function extraModules(?Company $company): array
    {
        if (!$company || !self::extrasColumnExists()) {
            return [];
        }
        $raw = $company->getAttribute('pos_module_extras');
        if (is_string($raw)) {
            $raw = json_decode($raw, true);
        }
        if (!is_array($raw)) {
            return [];
        }
        $known = PosCategoryProfiles::knownModules();
        $out = [];
        foreach ($raw as $key => $meta) {
            if (!in_array($key, $known, true)) {
                continue;
            }
            $out[$key] = is_array($meta) ? $meta : ['source' => 'admin'];
        }
        return $out;
    }

    /**
     * THE relevance half of the availability predicate: does module $key
     * belong to company $company — by its category, by an admin grant, or by
     * grandfathering? Unknown keys and a missing company are always relevant
     * (there is nothing to hide). Plan/add-on gates are NOT consulted here.
     */
    public static function moduleRelevant(?Company $company, string $key): bool
    {
        // A view that forgot to pass $company must not silently un-hide a
        // module: fall back to the request's own company before giving up.
        $company = $company ?? \App\Support\PosVocabulary::currentCompany();
        if (!$company || !in_array($key, PosCategoryProfiles::knownModules(), true)) {
            return true;
        }
        // Rollout safety: until the extras column exists, nothing can have been
        // grandfathered, so hiding would strip live shops. Stay dormant.
        if (!self::extrasColumnExists()) {
            return true;
        }
        $cid = (int) $company->id;
        if (isset(self::$relevanceCache[$cid][$key])) {
            return self::$relevanceCache[$cid][$key];
        }
        $relevant = in_array($key, self::categoryModules($company), true)
            || array_key_exists($key, self::extraModules($company));
        return self::$relevanceCache[$cid][$key] = $relevant;
    }

    /**
     * THE availability predicate (Task 1582): relevant for the category (or
     * granted / grandfathered) AND switched on / covered by the plan.
     *   - module flag  → the resolved feature map (already relevance-masked)
     *   - plan gate    → planAllows() (already relevance-aware)
     */
    public static function moduleAvailable(?Company $company, string $key): bool
    {
        $company = $company ?? \App\Support\PosVocabulary::currentCompany();
        if (in_array($key, self::ALL_FLAGS, true)) {
            return !empty(self::forCompany($company)->{$key});
        }
        if (in_array($key, self::PLAN_GATES, true)) {
            return self::planAllows($company, $key);
        }
        return true;
    }

    /** Persist one admin grant (or grandfather record) and flush caches. */
    public static function grantExtra(Company $company, string $key, string $source, ?string $reason = null, ?string $by = null): void
    {
        if (!self::extrasColumnExists() || !in_array($key, PosCategoryProfiles::knownModules(), true)) {
            return;
        }
        $extras = self::extraModules($company);
        $extras[$key] = array_filter([
            'source' => $source,
            'reason' => $reason,
            'by' => $by,
            'at' => now()->toDateTimeString(),
        ], fn ($v) => $v !== null && $v !== '');
        $company->forceFill(['pos_module_extras' => $extras])->save();
        self::flushGateCaches();
    }

    public static function revokeExtra(Company $company, string $key): void
    {
        if (!self::extrasColumnExists()) {
            return;
        }
        $extras = self::extraModules($company);
        unset($extras[$key]);
        $company->forceFill(['pos_module_extras' => $extras ?: null])->save();
        self::flushGateCaches();
    }

    /**
     * Admin changed the category: extras survive, but any that the NEW
     * category already covers are redundant and dropped so the admin page
     * shows only true outsiders.
     */
    public static function reevaluateExtras(Company $company): void
    {
        if (!self::extrasColumnExists()) {
            return;
        }
        self::flushGateCaches();
        $extras = self::extraModules($company);
        if (!$extras) {
            return;
        }
        $own = self::categoryModules($company);
        $kept = array_diff_key($extras, array_flip($own));
        if (count($kept) !== count($extras)) {
            $company->forceFill(['pos_module_extras' => $kept ?: null])->save();
            self::flushGateCaches();
        }
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

    /**
     * Does this company's PACKAGE carry the Pharmacy module? (Task 1558)
     *
     * The module rides the ordinary PLAN_GATES machinery — pharmacy_enabled on
     * pricing_plans — so internal accounts, blanket overrides, an active trial
     * and a paid add-on all behave exactly as they do for every other premium
     * feature, with no second entitlement rule to keep in step.
     */
    public static function pharmacyAllowed(?Company $company): bool
    {
        if (!$company) {
            return false;
        }
        if (array_key_exists($company->id, self::$pharmacyAllowedCache)) {
            return self::$pharmacyAllowedCache[$company->id];
        }

        return self::$pharmacyAllowedCache[$company->id]
            = self::planAllows($company, 'pharmacy_enabled');
    }

    /**
     * Pharmacy mode live RIGHT NOW — the single truth for navigation, the
     * settings card, every pharmacy route guard and every pharmacy-only field.
     *
     * Two things must both be true: the shop's own switch AND the package gate,
     * the same pairing callerIdLive() enforces. Reading the raw column alone
     * would leave a downgraded shop staring at menu entries and batch pickers
     * whose controllers would only ever answer 403 — the panel must never
     * advertise a feature it will refuse.
     */
    public static function pharmacyLive(?Company $company): bool
    {
        if (!$company) {
            return false;
        }
        if (!self::pharmacyAllowed($company)) {
            return false;
        }

        // The column is the fast answer, but a shop whose feature_flags were
        // written before the column existed (PROD schema-drift window) must
        // still resolve correctly, so the flag map is the fallback.
        if (\Illuminate\Support\Facades\Schema::hasColumn('companies', 'pharmacy_mode')) {
            return (bool) $company->pharmacy_mode;
        }

        return self::rawFlag($company, 'pharmacy');
    }

    /**
     * WHY the company has (or doesn't have) the Pharmacy module.
     * Returns 'internal' | 'override' | 'plan' | 'trial' | 'addon' | null.
     * Used by the locked settings card to say something truthful instead of a
     * flat "upgrade" — a trial shop especially needs to know its access ends.
     */
    public static function pharmacyAccessSource(?Company $company): ?string
    {
        if (!$company || !self::pharmacyAllowed($company)) {
            return null;
        }
        if ($company->is_internal_account) {
            return 'internal';
        }
        $sub = \App\Services\PlanLimitService::getActiveSubscription($company->id);
        if ($sub) {
            if ($sub->hasActiveOverride() && self::overrideGrantsEverything($sub)) {
                return 'override';
            }
            if ($sub->pricingPlan && !empty($sub->pricingPlan->pharmacy_enabled)) {
                return $sub->hasActiveOverride() ? 'override' : 'plan';
            }
            if ($sub->isTrialActive()) {
                return 'trial';
            }
        }

        return 'addon';
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
        self::$pharmacyAllowedCache = [];
        self::$relevanceCache = [];
        self::$extrasColumn = null;
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

        // Category profile (Task 1582): a plan-gated module that does not
        // BELONG to this kind of shop (and was neither granted nor
        // grandfathered) is unavailable whatever the package says — the same
        // single answer nav, controllers, tutorials and add-on sales all read.
        if (!self::moduleRelevant($company, $planColumn)) {
            return self::$planGateCache[$company->id][$planColumn] = false;
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
            // Second Schedule service families added Sep 2026 — these had no
            // business type at all, so such a shop fell through to the
            // restaurant default and opened with a kitchen it never asked for.
            'courier', 'photography', 'event_management', 'travel_agent',
            'rent_a_car', 'property_dealer', 'advertising', 'it_services',
            'security_services',
            // The rest of the PRA-taxable service families (Sep 2026). Before
            // this, a clinic, an academy, a consultant, an architect, a
            // contractor, a cargo agent, a warehouse, a cleaning firm, a repair
            // shop, a printing press, a studio, a gaming zone, an insurance
            // agent, a manpower agency, an equipment renter and a tailor had no
            // card at all and had to sign up as something they are not.
            'clinic', 'education', 'consultant', 'architect', 'construction',
            'manpower', 'cargo', 'warehouse', 'cleaning', 'repair_service',
            'printing', 'media_production', 'entertainment',
            'financial_services', 'equipment_rental', 'tailoring',
            // ...and an honest way to say "my business is still not listed".
            'other_service',
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
     * Short family headings the offered types are grouped under.
     *
     * The PRA list is 36 cards long; an ungrouped wall of tiles is unreadable,
     * so the sign-up picker shows them family by family. Membership lives HERE,
     * beside PANEL_CATEGORIES, so a type can never be offered and yet fall out
     * of the picker: categoryGroups() intersects with the offered list and
     * sweeps anything ungrouped into the catch-all heading.
     *
     * The keys are heading slugs — 'pos.auth_btg_<key>' carries the wording.
     */
    public const CATEGORY_GROUPS = [
        'food'         => ['restaurant', 'cafe', 'quick_service', 'catering', 'hotel', 'marquee'],
        'personal'     => ['salon', 'gym', 'laundry', 'tailoring'],
        'health_edu'   => ['clinic', 'education'],
        'professional' => ['consultant', 'architect', 'it_services', 'advertising', 'financial_services', 'property_dealer'],
        'transport'    => ['courier', 'cargo', 'travel_agent', 'rent_a_car', 'warehouse'],
        'technical'    => ['workshop', 'repair_service', 'construction', 'cleaning', 'equipment_rental', 'security_services', 'manpower'],
        'media'        => ['photography', 'media_production', 'printing', 'event_management', 'entertainment'],
        'trade'        => ['retail', 'pharmacy', 'grocery', 'clothing', 'electronics', 'hardware', 'autoparts', 'bakery'],
    ];

    /**
     * Extra words a shop might TYPE to find its own trade, including the
     * obvious Roman Urdu spellings. Matched alongside the localized label, so
     * this map only has to carry what the label itself does not say.
     */
    public const CATEGORY_SEARCH_TERMS = [
        'restaurant'         => 'restaurant hotel khana dine food dhaba',
        'cafe'               => 'cafe coffee chai tea bakery counter',
        'quick_service'      => 'fast food burger pizza dhaba takeaway tikka',
        'salon'              => 'salon spa parlour beauty clinic hair massage pedicure facial',
        'hotel'              => 'hotel guest house rooms motel rihaish',
        'marquee'            => 'marquee marriage hall club shadi banquet lawn',
        'catering'           => 'catering caterer khana supply degh',
        'laundry'            => 'laundry dry cleaner press kapre dhulai',
        'gym'                => 'gym fitness club yoga workout warzish',
        'workshop'           => 'workshop auto mechanic service station car wash denting painting',
        'courier'            => 'courier parcel express cargo delivery bhijwana',
        'photography'        => 'photography photographer videography studio shoot camera',
        'event_management'   => 'event management decor wedding planner tqreeb',
        'travel_agent'       => 'travel agent tour ticket umrah hajj visa safar',
        'rent_a_car'         => 'rent a car vehicle hire gaari kiraya taxi',
        'property_dealer'    => 'property dealer real estate estate agent jaidad plot',
        'advertising'        => 'advertising agency marketing billboard panaflex ishtihar',
        'it_services'        => 'it services software house call centre call center outsourcing web development',
        'security_services'  => 'security guards guard cctv chowkidar',
        'clinic'             => 'clinic doctor hospital dental dentist lab laboratory diagnostic pathology vet medical daktar',
        'education'          => 'school academy tuition coaching training institute computer college taleem',
        'consultant'         => 'consultant tax legal lawyer advocate accounts accountant audit management advisory',
        'architect'          => 'architect engineer engineering surveyor interior designer naqsha',
        'construction'       => 'construction contractor builder developer civil works tameer',
        'manpower'           => 'manpower recruitment labour supply staffing agency mazdoor',
        'cargo'              => 'cargo freight forwarding clearing agent packers movers goods transport',
        'warehouse'          => 'warehouse cold storage godown storage parking yard container',
        'cleaning'           => 'cleaning janitorial fumigation pest control sanitation safai',
        'repair_service'     => 'repair maintenance mobile electronics ac appliance machinery marammat',
        'printing'           => 'printing press screen printing design print flex publisher chapai',
        'media_production'   => 'media production video studio recording sound lights dj production house',
        'entertainment'      => 'cinema gaming zone play area kids snooker sports club amusement park arcade',
        'financial_services' => 'insurance money changer exchange broker brokerage commission agent auctioneer',
        'equipment_rental'   => 'equipment rental generator machinery crane event equipment kiraya',
        'tailoring'          => 'tailor tailoring boutique dress designer stitching darzi silai',
        'other_service'      => 'other service business not listed koi aur',
        'retail'             => 'retail store shop general store',
        'pharmacy'           => 'pharmacy medical store chemist dawa',
        'grocery'            => 'grocery kiryana mart supermarket',
        'clothing'           => 'clothing garments cloth kapre boutique',
        'electronics'        => 'electronics mobile computer appliances',
        'hardware'           => 'hardware building material sanitary paint',
        'autoparts'          => 'auto parts spare parts tyre battery',
        'bakery'             => 'bakery bread cake sweets',
    ];

    /**
     * Categories a shop may CHOOSE on one panel. Defaults to PRA.
     */
    public static function categories(?string $panel = null): array
    {
        return self::PANEL_CATEGORIES[$panel ?? 'pra'] ?? self::PANEL_CATEGORIES['pra'];
    }

    /**
     * The categories a NEW company may be created on, for one panel.
     *
     * Exactly what the panel offers plus the 'general' catch-all, which is
     * never an offered card but is the fallback whenever nobody classified the
     * shop — the same set each signup screen accepts. The SaaS admin's
     * company-create form (which asks the same question for a shop that phoned
     * in) is generated and validated from here, so no hard-coded second list
     * can drift away from PANEL_CATEGORIES.
     */
    public static function choosableCategories(?string $panel = null): array
    {
        return array_merge(self::categories($panel), ['general']);
    }

    /**
     * The offered list of a panel, split into family headings.
     *
     * Returns heading-slug => [category, ...] and NEVER drops a category: an
     * offered type that nobody put in a family lands under 'other', so the
     * picker can only ever show more than the list, not less.
     */
    public static function categoryGroups(?string $panel = null): array
    {
        $offered = self::categories($panel);
        $groups  = [];
        $placed  = [];

        foreach (self::CATEGORY_GROUPS as $heading => $members) {
            $rows = array_values(array_intersect($members, $offered));
            if ($rows) {
                $groups[$heading] = $rows;
                $placed = array_merge($placed, $rows);
            }
        }

        $leftover = array_values(array_diff($offered, $placed));
        if ($leftover) {
            $groups['other'] = array_merge($groups['other'] ?? [], $leftover);
        }

        return $groups;
    }

    /**
     * Lower-cased words a type can be found by when the shop types into the
     * picker's filter box. Includes the type's own slug so a scripted search
     * still works, but never the label — the page adds the LOCALIZED label
     * itself, which is the one the shop can actually read.
     */
    public static function categorySearchTerms(string $category): string
    {
        return trim($category . ' ' . str_replace('_', ' ', $category) . ' '
            . (self::CATEGORY_SEARCH_TERMS[$category] ?? ''));
    }

    /**
     * Every category we can still RESOLVE, including the retired goods ones
     * that pre-split shops are sitting on.
     */
    public static function allCategoryDefaults(): array
    {
        return self::CATEGORY_DEFAULTS + self::LEGACY_CATEGORIES;
    }

    /**
     * ONE category's raw preset flag map — the modules that category switches
     * on, with no base defaults merged in. This is what the Customize wizard
     * splits into "recommended" and "extra", and it is deliberately per
     * category: the shop's page must never carry the whole catalogue, because
     * the business type is a SaaS-admin-only decision (it picks the regulator).
     * An unknown/retired slug yields an empty map, so the page still renders.
     */
    public static function categoryFlagMap(?string $category): array
    {
        if ($category === null) {
            return [];
        }
        return self::allCategoryDefaults()[$category] ?? [];
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

    /**
     * Pharmacy mode is a FEATURE, not an identity either (Task 1558).
     *
     * Unlike restaurant mode there is ONE explicit master flag rather than a
     * family of switches: a medical store either runs the medicine catalogue,
     * batch/expiry stock and claim workflow, or it does not. Deriving it from
     * the children instead (batch_expiry || prescription) would let a general
     * store that merely wanted a prescription note find itself in pharmacy
     * mode, which is exactly the drift the restaurant column used to suffer.
     */
    public static function pharmacyModeFrom(array $flags): bool
    {
        return (bool) ($flags['pharmacy'] ?? false);
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
            // Pharmacy Mode (Task 1558): a shop that signs up as a medical
            // store now lands on the REAL switch, not a flag nobody reads.
            'pharmacy_mode'     => self::pharmacyModeFrom($defaults),
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
     * Both panels derive the same way. The FBR sale screen already computes its
     * own restaurant mode from kitchen/kot/tables, so an FBR-only exception
     * would just let the column drift away from the screen. (FBR's per-item
     * Store notes ride 'kitchen_notes', which is NOT one of these flags.)
     *
     * This derives from the map EXACTLY as given. Anything persisting a feature
     * map must go through featureUpdates() instead, which resolves dependencies
     * first — see the note there.
     */
    public static function masterSwitches(array $flags): array
    {
        $columns = [
            'inventory_enabled' => (bool) ($flags['inventory'] ?? false),
            'restaurant_mode'   => self::restaurantModeFrom($flags),
            // Pharmacy Mode (Task 1558): a THIRD column beside the flag map, so
            // it obeys the same rule — any path that writes feature_flags must
            // rewrite it, or the shop ends up with two contradictory answers.
            'pharmacy_mode'     => self::pharmacyModeFrom($flags),
        ];

        return array_filter(
            $columns,
            fn ($column) => \Illuminate\Support\Facades\Schema::hasColumn('companies', $column),
            ARRAY_FILTER_USE_KEY
        );
    }

    /**
     * The ONE way to persist a feature map — every write path uses this.
     *
     * Returns the company columns for a feature-map write: the CANONICAL map
     * (dependencies resolved) plus the master columns derived from that same
     * map. Deriving before resolving is how a surface stored a combination the
     * runtime immediately undoes — "KOT on, kitchen off" left a shop marked as
     * a restaurant while forCompany() switched every restaurant feature back
     * off, so it got the restaurant dashboard with no kitchen anywhere.
     *
     * feature_flags itself is hasColumn-guarded like the master columns: a
     * deployment whose migrations have not fully landed must still be able to
     * flip what it does have (PROD schema-drift rule).
     */
    public static function featureUpdates(array $flags): array
    {
        $flags  = self::resolve($flags);
        $update = self::masterSwitches($flags);

        if (\Illuminate\Support\Facades\Schema::hasColumn('companies', 'feature_flags')) {
            $update['feature_flags'] = $flags;
        }

        return $update;
    }

    /**
     * Columns for ONE inventory master-switch flip, wherever it comes from.
     *
     * inventory_enabled is a column AND a feature flag (the dual-switch trap).
     * A switch that wrote only the column silently reverted at the next
     * features save, because that save re-derives the column FROM the stale
     * map. Both surfaces of the switch move together, through the same shared
     * derivation as the settings toggles.
     */
    public static function inventoryToggleUpdates(?Company $company, bool $enabled): array
    {
        $flags = is_array($company?->feature_flags) ? $company->feature_flags : [];
        $flags['inventory'] = $enabled;

        return self::featureUpdates($flags);
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

    /**
     * The flags a given panel is allowed to know about.
     *
     * Most flags are shared, so FLAG_META carries no 'panel' key for them. A
     * flag that names another panel's business mode (Pharmacy Mode, Task 1558)
     * sets it, and is then invisible to the other panel's settings wizard —
     * a salon must not be able to read a medical store's module out of its own
     * page, exactly as the business-type catalogue is already scoped.
     *
     * @param  string  $panel  'pos' (PRA) or 'fbrpos'
     * @return array<int,string>
     */
    public static function flagsForPanel(string $panel): array
    {
        return array_values(array_filter(self::ALL_FLAGS, function (string $flag) use ($panel) {
            $only = self::FLAG_META[$flag]['panel'] ?? null;
            return $only === null || $only === $panel;
        }));
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

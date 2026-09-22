<?php
declare(strict_types=1);

use App\Models\AdminUser;
use App\Models\AppUpdate;
use App\Models\Company;
use App\Models\HotelRoom;
use App\Models\User;
use App\Services\HealthModuleService;
use App\Services\PosFeatureService;
use App\Services\PosServiceWorkflowProfiles;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Schema;

require dirname(__DIR__).'/vendor/autoload.php';
$app = require dirname(__DIR__).'/bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();

$fail = static function (string $message): never {
    fwrite(STDERR, "RC BROWSER FIXTURE REFUSED: {$message}\n");
    exit(2);
};
$fixturePath = (string) getenv('RC_BROWSER_FIXTURE_OUT');
$socket = (string) getenv('DB_SOCKET');
if (PHP_SAPI !== 'cli' || getenv('RC_BROWSER_FIXTURE_FRESH') !== '1') {
    $fail('fresh reset acknowledgement is required.');
}
if (!preg_match('#^/tmp/taxnest-rc-browser-[0-9]+(?:-[A-Za-z0-9_.-]+)?/safe-runtime/browser-state/fixture\.json$#', $fixturePath)
    || !preg_match('#^/tmp/taxnest-rc-mariadb-browser-[0-9]+/run/mariadb\.sock$#', $socket)
    || is_link($fixturePath) || is_link($fixturePath.'.tmp')
    || @filetype($socket) !== 'socket' || is_link($socket)) {
    $fail('exact isolated fixture target and MariaDB socket are required.');
}
if (DB::connection()->getDriverName() !== 'mysql'
    || DB::connection()->getDatabaseName() !== 'taxnest_rc_browser'
    || config('database.connections.mysql.host') !== '127.0.0.1'
    || config('database.connections.mysql.unix_socket') !== $socket) {
    $fail('connection is outside the browser-lab allowlist.');
}
foreach (['companies', 'users', 'admin_users', 'branches', 'hotel_rooms', 'pos_service_work_orders', 'health_patients', 'invoices', 'invoice_items'] as $table) {
    if (!Schema::hasTable($table)) {
        $fail("required migrated table is absent: {$table}");
    }
}
$announcementSource = resource_path('whats-new/return-elaan-fbr.png');
$announcementDestination = storage_path('app/public/app-updates/return-elaan-fbr.png');
if (!is_file($announcementSource)) {
    $fail('repository-owned FBR announcement image is absent.');
}
if (!is_file($announcementDestination)) {
    try {
        File::ensureDirectoryExists(dirname($announcementDestination));
        if (!File::copy($announcementSource, $announcementDestination)) {
            $fail('could not materialize the synthetic FBR announcement image.');
        }
    } catch (Throwable $e) {
        $fail('could not materialize the synthetic FBR announcement image.');
    }
}
if (!is_file($announcementDestination) || hash_file('sha256', $announcementSource) !== hash_file('sha256', $announcementDestination)) {
    $fail('synthetic FBR announcement image does not match the repository asset.');
}
if (Company::withoutGlobalScopes()->where('email', 'like', '%@rc-browser.invalid')->exists()
    || User::withoutGlobalScopes()->where('email', 'like', '%@rc-browser.invalid')->exists()) {
    $fail('fresh browser database already contains a synthetic fixture.');
}

$password = 'RcBrowser!'.bin2hex(random_bytes(18));
$now = now();
$company = static function (string $name, string $email, string $ntn, string $product, array $attributes = []): Company {
    return Company::withoutGlobalScopes()->create(array_merge([
        'name' => $name, 'email' => $email, 'ntn' => $ntn, 'phone' => '00000000000',
        'status' => 'approved', 'company_status' => 'active', 'product_type' => $product, 'default_language' => 'en',
        'is_internal_account' => true,
    ], $attributes));
};
$user = static function (Company $company, string $name, string $email, string $role, string $posRole = '', ?array $customAccess = null, ?string $healthRole = null) use ($password): User {
    $attributes = [
        'name' => $name, 'email' => $email, 'password' => Hash::make($password),
        'company_id' => $company->id, 'product_type' => $company->product_type,
        'role' => $role, 'pos_role' => $posRole ?: null, 'health_role' => $healthRole,
        'is_active' => true, 'language' => 'en',
    ];
    if ($customAccess !== null) {
        $attributes['pos_custom_access'] = json_encode($customAccess, JSON_THROW_ON_ERROR);
    }
    return User::withoutGlobalScopes()->create($attributes);
};

$hotelFlags = PosFeatureService::defaultsForCategory('hotel');
$hotelFlags['kitchen'] = true;
$hotelFlags['kot'] = true;
$hotelFlags['tables'] = true;
$hotel = $company('Synthetic RC Hotel', 'hotel-company@rc-browser.invalid', 'RCBROWSER001', 'pos', [
    'business_category' => 'hotel', 'pos_type' => 'hotel', 'feature_flags' => $hotelFlags,
    'restaurant_mode' => true, 'pos_integration_mode' => 'pra', 'pos_setup_completed' => true,
    'pos_tax_rate_cash' => 0, 'pos_tax_rate_card' => 0,
]);
$hotelBranch = DB::table('branches')->insertGetId([
    'company_id' => $hotel->id, 'name' => 'Synthetic Main Hotel Branch',
    'is_active' => true, 'created_at' => $now, 'updated_at' => $now,
]);
HotelRoom::withoutGlobalScopes()->create([
    'company_id' => $hotel->id, 'branch_id' => $hotelBranch, 'room_number' => 'RC-101',
    'room_type' => 'Synthetic Deluxe', 'capacity' => 2, 'rate_amount' => 5000,
    'rate_unit' => 'NGT', 'housekeeping' => 'clean', 'is_active' => true,
]);
$hotelOwner = $user($hotel, 'Synthetic Hotel Owner', 'hotel-owner@rc-browser.invalid', 'company_admin', 'pos_admin');
$hotelManager = $user($hotel, 'Synthetic Hotel Front Desk Manager', 'hotel-manager@rc-browser.invalid', 'staff', 'pos_manager', ['dashboard', 'hotel', 'hotel_housekeeping']);
$hotelHousekeeping = $user($hotel, 'Synthetic Hotel Housekeeping', 'hotel-housekeeping@rc-browser.invalid', 'staff', 'pos_cashier', ['dashboard', 'hotel_housekeeping']);
$hotelOutlet = $user($hotel, 'Synthetic Restaurant Outlet Cashier', 'hotel-outlet@rc-browser.invalid', 'staff', 'pos_cashier', ['dashboard', 'orders']);
$hotelDenied = $user($hotel, 'Synthetic Hotel Denied Waiter', 'hotel-denied@rc-browser.invalid', 'staff', 'pos_waiter');

$service = $company('Synthetic RC Event Services', 'service-company@rc-browser.invalid', 'RCBROWSER002', 'pos', [
    'business_category' => 'event_management', 'pos_type' => 'event_management',
    'feature_flags' => PosFeatureService::defaultsForCategory('event_management'),
    'restaurant_mode' => false, 'pos_integration_mode' => 'pra', 'pos_setup_completed' => true,
    'pos_tax_rate_cash' => 0, 'pos_tax_rate_card' => 0,
]);
$serviceWorker = $user($service, 'Synthetic Event Workflow Admin', 'service-work-orders@rc-browser.invalid', 'company_admin', 'pos_admin');
$serviceManager = $user($service, 'Synthetic Event Workflow Manager', 'service-manager@rc-browser.invalid', 'staff', 'pos_manager', ['dashboard', 'service_jobs']);
$serviceDenied = $user($service, 'Synthetic Event Workflow Denied Waiter', 'service-denied@rc-browser.invalid', 'staff', 'pos_waiter');

$fiscal = $company('Synthetic RC FBR Retail', 'fiscal-company@rc-browser.invalid', 'RCBROWSER003', 'fbrpos', [
    'business_category' => 'retail', 'pos_type' => 'retail', 'fbr_pos_enabled' => true,
    'pos_setup_completed' => true,
]);
$fiscalUser = $user($fiscal, 'Synthetic FBR Retail Owner', 'fiscal@rc-browser.invalid', 'company_admin', 'pos_admin');

foreach (['pos', 'fbr_pos'] as $audience) {
    AppUpdate::create([
        'title' => "Synthetic {$audience} top navigation check",
        'points' => ['Browser fixture notification'],
        'audience' => $audience,
        'audience_family' => 'all',
        'type' => 'improvement',
        'is_published' => true,
    ]);
}

$health = $company('Synthetic Browser Health Clinic', 'health-company@rc-browser.invalid', 'RCBROWSER004', 'health', [
    'health_org_type' => 'clinic', 'health_modules' => HealthModuleService::MODULES,
    'health_setup_completed' => true,
]);
$healthBranch = DB::table('branches')->insertGetId([
    'company_id' => $health->id, 'name' => 'Synthetic Health Main Branch',
    'is_head_office' => true, 'is_active' => true, 'created_at' => $now, 'updated_at' => $now,
]);
$healthUser = $user($health, 'Synthetic Health Owner', 'health@rc-browser.invalid', 'company_admin', '', null, 'health_owner');
$healthRestrictedBranch = DB::table('branches')->insertGetId([
    'company_id' => $health->id, 'name' => 'Synthetic Health Restricted Branch',
    'is_active' => true, 'created_at' => $now, 'updated_at' => $now,
]);
$healthBranchUser = $user($health, 'Synthetic Health Branch Receptionist', 'health-branch@rc-browser.invalid', 'staff', '', null, 'health_receptionist');
DB::table('branch_user')->insert([
    'user_id' => $healthBranchUser->id, 'branch_id' => $healthBranch,
    'access_level' => 'full', 'created_at' => $now, 'updated_at' => $now,
]);
$healthOwnPatient = DB::table('health_patients')->insertGetId([
    'company_id' => $health->id, 'branch_id' => $healthBranch,
    'mrn' => 'RC-OWN-001', 'name' => 'Synthetic Own Branch Patient',
    'is_active' => true, 'created_at' => $now, 'updated_at' => $now,
]);
$healthOtherBranchPatient = DB::table('health_patients')->insertGetId([
    'company_id' => $health->id, 'branch_id' => $healthRestrictedBranch,
    'mrn' => 'RC-OTHER-BRANCH-001', 'name' => 'Synthetic Other Branch Patient',
    'is_active' => true, 'created_at' => $now, 'updated_at' => $now,
]);
$healthOther = $company('Synthetic Isolated Health Tenant', 'health-isolated-company@rc-browser.invalid', 'RCBROWSER005', 'health', [
    'health_org_type' => 'clinic', 'health_modules' => ['opd'], 'health_setup_completed' => true,
]);
$healthOtherBranch = DB::table('branches')->insertGetId([
    'company_id' => $healthOther->id, 'name' => 'Synthetic Isolated Branch',
    'is_head_office' => true, 'is_active' => true, 'created_at' => $now, 'updated_at' => $now,
]);
$healthForeignPatient = DB::table('health_patients')->insertGetId([
    'company_id' => $healthOther->id, 'branch_id' => $healthOtherBranch,
    'mrn' => 'RC-ISOLATED-001', 'name' => 'Synthetic Isolated Patient',
    'is_active' => true, 'created_at' => $now, 'updated_at' => $now,
]);
$categoryJourneys = [];
foreach (['pra' => 'pos', 'fbr' => 'fbrpos'] as $panel => $product) {
    foreach (array_merge(PosFeatureService::categories($panel), ['general']) as $index => $category) {
        $profileCompany = new Company(['business_category' => $category, 'pos_type' => $category, 'product_type' => $product]);
        $profile = PosFeatureService::profile($profileCompany);
        $landing = $profile['landing'];
        $path = $panel === 'fbr' ? '/fbr-pos/billing' : ($landing === 'hotel_front_desk' ? '/pos/hotel'
            : ($landing === 'service_work_orders' ? '/pos/work-orders' : '/pos/invoice/create'));
        $nativeMarker = $panel === 'fbr' ? 'FBR POS Plans' : ($landing === 'hotel_front_desk' ? 'Front Desk' : ($landing === 'service_work_orders' ? PosServiceWorkflowProfiles::forCompany($profileCompany)['noun'].' Board' : 'Current Order'));
        $companyCategory = $company("Synthetic {$panel} {$category}", "category-{$panel}-{$category}@rc-browser.invalid", 'RCB'.str_pad((string) (600 + count($categoryJourneys)), 8, '0', STR_PAD_LEFT), $product, [
            'business_category' => $category, 'pos_type' => $category,
            'feature_flags' => PosFeatureService::defaultsForCategory($category),
            'fbr_pos_enabled' => $panel === 'fbr', 'pos_module_extras' => [], 'pharmacy_mode' => $category === 'pharmacy', 'pos_setup_completed' => true,
            'pos_integration_mode' => $panel === 'fbr' ? 'fbr' : 'pra',
        ]);
        $categoryUser = $user($companyCategory, "Synthetic {$category} owner", "category-user-{$panel}-{$category}@rc-browser.invalid", 'company_admin', 'pos_admin');
        $categoryJourneys[] = [
            'name' => "category-{$panel}-{$category}", 'login' => $categoryUser->email, 'password' => $password,
            'loginPath' => $panel === 'fbr' ? '/fbr-pos/login' : '/pos/login', 'paths' => [$path],
            'expectedPaths' => [$path], 'markers' => [$nativeMarker], 'mainMarkers' => [$nativeMarker],
            'categoryCoverage' => ['panel' => $panel, 'category' => $category, 'landing' => $landing,
                'mismatchPath' => $panel === 'fbr' ? ($category === 'salon' ? '/fbr-pos/stock' : ($category === 'general' ? null : '/fbr-pos/services')) : ($landing === 'service_work_orders' ? '/pos/hotel' : '/pos/work-orders'),
                'sameProductPositivePath' => $panel === 'fbr' ? ($category === 'pharmacy' ? '/fbr-pos/pharmacy/batches' : ($category === 'salon' ? '/fbr-pos/services' : '/fbr-pos/stock')) : null],
        ];
    }
}
$admin = AdminUser::create([
    'name' => 'Synthetic RC Platform Administrator', 'email' => 'hotel-admin-manage-as@rc-browser.invalid',
    'password' => Hash::make($password), 'role' => 'super_admin',
]);
$expectedCategoryEmails = [];
foreach (['pra', 'fbr'] as $panel) {
    foreach (array_merge(PosFeatureService::categories($panel), ['general']) as $category) {
        $expectedCategoryEmails[] = "category-user-{$panel}-{$category}@rc-browser.invalid";
    }
}
if (User::withoutGlobalScopes()->whereIn('email', $expectedCategoryEmails)->count() !== count($expectedCategoryEmails)) {
    $fail('fresh category actor matrix was not persisted.');
}

$fixture = [
    'generated_at' => $now->toIso8601String(), 'synthetic' => true,
    'readOnlyJourneys' => array_merge([
        ['name' => 'hotel-owner', 'login' => $hotelOwner->email, 'password' => $password, 'loginPath' => '/pos/login', 'paths' => ['/pos/hotel'], 'markers' => ['Front Desk']],
        ['name' => 'pra-topnav', 'login' => $hotelOwner->email, 'password' => $password, 'loginPath' => '/pos/login', 'paths' => ['/pos/invoice/create'], 'markers' => ['Current Order'], 'mainMarkers' => ['Current Order'], 'topNavPanel' => 'pra', 'topNavFactory' => 'restaurantPos'],
        ['name' => 'pra-admin-view-topnav', 'login' => $admin->email, 'password' => $password, 'loginPath' => '/admin/login', 'submitPath' => "/admin/companies/{$hotel->id}", 'submitSelector' => 'form[action$="/impersonate"]:has(input[name="mode"][value="view"])', 'paths' => ['/pos/invoice/create'], 'markers' => ['Current Order'], 'mainMarkers' => ['Current Order'], 'topNavPanel' => 'pra', 'topNavFactory' => 'restaurantPos'],
        ['name' => 'pra-admin-manage-topnav', 'login' => $admin->email, 'password' => $password, 'loginPath' => '/admin/login', 'submitPath' => "/admin/companies/{$hotel->id}", 'submitSelector' => 'form[action$="/impersonate"]:has(input[name="mode"][value="full"])', 'paths' => ['/pos/invoice/create'], 'markers' => ['Current Order'], 'mainMarkers' => ['Current Order'], 'topNavPanel' => 'pra', 'topNavFactory' => 'restaurantPos'],
        ['name' => 'hotel-manager', 'login' => $hotelManager->email, 'password' => $password, 'loginPath' => '/pos/login', 'paths' => ['/pos/hotel/rooms'], 'markers' => ['Rooms']],
        ['name' => 'hotel-housekeeping', 'login' => $hotelHousekeeping->email, 'password' => $password, 'loginPath' => '/pos/login', 'paths' => ['/pos/hotel/housekeeping'], 'markers' => ['Housekeeping']],
        ['name' => 'hotel-outlet', 'login' => $hotelOutlet->email, 'password' => $password, 'loginPath' => '/pos/login', 'paths' => ['/pos/hotel/restaurant'], 'markers' => ['Current Order'], 'allowRedirectTo' => '/pos/invoice/create'],
        ['name' => 'hotel-admin-manage-as', 'login' => $admin->email, 'password' => $password, 'loginPath' => '/admin/login', 'submitPath' => "/admin/companies/{$hotel->id}", 'submitSelector' => 'form[action$="/impersonate"]:has(input[name="mode"][value="full"])', 'paths' => ['/pos/hotel'], 'markers' => ['Front Desk']],
        ['name' => 'service-work-orders-manager', 'login' => $serviceManager->email, 'password' => $password, 'loginPath' => '/pos/login', 'paths' => ['/pos/work-orders', '/pos/work-orders/report.csv'], 'markers' => ['Event Plan Board'], 'usableSelectors' => ['a[href$="/pos/work-orders/create"]']],
        ['name' => 'health', 'login' => $healthUser->email, 'password' => $password, 'loginPath' => '/health/login', 'paths' => ['/health/dashboard'], 'markers' => ['Synthetic Browser Health Clinic']],
        ['name' => 'fiscal', 'login' => $fiscalUser->email, 'password' => $password, 'loginPath' => '/fbr-pos/login', 'paths' => ['/fbr-pos/create'], 'markers' => ['Current Order'], 'mainMarkers' => ['Current Order'], 'usableSelectors' => ['input[name="pos_product_search_nofill"]'], 'topNavPanel' => 'fbr', 'topNavFactory' => 'restaurantPos'],
        ['name' => 'fiscal-admin-view-topnav', 'login' => $admin->email, 'password' => $password, 'loginPath' => '/admin/login', 'submitPath' => "/admin/companies/{$fiscal->id}", 'submitSelector' => 'form[action$="/impersonate"]:has(input[name="mode"][value="view"])', 'paths' => ['/fbr-pos/create'], 'markers' => ['Current Order'], 'mainMarkers' => ['Current Order'], 'topNavPanel' => 'fbr', 'topNavFactory' => 'restaurantPos'],
        ['name' => 'fiscal-admin-manage-topnav', 'login' => $admin->email, 'password' => $password, 'loginPath' => '/admin/login', 'submitPath' => "/admin/companies/{$fiscal->id}", 'submitSelector' => 'form[action$="/impersonate"]:has(input[name="mode"][value="full"])', 'paths' => ['/fbr-pos/create'], 'markers' => ['Current Order'], 'mainMarkers' => ['Current Order'], 'topNavPanel' => 'fbr', 'topNavFactory' => 'restaurantPos'],
        ['name' => 'hotel-denied', 'login' => $hotelDenied->email, 'password' => $password, 'loginPath' => '/pos/login', 'paths' => ['/pos/hotel', '/pos/hotel/restaurant'], 'denied' => true],
        ['name' => 'service-work-orders-denied', 'login' => $serviceDenied->email, 'password' => $password, 'loginPath' => '/pos/login', 'paths' => ['/pos/work-orders', '/pos/work-orders/report.csv'], 'denied' => true],
    ], $categoryJourneys),
    'transactionalJourneys' => [[
        'name' => 'service-work-orders', 'login' => $serviceWorker->email, 'password' => $password,
        'loginPath' => '/pos/login', 'paths' => ['/pos/work-orders'], 'markers' => ['Event Plan Board'],
        'serviceWorkflow' => [
            'createPath' => '/pos/work-orders/create', 'customerName' => 'Synthetic Workflow Customer',
            'title' => 'Synthetic Browser Event', 'scheduledAt' => '2030-01-01T10:00',
            'quantity' => 1, 'unitPrice' => 500,
            'details' => ['event' => 'Synthetic event', 'venue' => 'Synthetic venue', 'guest_count' => '10'],
            'transitions' => ['brief_confirmed', 'planned', 'in_progress', 'event_complete', 'closed'],
            'invoiceMarker' => 'Fiscal sale',
        ],
    ]],
    'isolation' => [
        'healthCompanyId' => $health->id, 'healthBranchId' => $healthBranch,
        'foreignHealthCompanyId' => $healthOther->id, 'foreignHealthBranchId' => $healthOtherBranch,
        'branchUser' => ['login' => $healthBranchUser->email, 'password' => $password, 'loginPath' => '/health/login'],
        'ownPatient' => ['id' => $healthOwnPatient, 'identifier' => 'RC-OWN-001'],
        'otherBranchPatient' => ['id' => $healthOtherBranchPatient, 'identifier' => 'RC-OTHER-BRANCH-001'],
        'foreignTenantPatient' => ['id' => $healthForeignPatient, 'identifier' => 'RC-ISOLATED-001'],
    ],
];
$temporary = "{$fixturePath}.tmp";
if (file_put_contents($temporary, json_encode($fixture, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR).PHP_EOL, LOCK_EX) === false
    || !rename($temporary, $fixturePath)) {
    $fail('fixture write failed.');
}
chmod($fixturePath, 0600);
fwrite(STDOUT, "RC browser fresh synthetic fixture ready.\n");
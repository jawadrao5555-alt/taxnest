<?php
declare(strict_types=1);

use App\Models\AdminUser;
use App\Models\AppUpdate;
use App\Models\Company;
use App\Models\HotelRoom;
use App\Models\RestaurantFloor;
use App\Models\RestaurantOrder;
use App\Models\RestaurantOrderItem;
use App\Models\RestaurantTable;
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
foreach (['RC-201', 'RC-202'] as $number) {
    HotelRoom::withoutGlobalScopes()->create([
        'company_id' => $hotel->id, 'branch_id' => $hotelBranch, 'room_number' => $number,
        'room_type' => 'Synthetic Simple Desk', 'capacity' => 2, 'rate_amount' => 5000,
        'rate_unit' => 'NGT', 'housekeeping' => 'clean', 'is_active' => true,
    ]);
}
foreach ([
    'RC-INACTIVE' => ['is_active' => false],
    'RC-OUT' => ['service_state' => HotelRoom::SERVICE_OUT],
    'RC-DIRTY' => ['housekeeping' => HotelRoom::HK_DIRTY],
] as $number => $state) {
    HotelRoom::withoutGlobalScopes()->create(array_merge([
        'company_id' => $hotel->id, 'branch_id' => $hotelBranch, 'room_number' => $number,
        'room_type' => 'Synthetic Blocked Room', 'capacity' => 2, 'rate_amount' => 5000,
        'rate_unit' => 'NGT', 'housekeeping' => HotelRoom::HK_CLEAN,
        'service_state' => HotelRoom::SERVICE_IN, 'is_active' => true,
    ], $state));
}
$settingsHotel = $company('Synthetic Rooms Only Settings', 'settings-hotel@rc-browser.invalid', 'RCBROWSERSETTINGS', 'pos', [
    'business_category' => 'hotel', 'pos_type' => 'hotel',
    'feature_flags' => PosFeatureService::defaultsForCategory('hotel'),
    'restaurant_mode' => false, 'pos_integration_mode' => 'pra', 'pos_setup_completed' => true,
    'pos_theme' => 'blue', 'pos_tax_rate_cash' => 0, 'pos_tax_rate_card' => 0,
]);
$settingsOwner = $user($settingsHotel, 'Synthetic Hotel Settings Owner', 'hotel-settings@rc-browser.invalid', 'company_admin', 'pos_admin');
$waShop = $company('Synthetic WhatsApp Auto Send', 'wa-auto-company@rc-browser.invalid', 'RCBROWSERWA', 'pos', [
    'business_category' => 'restaurant', 'pos_type' => 'restaurant', 'pos_setup_completed' => true,
    'feature_flags' => PosFeatureService::defaultsForCategory('restaurant'), 'pos_whatsapp_bill_enabled' => true,
]);
$waShop->forceFill(['wa_api_enabled' => true, 'wa_phone_number_id' => '123456789',
    'wa_api_token' => \Illuminate\Support\Facades\Crypt::encryptString('synthetic-api-token'),
    'wa_template_name' => 'invoice_notification', 'wa_app_secret' => 'synthetic-app-secret', 'wa_attach_pdf' => true])->save();
$waOwner = $user($waShop, 'Synthetic WhatsApp Owner', 'wa-auto-owner@rc-browser.invalid', 'company_admin', 'pos_admin');
$tableShop = $company('Synthetic Table Order Recovery', 'table-orders-company@rc-browser.invalid', 'RCBROWSERTABLES', 'pos', [
    'business_category' => 'restaurant', 'pos_type' => 'restaurant',
    'feature_flags' => PosFeatureService::defaultsForCategory('restaurant'),
    'restaurant_mode' => true, 'pos_integration_mode' => 'pra', 'pos_setup_completed' => true,
    'table_click_direct_open' => true, 'pos_guided_flow_enabled' => false,
    'pos_tax_rate_cash' => 0, 'pos_tax_rate_card' => 0,
]);
// The real invoice-store route enforces subscription quota even for internal fixtures.
// Give only this synthetic shop a normal, active, bounded package; do not bypass the gate.
$addressProofPlan = \App\Models\PricingPlan::create([
    'name' => 'Synthetic delivery address proof', 'product_type' => 'pos',
    'invoice_limit' => 100, 'price' => 100, 'is_trial' => false,
]);
\App\Models\Subscription::create([
    'company_id' => $tableShop->id, 'pricing_plan_id' => $addressProofPlan->id,
    'start_date' => now()->subDay()->toDateString(), 'end_date' => now()->addDays(30)->toDateString(),
    'active' => true,
]);
$tableCashier = $user($tableShop, 'Synthetic Table Cashier', 'table-orders@rc-browser.invalid', 'user', 'pos_cashier');
$tableWaiter = $user($tableShop, 'Synthetic Table Waiter', 'table-waiter@rc-browser.invalid', 'user', 'pos_waiter');
$tableFloor = RestaurantFloor::create(['company_id' => $tableShop->id, 'name' => 'Synthetic Table Floor', 'is_active' => true]);
$tableCases = [];
$handoffCases = [];
$tableOwner = $user($tableShop, 'Synthetic Table Owner', 'table-owner@rc-browser.invalid', 'company_admin', 'pos_admin');
$riderLiveToken = 'rider-realtime-fixture-' . \Illuminate\Support\Str::random(32);
$riderLive = \App\Models\PosRider::create(['company_id' => $tableShop->id, 'name' => 'Synthetic Live Rider',
    'is_active' => true, 'on_duty' => true, 'duty_started_at' => $now, 'app_token' => hash('sha256', $riderLiveToken),
    'last_lat' => 31.51, 'last_lng' => 74.31, 'last_located_at' => $now->copy()->subSeconds(30)]);
$riderLiveToken = $riderLive->id . '|' . $riderLiveToken;
$riderLive->update(['app_token' => hash('sha256', $riderLiveToken)]);
$tableManager = $user($tableShop, 'Synthetic Transfer Manager', 'table-manager@rc-browser.invalid', 'user', 'pos_manager');
// Separate fictional companies keep read-only phone-search proof independent of
// transactional table/rider journeys and exercise the real company-scoped routes.
$contactShop = $company('Synthetic Contact Search', 'contact-company@rc-browser.invalid', 'RCBROWSERCONTACT', 'pos', [
    'business_category' => 'restaurant', 'pos_type' => 'restaurant', 'restaurant_mode' => true,
    'feature_flags' => PosFeatureService::defaultsForCategory('restaurant'),
    'pos_integration_mode' => 'pra', 'pos_setup_completed' => true, 'pos_guided_flow_enabled' => false,
    'pos_tax_rate_cash' => 0, 'pos_tax_rate_card' => 0,
]);
$contactOwner = $user($contactShop, 'Synthetic Contact Owner', 'contact-owner@rc-browser.invalid', 'company_admin', 'pos_admin');
$contactForeign = $company('Synthetic Foreign Contact', 'foreign-contact@rc-browser.invalid', 'RCBROWSERCONTACTX', 'pos');
$contactDay = \App\Services\PosBusinessDay::current($contactShop->id);
$contactOldDay = \Illuminate\Support\Carbon::parse($contactDay)->subDays(2)->toDateString();
// This historical fixture is already closed; retain the real pending-day guard.
\App\Models\PosDayCloseReport::forceCreate([
    'company_id' => $contactShop->id, 'branch_id' => 0, 'report_date' => $contactOldDay,
    'business_date' => $contactOldDay, 'report_number' => 'RC-CONTACT-CLOSED', 'closed_by' => $contactOwner->id,
]);
$contactCases = [
    ['RC-CONTACT-A', 'Synthetic Contact A', '0300-123 4567', $contactDay, $contactShop->id],
    ['RC-CONTACT-B', 'Synthetic Contact B', '0311 987-6543', $contactDay, $contactShop->id],
    ['RC-CONTACT-OLD', 'Synthetic Contact Old', '0300 123-4567', $contactOldDay, $contactShop->id],
    ['RC-CONTACT-FOREIGN', 'Synthetic Foreign Contact Bill', '0300-123 4567', $contactDay, $contactForeign->id],
];
foreach ($contactCases as [$number, $name, $phone, $day, $companyId]) {
    \App\Models\PosTransaction::forceCreate([
        'company_id' => $companyId, 'invoice_number' => $number, 'customer_name' => $name, 'customer_phone' => $phone,
        'delivery_address' => 'Synthetic Garden Road', 'business_date' => $day,
        'created_at' => $now, 'updated_at' => $now, 'created_by' => $companyId === $contactShop->id ? $contactOwner->id : null,
        'order_type' => 'delivery', 'status' => 'completed', 'invoice_mode' => 'pra', 'pra_status' => 'failed',
        'payment_method' => 'cash', 'subtotal' => 100, 'total_amount' => 100, 'tax_rate' => 0, 'tax_amount' => 0,
    ]);
}
$printEvidenceShop = $company('Synthetic Print Evidence', 'print-evidence@rc-browser.invalid', 'RCBROWSERPRINT', 'pos', [
    'business_category' => 'restaurant', 'pos_type' => 'restaurant', 'restaurant_mode' => true,
    'feature_flags' => PosFeatureService::defaultsForCategory('restaurant'), 'pos_integration_mode' => 'pra', 'pos_setup_completed' => true,
]);
$printEvidenceOwner = $user($printEvidenceShop, 'Synthetic Print Owner', 'print-evidence-owner@rc-browser.invalid', 'company_admin', 'pos_admin');
\App\Models\PosPrintJob::forceCreate(['company_id' => $printEvidenceShop->id, 'type' => 'proof',
    'target_printer' => 'Synthetic delayed queue', 'status' => 'pending', 'created_at' => $now->copy()->subHours(3)]);
\App\Models\PosPrintJob::forceCreate(['company_id' => $printEvidenceShop->id, 'type' => 'proof',
    'target_printer' => 'Synthetic no-document queue', 'status' => 'done', 'result_outcome' => 'no_document',
    'no_document_reason' => 'station_has_no_eligible_lines', 'result_received_at' => $now]);
\App\Models\PosPrintJob::forceCreate(['company_id' => $printEvidenceShop->id, 'type' => 'proof',
    'target_printer' => 'Synthetic Windows queue', 'status' => 'done', 'result_outcome' => 'spool_accepted', 'result_received_at' => $now]);
foreach (['held', 'preparing', 'ready'] as $index => $status) {
    $table = RestaurantTable::create([
        'company_id' => $tableShop->id, 'floor_id' => $tableFloor->id,
        'table_number' => 'RC-'.($index + 1), 'seats' => 4, 'status' => 'occupied',
        'occupied_since' => $now, 'is_active' => true,
    ]);
    $order = RestaurantOrder::create([
        'company_id' => $tableShop->id, 'table_id' => $table->id,
        'order_number' => 'RC-TABLE-'.($index + 1), 'order_type' => 'dine_in',
        'source' => 'waiter', 'status' => $status, 'created_by' => $tableWaiter->id,
        'subtotal' => 500, 'total_amount' => 500,
    ]);
    RestaurantOrderItem::create([
        'order_id' => $order->id, 'item_type' => 'manual', 'item_name' => 'Synthetic '.$status.' dish',
        'quantity' => 1, 'unit_price' => 500, 'subtotal' => 500,
    ]);
    $tableCases[] = ['tableId' => $table->id, 'number' => $table->table_number,
        'orderId' => $order->id, 'itemName' => 'Synthetic '.$status.' dish', 'status' => $status];
}
foreach (['desktop', 'mobile'] as $viewport) {
    $table = RestaurantTable::create(['company_id' => $tableShop->id, 'floor_id' => $tableFloor->id,
        'table_number' => 'HANDOFF-'.$viewport, 'seats' => 4, 'status' => 'occupied', 'occupied_since' => $now, 'is_active' => true]);
    $order = RestaurantOrder::create(['company_id' => $tableShop->id, 'table_id' => $table->id,
        'order_number' => 'RC-HANDOFF-'.$viewport, 'order_type' => 'dine_in', 'source' => 'waiter',
        'status' => 'held', 'created_by' => $tableWaiter->id, 'assigned_cashier_id' => $tableOwner->id,
        'subtotal' => 500, 'total_amount' => 500]);
    RestaurantOrderItem::create(['order_id' => $order->id, 'item_type' => 'manual',
        'item_name' => 'Synthetic handoff '.$viewport, 'quantity' => 1, 'unit_price' => 500, 'subtotal' => 500]);
    $handoffCases[$viewport] = ['tableId' => $table->id, 'number' => $table->table_number, 'orderId' => $order->id,
        'itemName' => 'Synthetic handoff '.$viewport];
}
// Repeated dish rows reproduce delete -> CANCEL plus duplicate ADD, separately per viewport.
$kotDeleteCases = [];
foreach (['desktop', 'mobile'] as $viewport) {
    $table = RestaurantTable::create(['company_id' => $tableShop->id, 'floor_id' => $tableFloor->id,
        'table_number' => 'KOT-DELETE-'.$viewport, 'seats' => 4, 'status' => 'occupied', 'occupied_since' => $now, 'is_active' => true]);
    $order = RestaurantOrder::create(['company_id' => $tableShop->id, 'table_id' => $table->id,
        'order_number' => 'RC-KOT-DELETE-'.$viewport, 'order_type' => 'dine_in', 'source' => 'waiter',
        'status' => 'held', 'created_by' => $tableWaiter->id, 'subtotal' => 600, 'total_amount' => 600,
        'kot_sent_at' => $now]);
    $lineIds = [];
    foreach ([1, 2, 3] as $qty) {
        $lineIds[] = RestaurantOrderItem::create(['order_id' => $order->id, 'item_type' => 'manual',
            'item_name' => 'Synthetic repeated dish', 'quantity' => $qty, 'unit_price' => 100,
            'subtotal' => $qty * 100, 'special_notes' => 'same notes', 'kot_printed_at' => $now])->id;
    }
    $kotDeleteCases[$viewport] = ['number' => $table->table_number, 'orderId' => $order->id, 'lineIds' => $lineIds];
}
$hotelOwner = $user($hotel, 'Synthetic Hotel Owner', 'hotel-owner@rc-browser.invalid', 'company_admin', 'pos_admin');
$hotelManager = $user($hotel, 'Synthetic Hotel Front Desk Manager', 'hotel-manager@rc-browser.invalid', 'staff', 'pos_manager', ['dashboard', 'hotel', 'hotel_housekeeping']);
$hotelManager->forceFill(['default_branch_id' => $hotelBranch])->save();
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
// Fictional directory rows expose every status and the unreadable offline alert.
foreach (['approved', 'active', 'pending', 'suspended', 'rejected'] as $n => $status) {
    $company('Synthetic Contrast '.$status, 'contrast-'.$status.'@rc-browser.invalid', '990090'.$n,
        ['pos', 'di', 'fbrpos', 'pos', 'di'][$n], [
            'owner_name' => 'Synthetic Contrast Owner', 'status' => $status,
            'agent_enabled' => $status === 'approved', 'agent_last_seen' => $now->copy()->subHours(4),
            'agent_version' => '1.0.0', 'pos_printer_settings' => ['silent_print_enabled' => true],
        ]);
}
$fiscal->forceFill(['fbr_sales_tax_registration_number'=>'1234567890123','fbr_tax_office'=>'Fictional tax office','fbr_pos_id'=>'1010',
 'address'=>'Fictional fiscal outlet','invoice_display_prefs'=>['fbrpos'=>['show_tax'=>false,'show_verify_line'=>false,'show_address'=>false,'show_ntn'=>false]]])->save();
$fiscalReceipt = \App\Models\FbrPosTransaction::create(['company_id'=>$fiscal->id,'invoice_number'=>'RC-FISCAL-RECEIPT','invoice_mode'=>'fbr',
 'fbr_status'=>'submitted','fbr_invoice_number'=>'9000000000001','status'=>'completed','subtotal'=>1000,'total_amount'=>1180,'tax_rate'=>18,'tax_amount'=>180,'payment_method'=>'card','created_by'=>$fiscalUser->id]);
$fiscalReceipt->items()->create(['item_name'=>'Synthetic fiscal goods','quantity'=>1,'unit_price'=>1000,'subtotal'=>1000,'total'=>1180,'tax_rate'=>18,'tax_amount'=>180]);
$reconcileCases = [];
foreach ([1366, 390] as $width) {
    foreach (['accepted', 'not_accepted'] as $decision) {
        $bill = \App\Models\FbrPosTransaction::withoutGlobalScopes()->create([
            'company_id' => $fiscal->id, 'invoice_number' => 'RC-RECONCILE-'.$width.'-'.$decision,
            'invoice_mode' => 'fbr', 'fbr_status' => 'verification_pending', 'status' => 'completed',
            'subtotal' => 1000, 'tax_amount' => 180, 'total_amount' => 1180,
            'payment_method' => 'cash', 'created_by' => $fiscalUser->id,
        ]);
        $reconcileCases[$width][$decision] = $bill->id;
    }
}

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

$topSellingCompany = $company('Synthetic Top Selling', 'top-selling-company@rc-browser.invalid', 'RCTOPSELL001', 'pos', [
    'business_category' => 'restaurant', 'pos_type' => 'restaurant', 'restaurant_mode' => true,
    'feature_flags' => PosFeatureService::defaultsForCategory('restaurant'), 'pos_setup_completed' => true,
]);
$topSellingOwner = $user($topSellingCompany, 'Synthetic Top Selling Owner', 'top-selling-owner@rc-browser.invalid', 'company_admin', 'pos_admin');
$topSellingOrder = RestaurantOrder::create(['company_id' => $topSellingCompany->id, 'order_number' => 'RC-TOP-ORDER', 'order_type' => 'takeaway', 'status' => 'completed', 'created_by' => $topSellingOwner->id, 'subtotal' => 13600, 'total_amount' => 13600]);
for ($i = 1; $i <= 16; $i++) {
    RestaurantOrderItem::create(['order_id' => $topSellingOrder->id, 'item_type' => 'manual', 'item_name' => 'Synthetic complete item '.$i, 'quantity' => $i, 'unit_price' => 100, 'subtotal' => $i * 100]);
}
$notificationDi = $company('Synthetic Notification DI', 'notification-di@rc-browser.invalid', 'RCNOTICEDI', 'di', ['sector_type' => 'Retail']);
$notificationUsers = [];
foreach (['pra' => $hotel, 'fbr' => $fiscal, 'di' => $notificationDi] as $panel => $noticeCompany) {
    foreach (['desktop', 'mobile', 'preflight-desktop', 'preflight-mobile'] as $viewport) {
        $account = $user($noticeCompany, 'Synthetic Notification '.$panel.' '.$viewport,
            'notice-'.$panel.'-'.$viewport.'@rc-browser.invalid', 'company_admin', 'pos_admin');
        $account->forceFill(['pra_elaan_seen_at' => now()])->save();
        $notificationUsers[$panel][$viewport] = $account->email;
    }
    for ($i = 1; $i <= 7; $i++) {
        AppUpdate::create([
            'title' => 'Synthetic routine '.$panel.' '.$i, 'points' => $i === 1
                ? ['Reception notice', 'Build date packaging se aye gi; metadata na ho to unavailable bataya jaye ga.']
                : ['Reception notice'],
            'audience' => $panel === 'di' ? 'di' : ($panel === 'fbr' ? 'fbr_pos' : 'pos'),
            'target_categories' => [$panel === 'di' ? 'di_retail' : ($panel === 'fbr' ? 'retail' : 'hotel')],
            'is_published' => true, 'is_featured' => false,
        ]);
    }
    if ($panel === 'di') continue;
    foreach (['a', 'b'] as $receipt) {
        AppUpdate::create([
            'title' => 'Synthetic featured '.$panel, 'points' => ['Important reception notice',
                'Build date packaging se aye gi; metadata na ho to unavailable bataya jaye ga.'],
            'audience' => $panel === 'di' ? 'di' : ($panel === 'fbr' ? 'fbr_pos' : 'pos'),
            'target_categories' => [$panel === 'di' ? 'di_retail' : ($panel === 'fbr' ? 'retail' : 'hotel')],
            'is_published' => true, 'is_featured' => true,
            'deployment_key' => sha1('synthetic-notification-'.$panel.'-'.$receipt),
        ]);
    }
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
        $nativeMarker = $panel === 'fbr' ? 'FBR POS Plans' : ($landing === 'hotel_front_desk' ? 'Hotel front desk' : ($landing === 'service_work_orders' ? PosServiceWorkflowProfiles::forCompany($profileCompany)['noun'].' Board' : 'Current Order'));
        $companyCategory = $company("Synthetic {$panel} {$category}", "category-{$panel}-{$category}@rc-browser.invalid", 'RCB'.str_pad((string) (600 + count($categoryJourneys)), 8, '0', STR_PAD_LEFT), $product, [
            'business_category' => $category, 'pos_type' => $category,
            'feature_flags' => PosFeatureService::defaultsForCategory($category),
            'inventory_enabled' => $panel === 'pra' && $category === 'restaurant',
            'fbr_pos_enabled' => $panel === 'fbr', 'pos_module_extras' => [], 'pharmacy_mode' => $category === 'pharmacy', 'pos_setup_completed' => true,
            'pos_integration_mode' => $panel === 'fbr' ? 'fbr' : 'pra',
        ]);
        $categoryUser = $user($companyCategory, "Synthetic {$category} owner", "category-user-{$panel}-{$category}@rc-browser.invalid", 'company_admin', 'pos_admin');
        if ($panel === 'pra' && $category === 'restaurant') {
            $inventoryOwner = $categoryUser;
        }
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

 // Synthetic customer amounts: seed persisted snapshots, never submit to PRA.
$amountCompany = $company('Synthetic Customer Amount Audit', 'amount-company@rc-browser.invalid', 'RCBAMOUNT01', 'pos', [
    'business_category' => 'restaurant', 'pos_type' => 'restaurant',
    'feature_flags' => PosFeatureService::defaultsForCategory('restaurant'),
    'restaurant_mode' => true, 'pos_integration_mode' => 'pra', 'pos_setup_completed' => true,
    'pra_reporting_enabled' => false, 'pos_tax_rate_cash' => 0, 'pos_tax_rate_card' => 0,
]);
$amountOwner = $user($amountCompany, 'Synthetic Amount Owner', 'amount-owner@rc-browser.invalid', 'company_admin', 'pos_admin');
$amountCustomer = \App\Models\PosCustomer::create([
    'company_id' => $amountCompany->id, 'name' => 'Synthetic Amount Customer', 'phone' => '00000000111',
]);
$amountCases = [];
foreach ([['AMOUNT-QR-316', 'qr_payment', 14, 316], ['AMOUNT-CASH-330', 'cash', 0, 330]] as [$number, $method, $discount, $payable]) {
    $amountBill = \App\Models\PosTransaction::create([
        'company_id' => $amountCompany->id, 'created_by' => $amountOwner->id,
        'customer_id' => $amountCustomer->id, 'customer_name' => $amountCustomer->name,
        'customer_phone' => $amountCustomer->phone, 'invoice_number' => $number,
        'invoice_mode' => 'local', 'status' => 'completed', 'transaction_type' => 'sale',
        'subtotal' => 330, 'discount_amount' => $discount, 'tax_rate' => 0, 'tax_amount' => 0,
        'total_amount' => $payable, 'tax_inclusive' => false, 'payment_method' => $method,
    ]);
    \App\Models\PosTransactionItem::create([
        'transaction_id' => $amountBill->id, 'item_type' => 'manual', 'item_name' => 'Synthetic Menu Item',
        'quantity' => 1, 'unit_price' => 330, 'subtotal' => 330, 'tax_rate' => 0, 'tax_amount' => 0,
    ]);
    $amountCases[] = ['id' => $amountBill->id, 'invoice' => $number, 'payable' => $payable];
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

// Dedicated fictional accepted invoice; no external regulator request.
$creditCompany = $company('Synthetic Credit Review Hotel', 'credit-hotel@rc-browser.invalid', 'RCBRCREDIT01', 'pos', [
    'business_category' => 'hotel', 'pos_type' => 'hotel', 'feature_flags' => PosFeatureService::defaultsForCategory('hotel'),
    'pos_integration_mode' => 'pra', 'pos_setup_completed' => true, 'pra_reporting_enabled' => false,
    'pos_tax_rate_cash' => 0, 'pos_tax_rate_card' => 0,
]);
$creditOwner = $user($creditCompany, 'Synthetic Credit Review Owner', 'credit-owner@rc-browser.invalid', 'company_admin', 'pos_admin');
$creditOwner->update(['pra_reporting_enabled' => false]);
$creditStays = app(\App\Services\HotelStayService::class);
$creditRoom = $creditStays->createRoom((int) $creditCompany->id, ['room_number' => 'CREDIT-1', 'room_type' => 'Standard',
    'capacity' => 2, 'rate_amount' => 1000, 'rate_unit' => 'NGT', 'branch_id' => null]);
$creditStay = $creditStays->book((int) $creditCompany->id, (int) $creditOwner->id, [
    'room_id' => $creditRoom->id, 'check_in_date' => $now->toDateString(), 'check_out_date' => $now->copy()->addDays(2)->toDateString(),
    'guest_name' => 'Synthetic credit review guest', 'walk_in' => true,
]);
$creditFolio = app(\App\Services\HotelFolioService::class);
$creditFolio->postPayment($creditStay, ['amount' => 2000, 'payment_method' => 'cash'], (int) $creditOwner->id);
$creditBill = $creditFolio->settleCoveredCharges($creditStay, (int) $creditOwner->id, 'cash')['transaction'];
$creditBill->update(['invoice_mode' => 'pra', 'pra_status' => 'submitted', 'pra_invoice_number' => 'SYNTHETIC-CREDIT-ORIGINAL']);

// Separate, explicitly synthetic accepted credit for refund form rendering only.
// No API call is made and no real acceptance is represented by this fixture.
$refundRoom = $creditStays->createRoom((int) $creditCompany->id, [
    'room_number' => 'CREDIT-REFUND', 'room_type' => 'Standard', 'capacity' => 2,
    'rate_amount' => 1000, 'rate_unit' => 'NGT', 'branch_id' => null,
]);
$refundStay = $creditStays->book((int) $creditCompany->id, (int) $creditOwner->id, [
    'room_id' => $refundRoom->id, 'check_in_date' => $now->toDateString(),
    'check_out_date' => $now->copy()->addDays(2)->toDateString(),
    'guest_name' => 'Synthetic accepted credit guest', 'walk_in' => true,
]);
$creditFolio->postPayment($refundStay, ['amount' => 2000, 'payment_method' => 'cash'], (int) $creditOwner->id);
$refundOriginal = $creditFolio->settleCoveredCharges($refundStay, (int) $creditOwner->id, 'cash')['transaction'];
$refundOriginal->update(['invoice_mode' => 'pra', 'pra_status' => 'submitted', 'pra_invoice_number' => 'SYNTHETIC-REFUND-ORIGINAL']);
$acceptedCredit = $refundOriginal->replicate();
$acceptedCredit->fill([
    'invoice_number' => 'SYNTHETIC-ACCEPTED-CREDIT', 'parent_transaction_id' => $refundOriginal->id,
    'transaction_type' => 'return', 'total_amount' => 1000, 'subtotal' => 1000,
    'payment_method' => 'hotel_credit_note', 'cash_received' => 0, 'change_due' => 0,
    'pra_status' => 'submitted', 'pra_invoice_number' => 'SYNTHETIC-ACCEPTED-CREDIT-FISCAL',
]);
$acceptedCredit->save();
// Link the fictional credit to its folio so the actual protected Print route is exercised.
$refundCharge = \App\Models\HotelFolioEntry::where('stay_id', $refundStay->id)->where('entry_type', 'charge')->firstOrFail();
$refundAdjustment = $refundCharge->replicate();
$refundAdjustment->fill([
    'entry_type' => 'adjustment', 'pos_transaction_id' => $acceptedCredit->id,
    'reverses_entry_id' => $refundCharge->id, 'description' => 'Synthetic accepted credit for receipt rendering',
    'quantity' => 1, 'unit_amount' => -1000, 'amount' => -1000, 'gross_amount' => -1000,
]);
$refundAdjustment->save();
\App\Models\HotelCreditNote::create([
    'company_id' => $creditCompany->id, 'stay_id' => $refundStay->id,
    'original_transaction_id' => $refundOriginal->id, 'credit_transaction_id' => $acceptedCredit->id,
    'created_by' => $creditOwner->id, 'request_key' => 'synthetic-browser-refund-001',
    'reason' => 'Synthetic accepted-credit form fixture', 'selection' => null,
]);


 // Separate unused rows make each viewport repeat the original pcs-to-kg edit.
$unitFlags = array_merge(PosFeatureService::defaultsForCategory('restaurant'), ['recipes' => true]);
$unitCompany = $company('Synthetic Ingredient Unit Audit', 'unit-company@rc-browser.invalid', 'RCBUNIT01', 'pos', [
    'business_category' => 'restaurant', 'pos_type' => 'restaurant', 'feature_flags' => $unitFlags,
    'restaurant_mode' => true, 'pos_integration_mode' => 'pra', 'pos_setup_completed' => true,
    'pra_reporting_enabled' => false,
]);
$unitOwner = $user($unitCompany, 'Synthetic Unit Owner', 'unit-owner@rc-browser.invalid', 'company_admin', 'pos_admin');
$unitCases = [];
foreach ([1366, 390] as $width) {
    $unused = \App\Models\Ingredient::create([
        'company_id' => $unitCompany->id, 'name' => 'Synthetic Unused Chicken '.$width,
        'unit' => 'pcs', 'base_unit' => 'pcs', 'conversion_factor' => 1,
        'current_stock' => 0, 'cost_per_unit' => 100, 'min_stock_level' => 0, 'is_active' => true,
    ]);
    $unitCases[$width] = ['id' => $unused->id, 'name' => $unused->name];
}
$unitStocked = \App\Models\Ingredient::create([
    'company_id' => $unitCompany->id, 'name' => 'Synthetic Stocked Chicken',
    'unit' => 'pcs', 'base_unit' => 'pcs', 'conversion_factor' => 1,
    'current_stock' => 50, 'cost_per_unit' => 100, 'min_stock_level' => 0, 'is_active' => true,
]);

// Actual stored document previews: disposable fixtures, never fiscal transport.
$previewDiCompany = $company('Synthetic Admin DI Preview', 'admin-di-preview@rc-browser.invalid', 'RCBADMINPREVIEW', 'di', ['fbr_environment'=>'sandbox']);
$previewDi = \App\Models\Invoice::forceCreate(['company_id'=>$previewDiCompany->id,'invoice_number'=>'RC-ADMIN-DI-PREVIEW','internal_invoice_number'=>'RC-ADMIN-DI-PREVIEW','buyer_name'=>'Synthetic Preview Buyer','invoice_date'=>'2026-10-01','status'=>'draft','total_amount'=>118,'total_value_excluding_st'=>100,'total_sales_tax'=>18]);
\App\Models\InvoiceItem::forceCreate(['invoice_id'=>$previewDi->id,'hs_code'=>'0101.2100','description'=>'Synthetic admin preview line','quantity'=>1,'price'=>100,'tax'=>18,'tax_rate'=>18]);
$previewFbr = \App\Models\FbrPosTransaction::forceCreate(['company_id'=>$fiscal->id,'invoice_number'=>'RC-ADMIN-FBR-PREVIEW','invoice_mode'=>'local','status'=>'completed','fbr_status'=>'local','created_by'=>$fiscalUser->id,'subtotal'=>100,'tax_amount'=>18,'total_amount'=>118,'payment_method'=>'cash']);
\App\Models\FbrPosTransactionItem::forceCreate(['transaction_id'=>$previewFbr->id,'item_name'=>'Synthetic admin preview line','quantity'=>1,'unit_price'=>100,'subtotal'=>100,'tax_amount'=>18,'total'=>118,'tax_rate'=>18]);
$wrongProductDi = \App\Models\Invoice::forceCreate(['company_id'=>$fiscal->id,'invoice_number'=>'RC-CROSS-PRODUCT-SECRET','buyer_name'=>'Synthetic Wrong Product','status'=>'draft','total_amount'=>999999]);
$adminPreviewDocuments = [
 ['metric'=>'di_invoices','product'=>'di','company'=>$previewDiCompany->id,'record'=>$previewDi->id,'reference'=>$previewDi->invoice_number],
 ['metric'=>'pos_transactions','product'=>'pos','company'=>$amountCompany->id,'record'=>$amountBill->id,'reference'=>$amountBill->invoice_number],
 ['metric'=>'fbrpos_transactions','product'=>'fbrpos','company'=>$fiscal->id,'record'=>$previewFbr->id,'reference'=>$previewFbr->invoice_number],
];

// Enforce the actual production policy in the disposable invoice-preview browser
// journey; application/runtime/database remain isolated and synthetic.
$fixtureEnvironment = app()->environment();
try {
    app()->instance('env', 'production');
    $policyResponse = (new \App\Http\Middleware\SecurityHeaders)->handle(
        \Illuminate\Http\Request::create('http://127.0.0.1/admin/dashboard/details/pos_transactions'),
        fn () => response('<html></html>')
    );
    $adminPreviewCsp = $policyResponse->headers->get('Content-Security-Policy');
} finally {
    app()->instance('env', $fixtureEnvironment);
}
if (!$adminPreviewCsp) throw new RuntimeException('Production CSP missing from preview fixture');

$fixture = [
    'generated_at' => $now->toIso8601String(), 'synthetic' => true,
    'readOnlyJourneys' => array_merge([
        ['name'=>'delivery-contact-search','login'=>$contactOwner->email,'password'=>$password,'loginPath'=>'/pos/login',
            'paths'=>['/pos/deliveries'],'markers'=>['Synthetic Contact A'],'deliveryContactSearch'=>true],
        ['name'=>'fbr-fiscal-receipt','login'=>$fiscalUser->email,'password'=>$password,'loginPath'=>'/fbr-pos/login','paths'=>['/fbr-pos/transaction/'.$fiscalReceipt->id.'/receipt'],'markers'=>['FBR: 9000000000001','STRN: 1234567890123','Tax Asaan / SMS 9966'],'fiscalReceipt'=>true],
        ['name'=>'fbr-identity-settings','login'=>$fiscalUser->email,'password'=>$password,'loginPath'=>'/fbr-pos/login','paths'=>['/fbr-pos/settings/identities'],'markers'=>['Branch / terminal FBR identity']],
        ['name' => 'readonly-reporting-connection', 'login' => $admin->email, 'password' => $password, 'loginPath' => '/admin/login',
            'paths' => ['/admin/reporting/connections'], 'markers' => ['Read-only reporting connections'],
            'reportingConnector' => ['companyId' => $amountCompany->id, 'companyName' => $amountCompany->name, 'foreignCompanyName' => $hotel->name, 'billing' => 646, 'date' => $now->toDateString()]],
        ['name'=>'whatsapp-optional-autosend','login'=>$waOwner->email,'password'=>$password,'loginPath'=>'/pos/login',
            'paths'=>['/pos/settings/whatsapp-delivery'],'markers'=>['WhatsApp auto-send'], 'whatsappAutoSend'=>true],
        ['name'=>'rider-realtime-map','login'=>$tableOwner->email,'password'=>$password,'loginPath'=>'/pos/login',
            'paths'=>['/pos/riders/tracking'],'markers'=>['Synthetic Live Rider'],
            'riderRealtime'=>['id'=>$riderLive->id,'token'=>$riderLiveToken]],
        ['name'=>'admin-company-directory-contrast','login'=>$admin->email,'password'=>$password,'loginPath'=>'/admin/login','paths'=>['/admin/companies?search=Synthetic%20Contrast'],'expectedPaths'=>['/admin/companies'],'markers'=>['Synthetic Contrast Owner'],'companyDirectoryContrast'=>true],
        ['name'=>'admin-dashboard-details','login'=>$admin->email,'password'=>$password,'loginPath'=>'/admin/login','paths'=>['/admin/dashboard'],'markers'=>['Admin Dashboard'],'dashboardDetails'=>true,'previewDocuments'=>$adminPreviewDocuments,'invoicePreviewCsp'=>$adminPreviewCsp],
        ['name' => 'customer-amount-audit', 'login' => $amountOwner->email, 'password' => $password, 'loginPath' => '/pos/login',
            'paths' => ['/pos/customers/'.$amountCustomer->id.'/history'], 'markers' => ['Synthetic Amount Customer'],
            'customerAmountAudit' => ['customerId' => $amountCustomer->id, 'cases' => $amountCases, 'spent' => 646]],
        ['name' => 'print-evidence-status', 'login' => $printEvidenceOwner->email, 'password' => $password, 'loginPath' => '/pos/login',
            'paths' => ['/pos/printer-settings'], 'markers' => ['Delayed print queue', 'Synthetic delayed queue', 'No document; no print submitted', 'Accepted by Windows; paper not verified']],
        ['name' => 'hotel-owner', 'login' => $hotelOwner->email, 'password' => $password, 'loginPath' => '/pos/login', 'paths' => ['/pos/hotel'], 'markers' => ['Hotel front desk'], 'usableSelectors' => ['[data-hotel-desk-menu="1"] a[data-hotel-desk-link="checkin"]', '[data-hotel-reception-summary="1"]', '[data-hotel-room-check-in]']],
        ['name' => 'pra-topnav', 'login' => $hotelOwner->email, 'password' => $password, 'loginPath' => '/pos/login', 'paths' => ['/pos/invoice/create'], 'markers' => ['Current Order'], 'mainMarkers' => ['Current Order'], 'topNavPanel' => 'pra', 'topNavFactory' => 'restaurantPos'],
        ['name' => 'pra-inventory-navigation', 'login' => $inventoryOwner->email, 'password' => $password, 'loginPath' => '/pos/login', 'paths' => ['/pos/inventory'], 'markers' => ['Inventory Dashboard']],
        ['name' => 'pra-admin-view-topnav', 'login' => $admin->email, 'password' => $password, 'loginPath' => '/admin/login', 'submitPath' => "/admin/companies/{$hotel->id}", 'submitSelector' => 'form[action$="/impersonate"]:has(input[name="mode"][value="view"])', 'paths' => ['/pos/invoice/create'], 'markers' => ['Current Order'], 'mainMarkers' => ['Current Order'], 'topNavPanel' => 'pra', 'topNavFactory' => 'restaurantPos'],
        ['name' => 'pra-admin-manage-topnav', 'login' => $admin->email, 'password' => $password, 'loginPath' => '/admin/login', 'submitPath' => "/admin/companies/{$hotel->id}", 'submitSelector' => 'form[action$="/impersonate"]:has(input[name="mode"][value="full"])', 'paths' => ['/pos/invoice/create'], 'markers' => ['Current Order'], 'mainMarkers' => ['Current Order'], 'topNavPanel' => 'pra', 'topNavFactory' => 'restaurantPos'],
        ['name' => 'hotel-manager', 'login' => $hotelManager->email, 'password' => $password, 'loginPath' => '/pos/login', 'paths' => ['/pos/hotel/rooms'], 'markers' => ['Rooms']],
        ['name' => 'hotel-housekeeping', 'login' => $hotelHousekeeping->email, 'password' => $password, 'loginPath' => '/pos/login', 'paths' => ['/pos/hotel/housekeeping'], 'markers' => ['Housekeeping']],
        ['name' => 'hotel-outlet', 'login' => $hotelOutlet->email, 'password' => $password, 'loginPath' => '/pos/login', 'paths' => ['/pos/hotel/restaurant'], 'markers' => ['Current Order'], 'allowRedirectTo' => '/pos/invoice/create'],
        ['name' => 'hotel-admin-manage-as', 'login' => $admin->email, 'password' => $password, 'loginPath' => '/admin/login', 'submitPath' => "/admin/companies/{$hotel->id}", 'submitSelector' => 'form[action$="/impersonate"]:has(input[name="mode"][value="full"])', 'paths' => ['/pos/hotel'], 'markers' => ['Hotel front desk'], 'usableSelectors' => ['[data-hotel-reception-summary="1"]', '[data-hotel-room-check-in]']],
        ['name' => 'service-work-orders-manager', 'login' => $serviceManager->email, 'password' => $password, 'loginPath' => '/pos/login', 'paths' => ['/pos/work-orders', '/pos/work-orders/report.csv'], 'markers' => ['Event Plan Board'], 'usableSelectors' => ['a[href$="/pos/work-orders/create"]']],
        ['name' => 'health', 'login' => $healthUser->email, 'password' => $password, 'loginPath' => '/health/login', 'paths' => ['/health/dashboard'], 'markers' => ['Synthetic Browser Health Clinic']],
        ['name' => 'fiscal', 'login' => $fiscalUser->email, 'password' => $password, 'loginPath' => '/fbr-pos/login', 'paths' => ['/fbr-pos/create'], 'markers' => ['Current Order'], 'mainMarkers' => ['Current Order'], 'usableSelectors' => ['input[name="pos_product_search_nofill"]'], 'topNavPanel' => 'fbr', 'topNavFactory' => 'restaurantPos'],
        ['name' => 'fiscal-admin-view-topnav', 'login' => $admin->email, 'password' => $password, 'loginPath' => '/admin/login', 'submitPath' => "/admin/companies/{$fiscal->id}", 'submitSelector' => 'form[action$="/impersonate"]:has(input[name="mode"][value="view"])', 'paths' => ['/fbr-pos/create'], 'markers' => ['Current Order'], 'mainMarkers' => ['Current Order'], 'topNavPanel' => 'fbr', 'topNavFactory' => 'restaurantPos'],
        ['name' => 'fiscal-admin-manage-topnav', 'login' => $admin->email, 'password' => $password, 'loginPath' => '/admin/login', 'submitPath' => "/admin/companies/{$fiscal->id}", 'submitSelector' => 'form[action$="/impersonate"]:has(input[name="mode"][value="full"])', 'paths' => ['/fbr-pos/create'], 'markers' => ['Current Order'], 'mainMarkers' => ['Current Order'], 'topNavPanel' => 'fbr', 'topNavFactory' => 'restaurantPos'],
        ['name' => 'hotel-denied', 'login' => $hotelDenied->email, 'password' => $password, 'loginPath' => '/pos/login', 'paths' => ['/pos/hotel', '/pos/hotel/restaurant'], 'denied' => true],
        ['name' => 'service-work-orders-denied', 'login' => $serviceDenied->email, 'password' => $password, 'loginPath' => '/pos/login', 'paths' => ['/pos/work-orders', '/pos/work-orders/report.csv'], 'denied' => true],
    ], $categoryJourneys),
    'transactionalJourneys' => [
        ['name' => 'fbr-owner-reconciliation', 'login' => $fiscalUser->email, 'password' => $password,
            'loginPath' => '/fbr-pos/login', 'paths' => ['/fbr-pos/fail-queue'],
            'markers' => ['FBR Fail Queue'], 'fbrReconciliation' => $reconcileCases],
        ['name' => 'ingredient-unit-audit', 'login' => $unitOwner->email, 'password' => $password, 'loginPath' => '/pos/login',
            'paths' => ['/pos/restaurant/ingredients'], 'markers' => ['Synthetic Stocked Chicken'],
            'ingredientUnitAudit' => ['unused' => $unitCases, 'stocked' => ['id' => $unitStocked->id, 'name' => $unitStocked->name]]],
        ['name' => 'hotel-credit-review', 'login' => $creditOwner->email, 'password' => $password, 'loginPath' => '/pos/login',
            'paths' => ['/pos/hotel/stays/'.$creditStay->id.'/credit-notes'],
            'hotelCreditReview' => ['billId' => $creditBill->id, 'path' => '/pos/hotel/stays/'.$creditStay->id.'/credit-notes',
                'refundPath' => '/pos/hotel/stays/'.$refundStay->id.'/credit-notes']],
        ['name' => 'occupied-table-orders', 'login' => $tableCashier->email, 'password' => $password,
            'loginPath' => '/pos/login', 'paths' => ['/pos/invoice/create'], 'markers' => ['Current Order'],
            'tableOrderWorkflow' => $tableCases, 'kotDeleteCases' => $kotDeleteCases, 'cashierHandoff' => ['cases' => $handoffCases, 'cashierId' => $tableCashier->id,
                'manager' => ['name' => 'synthetic-transfer-manager', 'login' => $tableManager->email, 'password' => $password, 'loginPath' => '/pos/login']]],
        ['name' => 'hotel-settings', 'login' => $settingsOwner->email, 'password' => $password, 'loginPath' => '/pos/login', 'paths' => ['/pos/customize'], 'hotelSettingsWorkflow' => true],
        ['name' => 'menu-exempt-filter', 'login' => $topSellingOwner->email, 'password' => $password, 'loginPath' => '/pos/login', 'paths' => ['/pos/products'], 'menuExemptFilter' => true],
        ['name' => 'restaurant-top-selling', 'login' => $topSellingOwner->email, 'password' => $password, 'loginPath' => '/pos/login', 'paths' => ['/pos/restaurant/top-selling'], 'restaurantTopSelling' => true, 'markers' => ['Synthetic complete item 16']],
        ['name' => 'notification-di', 'login' => $notificationUsers['di']['desktop'], 'mobileLogin' => $notificationUsers['di']['mobile'], 'preflightLogin' => $notificationUsers['di']['preflight-desktop'], 'preflightMobileLogin' => $notificationUsers['di']['preflight-mobile'], 'password' => $password, 'loginPath' => '/login', 'paths' => ['/profile'], 'notificationWorkflow' => 'di'],
        ['name' => 'notification-pra', 'login' => $notificationUsers['pra']['desktop'], 'mobileLogin' => $notificationUsers['pra']['mobile'], 'preflightLogin' => $notificationUsers['pra']['preflight-desktop'], 'preflightMobileLogin' => $notificationUsers['pra']['preflight-mobile'], 'password' => $password, 'loginPath' => '/pos/login', 'paths' => ['/pos/my-profile'], 'notificationWorkflow' => 'pra'],
        ['name' => 'notification-fbr', 'login' => $notificationUsers['fbr']['desktop'], 'mobileLogin' => $notificationUsers['fbr']['mobile'], 'preflightLogin' => $notificationUsers['fbr']['preflight-desktop'], 'preflightMobileLogin' => $notificationUsers['fbr']['preflight-mobile'], 'password' => $password, 'loginPath' => '/fbr-pos/login', 'paths' => ['/fbr-pos/my-profile'], 'notificationWorkflow' => 'fbr'],
        ['name' => 'hotel-simple-desk', 'login' => $hotelOwner->email, 'password' => $password, 'loginPath' => '/pos/login', 'hotelWorkflow' => true, 'paths' => ['/pos/hotel'], 'markers' => ['Hotel front desk']],
        [
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


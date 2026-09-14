<?php

/**
 * Disposable MariaDB checks: hotel board buckets, tenant URL isolation,
 * branch scoping, and receptionist/cashier/housekeeping/manager gates.
 *
 * Run via: bash scripts/tests/hotel-mariadb-isolation-board-check.sh
 * Does not alter phpunit.xml.
 */

use App\Models\Branch;
use App\Models\Company;
use App\Models\HotelStay;
use App\Models\User;
use App\Services\HotelAccessService;
use App\Services\HotelStayService;
use App\Services\PosFeatureService;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Schema;

require __DIR__ . '/../../vendor/autoload.php';
$app = require __DIR__ . '/../../bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();

$socket = getenv('DB_SOCKET') ?: '';
if ($socket === '' || !file_exists($socket)) {
    fwrite(STDERR, "BLOCKED: DB_SOCKET not usable\n");
    exit(2);
}

config([
    'database.default' => 'mysql',
    'database.connections.mysql.host' => getenv('DB_HOST') ?: '127.0.0.1',
    'database.connections.mysql.port' => getenv('DB_PORT') ?: '3307',
    'database.connections.mysql.database' => getenv('DB_DATABASE') ?: 'taxnest_dev',
    'database.connections.mysql.username' => getenv('DB_USERNAME') ?: 'taxnest_dev',
    'database.connections.mysql.password' => getenv('DB_PASSWORD') ?: 'taxnest_local_dev_only',
    'database.connections.mysql.unix_socket' => $socket,
]);
DB::purge('mysql');
DB::reconnect('mysql');

echo 'MariaDB: ' . DB::selectOne('select version() as v')->v . "\n";

if (!Schema::hasTable('hotel_rooms') || !Schema::hasTable('companies') || !Schema::hasTable('users')) {
    echo "Migrating taxnest_dev...\n";
    $code = $app->make(Kernel::class)->call('migrate', ['--force' => true]);
    if ($code !== 0) {
        fwrite(STDERR, "FAIL: migrate exited $code\n");
        exit(1);
    }
}

PosFeatureService::flushGateCaches();
PosFeatureService::assumeExtrasColumn(true);

$fail = 0;
$pass = function (string $msg) {
    echo "PASS $msg\n";
};
$assert = function (bool $ok, string $msg) use (&$fail, $pass) {
    if ($ok) {
        $pass($msg);
    } else {
        echo "FAIL $msg\n";
        $fail++;
    }
};

$suffix = bin2hex(random_bytes(4));
$mkCompany = function (string $label) use ($suffix) {
    return Company::create([
        'name' => "Hotel Isol $label $suffix",
        'ntn' => (string) random_int(100000000, 999999999),
        'email' => "hotel-isol-$label-$suffix@test.pk",
        'status' => 'approved',
        'company_status' => 'active',
        'product_type' => 'pos',
        'pos_integration_mode' => 'pra',
        'business_category' => 'hotel',
        'feature_flags' => PosFeatureService::defaultsForCategory('hotel'),
        'restaurant_mode' => false,
        'is_internal_account' => true,
        'pos_setup_completed' => true,
        'pos_tax_rate_cash' => 0,
        'pos_tax_rate_card' => 0,
    ]);
};
$mkUser = function (Company $company, string $role, string $posRole, ?array $custom = null) use ($suffix) {
    $user = User::create([
        'name' => "Isol $role",
        'email' => uniqid("hotel-$role-", true) . "-$suffix@test.pk",
        'password' => Hash::make('Secret@12345'),
        'company_id' => $company->id,
        'role' => $role,
        'pos_role' => $posRole,
        'is_active' => true,
    ]);
    // pos_custom_access is not fillable — forceFill matches Team saves.
    if ($custom !== null) {
        $user->forceFill(['pos_custom_access' => json_encode($custom)])->save();
    }

    return $user->fresh();
};

$companyA = $mkCompany('A');
$companyB = $mkCompany('B');
$ownerA = $mkUser($companyA, 'company_admin', 'pos_admin');
$ownerB = $mkUser($companyB, 'company_admin', 'pos_admin');
$manager = $mkUser($companyA, 'staff', 'pos_manager');
$receptionist = $mkUser($companyA, 'staff', 'pos_cashier'); // front-desk / receptionist
$hkOnly = $mkUser($companyA, 'staff', 'pos_cashier', ['dashboard', 'hotel_housekeeping']);
$deniedCashier = $mkUser($companyA, 'staff', 'pos_cashier', ['dashboard', 'orders']);

$assert(HotelAccessService::canFrontDesk($manager) && HotelAccessService::canManageRooms($manager), 'manager full hotel access');
$assert(HotelAccessService::canFrontDesk($receptionist) && !HotelAccessService::canManageRooms($receptionist), 'receptionist/cashier front desk only');
$assert(!HotelAccessService::canFrontDesk($hkOnly) && HotelAccessService::canHousekeeping($hkOnly), 'housekeeping-only gate');
$assert(!HotelAccessService::canFrontDesk($deniedCashier) && !HotelAccessService::canHousekeeping($deniedCashier), 'cashier without hotel grant blocked');

$stays = app(HotelStayService::class);
$roomDirty = $stays->createRoom((int) $companyA->id, [
    'room_number' => 'D1',
    'room_type' => 'Standard',
    'capacity' => 2,
    'rate_amount' => 3000,
    'rate_unit' => 'NGT',
]);
$stays->setHousekeeping($roomDirty, 'dirty');
$roomStay = $stays->createRoom((int) $companyA->id, [
    'room_number' => 'S1',
    'room_type' => 'Deluxe',
    'capacity' => 2,
    'rate_amount' => 5000,
    'rate_unit' => 'NGT',
]);
$roomFree = $stays->createRoom((int) $companyA->id, [
    'room_number' => 'A1',
    'room_type' => 'Standard',
    'capacity' => 2,
    'rate_amount' => 2500,
    'rate_unit' => 'NGT',
]);
$stays->createRoom((int) $companyB->id, [
    'room_number' => 'S1',
    'room_type' => 'Deluxe',
    'capacity' => 2,
    'rate_amount' => 5000,
    'rate_unit' => 'NGT',
]);

$today = now()->toDateString();
$tomorrow = now()->addDay()->toDateString();
$yesterday = now()->subDay()->toDateString();
// Arrival today (check-out tomorrow) — in-house + arrivals + pending dues.
$stay = $stays->book((int) $companyA->id, (int) $ownerA->id, [
    'room_id' => $roomStay->id,
    'check_in_date' => $today,
    'check_out_date' => $tomorrow,
    'guest_name' => 'In House Guest',
    'walk_in' => true,
]);
// Departure today — checked in yesterday, due out today.
$roomDep = $stays->createRoom((int) $companyA->id, [
    'room_number' => 'P1',
    'room_type' => 'Standard',
    'capacity' => 2,
    'rate_amount' => 2800,
    'rate_unit' => 'NGT',
]);
$departing = $stays->book((int) $companyA->id, (int) $ownerA->id, [
    'room_id' => $roomDep->id,
    'check_in_date' => $yesterday,
    'check_out_date' => $today,
    'guest_name' => 'Leaving Guest',
    'walk_in' => true,
]);

$boardA = $stays->board((int) $companyA->id, null);
$boardB = $stays->board((int) $companyB->id, null);
foreach (['available', 'dirty', 'inHouse', 'arrivals', 'departures', 'pending', 'dues', 'occupancy'] as $key) {
    $assert(array_key_exists($key, $boardA), "board has key $key");
}
$assert($boardA['dirty']->contains('id', $roomDirty->id), 'board dirty rooms');
$assert($boardA['available']->contains('id', $roomFree->id), 'board available rooms');
$assert($boardA['inHouse']->contains('id', $stay->id), 'board in-house guests');
$assert($boardA['arrivals']->contains('id', $stay->id), 'board arrivals today');
$assert($boardA['departures']->contains('id', $departing->id), 'board departures today');
$assert(($boardA['dues'][$stay->id] ?? 0) > 0 && $boardA['pending']->contains('id', $stay->id), 'board pending balances');
$assert(!$boardB['inHouse']->contains('id', $stay->id), 'board does not leak stay across companies');
$assert(HotelStay::where('company_id', $companyB->id)->where('id', $stay->id)->doesntExist(), 'model company isolation');

$httpGet = function (User $user, string $path, array $session = []) use ($app) {
    Auth::guard('pos')->login($user);
    app()->instance('currentCompanyId', (int) $user->company_id);
    $kernel = $app->make(\Illuminate\Contracts\Http\Kernel::class);
    $request = Request::create($path, 'GET');
    $sessionStore = $app['session']->driver();
    $sessionStore->start();
    foreach ($session as $key => $value) {
        $sessionStore->put($key, $value);
    }
    $request->setLaravelSession($sessionStore);
    $response = $kernel->handle($request);
    $kernel->terminate($request, $response);
    Auth::guard('pos')->logout();
    session()->flush();

    return $response->getStatusCode();
};

$statusOtherTenant = $httpGet($ownerB, '/pos/hotel/stays/' . $stay->id);
$assert($statusOtherTenant === 302, "URL tenant isolation redirects away status=$statusOtherTenant");

$statusOwner = $httpGet($ownerA, '/pos/hotel/stays/' . $stay->id);
$assert($statusOwner === 200, "owner can open own stay status=$statusOwner");

$statusHkDesk = $httpGet($hkOnly, '/pos/hotel');
$assert($statusHkDesk === 302, "HK-only cannot open front desk status=$statusHkDesk");
$statusHkRooms = $httpGet($hkOnly, '/pos/hotel/rooms');
$assert($statusHkRooms === 200, "HK-only can open rooms board status=$statusHkRooms");

$statusDenied = $httpGet($deniedCashier, '/pos/hotel');
$assert($statusDenied === 302, "denied cashier blocked from hotel status=$statusDenied");

if (Schema::hasTable('branches')) {
    $branchA = Branch::create([
        'company_id' => $companyA->id,
        'name' => 'Tower A',
        'code' => 'TA' . substr($suffix, 0, 4),
        'is_active' => true,
    ]);
    $branchB = Branch::create([
        'company_id' => $companyA->id,
        'name' => 'Tower B',
        'code' => 'TB' . substr($suffix, 0, 4),
        'is_active' => true,
    ]);
    $roomBr = $stays->createRoom((int) $companyA->id, [
        'room_number' => 'B1',
        'room_type' => 'Suite',
        'capacity' => 2,
        'rate_amount' => 8000,
        'rate_unit' => 'NGT',
        'branch_id' => $branchA->id,
    ]);
    $stayBr = $stays->book((int) $companyA->id, (int) $ownerA->id, [
        'room_id' => $roomBr->id,
        'check_in_date' => now()->addDays(3)->toDateString(),
        'check_out_date' => now()->addDays(5)->toDateString(),
        'guest_name' => 'Branch Guest',
    ]);
    $assert((int) $stayBr->branch_id === (int) $branchA->id, 'stay inherits room branch');

    $wrongBranch = $httpGet($ownerA, '/pos/hotel/stays/' . $stayBr->id, [
        'active_branch_id' => $branchB->id,
    ]);
    // POS ModelNotFoundException → redirect /pos/dashboard (not raw 404).
    $assert($wrongBranch === 302, "URL branch isolation redirects away status=$wrongBranch");
    $rightBranch = $httpGet($ownerA, '/pos/hotel/stays/' . $stayBr->id, [
        'active_branch_id' => $branchA->id,
    ]);
    $assert($rightBranch === 200, "URL same-branch stay opens status=$rightBranch");
    // Query-level proof even if HTTP session wiring differs in CLI.
    $assert(
        HotelStay::where('company_id', $companyA->id)->where('branch_id', $branchB->id)->where('id', $stayBr->id)->doesntExist(),
        'query branch isolation'
    );
} else {
    echo "SKIP branch URL isolation (no branches table)\n";
}

// No dedicated hotel JSON API routes — URL isolation is the API surface for V1.
$assert(!collect(\Illuminate\Support\Facades\Route::getRoutes())->contains(
    fn ($r) => str_starts_with($r->uri(), 'api/') && str_contains($r->uri(), 'hotel')
), 'no hotel JSON API routes (URL isolation is the surface)');

if ($fail > 0) {
    fwrite(STDERR, "FAIL: $fail assertion(s)\n");
    exit(1);
}
echo "OK hotel MariaDB isolation/board checks\n";
exit(0);

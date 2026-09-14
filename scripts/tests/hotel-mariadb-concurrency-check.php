<?php

/**
 * Disposable MariaDB checks for hotel room overlap (serial + concurrent).
 *
 * Run via: bash scripts/tests/hotel-mariadb-concurrency-check.sh
 * Does not alter phpunit.xml.
 */

use App\Exceptions\HotelStayException;
use App\Models\Company;
use App\Models\HotelStay;
use App\Models\User;
use App\Services\HotelStayService;
use App\Services\PosFeatureService;
use Illuminate\Contracts\Console\Kernel;
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

$suffix = bin2hex(random_bytes(4));
$company = Company::create([
    'name' => "Hotel Concurrency $suffix",
    'ntn' => (string) random_int(100000000, 999999999),
    'email' => "hotel-conc-$suffix@test.pk",
    'status' => 'approved',
    'company_status' => 'active',
    'product_type' => 'pos',
    'pos_integration_mode' => 'pra',
    'business_category' => 'hotel',
    'feature_flags' => PosFeatureService::defaultsForCategory('hotel'),
    'restaurant_mode' => false,
    'is_internal_account' => true,
    'pos_setup_completed' => true,
]);
$user = User::create([
    'name' => 'Conc Owner',
    'email' => "hotel-owner-$suffix@test.pk",
    'password' => Hash::make('Secret@12345'),
    'company_id' => $company->id,
    'role' => 'company_admin',
    'pos_role' => 'pos_admin',
    'is_active' => true,
]);

$stays = app(HotelStayService::class);
$room = $stays->createRoom((int) $company->id, [
    'room_number' => 'C1',
    'room_type' => 'Standard',
    'capacity' => 2,
    'rate_amount' => 3000,
    'rate_unit' => 'NGT',
    'branch_id' => null,
]);

$base = [
    'room_id' => $room->id,
    'check_in_date' => '2026-10-01',
    'check_out_date' => '2026-10-05',
];

$stays->book((int) $company->id, (int) $user->id, $base + [
    'guest_name' => 'First',
    'idempotency_key' => "first-$suffix",
]);
try {
    $stays->book((int) $company->id, (int) $user->id, $base + [
        'guest_name' => 'Second',
        'idempotency_key' => "second-$suffix",
    ]);
    fwrite(STDERR, "FAIL: serial overlapping booking was accepted\n");
    exit(1);
} catch (HotelStayException $e) {
    echo 'PASS serial overlap: ' . $e->getMessage() . "\n";
}

$room2 = $stays->createRoom((int) $company->id, [
    'room_number' => 'C2',
    'room_type' => 'Standard',
    'capacity' => 2,
    'rate_amount' => 3000,
    'rate_unit' => 'NGT',
    'branch_id' => null,
]);

$worker = __DIR__ . '/hotel-mariadb-book-worker.php';
$outDir = sys_get_temp_dir() . "/hotel-conc-$suffix";
mkdir($outDir, 0700, true);

$envPairs = [
    'DB_CONNECTION' => 'mysql',
    'DB_HOST' => getenv('DB_HOST') ?: '127.0.0.1',
    'DB_PORT' => getenv('DB_PORT') ?: '3307',
    'DB_DATABASE' => getenv('DB_DATABASE') ?: 'taxnest_dev',
    'DB_USERNAME' => getenv('DB_USERNAME') ?: 'taxnest_dev',
    'DB_PASSWORD' => getenv('DB_PASSWORD') ?: 'taxnest_local_dev_only',
    'DB_SOCKET' => $socket,
    'CACHE_STORE' => 'array',
    'APP_ENV' => 'local',
];
$envInline = '';
foreach ($envPairs as $k => $v) {
    $envInline .= $k . '=' . escapeshellarg($v) . ' ';
}

$pids = [];
for ($i = 0; $i < 2; $i++) {
    $out = "$outDir/$i.json";
    $log = "$outDir/$i.log";
    $full = $envInline . 'php ' . escapeshellarg($worker) . ' '
        . (int) $company->id . ' ' . (int) $user->id . ' ' . (int) $room2->id . ' '
        . escapeshellarg('2026-11-01') . ' ' . escapeshellarg('2026-11-04') . ' '
        . escapeshellarg("conc-$suffix-$i") . ' ' . escapeshellarg($out)
        . ' >' . escapeshellarg($log) . ' 2>&1 & echo $!';
    $pid = (int) trim((string) shell_exec($full));
    $pids[] = $pid;
}

$deadline = microtime(true) + 30;
while (microtime(true) < $deadline) {
    $alive = false;
    foreach ($pids as $pid) {
        if ($pid > 0 && @posix_kill($pid, 0)) {
            $alive = true;
            break;
        }
    }
    if (!$alive) {
        break;
    }
    usleep(50000);
}
usleep(100000);

$ok = 0;
$err = 0;
for ($i = 0; $i < 2; $i++) {
    $file = "$outDir/$i.json";
    if (!is_file($file)) {
        echo "child $i missing output; log=" . @file_get_contents("$outDir/$i.log") . "\n";
        $err++;
        continue;
    }
    $data = json_decode((string) file_get_contents($file), true) ?: [];
    if (!empty($data['ok'])) {
        $ok++;
    } else {
        $err++;
        echo 'child ' . $i . ' refused: ' . ($data['error'] ?? 'unknown') . "\n";
    }
}

$count = HotelStay::where('company_id', $company->id)
    ->where('room_id', $room2->id)
    ->whereIn('status', HotelStay::OPEN_STATUSES)
    ->count();

echo "ok=$ok err=$err open_stays=$count\n";
if ($ok === 1 && $err === 1 && $count === 1) {
    echo "PASS concurrent overlap: one winner, one refused\n";
    exit(0);
}

fwrite(STDERR, "FAIL: expected exactly one successful concurrent booking\n");
exit(1);

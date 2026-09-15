<?php

/**
 * MariaDB-native category lab probes (not PHPUnit).
 *
 * Asserts: required tables after migrate:fresh, tenant isolation of work
 * orders, and concurrent series allocation for one company.
 */

use App\Models\Company;
use App\Models\PosServiceWorkOrder;
use App\Services\PosFeatureService;
use App\Services\PosServiceWorkOrderService;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

require __DIR__ . '/../../vendor/autoload.php';
$app = require __DIR__ . '/../../bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();

$host = getenv('DB_HOST') ?: '127.0.0.1';
if (! in_array($host, ['127.0.0.1', 'localhost'], true)) {
    fwrite(STDERR, "BLOCKED: non-loopback DB host {$host}\n");
    exit(2);
}

$dbName = getenv('DB_DATABASE') ?: '';
if ($dbName !== 'taxnest_category_lab') {
    fwrite(STDERR, "BLOCKED: refusing probes against database '{$dbName}'\n");
    exit(2);
}

config([
    'database.default' => 'mysql',
    'database.connections.mysql.host' => $host,
    'database.connections.mysql.port' => getenv('DB_PORT') ?: '3306',
    'database.connections.mysql.database' => $dbName,
    'database.connections.mysql.username' => getenv('DB_USERNAME') ?: 'taxnest_dev',
    'database.connections.mysql.password' => getenv('DB_PASSWORD') ?: 'taxnest_local_dev_only',
    'database.connections.mysql.unix_socket' => '',
]);
DB::purge('mysql');
DB::reconnect('mysql');

echo 'MariaDB: ' . DB::selectOne('select version() as v')->v . "\n";

$fail = 0;
$pass = function (string $msg): void {
    echo "PASS {$msg}\n";
};
$assert = function (bool $ok, string $msg) use (&$fail, $pass): void {
    if ($ok) {
        $pass($msg);
    } else {
        echo "FAIL {$msg}\n";
        $fail++;
    }
};

foreach ([
    'companies',
    'users',
    'pos_service_work_orders',
    'pos_service_work_order_events',
    'pos_service_work_order_series',
    'fbr_pos_submission_evidence',
] as $table) {
    $assert(Schema::hasTable($table), "schema has {$table}");
}

PosFeatureService::flushGateCaches();
PosFeatureService::assumeExtrasColumn(true);

$suffix = bin2hex(random_bytes(3));
$mkCompany = function (string $category, string $label) use ($suffix) {
    return Company::create([
        'name' => "CatLab {$label} {$suffix}",
        'ntn' => (string) random_int(100000000, 999999999),
        'email' => "catlab-{$label}-{$suffix}@test.pk",
        'status' => 'approved',
        'company_status' => 'active',
        'product_type' => 'pos',
        'pos_integration_mode' => 'pra',
        'business_category' => $category,
        'feature_flags' => PosFeatureService::defaultsForCategory($category),
        'restaurant_mode' => false,
        'is_internal_account' => true,
        'pos_setup_completed' => true,
        'pos_tax_rate_cash' => 0,
        'pos_tax_rate_card' => 0,
    ]);
};

$a = $mkCompany('salon', 'a');
$b = $mkCompany('laundry', 'b');
$jobs = app(PosServiceWorkOrderService::class);

$orderA = $jobs->create($a, [
    'customer_name' => 'Guest A',
    'title' => 'Cut',
    'quantity' => 1,
    'unit_price' => 500,
    'details' => ['staff' => 'Ali', 'station' => '1'],
], null, null);

$orderB = $jobs->create($b, [
    'customer_name' => 'Guest B',
    'title' => 'Wash',
    'quantity' => 2,
    'unit_price' => 200,
    'details' => ['pieces' => '3', 'care' => 'cold'],
], null, null);

$assert(str_starts_with($orderA->job_number, 'SAL-'), 'salon prefix on job number');
$assert(str_starts_with($orderB->job_number, 'LND-'), 'laundry prefix on job number');
$assert(
    PosServiceWorkOrder::where('company_id', $a->id)->whereKey($orderB->id)->doesntExist(),
    'tenant A cannot see tenant B work order by id'
);
$assert(
    PosServiceWorkOrder::where('company_id', $b->id)->whereKey($orderA->id)->doesntExist(),
    'tenant B cannot see tenant A work order by id'
);

// Concurrent series bump via separate PHP CLI workers (avoid forking the
// already-bootstrapped Laravel/MySQL process).
$worker = __DIR__ . '/category-native-lab2-mariadb-book-worker.php';
$env = [];
foreach (array_merge($_ENV ?? [], $_SERVER ?? []) as $k => $v) {
    if (is_string($k) && (is_string($v) || is_int($v) || is_float($v))) {
        $env[$k] = (string) $v;
    }
}
$env = array_merge($env, [
    'DB_CONNECTION' => 'mysql',
    'DB_HOST' => $host,
    'DB_PORT' => (string) (getenv('DB_PORT') ?: '3306'),
    'DB_DATABASE' => $dbName,
    'DB_USERNAME' => getenv('DB_USERNAME') ?: 'taxnest_dev',
    'DB_PASSWORD' => getenv('DB_PASSWORD') ?: 'taxnest_local_dev_only',
    'APP_KEY' => getenv('APP_KEY') ?: 'base64:YWFhYWFhYWFhYWFhYWFhYWFhYWFhYWFhYWFhYWFhYWE=',
    'APP_ENV' => 'local',
    'PATH' => getenv('PATH') ?: '/usr/bin:/bin',
]);
$cmd = escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg($worker) . ' ' . (int) $a->id;
$descriptors = [1 => ['pipe', 'w'], 2 => ['pipe', 'w']];
$okKids = 0;
for ($i = 0; $i < 2; $i++) {
    $pipes = [];
    $proc = @proc_open($cmd, $descriptors, $pipes, base_path(), $env);
    if (! is_resource($proc)) {
        echo "FAIL could not start concurrent worker\n";
        $fail++;
        continue;
    }
    $stdout = stream_get_contents($pipes[1]);
    $stderr = stream_get_contents($pipes[2]);
    fclose($pipes[1]);
    fclose($pipes[2]);
    $code = proc_close($proc);
    if ($code === 0) {
        $okKids++;
    } else {
        echo "FAIL worker exit={$code} stdout={$stdout} stderr={$stderr}\n";
        $fail++;
    }
}
$assert($okKids === 2, 'concurrent salon series inserts both succeeded');

$numbers = PosServiceWorkOrder::where('company_id', $a->id)
    ->where('job_number', 'like', 'SAL-%')
    ->pluck('job_number')
    ->all();
$assert(count($numbers) === count(array_unique($numbers)), 'salon job numbers unique after concurrency');
$assert(count($numbers) >= 3, 'salon company has original + two concurrent jobs');

if ($fail > 0) {
    fwrite(STDERR, "CATEGORY MARIADB LAB: {$fail} failure(s)\n");
    exit(1);
}

echo "OK category MariaDB lab probes\n";
exit(0);

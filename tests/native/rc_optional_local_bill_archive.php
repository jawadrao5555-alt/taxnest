<?php
declare(strict_types=1);

// Real controller/row-lock races, exclusively on the disposable RC MariaDB lab.
$db = getenv('DB_DATABASE') ?: '';
$socket = getenv('DB_SOCKET') ?: '';
if (!preg_match('/^taxnest_rc_[a-z0-9_]+_full$/', $db)
    || !str_starts_with($socket, '/tmp/taxnest-rc-mariadb-')
    || getenv('APP_ENV') !== 'rc-mariadb' || !getenv('LD_PRELOAD')) {
    fwrite(STDERR, "FAIL unsafe optional-copy concurrency target\n"); exit(2);
}
require dirname(__DIR__, 2).'/vendor/autoload.php';
$app = require dirname(__DIR__, 2).'/bootstrap/app.php';
$app->make(\Illuminate\Contracts\Console\Kernel::class)->bootstrap();

use App\Http\Controllers\PosController;
use App\Models\User;
use App\Services\OptionalLocalBillArchive;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;

function requireProof(bool $condition, string $message): void
{
    if (!$condition) throw new RuntimeException($message);
}
function waitForFile(string $path): void
{
    $until = microtime(true) + 20;
    while (!is_file($path)) {
        if (microtime(true) > $until) throw new RuntimeException('Worker marker timeout');
        usleep(10000);
    }
}
function launchDelete(int $company, int $user, int $bill, string $root, string $marker): array
{
    $process = proc_open([PHP_BINARY, __FILE__, 'delete', (string) $company, (string) $user, (string) $bill, $root, $marker],
        [0 => ['file', '/dev/null', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
    if (!is_resource($process)) throw new RuntimeException('Delete worker launch failed');
    return [$process, $pipes, $marker];
}
function finishDelete(array $worker): void
{
    [$process, $pipes, $marker] = $worker;
    $output = '';
    foreach ($pipes as $pipe) { $output .= stream_get_contents($pipe); fclose($pipe); }
    requireProof(proc_close($process) === 0 && str_contains($output, 'Actual controller completed') && is_file($marker.'.completed'), 'Actual delete worker failed: '.$output);
}
function waitForLock(string $marker): void
{
    $until = microtime(true) + 20;
    $connection = (int) file_get_contents($marker);
    requireProof($connection > 0, 'Missing delete worker connection identity');
    $config = config('database.connections.mysql');
    $dsn = !empty($config['unix_socket']) ? 'mysql:unix_socket='.$config['unix_socket']
        : 'mysql:host='.$config['host'].';port='.$config['port'];
    $observer = new PDO($dsn, $config['username'], $config['password'], [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
    do {
        foreach ($observer->query('SHOW FULL PROCESSLIST')->fetchAll(PDO::FETCH_ASSOC) as $process) {
            // Some MariaDB versions expose the blocked prepared FOR UPDATE in
            // Statistics instead of INNODB_LOCK_WAITS. Observe this exact worker
            // connection executing its pending lock for >=1s. The writer stays
            // uncommitted until this is proven; verify real outcomes after release.
            if ((int) $process['Id'] === $connection && (int) $process['Time'] >= 1
                && str_contains(strtolower((string) $process['Info']), 'for update')
                && (str_contains((string) $process['Info'], 'pos_transactions')
                    || str_contains((string) $process['Info'], 'support_bill_archive_prefs'))) {
                requireProof(!is_file($marker.'.completed'), 'Delete finished while writer lock was still held');
                return;
            }
        }
        if (microtime(true) > $until) throw new RuntimeException('Independent delete did not block on its pending FOR UPDATE');
        usleep(10000);
    } while (true);
}

if (($argv[1] ?? '') === 'delete') {
    [$company, $userId, $bill] = [(int) $argv[2], (int) $argv[3], (int) $argv[4]];
    $root = $argv[5]; $marker = $argv[6];
    requireProof(str_starts_with($root, dirname($socket).'/') && str_starts_with($marker, dirname($socket).'/'), 'Unsafe worker paths');
    config(['local_bill_archive.key_root' => $root]);
    auth('pos')->setUser(User::findOrFail($userId));
    app()->instance('currentCompanyId', $company);
    DB::listen(function ($query) use ($marker) {
        if (str_contains(strtolower($query->sql), 'select') && str_contains($query->sql, 'pos_transactions')
            && !str_contains(strtolower($query->sql), 'for update')) file_put_contents($marker, (string) DB::connection()->getPdo()->query('SELECT CONNECTION_ID()')->fetchColumn());
    });
    // Real production controller, synthetic rows and no regulator/network call.
    (new PosController)->deleteTransaction($bill);
    file_put_contents($marker.'.completed', 'completed');
    echo "Actual controller completed\n";
    exit(0);
}

$root = dirname($socket).'/optional-archive-keys-'.bin2hex(random_bytes(6));
config(['local_bill_archive.key_root' => $root]);
$archive = app(OptionalLocalBillArchive::class);
foreach (['pos_transactions', 'support_bill_archive_prefs', 'support_bill_archives'] as $table) {
    requireProof(strcasecmp((string) DB::selectOne('SELECT ENGINE FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ?', [$table])->ENGINE, 'InnoDB') === 0, 'Native archive proof requires InnoDB');
}
$now = now();
$company = DB::table('companies')->insertGetId(['name' => 'RC Optional Copy Fictional A', 'ntn' => 'RC-ARCHIVE-'.bin2hex(random_bytes(4)),
    'product_type' => 'pos', 'inventory_enabled' => false, 'pra_reporting_enabled' => false, 'created_at' => $now, 'updated_at' => $now]);
$other = DB::table('companies')->insertGetId(['name' => 'RC Optional Copy Fictional B', 'ntn' => 'RC-ARCHIVE-'.bin2hex(random_bytes(4)),
    'product_type' => 'pos', 'inventory_enabled' => false, 'pra_reporting_enabled' => false, 'created_at' => $now, 'updated_at' => $now]);
$userId = DB::table('users')->insertGetId(['company_id' => $company, 'name' => 'RC Fictional owner', 'email' => 'rc-archive-'.bin2hex(random_bytes(4)).'@example.test',
    'password' => password_hash('Synthetic-never-login', PASSWORD_BCRYPT), 'role' => 'company_admin', 'pos_role' => 'pos_admin', 'created_at' => $now, 'updated_at' => $now]);
$user = User::findOrFail($userId);
$companyBefore = (array) DB::table('companies')->find($company);
$otherBefore = (array) DB::table('companies')->find($other);
function localBill(int $company, int $userId): int
{
    $id = DB::table('pos_transactions')->insertGetId(['company_id' => $company, 'created_by' => $userId,
        'invoice_number' => 'RC-OPTIONAL-'.bin2hex(random_bytes(6)), 'invoice_mode' => 'local', 'pra_status' => 'local',
        'status' => 'completed', 'transaction_type' => 'sale', 'subtotal' => 100, 'total_amount' => 100,
        'payment_method' => 'cash', 'created_at' => now(), 'updated_at' => now()]);
    DB::table('pos_transaction_items')->insert(['transaction_id' => $id, 'item_name' => 'RC synthetic item',
        'quantity' => 1, 'unit_price' => 100, 'subtotal' => 100, 'created_at' => now(), 'updated_at' => now()]);
    return $id;
}
$workers = []; $markers = [];
try {
    $archive->grant($company, $user, 30, now()->addDays(7));
    // Simulated Agent callback owns the row lock before a real deletion reads it.
    $bill = localBill($company, $userId);
    DB::beginTransaction();
    DB::table('pos_transactions')->where('id', $bill)->lockForUpdate()->first();
    DB::table('pos_transactions')->where('id', $bill)->update(['pra_invoice_number' => 'RC-FISCAL-LOCK-PROOF']);
    $marker = dirname($socket).'/archive-fiscal-'.bin2hex(random_bytes(6)); $markers[] = $marker;
    $workers[] = launchDelete($company, $userId, $bill, $root, $marker);
    waitForFile($marker); waitForLock($marker); DB::commit();
    finishDelete(array_pop($workers));
    requireProof(DB::table('pos_transactions')->where('id', $bill)->value('pra_invoice_number') === 'RC-FISCAL-LOCK-PROOF', 'Fiscal callback bill was lost');
    requireProof(DB::table('pos_transaction_items')->where('transaction_id', $bill)->count() === 1, 'Fiscal callback items changed');
    requireProof(DB::table('support_bill_archives')->where('company_id', $company)->count() === 0, 'Reported bill copied as local');

    // A stop already holding the consent lock wins before capture; the actual
    // delete may continue but must never create an optional copy after stop.
    $bill = localBill($company, $userId);
    DB::beginTransaction();
    $archive->stop($company, $user);
    $marker = dirname($socket).'/archive-stop-'.bin2hex(random_bytes(6)); $markers[] = $marker;
    $workers[] = launchDelete($company, $userId, $bill, $root, $marker);
    waitForFile($marker); waitForLock($marker); DB::commit();
    finishDelete(array_pop($workers));
    requireProof(!DB::table('pos_transactions')->where('id', $bill)->exists(), 'Stopped-consent authorized deletion failed');
    requireProof(DB::table('support_bill_archives')->where('company_id', $company)->count() === 0, 'Stop/capture race retained data without consent');

    // Active consent, actual delete, native schema and decryptable copy.
    $archive->grant($company, $user, 30, now()->addDays(7));
    $bill = localBill($company, $userId);
    $marker = dirname($socket).'/archive-copy-'.bin2hex(random_bytes(6)); $markers[] = $marker;
    finishDelete(launchDelete($company, $userId, $bill, $root, $marker));
    requireProof(!DB::table('pos_transactions')->where('id', $bill)->exists(), 'Active-consent deletion failed');
    $copy = DB::table('support_bill_archives')->where('company_id', $company)->where('source_id', $bill)->first();
    requireProof($copy !== null && $archive->readPayload($copy) !== [], 'Native actual delete did not retain decryptable facts');
    requireProof((array) DB::table('companies')->find($company) === $companyBefore, 'Archive rewrote company settings');
    requireProof((array) DB::table('companies')->find($other) === $otherBefore, 'Second company changed');
    requireProof(DB::table('support_bill_archives')->where('company_id', $other)->count() === 0, 'Second company copied without consent');
    echo "PASS: native InnoDB callback/delete and stop/capture pending FOR UPDATE; actual controller capture; existing settings and second tenant preserved\n";
} finally {
    if (DB::transactionLevel()) DB::rollBack();
    foreach ($workers as [$process, $pipes]) {
        proc_terminate($process);
        foreach ($pipes as $pipe) { fwrite(STDERR, stream_get_contents($pipe)); fclose($pipe); }
        proc_close($process);
    }
    foreach ($markers as $marker) { @unlink($marker); @unlink($marker.'.completed'); }
    if (is_dir($root)) File::deleteDirectory($root);
}

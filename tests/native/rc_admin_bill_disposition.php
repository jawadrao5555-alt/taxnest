<?php
declare(strict_types=1);

// Read-only SQL equivalence on the isolated native MariaDB RC instance.
$db = getenv('DB_DATABASE') ?: '';
if (!preg_match('/^taxnest_rc_[a-z0-9_]+_full$/', $db)
    || !str_starts_with(getenv('DB_SOCKET') ?: '', '/tmp/taxnest-rc-mariadb-')
    || getenv('APP_ENV') !== 'rc-mariadb' || !getenv('LD_PRELOAD')) {
    fwrite(STDERR, "FAIL unsafe billing disposition target\n"); exit(2);
}
require dirname(__DIR__, 2).'/vendor/autoload.php';
$app = require dirname(__DIR__, 2).'/bootstrap/app.php';
$app->make(\Illuminate\Contracts\Console\Kernel::class)->bootstrap();

use App\Services\AdminPosBillDisposition as Disposition;
use Illuminate\Support\Facades\DB;

foreach (['pos' => ['pos_transactions', 'pra'], 'fbrpos' => ['fbr_pos_transactions', 'fbr']] as $product => [$table, $prefix]) {
    foreach ([
        ['reported', 'local', 'failed', 'FISCAL-123'],
        ['local_final', $prefix, null, null], ['local_final', null, null, null],
        ['pending_decision', 'local', 'local', null],
        ['reporting_pending', $prefix, 'pending', null],
        ['reporting_pending', $prefix, 'submitted', null],
        ['reporting_pending', $prefix, 'verification_pending', null],
        ['reporting_failed', $prefix, 'failed', null],
        ['reporting_failed', $prefix, 'config_error', null],
        ['unclassified', $prefix, 'legacy_custom', null],
    ] as [$expected, $mode, $status, $number]) {
        $sql = 'SELECT '.Disposition::sql($product)." AS disposition FROM (SELECT ? AS invoice_mode, ? AS {$prefix}_status, ? AS {$prefix}_invoice_number, ? AS status) AS $table";
        $result = DB::selectOne($sql, [$mode, $status, $number, 'completed']);
        $record = (object) ['invoice_mode' => $mode, $prefix.'_status' => $status,
            $prefix.'_invoice_number' => $number, 'status' => 'completed'];
        if ($result->disposition !== $expected || Disposition::state($record, $product) !== $expected) {
            throw new RuntimeException('Read-only billing SQL/row disposition mismatch');
        }
    }
}
echo "PASS: 20 native MariaDB billing dispositions match row labels without changing any bill\n";

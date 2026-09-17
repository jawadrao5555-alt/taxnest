<?php
declare(strict_types=1);

use App\Jobs\BulkSubmitInvoiceJob;
use App\Jobs\SeedBulkSubmitBatchJob;
use Illuminate\Contracts\Console\Kernel;

require __DIR__.'/../../vendor/autoload.php';
$app = require __DIR__.'/../../bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();
config(['queue.default' => 'database']);

$batchId = isset($argv[1]) ? (int) $argv[1] : 0;
$mode = $argv[2] ?? 'dispatch';
$barrier = $argv[3] ?? '';
$ready = $argv[4] ?? '';
if ($batchId < 1 || !preg_match('/^taxnest_rc_[a-z0-9_]+_di$/', (string) getenv('DB_DATABASE'))) {
    fwrite(STDERR, "unsafe DI outbox worker target\n");
    exit(2);
}
if (!in_array($mode, ['dispatch', 'crash_after_enqueue'], true)) {
    fwrite(STDERR, "unsafe DI outbox worker mode\n");
    exit(2);
}
if ($barrier !== '') {
    if ($ready === '') {
        fwrite(STDERR, "outbox ready path missing\n");
        exit(1);
    }
    touch($ready);
    $deadline = microtime(true) + 15;
    while (!is_file($barrier) && microtime(true) < $deadline) {
        usleep(10_000);
    }
    if (!is_file($barrier)) {
        fwrite(STDERR, "outbox barrier timed out\n");
        exit(1);
    }
}
$job = new SeedBulkSubmitBatchJob($batchId);
if ($mode === 'crash_after_enqueue') {
    $claim = new ReflectionMethod($job, 'claimOutboxRows');
    $claim->setAccessible(true);
    $rows = $claim->invoke($job);
    if (count($rows) !== 1) {
        fwrite(STDERR, "crash fixture could not claim exactly one outbox row\n");
        exit(1);
    }
    // Deliberately model process loss after durable queue hand-off and before
    // dispatched_at is marked. The recovery lease must later redeliver it.
    BulkSubmitInvoiceJob::dispatch((int) $rows[0]->invoice_id, (int) $rows[0]->batch_id, $rows[0]->user_id ? (int) $rows[0]->user_id : null);
    echo "enqueued_before_mark\n";
    exit(0);
}
$method = new ReflectionMethod($job, 'dispatchOutbox');
$method->setAccessible(true);
$method->invoke($job);
echo "dispatched\n";
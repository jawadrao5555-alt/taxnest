<?php
declare(strict_types=1);

use App\Jobs\SeedBulkSubmitBatchJob;
use Illuminate\Contracts\Console\Kernel;

require __DIR__.'/../../vendor/autoload.php';
$app = require __DIR__.'/../../bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();
config(['queue.default' => 'database']);

$batchId = isset($argv[1]) ? (int) $argv[1] : 0;
$barrier = $argv[2] ?? '';
$ready = $argv[3] ?? '';
if ($batchId < 1 || !preg_match('/^taxnest_rc_[a-z0-9_]+_di$/', (string) getenv('DB_DATABASE'))) {
    fwrite(STDERR, "unsafe DI outbox worker target\n");
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
$method = new ReflectionMethod($job, 'dispatchOutbox');
$method->setAccessible(true);
$method->invoke($job);
echo "dispatched\n";
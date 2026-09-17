<?php
declare(strict_types=1);

use App\Jobs\BulkSubmitInvoiceJob;
use Illuminate\Contracts\Console\Kernel;

require __DIR__.'/../../vendor/autoload.php';
$app = require __DIR__.'/../../bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();

$batchId = isset($argv[1]) ? (int) $argv[1] : 0;
$invoiceId = isset($argv[2]) ? (int) $argv[2] : 0;
$barrier = $argv[3] ?? '';
$ready = $argv[4] ?? '';
if ($batchId < 1 || $invoiceId < 1 || !str_starts_with((string) getenv('DB_DATABASE'), 'taxnest_rc_')) {
    fwrite(STDERR, "unsafe DI result worker target\n");
    exit(2);
}
if ($barrier !== '') {
    if ($ready === '') {
        fwrite(STDERR, "result ready path missing\n");
        exit(1);
    }
    touch($ready);
    $deadline = microtime(true) + 15;
    while (!is_file($barrier) && microtime(true) < $deadline) {
        usleep(10_000);
    }
    if (!is_file($barrier)) {
        fwrite(STDERR, "result barrier timed out\n");
        exit(1);
    }
}

BulkSubmitInvoiceJob::recordResult($batchId, $invoiceId, 'success', 'native concurrent result');
echo "recorded\n";
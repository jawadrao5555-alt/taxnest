<?php
declare(strict_types=1);

use App\Jobs\BulkSubmitInvoiceJob;
use Illuminate\Contracts\Console\Kernel;

require __DIR__.'/../../vendor/autoload.php';
$app = require __DIR__.'/../../bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();

$batchId = isset($argv[1]) ? (int) $argv[1] : 0;
$invoiceId = isset($argv[2]) ? (int) $argv[2] : 0;
if ($batchId < 1 || $invoiceId < 1 || !str_starts_with((string) getenv('DB_DATABASE'), 'taxnest_rc_')) {
    fwrite(STDERR, "unsafe DI result worker target\n");
    exit(2);
}

BulkSubmitInvoiceJob::recordResult($batchId, $invoiceId, 'success', 'native concurrent result');
echo "recorded\n";
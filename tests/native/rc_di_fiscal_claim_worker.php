<?php
declare(strict_types=1);

use App\Services\DiFiscalSubmissionState;
use Illuminate\Contracts\Console\Kernel;

require __DIR__.'/../../vendor/autoload.php';
$app = require __DIR__.'/../../bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();

$invoiceId = isset($argv[1]) ? (int) $argv[1] : 0;
if ($invoiceId < 1 || !str_starts_with((string) getenv('DB_DATABASE'), 'taxnest_rc_')) {
    fwrite(STDERR, "unsafe DI claim worker target\n");
    exit(2);
}

echo DiFiscalSubmissionState::reserve($invoiceId, 'native_race', 'sandbox') ? "claimed\n" : "not_claimed\n";
<?php
declare(strict_types=1);

use App\Services\DiFiscalSubmissionState;
use Illuminate\Contracts\Console\Kernel;

require __DIR__.'/../../vendor/autoload.php';
$app = require __DIR__.'/../../bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();

$invoiceId = isset($argv[1]) ? (int) $argv[1] : 0;
$barrier = $argv[2] ?? '';
$ready = $argv[3] ?? '';
if ($invoiceId < 1 || !str_starts_with((string) getenv('DB_DATABASE'), 'taxnest_rc_')) {
    fwrite(STDERR, "unsafe DI claim worker target\n");
    exit(2);
}
if ($barrier !== '') {
    if ($ready === '') {
        fwrite(STDERR, "claim ready path missing\n");
        exit(1);
    }
    touch($ready);
    $deadline = microtime(true) + 15;
    while (!is_file($barrier) && microtime(true) < $deadline) {
        usleep(10_000);
    }
    if (!is_file($barrier)) {
        fwrite(STDERR, "claim barrier timed out\n");
        exit(1);
    }
}

echo DiFiscalSubmissionState::reserve($invoiceId, 'native_race', 'sandbox') ? "claimed\n" : "not_claimed\n";
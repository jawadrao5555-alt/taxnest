<?php

use App\Models\Company;
use App\Support\DevStagingGuard;
use Illuminate\Contracts\Console\Kernel;

require dirname(__DIR__, 2).'/vendor/autoload.php';
$app = require dirname(__DIR__, 2).'/bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();

// Laravel's web exception renderer is inappropriate for an acceptance script.
// A failed check must reach the caller as a nonzero process exit.
set_exception_handler(static function (Throwable $error): void {
    fwrite(STDERR, $error->getMessage().PHP_EOL);
    exit(1);
});
if (PHP_SAPI !== 'cli' || !$app->environment('local','testing') || env('HOSPITAL_DEMO_ALLOW') !== '1'
    || DevStagingGuard::problems()) {
    throw new RuntimeException('Opted-in local disposable MariaDB required.');
}
$hospital = Company::where('ntn','DEMO-344-A')->firstOrFail();
if ($hospital->name !== 'TaxNest Demo General Hospital' || $hospital->fbr_reporting_enabled) {
    throw new RuntimeException('Incorrect fixture or regulator reporting enabled.');
}
return $hospital;

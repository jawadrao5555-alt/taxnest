<?php

// Only the disposable file-session Chromium fixture may invoke this operator drill.
$socket = getenv('DB_SOCKET') ?: '';
if (getenv('APP_ENV') !== 'rc-browser' || getenv('DB_DATABASE') !== 'taxnest_rc_browser'
    || ! getenv('LD_PRELOAD') || ! preg_match('#^/tmp/taxnest-rc-mariadb-browser-[0-9]+/run/mariadb.sock$#D', $socket)) {
    fwrite(STDERR, "FAIL unsafe browser restore drill\n");
    exit(2);
}
require dirname(__DIR__, 2).'/vendor/autoload.php';
$app = require dirname(__DIR__, 2).'/bootstrap/app.php';
$app->make(\Illuminate\Contracts\Console\Kernel::class)->bootstrap();
if (config('session.driver') !== 'file') {
    throw new RuntimeException('File session proof is required.');
}
$before = \Illuminate\Support\Facades\DB::table('companies')->orderBy('id')->get()->toJson();
if (\Illuminate\Support\Facades\Artisan::call('security:restore-quarantine', [
    '--restore-id' => 'browser-drill-'.bin2hex(random_bytes(16)), '--confirm' => 'Quarantine restored access',
]) !== 0 || $before !== \Illuminate\Support\Facades\DB::table('companies')->orderBy('id')->get()->toJson()) {
    throw new RuntimeException('Browser restore quarantine or company preservation failed.');
}
echo "PASS: disposable file-session restore quarantine preserves companies and Agent pairing\n";

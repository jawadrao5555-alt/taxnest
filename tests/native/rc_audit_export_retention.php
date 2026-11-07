<?php

declare(strict_types=1);

$database = getenv('DB_DATABASE') ?: '';
$socket = getenv('DB_SOCKET') ?: '';
if (getenv('APP_ENV') !== 'rc-mariadb' || ! getenv('LD_PRELOAD')
    || ! preg_match('/^taxnest_rc_[a-z0-9_]+_full$/D', $database)
    || ! preg_match('#^/tmp/taxnest-rc-mariadb-[a-z0-9_]+/run/mariadb.sock$#D', $socket)) {
    fwrite(STDERR, "FAIL unsafe audit export retention target\n");
    exit(2);
}
require dirname(__DIR__, 2).'/vendor/autoload.php';
$app = require dirname(__DIR__, 2).'/bootstrap/app.php';
$app->make(\Illuminate\Contracts\Console\Kernel::class)->bootstrap();

use App\Models\AuditPack;
use App\Models\Company;
use App\Services\AuditPackRetention;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\Process\Process;

config(['filesystems.disks.local.root' => dirname($socket).'/audit-retention']);
Storage::forgetDisk('local');
if (($argv[1] ?? '') === 'worker') {
    [$companyId,$barrier,$index] = array_slice($argv, 2);
    if (! ctype_digit($companyId) || ! ctype_digit($index) || ! str_starts_with($barrier, dirname($socket).'/audit-retention-')) {
        throw new RuntimeException('Unsafe retention barrier');
    }
    file_put_contents($barrier.'.ready-'.$index, 'ready');
    $until = microtime(true) + 20;
    while (! is_file($barrier)) {
        if (microtime(true) > $until) {
            throw new RuntimeException('Retention barrier timed out');
        }usleep(10000);
    }
    $result = AuditPackRetention::prune((int) $companyId, 1);
    if ($result['failed']) {
        throw new RuntimeException('Native retention worker failed');
    }
    echo 'EXPIRED='.$result['expired']."\n";
    exit(0);
}
$suffix = bin2hex(random_bytes(8));
$company = Company::create(['name' => 'Synthetic export retention', 'ntn' => $suffix, 'product_type' => 'di']);
$other = Company::create(['name' => 'Other synthetic export retention', 'ntn' => 'x'.$suffix, 'product_type' => 'di']);
$make = function ($tenant, $days) {
    $pack = AuditPack::create(['company_id' => $tenant, 'date_from' => '2026-01-01', 'date_to' => '2026-01-31', 'status' => 'ready']);
    $pack->forceFill(['created_at' => now()->subDays($days), 'file_path' => 'audit-packs/company_'.$tenant.'/fbr-audit-pack-'.$pack->id.'.zip', 'file_size' => 18])->save();
    Storage::disk('local')->put($pack->file_path, 'SYNTHETIC ZIP DATA');

    return $pack;
};
$pack = $make($company->id, 8);
$foreign = $make($other->id, 1);
$foreignBefore = $foreign->fresh()->getAttributes();
$invoiceCount = DB::table('invoices')->count();
$barrier = dirname($socket).'/audit-retention-'.$suffix;
$processes = [];
try {
    foreach ([1, 2] as $index) {
        $p = new Process([PHP_BINARY, '-c', php_ini_loaded_file(), __FILE__, 'worker', (string) $company->id, $barrier, (string) $index]);
        $p->setTimeout(40);
        $p->start();
        $processes[] = $p;
    }
    $until = microtime(true) + 20;
    while (count(glob($barrier.'.ready-*')) !== 2) {
        if (microtime(true) > $until) {
            throw new RuntimeException('Retention workers not ready');
        }usleep(10000);
    }
    file_put_contents($barrier, 'go');
    $expired = 0;
    foreach ($processes as $p) {
        $p->wait();
        if (! $p->isSuccessful()) {
            throw new RuntimeException('Retention worker failed: '.$p->getErrorOutput());
        }if (! preg_match('/EXPIRED=(\d+)/', $p->getOutput(), $match)) {
            throw new RuntimeException('Missing retention count');
        }$expired += (int) $match[1];
    }
    $retained = $pack->fresh();
    if ($expired !== 1 || ! $retained || $retained->status !== 'expired' || $retained->file_path !== null || Storage::disk('local')->exists($pack->file_path)
        || $foreignBefore !== $foreign->fresh()->getAttributes() || ! Storage::disk('local')->exists($foreign->file_path)
        || $invoiceCount !== DB::table('invoices')->count()) {
        throw new RuntimeException('Native retention identity/preservation proof failed');
    }
    $bad = $make($company->id, 8);
    $bad->update(['file_path' => $foreign->file_path]);
    $before = $bad->fresh()->getAttributes();
    $failed = AuditPackRetention::prune($company->id, 1);
    if ($failed['failed'] !== 1 || $before !== $bad->fresh()->getAttributes() || ! Storage::disk('local')->exists($foreign->file_path)) {
        throw new RuntimeException('Native retention path isolation failed');
    }
    echo "PASS: native concurrent audit export expiry retains history and invoices; foreign paths and current exports preserved\n";
} finally {
    foreach ($processes as $p) {
        if ($p->isRunning()) {
            $p->stop(1);
        }
    }
    if (is_file($barrier)) {
        unlink($barrier);
    }foreach (glob($barrier.'.ready-*') as $ready) {
        unlink($ready);
    }
}

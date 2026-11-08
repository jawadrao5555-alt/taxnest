<?php

declare(strict_types=1);

$database = getenv('DB_DATABASE') ?: '';
$socket = getenv('DB_SOCKET') ?: '';
if (getenv('APP_ENV') !== 'rc-mariadb' || !getenv('LD_PRELOAD')
    || !preg_match('/^taxnest_rc_[a-z0-9_]+_full$/D', $database)
    || !preg_match('#^/tmp/taxnest-rc-mariadb-[a-z0-9_]+/run/mariadb.sock$#D', $socket)) {
    fwrite(STDERR, "FAIL unsafe company deletion lifecycle target\n");
    exit(2);
}
require dirname(__DIR__, 2).'/vendor/autoload.php';
$app = require dirname(__DIR__, 2).'/bootstrap/app.php';
$app->make(\Illuminate\Contracts\Console\Kernel::class)->bootstrap();

use App\Models\{AdminUser, Company, User};
use App\Services\CompanyDeletionLifecycle;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\{Auth, DB, Hash};
use Symfony\Component\Process\Process;

if (($argv[1] ?? '') === 'worker') {
    [$companyId, $userId, $barrier, $index] = array_slice($argv, 2);
    if (!ctype_digit($companyId) || !ctype_digit($userId) || !ctype_digit($index)
        || !str_starts_with($barrier, dirname($socket).'/company-deletion-')) {
        throw new RuntimeException('Unsafe deletion lifecycle barrier.');
    }
    $session = app('session')->driver();
    $request = Request::create('/profile', 'DELETE', ['reason' => 'Concurrent verified synthetic request']);
    $request->setLaravelSession($session);
    app()->instance('request', $request);
    Auth::guard('web')->loginUsingId((int) $userId);
    file_put_contents($barrier.'.ready-'.$index, 'ready');
    $deadline = microtime(true) + 20;
    while (!is_file($barrier)) {
        if (microtime(true) > $deadline) throw new RuntimeException('Deletion lifecycle barrier timed out.');
        usleep(10000);
    }
    $record = app(CompanyDeletionLifecycle::class)->requestByOwner(
        Company::findOrFail((int) $companyId), User::findOrFail((int) $userId), 'Concurrent verified synthetic request'
    );
    echo 'REQUEST_ID='.$record->id."\n";
    exit(0);
}

$suffix = bin2hex(random_bytes(8));
$company = Company::create(['name' => 'Synthetic deletion lifecycle', 'ntn' => $suffix, 'product_type' => 'di']);
$company->forceFill(['agent_api_key' => 'synthetic-pair-'.$suffix, 'agent_enabled' => true])->saveQuietly();
$other = Company::create(['name' => 'Foreign synthetic deletion lifecycle', 'ntn' => 'x'.$suffix, 'product_type' => 'di']);
$owner = User::create(['name' => 'Synthetic deletion owner', 'company_id' => $company->id,
    'email' => $suffix.'@deletion.invalid', 'password' => Hash::make('SyntheticOwner123!'), 'role' => 'company_admin']);
$foreign = User::create(['name' => 'Foreign deletion owner', 'company_id' => $other->id,
    'email' => 'other-'.$suffix.'@deletion.invalid', 'password' => Hash::make('SyntheticOther123!'), 'role' => 'company_admin']);
$foreign->forceFill(['session_generation' => '22222222-2222-4222-8222-222222222222'])->saveQuietly();
$foreignBefore = $foreign->fresh()->getAttributes();
$agentBefore = $company->fresh()->only(['agent_api_key', 'agent_api_key_hash', 'agent_enabled']);
$barrier = dirname($socket).'/company-deletion-'.$suffix;
$processes = [];
try {
    foreach ([1, 2] as $index) {
        $process = new Process([PHP_BINARY, '-c', php_ini_loaded_file(), __FILE__, 'worker',
            (string) $company->id, (string) $owner->id, $barrier, (string) $index]);
        $process->setTimeout(40);
        $process->start();
        $processes[] = $process;
    }
    $deadline = microtime(true) + 20;
    while (count(glob($barrier.'.ready-*')) !== 2) {
        if (microtime(true) > $deadline) throw new RuntimeException('Deletion request workers did not reach barrier.');
        usleep(10000);
    }
    file_put_contents($barrier, 'go');
    $requestIds = [];
    foreach ($processes as $process) {
        $process->wait();
        if (!$process->isSuccessful()) throw new RuntimeException('Deletion request worker failed: '.$process->getErrorOutput());
        if (!preg_match('/REQUEST_ID=(\d+)/', $process->getOutput(), $match)) {
            throw new RuntimeException('Deletion request worker omitted identity.');
        }
        $requestIds[] = (int) $match[1];
    }
    if (count(array_unique($requestIds)) !== 1
        || DB::table('company_deletion_requests')->where('company_id', $company->id)->count() !== 1
        || DB::table('company_deletion_request_events')->where('company_id', $company->id)
            ->where('event', 'deletion_requested')->count() !== 1) {
        throw new RuntimeException('Concurrent deletion requests did not converge on one verified lifecycle.');
    }

    $admin = AdminUser::create(['name' => 'Synthetic lifecycle admin',
        'email' => 'admin-'.$suffix.'@deletion.invalid', 'password' => Hash::make('SyntheticAdmin123!'), 'role' => 'super_admin']);
    $service = app(CompanyDeletionLifecycle::class);
    DB::transaction(function () use ($company, $admin, $service) {
        $locked = Company::query()->whereKey($company->id)->lockForUpdate()->firstOrFail();
        $service->beginHold($locked, $admin, 'Reviewed synthetic request');
        $locked->forceFill(['deleted_reason' => 'Reviewed synthetic request'])->saveQuietly();
        $locked->delete();
    });
    $held = DB::table('company_deletion_requests')->where('company_id', $company->id)->first();
    $holdSeconds = (int) \Illuminate\Support\Carbon::parse($held->hold_until)
        ->diffInSeconds(\Illuminate\Support\Carbon::parse($held->binned_at));
    if ($held->status !== CompanyDeletionLifecycle::ON_HOLD
        || $holdSeconds !== Company::BIN_HOLD_DAYS * 86400) {
        throw new RuntimeException('Native deletion hold did not retain the exact deadline.');
    }

    DB::transaction(function () use ($company, $admin, $service) {
        $locked = Company::onlyTrashed()->whereKey($company->id)->lockForUpdate()->firstOrFail();
        $locked->restore();
        $locked->forceFill(['deleted_reason' => null])->saveQuietly();
        $service->restored($locked, $admin);
    });
    $restored = DB::table('company_deletion_requests')->where('company_id', $company->id)->first();
    if ($restored->status !== CompanyDeletionLifecycle::RESTORED || $restored->active_key !== null
        || !\Illuminate\Support\Str::isUuid((string) $owner->fresh()->session_generation)
        || $foreignBefore !== $foreign->fresh()->getAttributes()
        || $agentBefore !== $company->fresh()->only(['agent_api_key', 'agent_api_key_hash', 'agent_enabled'])) {
        throw new RuntimeException('Native deletion restore isolation or Agent-pairing preservation failed.');
    }
    echo "PASS: native concurrent verified company deletion request creates one exact hold; restore revokes only browser sessions and preserves foreign tenant and Agent pairing\n";
} finally {
    foreach ($processes as $process) if ($process->isRunning()) $process->stop(1);
    if (is_file($barrier)) unlink($barrier);
    foreach (glob($barrier.'.ready-*') as $ready) unlink($ready);
}

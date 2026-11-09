<?php

declare(strict_types=1);

$database = getenv('DB_DATABASE') ?: '';
$socket = getenv('DB_SOCKET') ?: '';
if (getenv('APP_ENV') !== 'rc-mariadb' || ! getenv('LD_PRELOAD')
    || ! preg_match('/^taxnest_rc_[a-z0-9_]+_full$/D', $database)
    || ! preg_match('#^/tmp/taxnest-rc-mariadb-[a-z0-9_]+/run/mariadb.sock$#D', $socket)) {
    fwrite(STDERR, "FAIL unsafe support lifecycle target\n");
    exit(2);
}
require dirname(__DIR__, 2).'/vendor/autoload.php';
$app = require dirname(__DIR__, 2).'/bootstrap/app.php';
$app->make(\Illuminate\Contracts\Console\Kernel::class)->bootstrap();

use App\Http\Controllers\SaasAdmin\SupportInboxController;
use App\Models\AdminUser;
use App\Models\Company;
use App\Models\SupportCase;
use App\Models\SupportCaseEvent;
use Illuminate\Http\Request;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;
use Symfony\Component\Process\Process;

if (($argv[1] ?? '') === 'worker') {
    [$caseId, $adminId, $barrier, $index] = array_slice($argv, 2);
    if (! ctype_digit($caseId) || ! ctype_digit($adminId) || ! ctype_digit($index)
        || ! str_starts_with($barrier, dirname($socket).'/support-case-')) {
        throw new RuntimeException('Unsafe support lifecycle worker');
    }
    $session = app('session')->driver();
    $request = Request::create('/admin/support-inbox/cases/'.$caseId.'/resolve', 'POST', [
        'resolution_build' => 'native-support-1',
        'resolution_note' => 'Native concurrent exact-build resolution.',
    ]);
    $request->setLaravelSession($session);
    app()->instance('request', $request);
    auth('admin')->login(AdminUser::findOrFail((int) $adminId));
    file_put_contents($barrier.'.ready-'.$index, 'ready');
    $deadline = microtime(true) + 20;
    while (! is_file($barrier)) {
        if (microtime(true) > $deadline) {
            throw new RuntimeException('Support lifecycle barrier timed out');
        }
        usleep(10000);
    }
    try {
        app(SupportInboxController::class)->resolveCase($request, SupportCase::findOrFail((int) $caseId));
        echo "RESOLUTION_WON\n";
    } catch (HttpExceptionInterface $e) {
        if ($e->getStatusCode() !== 409) {
            throw $e;
        }
        echo "RESOLUTION_REFUSED\n";
    }
    exit(0);
}

$suffix = bin2hex(random_bytes(8));
$admin = AdminUser::create([
    'name' => 'Synthetic native support owner',
    'email' => $suffix.'@support.invalid',
    'password' => bcrypt('SyntheticOnly123!'),
    'role' => 'super_admin',
]);
$company = Company::create(['name' => 'Synthetic support company', 'ntn' => $suffix, 'product_type' => 'pos']);
$other = Company::create(['name' => 'Other support company', 'ntn' => 'x'.$suffix, 'product_type' => 'pos']);
$make = function (Company $tenant, int $uid) use ($admin): SupportCase {
    $case = SupportCase::create([
        'company_id' => $tenant->id,
        'assigned_admin_id' => $admin->id,
        'opened_by_admin_id' => $admin->id,
        'source_box' => 'inbox',
        'source_uid' => $uid,
        'subject' => 'Synthetic native support case',
        'status' => SupportCase::STATUS_OPEN,
        'opened_at' => '2026-01-01 12:00:00',
    ]);
    SupportCaseEvent::create([
        'support_case_id' => $case->id,
        'actor_admin_id' => $admin->id,
        'event_type' => 'opened',
        'to_status' => SupportCase::STATUS_OPEN,
        'note' => 'Synthetic native opening.',
        'occurred_at' => now(),
    ]);

    return $case;
};
$case = $make($company, random_int(100000, 999999));
$foreign = $make($other, random_int(1000000, 1999999));
$foreignBefore = $foreign->fresh()->getAttributes();
$foreignEventsBefore = $foreign->events()->get()->map->getAttributes()->all();
$session = app('session')->driver();
$slaRequest = Request::create('/admin/support-inbox/cases/'.$case->id.'/assign', 'POST', [
    'assigned_admin_id' => $admin->id,
    'priority' => SupportCase::PRIORITY_NORMAL,
    'sla_due_at' => '2030-01-01T12:00',
]);
$slaRequest->setLaravelSession($session);
app()->instance('request', $slaRequest);
auth('admin')->login($admin);
app(SupportInboxController::class)->assignCase($slaRequest, $case);
$case->refresh();
if ($case->opened_at?->format('Y-m-d H:i:s') !== '2026-01-01 12:00:00'
    || $case->sla_due_at?->format('Y-m-d H:i:s') !== '2030-01-01 12:00:00'
    || $case->events()->where('event_type', 'sla_changed')->count() !== 1) {
    throw new RuntimeException('Support SLA deadline or timeline evidence was not retained');
}
$barrier = dirname($socket).'/support-case-'.$suffix;
$processes = [];
try {
    foreach ([1, 2] as $index) {
        $process = new Process([PHP_BINARY, '-c', php_ini_loaded_file(), __FILE__, 'worker', (string) $case->id,
            (string) $admin->id, $barrier, (string) $index]);
        $process->setTimeout(40);
        $process->start();
        $processes[] = $process;
    }
    $deadline = microtime(true) + 20;
    while (count(glob($barrier.'.ready-*')) !== 2) {
        if (microtime(true) > $deadline) {
            throw new RuntimeException('Support lifecycle workers not ready');
        }
        usleep(10000);
    }
    file_put_contents($barrier, 'go');
    $wins = 0;
    foreach ($processes as $process) {
        $process->wait();
        if (! $process->isSuccessful()) {
            throw new RuntimeException('Support lifecycle worker failed: '.$process->getErrorOutput());
        }
        $wins += substr_count($process->getOutput(), 'RESOLUTION_WON');
    }
    $case->refresh();
    if ($wins !== 1 || $case->status !== SupportCase::STATUS_RESOLVED || $case->resolution_build !== 'native-support-1'
        || $case->events()->where('event_type', 'resolved')->count() !== 1
        || $foreignBefore !== $foreign->fresh()->getAttributes()
        || $foreignEventsBefore !== $foreign->events()->get()->map->getAttributes()->all()) {
        throw new RuntimeException('Concurrent support resolution or tenant preservation failed');
    }

    $session = app('session')->driver();
    $request = Request::create('/admin/support-inbox/cases/'.$case->id.'/reopen', 'POST', [
        'reopen_note' => 'Native recurrence evidence.',
    ]);
    $request->setLaravelSession($session);
    app()->instance('request', $request);
    auth('admin')->login($admin);
    app(SupportInboxController::class)->reopenCase($request, $case);
    $case->refresh();
    if ($case->status !== SupportCase::STATUS_REOPENED || $case->resolution_build !== 'native-support-1'
        || $case->sla_due_at?->format('Y-m-d H:i:s') !== '2030-01-01 12:00:00'
        || $case->events()->where('event_type', 'sla_changed')->count() !== 1
        || $case->events()->where('event_type', 'reopened')->count() !== 1
        || $case->events()->count() !== 4) {
        throw new RuntimeException('Support reopen did not retain resolution timeline');
    }
    $related = $make($company, random_int(2000000, 2999999));
    $linkRequest = Request::create('/admin/support-inbox/cases/'.$case->id.'/links', 'POST', [
        'related_case_id' => $related->id, 'kind' => 'duplicate', 'note' => 'Native retained duplicate message evidence.',
    ]);
    $linkRequest->setLaravelSession($session);
    app(SupportInboxController::class)->linkCase($linkRequest, $case);
    $reverse = Request::create('/admin/support-inbox/cases/'.$related->id.'/links', 'POST', [
        'related_case_id' => $case->id, 'kind' => 'duplicate', 'note' => 'Native reversed retry.',
    ]);
    $reverse->setLaravelSession($session);
    app(SupportInboxController::class)->linkCase($reverse, $related);
    if (\Illuminate\Support\Facades\DB::table('support_case_links')->where('company_id', $company->id)->count() !== 1
        || $case->fresh()->status !== SupportCase::STATUS_REOPENED
        || $foreignBefore !== $foreign->fresh()->getAttributes()) {
        throw new RuntimeException('Native duplicate evidence identity or status preservation failed.');
    }
    echo "PASS: native support duplicate links remain idempotent and preserve both case states and other tenant\n";
    $originalCompanyId = (int) $company->id;
    $company->delete();
    $company->forceDelete();
    $case->refresh();
    if ($case->opened_at?->format('Y-m-d H:i:s') !== '2026-01-01 12:00:00'
        || $case->company_id !== null || (int) $case->company_id_snapshot !== $originalCompanyId
        || $case->company_name_snapshot !== $company->name
        || $case->events()->where('event_type', 'resolved')->count() !== 1
        || $foreignBefore !== $foreign->fresh()->getAttributes()) {
        throw new RuntimeException('Native support history was lost or blocked reviewed company purge.');
    }
    $retainedLinks = app(SupportInboxController::class)->caseShow($case)->getData()['caseLinks'];
    if (count($retainedLinks) !== 1 || $retainedLinks[0]['case_id'] !== (int) $related->id) {
        throw new RuntimeException('Native retained links lost original company scope after purge.');
    }
    echo "PASS: native reviewed company purge retains support identity and immutable history without cross-tenant loss\n";
    echo "PASS: native support SLA and concurrent resolution retain one exact build; reopen keeps history and other company unchanged\n";
} finally {
    foreach ($processes as $process) {
        if ($process->isRunning()) {
            $process->stop(1);
        }
    }
    if (is_file($barrier)) {
        unlink($barrier);
    }
    foreach (glob($barrier.'.ready-*') as $ready) {
        unlink($ready);
    }
}

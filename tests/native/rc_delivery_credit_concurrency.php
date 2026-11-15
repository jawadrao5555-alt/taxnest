<?php
declare(strict_types=1);

// This probe may only use the socket-only, disposable native RC lab.
$db = getenv('DB_DATABASE') ?: '';
$socket = getenv('DB_SOCKET') ?: '';
if (!preg_match('/^taxnest_rc_[a-z0-9_]+_full$/', $db)
    || !preg_match('#^/tmp/taxnest-rc-mariadb-[a-z0-9_]+/run/mariadb.sock$#', $socket)
    || getenv('APP_ENV') !== 'rc-mariadb' || !getenv('LD_PRELOAD')) {
    fwrite(STDERR, "FAIL unsafe delivery credit concurrency target\n");
    exit(2);
}
require dirname(__DIR__, 2).'/vendor/autoload.php';
$app = require dirname(__DIR__, 2).'/bootstrap/app.php';
$app->make(\Illuminate\Contracts\Console\Kernel::class)->bootstrap();

use App\Http\Controllers\FbrPosSchoolFeeController;
use App\Models\{Company, User};
use Illuminate\Http\Request;
use Illuminate\Support\Facades\{DB, Http};
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

Http::preventStrayRequests();

function creditRequest(array $fixture, string $action, array $payload): array
{
    auth('pos')->setUser(User::findOrFail($fixture['user']));
    app()->instance('currentCompanyId', $fixture['company']);
    app()->instance('currentBranchGuard', 'pos');
    app()->bind('currentBranchId', fn () => null);
    try {
        \App\Services\PosDeliveryAccount::collect($fixture['company'], $fixture['account'], $fixture['user'], $payload['request_id'], $payload['amount']);
        return ['status' => 200];
    } catch (ValidationException $e) { return ['status' => 422]; }
}

if (($argv[1] ?? '') === 'worker') {
    $file = $argv[2]; $worker = (int) $argv[3];
    if (!str_starts_with($file, dirname($socket).'/credit-')) throw new RuntimeException('Unsafe school fixture');
    $fixture = json_decode(file_get_contents($file), true, 512, JSON_THROW_ON_ERROR);
    file_put_contents($file.'.ready.'.$worker, 'ready');
    $deadline = microtime(true) + 20;
    while (!is_file($file.'.go')) {
        if (microtime(true) > $deadline) throw new RuntimeException('Credit worker barrier timeout');
        usleep(10000);
    }
    $job = $fixture['jobs'][$worker];
    $result = creditRequest($fixture, $job['action'], $job['payload']);
    file_put_contents($file.'.result.'.$worker, json_encode($result, JSON_THROW_ON_ERROR));
    exit(0);
}

function creditRace(array $fixture, array $jobs): array
{
    $file = dirname(getenv('DB_SOCKET')).'/credit-'.bin2hex(random_bytes(8));
    file_put_contents($file, json_encode($fixture + ['jobs' => $jobs], JSON_THROW_ON_ERROR));
    chmod($file, 0600);
    $workers = [];
    try {
        foreach ([0, 1] as $i) {
            $process = proc_open([PHP_BINARY, __FILE__, 'worker', $file, (string) $i],
                [0 => ['file', '/dev/null', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
            if (!is_resource($process)) throw new RuntimeException('Credit worker launch failed');
            foreach ($pipes as $pipe) stream_set_blocking($pipe, false);
            $workers[] = ['process' => $process, 'pipes' => $pipes, 'output' => '', 'exit' => null];
        }
        $deadline = microtime(true) + 30;
        while (!is_file($file.'.ready.0') || !is_file($file.'.ready.1')) {
            if (microtime(true) > $deadline) throw new RuntimeException('Credit workers failed before barrier');
            usleep(10000);
        }
        file_put_contents($file.'.go', 'go');
        do {
            $running = false;
            foreach ($workers as &$worker) {
                foreach ($worker['pipes'] as $pipe) $worker['output'] .= stream_get_contents($pipe);
                if ($worker['exit'] !== null) continue;
                $status = proc_get_status($worker['process']);
                if ($status['running']) $running = true;
                else $worker['exit'] = $status['exitcode'];
            }
            unset($worker);
            if ($running && microtime(true) > $deadline) throw new RuntimeException('Credit race timeout');
            if ($running) usleep(10000);
        } while ($running);
        foreach ($workers as $worker) {
            if ($worker['exit'] !== 0) throw new RuntimeException('Credit worker failed: '.$worker['output']);
        }
        return array_map(fn ($i) => json_decode(file_get_contents($file.'.result.'.$i), true, 512, JSON_THROW_ON_ERROR), [0, 1]);
    } finally {
        foreach ($workers as $worker) {
            if (proc_get_status($worker['process'])['running']) proc_terminate($worker['process']);
            foreach ($worker['pipes'] as $pipe) fclose($pipe);
            proc_close($worker['process']);
        }
        foreach (['', '.go', '.ready.0', '.ready.1', '.result.0', '.result.1'] as $suffix) @unlink($file.$suffix);
    }
}

function creditFixture(): array
{
    $suffix = bin2hex(random_bytes(6));
    $company = Company::create(['name' => 'Synthetic credit '.$suffix, 'ntn' => 'RCCREDIT'.$suffix,
        'email' => 'credit-'.$suffix.'@native.invalid', 'product_type' => 'pos',
        'status' => 'approved', 'company_status' => 'active', 'is_internal_account' => true]);
    $user = User::create(['name' => 'Synthetic credit owner', 'email' => 'credit-owner-'.$suffix.'@native.invalid',
        'password' => bcrypt('SyntheticOnly123!'), 'company_id' => $company->id,
        'role' => 'company_admin', 'pos_role' => 'pos_admin', 'is_active' => true]);
    $account = DB::table('pos_delivery_accounts')->insertGetId([
        'company_id' => $company->id, 'transaction_id' => 900000001, 'rider_id' => 900000001,
        'rider_name' => 'Retained synthetic rider', 'customer_name' => 'Retained synthetic customer',
        'business_date' => today()->toDateString(), 'received_amount' => 0,
        'credit_amount' => 1000, 'outstanding_amount' => 1000, 'created_at' => now(), 'updated_at' => now()]);
    return ['company' => $company->id, 'user' => $user->id, 'account' => $account];
}
$f = creditFixture();
$payload = ['request_id' => (string) Str::uuid(), 'amount' => '400'];
$race = creditRace($f, array_fill(0, 2, ['action' => 'collect', 'payload' => $payload]));
$account = fn () => DB::table('pos_delivery_accounts')->where('id', $f['account'])->first();
$events = fn () => DB::table('pos_delivery_account_events')->where('account_id', $f['account']);
if (array_column($race, 'status') !== [200, 200] || (float) $account()->outstanding_amount !== 600.0 || $events()->count() !== 1) throw new RuntimeException('Duplicate concurrent credit recovery counted twice');
$race = creditRace($f, [
    ['action' => 'collect', 'payload' => ['request_id' => (string) Str::uuid(), 'amount' => '500']],
    ['action' => 'collect', 'payload' => ['request_id' => (string) Str::uuid(), 'amount' => '500']],
]);
$statuses = array_column($race, 'status'); sort($statuses);
if ($statuses !== [200, 422] || (float) $account()->outstanding_amount !== 100.0 || (float) $events()->sum('amount') !== 900.0) throw new RuntimeException('Concurrent recovery exceeded outstanding credit');
if ($account()->rider_name !== 'Retained synthetic rider' || (float) $account()->credit_amount !== 1000.0) throw new RuntimeException('Collection rewrote original financial attribution');
echo "PASS: native retained delivery credit duplicate recovery is single and concurrent overcollection rejected\n";

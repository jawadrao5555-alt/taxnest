<?php
declare(strict_types=1);

// This probe may only use the socket-only, disposable native RC lab.
$db = getenv('DB_DATABASE') ?: '';
$socket = getenv('DB_SOCKET') ?: '';
if (!preg_match('/^taxnest_rc_[a-z0-9_]+_full$/', $db)
    || !preg_match('#^/tmp/taxnest-rc-mariadb-[a-z0-9_]+/run/mariadb.sock$#', $socket)
    || getenv('APP_ENV') !== 'rc-mariadb' || !getenv('LD_PRELOAD')) {
    fwrite(STDERR, "FAIL unsafe school fee concurrency target\n");
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

function schoolRequest(array $fixture, string $action, array $payload): array
{
    \Illuminate\Support\Carbon::setTestNow($fixture['now']);
    auth('fbrpos')->setUser(User::findOrFail($fixture['user']));
    app()->instance('currentCompanyId', $fixture['company']);
    app()->instance('currentBranchGuard', 'fbrpos');
    app()->bind('currentBranchId', fn () => null);
    $request = Request::create('/fbr-pos/'.($action === 'close' ? 'day-close' : 'school-fees'),
        'POST', $payload, [], [], ['HTTP_ACCEPT' => 'application/json']);
    $request->setLaravelSession(app('session')->driver());
    app()->instance('request', $request);
    try {
        $response = app(FbrPosSchoolFeeController::class)->{$action}($request);
        if ($response->getStatusCode() !== 200) throw new RuntimeException('Unexpected response status');
        return ['status' => 200, 'data' => $response->getData(true)];
    } catch (ValidationException $exception) {
        return ['status' => 422];
    }
}

if (($argv[1] ?? '') === 'worker') {
    $file = $argv[2]; $worker = (int) $argv[3];
    if (!str_starts_with($file, dirname($socket).'/school-')) throw new RuntimeException('Unsafe school fixture');
    $fixture = json_decode(file_get_contents($file), true, 512, JSON_THROW_ON_ERROR);
    file_put_contents($file.'.ready.'.$worker, 'ready');
    $deadline = microtime(true) + 20;
    while (!is_file($file.'.go')) {
        if (microtime(true) > $deadline) throw new RuntimeException('School worker barrier timeout');
        usleep(10000);
    }
    $job = $fixture['jobs'][$worker];
    $result = schoolRequest($fixture, $job['action'], $job['payload']);
    file_put_contents($file.'.result.'.$worker, json_encode($result, JSON_THROW_ON_ERROR));
    exit(0);
}

function schoolRace(array $fixture, array $jobs): array
{
    $file = dirname(getenv('DB_SOCKET')).'/school-'.bin2hex(random_bytes(8));
    file_put_contents($file, json_encode($fixture + ['jobs' => $jobs], JSON_THROW_ON_ERROR));
    chmod($file, 0600);
    $workers = [];
    try {
        foreach ([0, 1] as $i) {
            $process = proc_open([PHP_BINARY, __FILE__, 'worker', $file, (string) $i],
                [0 => ['file', '/dev/null', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
            if (!is_resource($process)) throw new RuntimeException('School worker launch failed');
            foreach ($pipes as $pipe) stream_set_blocking($pipe, false);
            $workers[] = ['process' => $process, 'pipes' => $pipes, 'output' => '', 'exit' => null];
        }
        $deadline = microtime(true) + 30;
        while (!is_file($file.'.ready.0') || !is_file($file.'.ready.1')) {
            if (microtime(true) > $deadline) throw new RuntimeException('School workers failed before barrier');
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
            if ($running && microtime(true) > $deadline) throw new RuntimeException('School race timeout');
            if ($running) usleep(10000);
        } while ($running);
        foreach ($workers as $worker) {
            if ($worker['exit'] !== 0) throw new RuntimeException('School worker failed: '.$worker['output']);
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

function schoolFixture(string $suffix): array
{
    $company = Company::create([
        'name' => 'Synthetic school race '.$suffix, 'ntn' => 'RCSCHOOL'.$suffix,
        'email' => 'school-'.$suffix.'@native.invalid', 'product_type' => 'fbrpos',
        'pos_type' => 'education', 'business_category' => 'education',
        'status' => 'approved', 'company_status' => 'active', 'is_internal_account' => true,
        'fbr_pos_enabled' => true, 'fbr_reporting_enabled' => false,
    ]);
    \App\Models\Subscription::create([
        'company_id' => $company->id, 'pricing_plan_id' => null, 'start_date' => today(),
        'end_date' => null, 'active' => true, 'override_type' => 'lifetime',
    ]);
    $user = User::create([
        'name' => 'Synthetic school owner', 'email' => 'school-owner-'.$suffix.'@native.invalid',
        'password' => bcrypt('SyntheticOnly123!'), 'company_id' => $company->id,
        'role' => 'company_admin', 'pos_role' => 'pos_admin', 'is_active' => true,
    ]);
    return ['company' => $company->id, 'user' => $user->id, 'now' => now()->startOfDay()->addHours(12)->toIso8601String()];
}
function schoolPayload(): array
{
    return ['student_reference' => 'SYN-RACE-001', 'fee_head' => 'tuition', 'amount' => '1250.50',
        'payment_method' => 'cash', 'cash_confirmed' => '1', 'request_key' => (string) Str::uuid()];
}
function schoolClosePayload(array $fixture, int $count): array
{
    return ['date' => substr($fixture['now'], 0, 10), 'confirm' => '1',
        'expected_receipt_count' => $count, 'expected_cash_paisa' => $count * 125050];
}

$fixture = schoolFixture(bin2hex(random_bytes(5)));
$payload = schoolPayload();
$results = schoolRace($fixture, array_fill(0, 2, ['action' => 'store', 'payload' => $payload]));
$receipts = fn () => DB::table('school_fee_receipts')->where('company_id', $fixture['company']);
$closes = fn () => DB::table('school_fee_day_closes')->where('company_id', $fixture['company']);
if ($results[0]['status'] !== 200 || $results[1]['status'] !== 200
    || $results[0]['data']['receipt_id'] !== $results[1]['data']['receipt_id']
    || $receipts()->count() !== 1 || (int) $receipts()->sum('amount_paisa') !== 125050) {
    throw new RuntimeException('Duplicate concurrent collection changed cash');
}
$close = schoolClosePayload($fixture, 1);
$results = schoolRace($fixture, array_fill(0, 2, ['action' => 'close', 'payload' => $close]));
if ($results[0]['status'] !== 200 || $results[1]['status'] !== 200
    || $results[0]['data']['report_id'] !== $results[1]['data']['report_id']
    || $closes()->count() !== 1 || (int) $closes()->value('cash_amount_paisa') !== 125050
    || (int) $closes()->value('receipt_count') !== 1) throw new RuntimeException('Duplicate close changed snapshot');
$before = (array) $closes()->first();
$retry = schoolRequest($fixture, 'store', $payload);
if ($retry['status'] !== 200 || (int) $retry['data']['receipt_id'] !== (int) $receipts()->value('id')) throw new RuntimeException('Post-close retry lost original receipt');
if (schoolRequest($fixture, 'store', schoolPayload())['status'] !== 422) throw new RuntimeException('Fresh collection entered closed date');
foreach (['amount' => '999.00', 'fee_head' => 'exam', 'student_reference' => 'SYN-CHANGED'] as $key => $value) {
    if (schoolRequest($fixture, 'store', array_replace($payload, [$key => $value]))['status'] !== 422) throw new RuntimeException('Changed retry was accepted');
}
if ($before !== (array) $closes()->first() || $receipts()->count() !== 1) throw new RuntimeException('Closed cash evidence mutated');

// Race one new collection against a close of the previous one-receipt preview.
$second = schoolFixture(bin2hex(random_bytes(5)));
schoolRequest($second, 'store', schoolPayload());
$racedPayload = schoolPayload();
$race = schoolRace($second, [
    ['action' => 'store', 'payload' => $racedPayload],
    ['action' => 'close', 'payload' => schoolClosePayload($second, 1)],
]);
$rows = DB::table('school_fee_receipts')->where('company_id', $second['company']);
$snapshots = DB::table('school_fee_day_closes')->where('company_id', $second['company']);
if ($race[0]['status'] === 200 && $race[1]['status'] === 422) {
    if ($rows->count() !== 2 || $snapshots->exists()) throw new RuntimeException('Stale close persisted');
    $retry = schoolRequest($second, 'close', schoolClosePayload($second, 2));
    if ($retry['status'] !== 200) throw new RuntimeException('Refreshed close failed');
} elseif ($race[0]['status'] === 422 && $race[1]['status'] === 200) {
    if ($rows->count() !== 1) throw new RuntimeException('Collection bypassed closed day');
} else {
    throw new RuntimeException('Unsafe concurrent collection/close outcome: '.json_encode($race));
}
if ($snapshots->count() !== 1 || (int) $snapshots->value('receipt_count') !== $rows->count()
    || (int) $snapshots->value('cash_amount_paisa') !== (int) $rows->sum('amount_paisa')) {
    throw new RuntimeException('Final close does not exactly match collected cash');
}
echo "PASS: native school duplicate collection and close remain single; collect-close race serializes cash; post-close replay preserved and fresh payment rejected\n";

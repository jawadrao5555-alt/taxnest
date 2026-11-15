<?php
declare(strict_types=1);

// This probe may only use the socket-only, disposable native RC lab.
$db = getenv('DB_DATABASE') ?: '';
$socket = getenv('DB_SOCKET') ?: '';
if (!preg_match('/^taxnest_rc_[a-z0-9_]+_full$/', $db)
    || !preg_match('#^/tmp/taxnest-rc-mariadb-[a-z0-9_]+/run/mariadb.sock$#', $socket)
    || getenv('APP_ENV') !== 'rc-mariadb' || !getenv('LD_PRELOAD')) {
    fwrite(STDERR, "FAIL unsafe hospital fee concurrency target\n");
    exit(2);
}
require dirname(__DIR__, 2).'/vendor/autoload.php';
$app = require dirname(__DIR__, 2).'/bootstrap/app.php';
$app->make(\Illuminate\Contracts\Console\Kernel::class)->bootstrap();

use App\Http\Controllers\FbrPosHospitalController;
use App\Models\{Company, User};
use Illuminate\Http\Request;
use Illuminate\Support\Facades\{DB, Http};
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

Http::preventStrayRequests();

function hospitalRequest(array $fixture, string $action, array $payload): array
{
    \Illuminate\Support\Carbon::setTestNow($fixture['now']);
    auth('fbrpos')->setUser(User::findOrFail($fixture['user']));
    app()->instance('currentCompanyId', $fixture['company']);
    app()->instance('currentBranchGuard', 'fbrpos');
    app()->bind('currentBranchId', fn () => null);
    $session = app('session')->driver();
    $session->forget(['active_branch_id', 'impersonation.readonly']);
    $request = Request::create('/fbr-pos/hospital'.($action === 'close' ? '/day-close' : ''),
        'POST', $payload, [], [], ['HTTP_ACCEPT' => 'application/json']);
    $request->setLaravelSession($session);
    app()->instance('request', $request);
    try {
        $controller = app(FbrPosHospitalController::class);
        $response = $action === 'payment' ? $controller->payment($request, (int)$payload['_invoice_id']) : $controller->{$action}($request);
        if ($response->getStatusCode() !== 200) throw new RuntimeException('Unexpected response status');
        return ['status' => 200, 'data' => $response->getData(true)];
    } catch (ValidationException $exception) {
        return ['status' => 422];
    }
}

if (($argv[1] ?? '') === 'worker') {
    $file = $argv[2]; $worker = (int) $argv[3];
    if (!str_starts_with($file, dirname($socket).'/hospital-')) throw new RuntimeException('Unsafe hospital fixture');
    $fixture = json_decode(file_get_contents($file), true, 512, JSON_THROW_ON_ERROR);
    file_put_contents($file.'.ready.'.$worker, 'ready');
    $deadline = microtime(true) + 20;
    while (!is_file($file.'.go')) {
        if (microtime(true) > $deadline) throw new RuntimeException('Hospital worker barrier timeout');
        usleep(10000);
    }
    $job = $fixture['jobs'][$worker];
    $result = hospitalRequest($fixture, $job['action'], $job['payload']);
    file_put_contents($file.'.result.'.$worker, json_encode($result, JSON_THROW_ON_ERROR));
    exit(0);
}

function hospitalRace(array $fixture, array $jobs): array
{
    $file = dirname(getenv('DB_SOCKET')).'/hospital-'.bin2hex(random_bytes(8));
    file_put_contents($file, json_encode($fixture + ['jobs' => $jobs], JSON_THROW_ON_ERROR));
    chmod($file, 0600);
    $workers = [];
    try {
        foreach ([0, 1] as $i) {
            $process = proc_open([PHP_BINARY, __FILE__, 'worker', $file, (string) $i],
                [0 => ['file', '/dev/null', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
            if (!is_resource($process)) throw new RuntimeException('Hospital worker launch failed');
            foreach ($pipes as $pipe) stream_set_blocking($pipe, false);
            $workers[] = ['process' => $process, 'pipes' => $pipes, 'output' => '', 'exit' => null];
        }
        $deadline = microtime(true) + 30;
        while (!is_file($file.'.ready.0') || !is_file($file.'.ready.1')) {
            if (microtime(true) > $deadline) throw new RuntimeException('Hospital workers failed before barrier');
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
            if ($running && microtime(true) > $deadline) throw new RuntimeException('Hospital race timeout');
            if ($running) usleep(10000);
        } while ($running);
        foreach ($workers as $worker) {
            if ($worker['exit'] !== 0) throw new RuntimeException('Hospital worker failed: '.$worker['output']);
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

function hospitalFixture(string $suffix): array
{
    $company = Company::create([
        'name' => 'Synthetic hospital race '.$suffix, 'ntn' => 'RCHOSPITAL'.$suffix,
        'email' => 'hospital-'.$suffix.'@native.invalid', 'product_type' => 'fbrpos',
        'pos_type' => 'clinic', 'business_category' => 'clinic',
        'status' => 'approved', 'company_status' => 'active', 'is_internal_account' => true,
        'fbr_pos_enabled' => true, 'fbr_reporting_enabled' => false,
    ]);
    \App\Models\Subscription::create([
        'company_id' => $company->id, 'pricing_plan_id' => null, 'start_date' => today(),
        'end_date' => null, 'active' => true, 'override_type' => 'lifetime',
    ]);
    $user = User::create([
        'name' => 'Synthetic hospital owner', 'email' => 'hospital-owner-'.$suffix.'@native.invalid',
        'password' => bcrypt('SyntheticOnly123!'), 'company_id' => $company->id,
        'role' => 'company_admin', 'pos_role' => 'pos_admin', 'is_active' => true,
    ]);
    return ['company' => $company->id, 'user' => $user->id, 'now' => now()->startOfDay()->addHours(12)->toIso8601String()];
}

function hospitalPayload(array $changes=[]): array {
 return array_replace(['patient_reference'=>'SYN-RACE','service_head'=>'consultation','total_amount'=>'1000','amount'=>'400',
 'payment_method'=>'cash','payment_confirmed'=>'1','expected_branch_key'=>0,'request_key'=>(string)Str::uuid()],$changes);
}
function hospitalClose(array $fixture,int $count,int $cash): array {
 return ['date'=>substr($fixture['now'],0,10),'confirm'=>'1','expected_branch_key'=>0,'expected_receipt_count'=>$count,
 'expected_cash_paisa'=>$cash,'expected_card_paisa'=>0,'expected_bank_paisa'=>0,'counted_cash'=>number_format($cash/100,2,'.',''),'variance_note'=>''];
}
$fixture=hospitalFixture(bin2hex(random_bytes(5)));$payload=hospitalPayload();
$r=hospitalRace($fixture,array_fill(0,2,['action'=>'store','payload'=>$payload]));
$payments=fn()=>DB::table('hospital_payments')->where('company_id',$fixture['company']);
$invoices=fn()=>DB::table('hospital_invoices')->where('company_id',$fixture['company']);
if($r[0]['status']!==200||$r[1]['status']!==200||(int)$r[0]['data']['receipt_id']!==(int)$r[1]['data']['receipt_id']||$payments()->count()!==1||$invoices()->count()!==1||(int)$payments()->sum('amount_paisa')!==40000)throw new RuntimeException('Hospital duplicate invoice/payment');
$invoice=(int)DB::table('hospital_invoices')->where('company_id',$fixture['company'])->value('id');
$r=hospitalRace($fixture,[['action'=>'payment','payload'=>hospitalPayload(['_invoice_id'=>$invoice,'amount'=>'600'])],['action'=>'payment','payload'=>hospitalPayload(['_invoice_id'=>$invoice,'amount'=>'600'])]]);
$statuses=array_column($r,'status');sort($statuses);
if($statuses!==[200,422]||$payments()->count()!==2||(int)$payments()->sum('amount_paisa')!==100000)throw new RuntimeException('Concurrent payments exceeded balance');
$close=hospitalClose($fixture,2,100000);$r=hospitalRace($fixture,array_fill(0,2,['action'=>'close','payload'=>$close]));
$snapshots=fn()=>DB::table('hospital_day_closes')->where('company_id',$fixture['company']);
if($r[0]['status']!==200||$r[1]['status']!==200||(int)$r[0]['data']['report_id']!==(int)$r[1]['data']['report_id']||$snapshots()->count()!==1||(int)$snapshots()->value('cash_paisa')!==100000||(int)$snapshots()->value('receipt_count')!==2||(int)$snapshots()->value('variance_paisa')!==0)throw new RuntimeException('Duplicate Hospital closing');
$before=(array)$snapshots()->first();
$recovered=hospitalRequest($fixture,'store',$payload);
if($recovered['status']!==200||(int)$recovered['data']['receipt_id']!==(int)$payments()->orderBy('id')->value('id'))throw new RuntimeException('Saved post-close recovery lost');
if(hospitalRequest($fixture,'store',hospitalPayload())['status']!==422||$before!==(array)$snapshots()->first())throw new RuntimeException('Hospital closed snapshot mutated');
$second=hospitalFixture(bin2hex(random_bytes(5)));hospitalRequest($second,'store',hospitalPayload());
$id=(int)DB::table('hospital_invoices')->where('company_id',$second['company'])->value('id');
$r=hospitalRace($second,[['action'=>'payment','payload'=>hospitalPayload(['_invoice_id'=>$id,'amount'=>'300'])],['action'=>'close','payload'=>hospitalClose($second,1,40000)]]);
$q=DB::table('hospital_payments')->where('company_id',$second['company']);$c=DB::table('hospital_day_closes')->where('company_id',$second['company']);
if($r[0]['status']===200&&$r[1]['status']===422){if($q->count()!==2||$c->exists())throw new RuntimeException('Stale Hospital close');if(hospitalRequest($second,'close',hospitalClose($second,2,70000))['status']!==200)throw new RuntimeException('Refreshed Hospital close failed');}
elseif($r[0]['status']===422&&$r[1]['status']===200){if($q->count()!==1)throw new RuntimeException('Late Hospital payment');}
else throw new RuntimeException('Unsafe Hospital collect-close race');
if($c->count()!==1||(int)$c->value('cash_paisa')!==(int)$q->sum('amount_paisa')||(int)$c->value('receipt_count')!==$q->count()||(int)$c->value('variance_paisa')!==0)throw new RuntimeException('Hospital snapshot mismatch');
echo "PASS: native hospital duplicate recovery, concurrent overpayment exclusion, immutable close and payment-close race\n";

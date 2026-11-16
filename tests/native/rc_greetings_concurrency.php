<?php
declare(strict_types=1);

// This probe may only use the socket-only, disposable native RC lab.
$db = getenv('DB_DATABASE') ?: '';
$socket = getenv('DB_SOCKET') ?: '';
if (!preg_match('/^taxnest_rc_[a-z0-9_]+_full$/', $db)
    || !preg_match('#^/tmp/taxnest-rc-mariadb-[a-z0-9_]+/run/mariadb.sock$#', $socket)
    || getenv('APP_ENV') !== 'rc-mariadb' || !getenv('LD_PRELOAD')) {
    fwrite(STDERR, "FAIL unsafe greeting concurrency target\n");
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

function greetingRequest(array $fixture, string $action, array $payload): array
{
    $transport = new class($fixture['calls'], $fixture['unknown']) extends \App\Services\GreetingTransport {
        public function __construct(private string $calls, private bool $unknown) {}
        public function available(): bool { return true; }
        public function send(string $email, string $body): void {
            file_put_contents($this->calls, "synthetic-send\n", FILE_APPEND | LOCK_EX);
            usleep(100000);
            if ($this->unknown) throw new RuntimeException('synthetic response lost');
        }
    };
    app()->instance(\App\Services\GreetingTransport::class, $transport);
    app(\App\Services\CompanyGreetingService::class)->run(\Carbon\CarbonImmutable::parse('2027-02-28 09:00:00','Asia/Karachi'));
    return ['status'=>200];
}

if (($argv[1] ?? '') === 'worker') {
    $file = $argv[2]; $worker = (int) $argv[3];
    if (!str_starts_with($file, dirname($socket).'/greeting-')) throw new RuntimeException('Unsafe greeting fixture');
    $fixture = json_decode(file_get_contents($file), true, 512, JSON_THROW_ON_ERROR);
    file_put_contents($file.'.ready.'.$worker, 'ready');
    $deadline = microtime(true) + 20;
    while (!is_file($file.'.go')) {
        if (microtime(true) > $deadline) throw new RuntimeException('Greeting worker barrier timeout');
        usleep(10000);
    }
    $job = $fixture['jobs'][$worker];
    $result = greetingRequest($fixture, $job['action'], $job['payload']);
    file_put_contents($file.'.result.'.$worker, json_encode($result, JSON_THROW_ON_ERROR));
    exit(0);
}

function greetingRace(array $fixture, array $jobs): array
{
    $file = dirname(getenv('DB_SOCKET')).'/greeting-'.bin2hex(random_bytes(8));
    file_put_contents($file, json_encode($fixture + ['jobs' => $jobs], JSON_THROW_ON_ERROR));
    chmod($file, 0600);
    $workers = [];
    try {
        foreach ([0, 1] as $i) {
            $process = proc_open([PHP_BINARY, __FILE__, 'worker', $file, (string) $i],
                [0 => ['file', '/dev/null', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
            if (!is_resource($process)) throw new RuntimeException('Greeting worker launch failed');
            foreach ($pipes as $pipe) stream_set_blocking($pipe, false);
            $workers[] = ['process' => $process, 'pipes' => $pipes, 'output' => '', 'exit' => null];
        }
        $deadline = microtime(true) + 30;
        while (!is_file($file.'.ready.0') || !is_file($file.'.ready.1')) {
            if (microtime(true) > $deadline) throw new RuntimeException('Greeting workers failed before barrier');
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
            if ($running && microtime(true) > $deadline) throw new RuntimeException('Greeting race timeout');
            if ($running) usleep(10000);
        } while ($running);
        foreach ($workers as $worker) {
            if ($worker['exit'] !== 0) throw new RuntimeException('Greeting worker failed: '.$worker['output']);
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


foreach ([false, true] as $unknown) {
    $suffix = bin2hex(random_bytes(6));
    $company = Company::create(['name'=>'Synthetic greeting '.$suffix,'ntn'=>'SYNGR'.$suffix,'product_type'=>'di']);
    $recipient = DB::table('greeting_recipients')->insertGetId(['company_id'=>$company->id,'name'=>'Synthetic recipient','email'=>'synthetic@example.invalid','event'=>'birthday','event_date'=>'2000-02-29','email_consent'=>true,'recorded_by'=>0,'created_at'=>now(),'updated_at'=>now()]);
    DB::table('greeting_preferences')->insert(['company_id'=>$company->id,'enabled'=>true,'timezone'=>'Asia/Karachi','templates'=>json_encode(['birthday'=>'Hello {name}']),'updated_by'=>0,'created_at'=>now(),'updated_at'=>now()]);
    $calls = dirname($socket).'/greeting-calls-'.$suffix;
    $fixture = ['calls'=>$calls,'unknown'=>$unknown];
    try {
        greetingRace($fixture,[['action'=>'run','payload'=>[]],['action'=>'run','payload'=>[]]]);
        greetingRequest($fixture,'retry',[]);
        $rows = DB::table('greeting_deliveries')->where('company_id',$company->id)->get();
        if ($rows->count() !== 1 || $rows[0]->status !== ($unknown ? 'hold' : 'sent') || (int) $rows[0]->attempts !== 1) throw new RuntimeException('Greeting delivery dedup/state mismatch');
        if (count(file($calls)) !== 1) throw new RuntimeException('Concurrent or ambiguous delivery repeated');
        echo 'PASS synthetic greeting '.($unknown ? 'ambiguous HOLD no resend' : 'concurrent claim exactly one delivery')."\n";
    } finally {
        @unlink($calls);
        DB::table('greeting_preferences')->where('company_id',$company->id)->delete();
        DB::table('greeting_recipients')->where('company_id',$company->id)->delete();
        DB::table('greeting_deliveries')->where('company_id',$company->id)->delete();
        $company->delete();
    }
}

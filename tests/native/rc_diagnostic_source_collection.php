<?php
declare(strict_types=1);

$database = getenv('DB_DATABASE') ?: '';
$socket = getenv('DB_SOCKET') ?: '';
if (getenv('APP_ENV') !== 'rc-mariadb' || !getenv('LD_PRELOAD')
    || !preg_match('/^taxnest_rc_[a-z0-9_]+_full$/D', $database)
    || !preg_match('#^/tmp/taxnest-rc-mariadb-[a-z0-9_]+/run/mariadb.sock$#D', $socket)) {
    fwrite(STDERR, "FAIL unsafe diagnostic observation target\n"); exit(2);
}
require dirname(__DIR__, 2).'/vendor/autoload.php';
$app = require dirname(__DIR__, 2).'/bootstrap/app.php';
$app->make(\Illuminate\Contracts\Console\Kernel::class)->bootstrap();

use App\Models\Company;
use App\Services\Reporting\DiagnosticSourceCollection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Symfony\Component\Process\Process;

if (($argv[1] ?? '') === 'worker') {
    [$companyId, $day, $run, $barrier, $index] = array_slice($argv, 2);
    if (!ctype_digit($companyId) || !ctype_digit($index) || !str_starts_with($barrier, dirname($socket).'/diag-observation-')) throw new RuntimeException('Unsafe observation worker identity.');
    file_put_contents($barrier.'.ready-'.$index, 'ready');
    $until = microtime(true) + 20;
    while (!is_file($barrier)) { if (microtime(true)>$until) throw new RuntimeException('Observation barrier timeout.'); usleep(10000); }
    if (app(DiagnosticSourceCollection::class)->capture((int) $companyId, 'pra.fiscal', $day, $run) !== 'OBSERVED') throw new RuntimeException('Native source was not observed.');
    exit(0);
}
$suffix = bin2hex(random_bytes(8));
$company = Company::create(['name'=>'Synthetic diagnostic source','ntn'=>$suffix,'product_type'=>'pos']);
$other = Company::create(['name'=>'Other synthetic diagnostic source','ntn'=>'x'.$suffix,'product_type'=>'pos']);
$day = now(config('reporting_connector.timezone', 'Asia/Karachi'))->subDay()->toDateString();
$stamp = $day.' 12:00:00';
DB::table('pra_logs')->insert([
    ['company_id'=>$company->id, 'created_at'=>$stamp, 'updated_at'=>$stamp, 'request_payload'=>'{"synthetic":"PRIVATE"}'],
    ['company_id'=>$other->id, 'created_at'=>$stamp, 'updated_at'=>$stamp, 'request_payload'=>'{"synthetic":"FOREIGN"}'],
]);
$before = DB::table('pra_logs')->whereIn('company_id',[$company->id,$other->id])->orderBy('id')->get()->toJson();
$run = (string) Str::uuid(); $barrier = dirname($socket).'/diag-observation-'.$suffix; $processes=[];
try {
    foreach ([1,2] as $index) {
        $p = new Process([PHP_BINARY,'-c',php_ini_loaded_file(),__FILE__,'worker',(string)$company->id,$day,$run,$barrier,(string)$index]);
        $p->setTimeout(40); $p->start(); $processes[]=$p;
    }
    $until=microtime(true)+20;
    while (count(glob($barrier.'.ready-*'))!==2) { if(microtime(true)>$until)throw new RuntimeException('Observation workers did not reach barrier.');usleep(10000); }
    file_put_contents($barrier,'go');
    foreach ($processes as $p) { $p->wait(); if(!$p->isSuccessful())throw new RuntimeException('Native observation worker failed.'); }
    $observation=DB::table('diagnostic_source_observations')->where('company_id',$company->id)->sole();
    $report=app(DiagnosticSourceCollection::class)->latest($company->id,'pra.fiscal',$day,$day);
    if ((int)$observation->retained_rows!==1 || $report['complete_collection_proven']!==false
        || DB::table('diagnostic_source_observations')->where('company_id',$other->id)->count()!==0
        || DB::table('pra_logs')->whereIn('company_id',[$company->id,$other->id])->orderBy('id')->get()->toJson()!==$before
        || str_contains(json_encode($report),'PRIVATE')) throw new RuntimeException('Native observation scope, idempotency or source preservation failed.');
    echo "PASS: native concurrent source observation is idempotent, scoped and preserves source rows\n";
} finally {
    foreach($processes as $p)if($p->isRunning())$p->stop(1);
    if(is_file($barrier))unlink($barrier);
    foreach(glob($barrier.'.ready-*') as $ready)unlink($ready);
}

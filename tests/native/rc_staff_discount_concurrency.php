<?php
declare(strict_types=1);

$db=getenv('DB_DATABASE') ?: ''; $socket=getenv('DB_SOCKET') ?: '';
if (!preg_match('/^taxnest_rc_[a-z0-9_]+_full$/',$db) || !preg_match('#^/tmp/taxnest-rc-mariadb-[a-z0-9_]+/run/mariadb.sock$#',$socket)
    || getenv('APP_ENV') !== 'rc-mariadb' || !getenv('LD_PRELOAD')) {
    fwrite(STDERR,"FAIL unsafe staff concurrency target\n"); exit(2);
}
require dirname(__DIR__,2).'/vendor/autoload.php';
$app=require dirname(__DIR__,2).'/bootstrap/app.php';
$app->make(\Illuminate\Contracts\Console\Kernel::class)->bootstrap();
use App\Models\{Company,User,PosTransaction};
use Illuminate\Support\Facades\DB;
use Illuminate\Http\Request;

function staffRequest(array $payload, int $userId): Request {
    auth('pos')->loginUsingId($userId);
    app()->instance('currentCompanyId',auth('pos')->user()->company_id);
    app()->instance('currentBranchGuard','pos');
    $r=Request::create('/pos/invoice/store','POST',$payload,[],[],['HTTP_ACCEPT'=>'application/json']);
    $r->setLaravelSession(app('session')->driver()); app()->instance('request',$r);
    return $r;
}

if (($argv[1] ?? '') === 'worker') {
    $file=$argv[2]; $mode=$argv[3]; $worker=$argv[4];
    if (!str_starts_with($file,dirname($socket).'/staff-')) throw new RuntimeException('Unsafe staff fixture');
    $f=json_decode(file_get_contents($file),true,512,JSON_THROW_ON_ERROR);
    file_put_contents($file.'.ready.'.$worker,'ready');
    $end=microtime(true)+20; while(!is_file($file.'.go')) {if(microtime(true)>$end)throw new RuntimeException('Barrier timeout');usleep(10000);}
    try {
        if($mode==='review') {
            $r=staffRequest(['revision'=>1,'action'=>'approve','discount_type'=>'amount','discount_value'=>125],$f['owner']);
            $response=app(\App\Http\Controllers\PosStaffDiscountController::class)->review($r,$f['request']);
        } else {
            $r=staffRequest($f['payload'],$f['cashier']);
            $response=app(\App\Http\Controllers\PosController::class)->storeInvoice($r);
        }
        if($response->getStatusCode()!==200) throw new RuntimeException('Unexpected staff worker response '.$response->getStatusCode());
        echo "accepted\n";
    } catch(\Illuminate\Validation\ValidationException $e) {echo "stale\n";}
    catch(\Symfony\Component\HttpKernel\Exception\HttpException $e) {if($e->getStatusCode()!==409)throw $e;echo "stale\n";}
    exit(0);
}

function staffRace(string $file,string $mode): void {
    $workers=[];
    try {
        foreach([0,1] as $i) {
            $p=proc_open([PHP_BINARY,__FILE__,'worker',$file,$mode,(string)$i],[0=>['file','/dev/null','r'],1=>['pipe','w'],2=>['pipe','w']],$pipes);
            if(!is_resource($p))throw new RuntimeException('Staff worker launch failed');
            foreach($pipes as $pipe)stream_set_blocking($pipe,false);
            $workers[]=['p'=>$p,'pipes'=>$pipes,'out'=>'','exit'=>null];
        }
        $deadline=microtime(true)+30;
        while(!is_file($file.'.ready.0')||!is_file($file.'.ready.1')) {if(microtime(true)>$deadline)throw new RuntimeException('Staff workers failed before barrier');usleep(10000);}
        file_put_contents($file.'.go','go');
        do {
            $running=false;
            foreach($workers as &$w) {
                foreach($w['pipes'] as $pipe)$w['out'].=stream_get_contents($pipe);
                if($w['exit']!==null)continue;
                $s=proc_get_status($w['p']); if($s['running'])$running=true;else $w['exit']=$s['exitcode'];
            } unset($w);
            if($running&&microtime(true)>$deadline)throw new RuntimeException('Staff worker timeout');
            if($running)usleep(10000);
        }while($running);
        foreach($workers as $w)if($w['exit']!==0)throw new RuntimeException('Staff worker failed: '.$w['out']);
    } finally {
        foreach($workers as $w){if(proc_get_status($w['p'])['running'])proc_terminate($w['p']);foreach($w['pipes'] as $pipe)fclose($pipe);proc_close($w['p']);}
        foreach(['.go','.ready.0','.ready.1'] as $suffix)@unlink($file.$suffix);
    }
}

$company=Company::create(['name'=>'Synthetic staff concurrency','ntn'=>'RCSTAFFRACE','email'=>'staff-race@native.invalid','product_type'=>'pos',
    'status'=>'approved','company_status'=>'active','is_internal_account'=>true,'pra_reporting_enabled'=>false,'pos_integration_mode'=>'pra',
    'pos_tax_rate_cash'=>16,'pos_tax_rate_card'=>5]);
$company->forceFill(['pos_staff_discount_enabled'=>true])->save();
$make=fn($name,$role,$posRole)=>User::create(['name'=>$name,'email'=>$name.'@native.invalid','password'=>bcrypt('SyntheticOnly123!'),'company_id'=>$company->id,'role'=>$role,'pos_role'=>$posRole,'is_active'=>true]);
$owner=$make('staff-race-owner','company_admin','pos_admin'); $cashier=$make('staff-race-cashier','user','pos_cashier');
$uuid=(string)\Illuminate\Support\Str::uuid();
$payload=['items'=>[['name'=>'Fictional race meal','quantity'=>1,'unit_price'=>1000,'_manual'=>true]],'payment_method'=>'cash','order_type'=>'takeaway',
    'discount_type'=>'percentage','discount_value'=>10,'offline_uuid'=>$uuid,
    'staff_discount'=>['employee_id'=>$cashier->id,'kind'=>'staff','reason'=>'Synthetic race','request_uuid'=>$uuid]];
$response=app(\App\Http\Controllers\PosController::class)->storeInvoice(staffRequest($payload,$cashier->id));
if($response->getStatusCode()!==202 || PosTransaction::where('company_id',$company->id)->exists())throw new RuntimeException('Proposal unexpectedly finalized');
$id=$response->getData(true)['request_id'];
$file=dirname($socket).'/staff-'.bin2hex(random_bytes(8));
try {
    file_put_contents($file,json_encode(['owner'=>$owner->id,'cashier'=>$cashier->id,'request'=>$id],JSON_THROW_ON_ERROR)); chmod($file,0600);
    staffRace($file,'review');
    if((int)DB::table('pos_staff_discount_requests')->where('id',$id)->value('revision')!==2
        || DB::table('pos_staff_discount_events')->where('request_id',$id)->count()!==2)throw new RuntimeException('Multiple concurrent reviews won');
    unset($payload['staff_discount']); $payload+=['staff_discount_request_id'=>$id,'staff_discount_revision'=>2];
    file_put_contents($file,json_encode(['owner'=>$owner->id,'cashier'=>$cashier->id,'request'=>$id,'payload'=>$payload],JSON_THROW_ON_ERROR));
    staffRace($file,'pay');
    $bills=PosTransaction::where('company_id',$company->id)->get();
    if($bills->count()!==1 || (float)$bills[0]->discount_amount!==125.0 || (float)$bills[0]->total_amount!==1015.0
        || $bills[0]->pra_status!==null || (int)$bills[0]->created_by!==$cashier->id
        || DB::table('pos_payments')->where('transaction_id',$bills[0]->id)->count()!==1)throw new RuntimeException('Concurrent finalization duplicated or changed money');
    $retry=app(\App\Http\Controllers\PosController::class)->storeInvoice(staffRequest($payload,$cashier->id));
    if($retry->getStatusCode()!==200 || $retry->getData(true)['transaction_id']!==$bills[0]->id)throw new RuntimeException('Lost response retry did not recover original invoice');
    echo "PASS: native concurrent owner reviews=1; same approval final bill/payment=1; approved money/cashier/NULL fiscal status preserved; lost-response retry recovered original\n";
} finally {@unlink($file);}

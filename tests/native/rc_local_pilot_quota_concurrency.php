<?php
declare(strict_types=1);

// Exact disposable socket fixture only. No production configuration or external fiscal IO.
$db=getenv('DB_DATABASE')?:'';$socket=getenv('DB_SOCKET')?:'';
if (getenv('APP_ENV')!=='rc-mariadb' || !getenv('LD_PRELOAD') || getenv('TAXNEST_RC_NO_EXTERNAL_FISCAL')!=='1'
    || !preg_match('/^taxnest_rc_[a-z0-9_]+_full$/D',$db)
    || !preg_match('#^/tmp/taxnest-rc-mariadb-[a-z0-9_]+/run/mariadb.sock$#D',$socket)) {
    fwrite(STDERR,"FAIL unsafe local pilot quota target\n");exit(2);
}
require dirname(__DIR__,2).'/vendor/autoload.php';
$app=require dirname(__DIR__,2).'/bootstrap/app.php';$app->make(\Illuminate\Contracts\Console\Kernel::class)->bootstrap();
use App\Http\Controllers\{FbrPosSchoolFeeController,FbrPosHospitalController};
use App\Models\{Company,PricingPlan,User};
use App\Services\{TrialSubscriptionService,SubscriptionAssignmentService,ProspectiveSubscriptionPolicy};
use Carbon\{Carbon,CarbonImmutable};
use Illuminate\Http\Request;
use Illuminate\Support\Facades\{DB,Http,Mail,Notification};
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;
use Symfony\Component\Process\Process;
Http::preventStrayRequests();Mail::fake();Notification::fake();
function pilotProof(bool $condition,string $message):void { if(!$condition)throw new RuntimeException($message); }
function pilotClock(string $at):void { Carbon::setTestNow(Carbon::parse($at));CarbonImmutable::setTestNow(CarbonImmutable::parse($at)); }
function pilotTable(string $kind):string { return $kind==='school'?'school_fee_receipts':'hospital_payments'; }
function pilotRequest(array $fixture,array $payload):array {
    pilotClock($fixture['now']);auth('fbrpos')->setUser(User::findOrFail($fixture['user']));
    app()->instance('currentCompanyId',$fixture['company']);app()->instance('currentBranchGuard','fbrpos');app()->bind('currentBranchId',fn()=>null);
    $session=app('session')->driver();$session->forget(['active_branch_id','impersonation.readonly']);
    $request=Request::create('/fbr-pos/'.($fixture['kind']==='school'?'school-fees':'hospital'),'POST',$payload,[],[],['HTTP_ACCEPT'=>'application/json']);
    $request->setLaravelSession($session);app()->instance('request',$request);
    try {
        $controller=app($fixture['kind']==='school'?FbrPosSchoolFeeController::class:FbrPosHospitalController::class);
        $action=$payload['_action']??'store';
        $response=$action==='payment'?$controller->payment($request,(int)$payload['_invoice_id']):$controller->{$action}($request);
        return ['status'=>$response->getStatusCode(),'data'=>$response->getData(true)];
    } catch(ValidationException $e) { return ['status'=>422,'keys'=>array_keys($e->errors())]; }
    catch(HttpExceptionInterface $e) { return ['status'=>$e->getStatusCode()]; }
}
if(($argv[1]??'')==='worker') {
    $file=$argv[2]??'';$index=$argv[3]??'';
    pilotProof(str_starts_with($file,dirname($socket).'/pilot-quota-')&&in_array($index,['0','1'],true),'Unsafe pilot barrier');
    $fixture=json_decode(file_get_contents($file),true,512,JSON_THROW_ON_ERROR);pilotClock($fixture['now']);
    DB::statement('SET TRANSACTION ISOLATION LEVEL REPEATABLE READ');DB::beginTransaction();
    try {
        // Deliberately establish the old consistent snapshot BEFORE the other worker commits.
        DB::table(pilotTable($fixture['kind']))->where('company_id',$fixture['company'])->count();
        DB::table('subscriptions')->where('company_id',$fixture['company'])->get();
        file_put_contents($file.'.ready-'.$index,'ready');$deadline=microtime(true)+25;
        while(!is_file($file.'.go')) { if(microtime(true)>$deadline)throw new RuntimeException('Pilot barrier timed out');usleep(10000); }
        $result=pilotRequest($fixture,$fixture['jobs'][(int)$index]);
        if($result['status']===200)DB::commit();else DB::rollBack();
        Http::assertNothingSent();file_put_contents($file.'.result-'.$index,json_encode($result,JSON_THROW_ON_ERROR));
    } catch(Throwable $e) { if(DB::transactionLevel())DB::rollBack();throw $e; }
    exit;
}
function pilotRace(array $fixture,array $jobs):array {
    $file=dirname(getenv('DB_SOCKET')).'/pilot-quota-'.bin2hex(random_bytes(10));
    file_put_contents($file,json_encode($fixture+['jobs'=>$jobs],JSON_THROW_ON_ERROR));chmod($file,0600);$workers=[];
    try {
        foreach([0,1]as$i){$p=new Process([PHP_BINARY,__FILE__,'worker',$file,(string)$i]);$p->setTimeout(45);$p->start();$workers[]=$p;}
        $deadline=microtime(true)+25;
        while(count(glob($file.'.ready-*'))!==2){if(microtime(true)>$deadline)throw new RuntimeException('Pilot workers not ready');usleep(10000);}
        file_put_contents($file.'.go','go');
        foreach($workers as$p){$p->wait();pilotProof($p->isSuccessful(),'Pilot worker failed: '.$p->getErrorOutput());}
        return array_map(fn($i)=>json_decode(file_get_contents($file.'.result-'.$i),true,512,JSON_THROW_ON_ERROR),[0,1]);
    } finally {foreach($workers as$p)if($p->isRunning())$p->stop();foreach(glob($file.'*')?:[]as$f)unlink($f);}
}
function pilotFixture(string $kind,int $limit=1):array {
    pilotClock('2028-04-10T10:00:00+00:00');$suffix=bin2hex(random_bytes(7));
    $category=$kind==='school'?'education':'clinic';
    $company=Company::create(['name'=>'Synthetic pilot quota '.$kind,'ntn'=>'PQ-'.$suffix,'product_type'=>'fbrpos',
        'business_category'=>$category,'pos_type'=>$category,'status'=>'approved','company_status'=>'active',
        'fbr_pos_enabled'=>true,'fbr_reporting_enabled'=>false,'is_internal_account'=>false]);
    $plan=PricingPlan::create(['name'=>'Business','product_type'=>'fbrpos','price'=>1000,'invoice_limit'=>$limit,'user_limit'=>3,'branch_limit'=>1]);
    TrialSubscriptionService::ensureSelectedTrial($company->id,'fbrpos',$plan->id);
    $quote=SubscriptionAssignmentService::computePrice($plan,'annual',$company);
    $sub=SubscriptionAssignmentService::assign($company->id,$plan->id,'annual',paidRenewal:true,verifiedReceived:$quote['final_price']);
    pilotClock(CarbonImmutable::parse(ProspectiveSubscriptionPolicy::display($sub)['paid_starts_at'])->addSecond()->toIso8601String());
    $user=User::create(['name'=>'Synthetic quota operator','email'=>'pilot-'.$suffix.'@native.invalid','password'=>bcrypt(Str::random(40)),
        'company_id'=>$company->id,'role'=>'company_admin','pos_role'=>'pos_admin','is_active'=>true]);
    return ['kind'=>$kind,'company'=>$company->id,'user'=>$user->id,'now'=>now()->toIso8601String()];
}
function pilotPayload(string $kind):array {
    $base=['request_key'=>(string)Str::uuid(),'expected_branch_key'=>0,'amount'=>'100.00','payment_method'=>'cash'];
    return $base+($kind==='school'?['student_reference'=>'SYN-Q-STUDENT','fee_head'=>'tuition','fee_period'=>now()->format('Y-m'),'cash_confirmed'=>'1']
        :['patient_reference'=>'SYN-Q-PATIENT','service_head'=>'consultation','total_amount'=>'100.00','payment_confirmed'=>'1']);
}
function pilotPartial(array $fixture):array {
    $payload=array_replace(pilotPayload($fixture['kind']),['amount'=>'40.00']);
    if($fixture['kind']==='school')$payload['fee_lines']=[['fee_head'=>'tuition','amount'=>'100.00']];
    $saved=pilotRequest($fixture,$payload);pilotProof($saved['status']===200,'Initial partial collection failed');
    $row=DB::table(pilotTable($fixture['kind']))->where('id',$saved['data']['receipt_id'])->first();
    $payment=array_replace($payload,['request_key'=>(string)Str::uuid(),'amount'=>'60.00']);
    if($fixture['kind']==='school')$payment['account_id']=$row->account_id;
    else{$payment['_action']='payment';$payment['_invoice_id']=$row->invoice_id;}
    return [$payload,$payment,$saved['data']['receipt_id']];
}
function pilotClose(array $fixture,int $count,int $cash):array {
    return ['_action'=>'close','date'=>substr($fixture['now'],0,10),'confirm'=>'1','expected_branch_key'=>0,
        'expected_receipt_count'=>$count,'expected_cash_paisa'=>$cash,'expected_card_paisa'=>0,'expected_bank_paisa'=>0,
        'counted_cash'=>number_format($cash/100,2,'.',''),'variance_note'=>''];
}
function pilotPartialAndClose(string $kind):void {
    $f=pilotFixture($kind,10);[$original,$payment]=pilotPartial($f);
    $r=pilotRace($f,[$payment,array_replace($payment,['request_key'=>(string)Str::uuid()])]);
    $statuses=array_column($r,'status');sort($statuses);
    pilotProof($statuses===[200,422],'Concurrent partial balance accepted two payments');
    $loser=$r[0]['status']===422?0:1;
    pilotProof(in_array('amount',$r[$loser]['keys']??[],true),'Partial balance rejected for unrelated reason');
    $rows=DB::table(pilotTable($kind))->where('company_id',$f['company']);
    pilotProof($rows->count()===2&&(int)$rows->sum('amount_paisa')===10000,'Partial balance cash exceeded payable');
    if($kind==='school')pilotProof((int)DB::table('school_fee_accounts')->where('company_id',$f['company'])->value('paid_paisa')===10000,'School account snapshot lost payment');
    else pilotProof(DB::table('hospital_invoices')->where('company_id',$f['company'])->count()===1,'Hospital installment duplicated invoice');

    $f=pilotFixture($kind,10);[$original,$payment,$receiptId]=pilotPartial($f);
    $payment['amount']='30.00';$r=pilotRace($f,[$payment,pilotClose($f,1,4000)]);
    $rows=fn()=>DB::table(pilotTable($kind))->where('company_id',$f['company']);
    $closeTable=$kind==='school'?'school_fee_day_closes':'hospital_day_closes';
    $closes=fn()=>DB::table($closeTable)->where('company_id',$f['company']);
    if($r[0]['status']===200&&$r[1]['status']===422){
        pilotProof($rows()->count()===2&&!$closes()->exists(),'Stale close captured old cash');
        pilotProof(pilotRequest($f,pilotClose($f,2,7000))['status']===200,'Refreshed close failed');
    }elseif($r[0]['status']===422&&$r[1]['status']===200){
        pilotProof($rows()->count()===1,'Payment inserted after close');
    }else throw new RuntimeException('Unsafe old-snapshot payment-close race');
    pilotProof($closes()->count()===1,'Close snapshot missing or duplicated');
    $saved=$closes()->first();
    pilotProof((int)$saved->{$kind==='school'?'cash_amount_paisa':'cash_paisa'}===(int)$rows()->sum('amount_paisa')&&(int)$saved->receipt_count===$rows()->count(),'Closing cash/count does not match saved payments');
    $snapshot=$closes()->get()->toJson();$receipts=$rows()->orderBy('id')->get()->toJson();
    $replay=pilotRequest($f,$original);
    pilotProof($replay['status']===200&&$replay['data']['receipt_id']===$receiptId,'Closed-day lost response did not recover original receipt');
    $changed=pilotRequest($f,array_replace($original,['amount'=>'39.00']));
    pilotProof($changed['status']===422&&in_array('request_key',$changed['keys']??[],true),'Closed-day changed payment data accepted');
    pilotProof($snapshot===$closes()->get()->toJson()&&$receipts===$rows()->orderBy('id')->get()->toJson(),'Closed cash snapshot or receipt changed on retry');
}
foreach(['school','hospital']as$kind) {
    // Distinct requests compete for the one remaining paid slot under old RR snapshots.
    $f=pilotFixture($kind);$payloads=[pilotPayload($kind),pilotPayload($kind)];$r=pilotRace($f,$payloads);
    $success=array_keys(array_filter($r,fn($x)=>$x['status']===200));
    pilotProof(count($success)===1&&in_array($r[1-$success[0]]['status'],[403,422],true),'Distinct pilot requests exceeded last slot');
    $winner=$success[0];$rows=fn()=>DB::table(pilotTable($kind))->where('company_id',$f['company']);
    pilotProof($rows()->count()===1&&(int)$rows()->sum('amount_paisa')===10000,'Pilot quota race duplicated cash');
    if($kind==='hospital')pilotProof(DB::table('hospital_invoices')->where('company_id',$f['company'])->count()===1,'Pilot race duplicated invoice');
    $before=$rows()->orderBy('id')->get()->toJson();$replay=pilotRequest($f,$payloads[$winner]);
    pilotProof($replay['status']===200&&$replay['data']['receipt_id']===$r[$winner]['data']['receipt_id'],'Paid limit blocked lost-response replay');
    $changed=pilotRequest($f,array_replace($payloads[$winner],['amount'=>'99.00']));
    pilotProof($changed['status']===422&&in_array('request_key',$changed['keys']??[],true),'Changed payment data not rejected');
    pilotProof($before===$rows()->orderBy('id')->get()->toJson(),'Replay changed original receipt or cash');
    // Same payment identity must recover the winner even with the pre-commit snapshot.
    $same=pilotFixture($kind);$payload=pilotPayload($kind);$r=pilotRace($same,[$payload,$payload]);
    pilotProof($r[0]['status']===200&&$r[1]['status']===200&&$r[0]['data']['receipt_id']===$r[1]['data']['receipt_id'],'Old snapshot duplicate retry did not recover same receipt');
    pilotProof(DB::table(pilotTable($kind))->where('company_id',$same['company'])->count()===1,'Same identity inserted twice');
    pilotProof(!Company::findOrFail($same['company'])->fbr_reporting_enabled,'Pilot fiscal flag changed');
    $tamper=pilotFixture($kind);$first=pilotPayload($kind);$second=array_replace($first,['amount'=>'99.00']);
    $conflict=pilotRace($tamper,[$first,$second]);$success=array_keys(array_filter($conflict,fn($x)=>$x['status']===200));
    pilotProof(count($success)===1,'Changed-data race did not have exactly one winner');$loser=1-$success[0];
    pilotProof($conflict[$loser]['status']===422&&in_array('request_key',$conflict[$loser]['keys']??[],true),'Old snapshot changed-data retry missed identity conflict');
    $saved=DB::table(pilotTable($kind))->where('company_id',$tamper['company']);
    pilotProof($saved->count()===1&&(int)$saved->sum('amount_paisa')===($success[0]===0?10000:9900),'Changed-data race corrupted original cash');

}
foreach(['school','hospital']as$kind)pilotPartialAndClose($kind);
Http::assertNothingSent();Carbon::setTestNow();CarbonImmutable::setTestNow();
echo "PASS: native School Hospital paid last-slot serialized under old RR snapshot; same payment recovers same receipt changed data rejected cash unchanged no fiscal calls\n";
echo "PASS: native School Hospital old RR partial balance excludes overpayment; payment-close race and saved closing cash immutable on same-receipt retry and changed-data rejection\n";

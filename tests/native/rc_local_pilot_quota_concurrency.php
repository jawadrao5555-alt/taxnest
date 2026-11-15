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
        $response=app($fixture['kind']==='school'?FbrPosSchoolFeeController::class:FbrPosHospitalController::class)->store($request);
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
function pilotFixture(string $kind):array {
    pilotClock('2028-04-10T10:00:00+00:00');$suffix=bin2hex(random_bytes(7));
    $category=$kind==='school'?'education':'clinic';
    $company=Company::create(['name'=>'Synthetic pilot quota '.$kind,'ntn'=>'PQ-'.$suffix,'product_type'=>'fbrpos',
        'business_category'=>$category,'pos_type'=>$category,'status'=>'approved','company_status'=>'active',
        'fbr_pos_enabled'=>true,'fbr_reporting_enabled'=>false,'is_internal_account'=>false]);
    $plan=PricingPlan::create(['name'=>'Business','product_type'=>'fbrpos','price'=>1000,'invoice_limit'=>1,'user_limit'=>3,'branch_limit'=>1]);
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
}
Http::assertNothingSent();Carbon::setTestNow();CarbonImmutable::setTestNow();
echo "PASS: native School Hospital paid last-slot serialized under old RR snapshot; same payment recovers same receipt changed data rejected cash unchanged no fiscal calls\n";

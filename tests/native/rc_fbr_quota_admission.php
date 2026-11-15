<?php
declare(strict_types=1);
$db=getenv('DB_DATABASE')?:'';$socket=getenv('DB_SOCKET')?:'';
if(getenv('APP_ENV')!=='rc-mariadb'||!getenv('LD_PRELOAD')||!preg_match('/^taxnest_rc_[a-z0-9_]+_full$/D',$db)||!preg_match('#^/tmp/taxnest-rc-mariadb-[a-z0-9_]+/run/mariadb.sock$#D',$socket)){fwrite(STDERR,"FAIL unsafe FBR admission target\n");exit(2);}
require dirname(__DIR__,2).'/vendor/autoload.php';$app=require dirname(__DIR__,2).'/bootstrap/app.php';$app->make(\Illuminate\Contracts\Console\Kernel::class)->bootstrap();
use App\Models\{Company,FbrPosTransaction,PricingPlan,Subscription};
use App\Services\{FbrProspectiveQuotaRetention as Retention,ProspectiveSubscriptionPolicy as Policy,TrialSubscriptionService,SubscriptionAssignmentService as Assignment,PlanLimitService};
use Carbon\{Carbon,CarbonImmutable};
use Illuminate\Support\Facades\{DB,Http,Mail,Notification};
use Illuminate\Validation\ValidationException;
use Symfony\Component\Process\Process;
Http::preventStrayRequests();Mail::fake();Notification::fake();
function quotaProof(bool $ok,string $reason):void{if(!$ok)throw new RuntimeException($reason);}
function quotaClock(string $stamp):void{Carbon::setTestNow(Carbon::parse($stamp));CarbonImmutable::setTestNow(CarbonImmutable::parse($stamp));}
function admission(int $company,int $user,string $uuid):array {
 auth('fbrpos')->setUser(\App\Models\User::findOrFail($user));app()->instance('currentCompanyId',$company);
 $request=\Illuminate\Http\Request::create('/fbr-pos/store','POST',['items'=>[['item_name'=>'Synthetic quota item','quantity'=>1,'unit_price'=>100]],'payment_method'=>'cash','cash_received'=>200,'save_as_provisional'=>true,'offline_uuid'=>$uuid]);$request->headers->set('Accept','application/json');
 $response=app(\App\Http\Controllers\FbrPosController::class)->store($request);quotaProof($response->getStatusCode()===200,'Unexpected FBR store response');return $response->getData(true);
}
if(($argv[1]??'')==='worker'){
 [$company,$user,$uuid,$stamp,$barrier,$index]=array_slice($argv,2);quotaProof(str_starts_with($barrier,dirname($socket).'/admission-')&&ctype_digit($index),'Unsafe admission barrier');quotaClock($stamp);
 DB::beginTransaction();FbrPosTransaction::where('company_id',$company)->count(); // Establish an old REPEATABLE READ snapshot before the competing write.
 file_put_contents($barrier.'.ready-'.$index,'ready');$until=microtime(true)+20;while(!is_file($barrier)){if(microtime(true)>$until)throw new RuntimeException('Admission barrier timeout');usleep(10000);}
 try{$result=admission((int)$company,(int)$user,$uuid);DB::commit();$result=['status'=>200,'id'=>$result['transaction_id'],'replayed'=>$result['replayed']??false];}catch(ValidationException $e){DB::rollBack();quotaProof(isset($e->errors()['subscription']),'Unexpected store validation');$result=['status'=>422];}
 Http::assertNothingSent();file_put_contents($barrier.'.result-'.$index,json_encode($result,JSON_THROW_ON_ERROR));exit;
}
$suffix=bin2hex(random_bytes(8));$workers=[];$barriers=[];
try{
 foreach(['distinct','same']as$scenario){
  quotaClock('2028-04-10T10:00:00+00:00');$c=Company::create(['name'=>'Synthetic FBR admission','ntn'=>'AD-'.$scenario.'-'.$suffix,'product_type'=>'fbrpos','fbr_pos_enabled'=>true,'fbr_reporting_enabled'=>false,'status'=>'approved','company_status'=>'active']);
  $p=PricingPlan::create(['name'=>'Business','product_type'=>'fbrpos','price'=>1000,'is_trial'=>false,'invoice_limit'=>1,'user_limit'=>3,'branch_limit'=>1]);
  TrialSubscriptionService::ensureSelectedTrial($c->id,'fbrpos',$p->id);$quote=Assignment::computePrice($p,'annual',$c);$paid=Assignment::assign($c->id,$p->id,'annual',paidRenewal:true,verifiedReceived:$quote['final_price']);quotaClock(CarbonImmutable::parse(Policy::display($paid)['paid_starts_at'])->addSecond()->toIso8601String());
  $owner=\App\Models\User::create(['name'=>'Synthetic admission owner','email'=>$scenario.$suffix.'@admission.invalid','password'=>bcrypt('SyntheticOnly123!'),'company_id'=>$c->id,'role'=>'company_admin','is_active'=>true]);
  $uuid=(string)\Illuminate\Support\Str::uuid();$barrier=dirname($socket).'/admission-'.$scenario.'-'.$suffix;$barriers[]=$barrier;$round=[];
  for($i=0;$i<2;$i++){$id=$scenario==='same'?$uuid:(string)\Illuminate\Support\Str::uuid();$worker=new Process([PHP_BINARY,__FILE__,'worker',(string)$c->id,(string)$owner->id,$id,now()->toIso8601String(),$barrier,(string)$i]);$worker->setTimeout(60);$worker->start();$workers[]=$worker;$round[]=$worker;}
  $until=microtime(true)+25;while(count(glob($barrier.'.ready-*'))!==2){if(microtime(true)>$until)throw new RuntimeException('Admission startup timeout');usleep(10000);}file_put_contents($barrier,'go');
  foreach($round as$worker){$worker->wait();quotaProof($worker->isSuccessful(),'Admission worker failed: '.$worker->getErrorOutput());}
  $results=array_map(fn($file)=>json_decode(file_get_contents($file),true,512,JSON_THROW_ON_ERROR),glob($barrier.'.result-*'));$statuses=array_column($results,'status');sort($statuses);quotaProof($statuses===($scenario==='same'?[200,200]:[200,422]),'Incorrect admission race outcomes');
  $bill=FbrPosTransaction::where('company_id',$c->id)->sole();quotaProof(PlanLimitService::canCreateFbrPosBill($c->id)['remaining']===0,'Last slot not consumed exactly once');$before=$bill->getAttributes();$replay=admission($c->id,$owner->id,$bill->offline_uuid);quotaProof($replay['transaction_id']===$bill->id&&($replay['replayed']??false)&&$bill->fresh()->getAttributes()===$before,'Full quota replay changed bill');
  if($scenario==='same')quotaProof(count(array_unique(array_column($results,'id')))===1,'Same UUID produced distinct bills');
  quotaProof($bill->fbr_status==='local'&&$bill->invoice_mode==='local'&&!$bill->fbr_invoice_number,'Synthetic admission attempted fiscal output');
 }
 Http::assertNothingSent();echo "PASS: native FBR actual store old-snapshot distinct UUID last-slot race admits one; identical UUID race and full-quota replay return same bill without fiscal calls\n";
}finally{foreach($workers as$worker)if($worker->isRunning())$worker->stop();foreach($barriers as$barrier)foreach(glob($barrier.'*')?:[]as$file)unlink($file);Carbon::setTestNow();CarbonImmutable::setTestNow();}

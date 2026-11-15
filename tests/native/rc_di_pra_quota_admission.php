<?php
declare(strict_types=1);
$db=getenv('DB_DATABASE')?:'';$socket=getenv('DB_SOCKET')?:'';
if(getenv('APP_ENV')!=='rc-mariadb'||!getenv('LD_PRELOAD')||!preg_match('/^taxnest_rc_[a-z0-9_]+_full$/D',$db)||!preg_match('#^/tmp/taxnest-rc-mariadb-[a-z0-9_]+/run/mariadb.sock$#D',$socket)){fwrite(STDERR,"FAIL unsafe DI PRA admission target\n");exit(2);}
require dirname(__DIR__,2).'/vendor/autoload.php';$app=require dirname(__DIR__,2).'/bootstrap/app.php';$app->make(\Illuminate\Contracts\Console\Kernel::class)->bootstrap();
use App\Models\{Company,FbrPosTransaction,PricingPlan,Subscription,Invoice,PosTransaction};
use App\Services\{FbrProspectiveQuotaRetention as Retention,ProspectiveSubscriptionPolicy as Policy,TrialSubscriptionService,SubscriptionAssignmentService as Assignment,PlanLimitService,ProspectiveQuotaGuard as Guard,DiFiscalSubmissionState as Fiscal};
use Carbon\{Carbon,CarbonImmutable};
use Illuminate\Support\Facades\{DB,Http,Mail,Notification};
use Illuminate\Validation\ValidationException;
use Symfony\Component\Process\Process;
Http::preventStrayRequests();Mail::fake();Notification::fake();
function quotaProof(bool $ok,string $reason):void{if(!$ok)throw new RuntimeException($reason);}
function quotaClock(string $stamp):void{Carbon::setTestNow(Carbon::parse($stamp));CarbonImmutable::setTestNow(CarbonImmutable::parse($stamp));}
function praAdmission(int $company,string $identity):int{return DB::transaction(function()use($company,$identity){$context=Guard::context($company);Guard::assertPra($company,$context);return PosTransaction::create(['company_id'=>$company,'invoice_number'=>$identity,'status'=>'completed','invoice_mode'=>'pra','transaction_type'=>'sale'])->id;});}
function diDraft(int $company,string $number):int{return DB::table('invoices')->insertGetId(['company_id'=>$company,'buyer_name'=>'Synthetic quota buyer','invoice_number'=>$number,'status'=>'draft','created_at'=>now(),'updated_at'=>now()]);}
if(($argv[1]??'')==='worker'){
 [$kind,$company,$identity,$stamp,$barrier,$index]=array_slice($argv,2);quotaProof(str_starts_with($barrier,dirname($socket).'/di-pra-')&&ctype_digit($index),'Unsafe quota admission barrier');quotaClock($stamp);
 DB::beginTransaction();DB::table($kind==='di'?'invoices':'pos_transactions')->where('company_id',$company)->count();
 file_put_contents($barrier.'.ready-'.$index,'ready');$until=microtime(true)+20;while(!is_file($barrier)){if(microtime(true)>$until)throw new RuntimeException('Quota admission barrier timeout');usleep(10000);}
 try{$result=$kind==='di'?Fiscal::reserve((int)$identity,'synthetic_native','production')?->id:praAdmission((int)$company,$identity);DB::commit();$result=['status'=>200,'id'=>$result];}catch(ValidationException $e){DB::rollBack();quotaProof(isset($e->errors()['subscription']),'Wrong quota denial');$result=['status'=>422];}
 Http::assertNothingSent();file_put_contents($barrier.'.result-'.$index,json_encode($result,JSON_THROW_ON_ERROR));exit;
}
$suffix=bin2hex(random_bytes(8));$workers=[];$barriers=[];
try{
 foreach(['di','pos']as$product){
  quotaClock('2028-04-10T10:00:00+00:00');$c=Company::create(['name'=>'Synthetic native admission','ntn'=>'DP-'.$product.'-'.$suffix,'product_type'=>$product,'status'=>'approved','company_status'=>'active']);
  $p=PricingPlan::create(['name'=>'Business','product_type'=>$product,'price'=>1000,'is_trial'=>false,'invoice_limit'=>1,'user_limit'=>3,'branch_limit'=>1,'inventory_enabled'=>false]);
  TrialSubscriptionService::ensureSelectedTrial($c->id,$product,$p->id);$quote=Assignment::computePrice($p,'annual',$c);$paid=Assignment::assign($c->id,$p->id,'annual',paidRenewal:true,verifiedReceived:$quote['final_price']);quotaClock(CarbonImmutable::parse(Policy::display($paid)['paid_starts_at'])->addSecond()->toIso8601String());
  $barrier=dirname($socket).'/di-pra-'.$product.'-'.$suffix;$barriers[]=$barrier;$round=[];$identities=[];
  for($i=0;$i<2;$i++){$identity=$product==='di'?diDraft($c->id,'SYN-DP-'.$suffix.'-'.$i):'SYN-DP-'.$suffix.'-'.$i;$identities[]=$identity;$worker=new Process([PHP_BINARY,__FILE__,'worker',$product==='di'?'di':'pra',(string)$c->id,(string)$identity,now()->toIso8601String(),$barrier,(string)$i]);$worker->setTimeout(60);$worker->start();$workers[]=$worker;$round[]=$worker;}
  $until=microtime(true)+25;while(count(glob($barrier.'.ready-*'))!==2){if(microtime(true)>$until)throw new RuntimeException('Quota admission startup timeout');usleep(10000);}file_put_contents($barrier,'go');foreach($round as$worker){$worker->wait();quotaProof($worker->isSuccessful(),'Quota admission worker failed: '.$worker->getErrorOutput());}
  $results=array_map(fn($file)=>json_decode(file_get_contents($file),true,512,JSON_THROW_ON_ERROR),glob($barrier.'.result-*'));$statuses=array_column($results,'status');sort($statuses);quotaProof($statuses===[200,422],'More than one last-slot admission or unexpected denial');
  if($product==='pos'){
   quotaProof(PosTransaction::where('company_id',$c->id)->count()===1&&PlanLimitService::canCreatePosBill($c->id)['remaining']===0,'PRA guarded-write quota not retained');
   continue;
  }
  $winner=Invoice::withoutGlobalScopes()->where('company_id',$c->id)->where('is_fbr_processing',true)->sole();$loser=Invoice::withoutGlobalScopes()->where('company_id',$c->id)->where('id','!=',$winner->id)->sole();
  $before=$winner->getAttributes();quotaProof(Fiscal::reserve($winner->id,'synthetic_retry','production')===null&&$winner->fresh()->getAttributes()===$before,'Same DI claim retry changed reservation');
  Fiscal::verificationRequired($winner,'production','synthetic_timeout');$winner->save();
  try{Fiscal::reserve($loser->id,'synthetic_retry','production');throw new RuntimeException('Unknown DI result released capacity');}catch(ValidationException $e){quotaProof(isset($e->errors()['subscription']),'Wrong unknown-result denial');}
  // Sandbox simulation may reserve a separate draft without consuming production capacity.
  $sandbox=diDraft($c->id,'SYN-SANDBOX-'.$suffix);$sandboxClaim=Fiscal::reserve($sandbox,'synthetic_sandbox','sandbox');quotaProof($sandboxClaim!==null,'Sandbox reservation consumed production cap');Fiscal::simulated($sandboxClaim);$sandboxClaim->save();
  Fiscal::rejected($winner);$winner->save();$second=Fiscal::reserve($loser->id,'synthetic_after_rejection','production');quotaProof($second!==null,'Definite rejection failed to release capacity');
  $second->fiscal_lease_expires_at=now()->subSecond();$second->save();quotaProof(Fiscal::reserve($second->id,'synthetic_retry','sandbox')===null,'Expired production claim resent');$second->refresh();quotaProof($second->fiscal_submission_environment==='production'&&$second->status==='pending_verification','Expired retry relabeled or freed production claim');
  quotaProof(Invoice::withoutGlobalScopes()->where('company_id',$c->id)->whereNotNull('fbr_invoice_number')->count()===0,'Reservation fixture fabricated fiscal acceptance');
 }
 Http::assertNothingSent();echo "PASS: native DI PRA old-snapshot distinct bill last-slot admission permits one; DI same-claim retry unknown hold definite rejection and frozen production expiry preserved without provider calls\n";
}finally{foreach($workers as$worker)if($worker->isRunning())$worker->stop();foreach($barriers as$barrier)foreach(glob($barrier.'*')?:[]as$file)unlink($file);Carbon::setTestNow();CarbonImmutable::setTestNow();}

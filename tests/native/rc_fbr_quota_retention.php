<?php
declare(strict_types=1);
$db=getenv('DB_DATABASE')?:'';$socket=getenv('DB_SOCKET')?:'';
if(getenv('APP_ENV')!=='rc-mariadb'||!getenv('LD_PRELOAD')||!preg_match('/^taxnest_rc_[a-z0-9_]+_full$/D',$db)||!preg_match('#^/tmp/taxnest-rc-mariadb-[a-z0-9_]+/run/mariadb.sock$#D',$socket)){fwrite(STDERR,"FAIL unsafe FBR quota target\n");exit(2);}
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
function quotaDelete(int $company,int $id):void{DB::transaction(function()use($company,$id){$bill=FbrPosTransaction::where('company_id',$company)->whereKey($id)->lockForUpdate()->first();if(!$bill)return;Retention::capture(collect([$bill]),'single-delete');Retention::capture(collect([$bill]),'single-delete');$bill->delete();},3);}
function quotaBill(Company $company,array $extra=[]):FbrPosTransaction{return FbrPosTransaction::create($extra+['company_id'=>$company->id,'invoice_number'=>'NQ-'.bin2hex(random_bytes(8)),'status'=>'completed','invoice_mode'=>'local','fbr_status'=>'local','total_amount'=>100,'transaction_type'=>'sale','created_at'=>now(),'updated_at'=>now()]);}
function quotaPay(Company $c,PricingPlan $p):Subscription{$quote=Assignment::computePrice($p,'annual',$c);return Assignment::assign($c->id,$p->id,'annual',paidRenewal:true,verifiedReceived:$quote['final_price']);}
if(($argv[1]??'')==='worker'){
 [$company,$bill,$stamp,$barrier,$index]=array_slice($argv,2);
 quotaProof(str_starts_with($barrier,dirname($socket).'/quota-')&&ctype_digit($index),'Unsafe quota barrier');quotaClock($stamp);file_put_contents($barrier.'.ready-'.$index,'ready');$until=microtime(true)+20;
 while(!is_file($barrier)){if(microtime(true)>$until)throw new RuntimeException('Quota barrier timeout');usleep(10000);}quotaDelete((int)$company,(int)$bill);exit;
}
quotaClock('2028-04-10T10:00:00+00:00');$suffix=bin2hex(random_bytes(8));
$c=Company::create(['name'=>'Synthetic FBR native quota','ntn'=>'FQ-'.$suffix,'product_type'=>'fbrpos','fbr_pos_enabled'=>true,'status'=>'approved','company_status'=>'active']);
$p=PricingPlan::create(['name'=>'Business','product_type'=>'fbrpos','price'=>1000,'is_trial'=>false,'invoice_limit'=>2,'user_limit'=>3,'branch_limit'=>1]);
TrialSubscriptionService::ensureSelectedTrial($c->id,'fbrpos',$p->id);$trial=quotaBill($c);$paid=quotaPay($c,$p);quotaClock(CarbonImmutable::parse(Policy::display($paid)['paid_starts_at'])->addSecond()->toIso8601String());$bill=quotaBill($c);
quotaProof(PlanLimitService::canCreateFbrPosBill($c->id)['remaining']===1,'FBR paid boundary inaccurate');
$barrier=dirname($socket).'/quota-'.$suffix;$workers=[];
try{
 for($i=0;$i<2;$i++){$worker=new Process([PHP_BINARY,__FILE__,'worker',(string)$c->id,(string)$bill->id,now()->toIso8601String(),$barrier,(string)$i]);$worker->setTimeout(45);$worker->start();$workers[]=$worker;}
 $until=microtime(true)+20;while(count(glob($barrier.'.ready-*'))!==2){if(microtime(true)>$until)throw new RuntimeException('FBR quota worker timeout');usleep(10000);}file_put_contents($barrier,'go');foreach($workers as$worker){$worker->wait();quotaProof($worker->isSuccessful(),'FBR quota worker failed: '.$worker->getErrorOutput());}
 quotaProof(!FbrPosTransaction::find($bill->id)&&Retention::deletedCount($c->id)===1&&PlanLimitService::canCreateFbrPosBill($c->id)['remaining']===1,'Concurrent FBR deletion reclaimed or duplicated paid slot');
 $saved=DB::table('prospective_fbr_quota_retention')->where('company_id',$c->id)->where('transaction_id',$bill->id)->sole();quotaDelete($c->id,$bill->id);DB::transaction(fn()=>Retention::capture(collect([$bill]),'single-delete'));
 quotaProof($saved==DB::table('prospective_fbr_quota_retention')->where('id',$saved->id)->first(),'FBR retry changed retained evidence');
 try{DB::transaction(fn()=>Retention::capture(collect([$bill]),'day-close'));throw new RuntimeException('Changed FBR source accepted');}catch(ValidationException $e){quotaProof(isset($e->errors()['subscription']),'Wrong FBR source hold');}
 // Mixed trial and paid rows deleted under one day-close transaction preserve only paid usage.
 $second=quotaBill($c);DB::transaction(function()use($c,$trial,$second){$rows=FbrPosTransaction::where('company_id',$c->id)->whereIn('id',[$trial->id,$second->id])->lockForUpdate()->get();Retention::capture($rows,'day-close');foreach($rows as$row)$row->delete();});
 quotaProof(Retention::deletedCount($c->id)===2&&PlanLimitService::canCreateFbrPosBill($c->id)['allowed']===false,'Mixed FBR day-close consumed trial or reclaimed paid slot');
 $renewed=quotaPay($c,$p);quotaProof($paid->lifecycle_snapshot['first_paid_starts_at']===$renewed->lifecycle_snapshot['first_paid_starts_at']&&PlanLimitService::canCreateFbrPosBill($c->id)['allowed']===false,'FBR renewal reset paid usage');
 $rollback=quotaBill($c);$before=$rollback->fresh()->getAttributes();try{DB::transaction(function()use($rollback){$row=FbrPosTransaction::whereKey($rollback->id)->lockForUpdate()->firstOrFail();Retention::capture(collect([$row]),'single-delete');$row->delete();throw new RuntimeException('synthetic FBR rollback');});}catch(RuntimeException $e){quotaProof($e->getMessage()==='synthetic FBR rollback','Wrong rollback failure');}
 quotaProof($rollback->fresh()->getAttributes()===$before&&!DB::table('prospective_fbr_quota_retention')->where('company_id',$c->id)->where('transaction_id',$rollback->id)->exists(),'FBR rollback retained a partial deletion');
 $legacy=Company::create(['name'=>'Synthetic FBR legacy','ntn'=>'FQL-'.$suffix,'product_type'=>'fbrpos']);$legacySub=Subscription::create(['company_id'=>$legacy->id,'pricing_plan_id'=>$p->id,'active'=>true,'start_date'=>now()->toDateString(),'end_date'=>now()->addYear()->toDateString()]);$legacyBefore=$legacySub->fresh()->getAttributes();$legacyBill=quotaBill($legacy);$legacyRow=$legacyBill->fresh()->getAttributes();DB::transaction(fn()=>Retention::capture(collect([$legacyBill]),'single-delete'));
 quotaProof(!DB::table('prospective_fbr_quota_retention')->where('company_id',$legacy->id)->exists()&&$legacySub->fresh()->getAttributes()===$legacyBefore&&$legacyBill->fresh()->getAttributes()===$legacyRow,'Legacy FBR data changed');
 Http::assertNothingSent();echo "PASS: native FBR prospective concurrent capture delete retry retains paid quota; mixed day-close excludes trial renewal no reset changed source denied rollback and legacy preserved no fiscal calls\n";
}finally{foreach($workers as$worker)if($worker->isRunning())$worker->stop();foreach(glob($barrier.'*')?:[]as$file)unlink($file);Carbon::setTestNow();CarbonImmutable::setTestNow();}

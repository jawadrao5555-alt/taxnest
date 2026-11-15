<?php
declare(strict_types=1);
$db=getenv('DB_DATABASE')?:'';$socket=getenv('DB_SOCKET')?:'';
if(getenv('APP_ENV')!=='rc-mariadb'||!getenv('LD_PRELOAD')||!preg_match('/^taxnest_rc_[a-z0-9_]+_full$/D',$db)||!preg_match('#^/tmp/taxnest-rc-mariadb-[a-z0-9_]+/run/mariadb.sock$#D',$socket)){fwrite(STDERR,"FAIL unsafe prospective quota target\n");exit(2);}
require dirname(__DIR__,2).'/vendor/autoload.php';$app=require dirname(__DIR__,2).'/bootstrap/app.php';$app->make(\Illuminate\Contracts\Console\Kernel::class)->bootstrap();
use App\Models\{Company,PosTransaction,PricingPlan,Subscription};
use App\Services\{ProspectiveQuotaRetention as Retention,ProspectiveSubscriptionPolicy as Policy,TrialSubscriptionService,SubscriptionAssignmentService as Assignment,PlanLimitService};
use Carbon\{Carbon,CarbonImmutable};
use Illuminate\Support\Facades\{DB,Http,Mail,Notification};
use Illuminate\Validation\ValidationException;
use Symfony\Component\Process\Process;
Http::preventStrayRequests();Mail::fake();Notification::fake();
function quotaProof(bool $ok,string $reason):void{if(!$ok)throw new RuntimeException($reason);}
function quotaClock(string $stamp):void{Carbon::setTestNow(Carbon::parse($stamp));CarbonImmutable::setTestNow(CarbonImmutable::parse($stamp));}
function quotaDelete(int $company,int $id):void{DB::transaction(function()use($company,$id){$bill=PosTransaction::where('company_id',$company)->whereKey($id)->lockForUpdate()->first();if(!$bill)return;Retention::capture(collect([$bill]),'single-delete',$id);Retention::capture(collect([$bill]),'single-delete',$id);$bill->delete();},3);}
function quotaBill(Company $company,array $extra=[]):PosTransaction{return PosTransaction::create($extra+['company_id'=>$company->id,'invoice_number'=>'NQ-'.bin2hex(random_bytes(8)),'status'=>'completed','invoice_mode'=>'pra','transaction_type'=>'sale','created_at'=>now(),'updated_at'=>now()]);}
function quotaPay(Company $c,PricingPlan $p):Subscription{$quote=Assignment::computePrice($p,'annual',$c);return Assignment::assign($c->id,$p->id,'annual',paidRenewal:true,verifiedReceived:$quote['final_price']);}
if(($argv[1]??'')==='worker'){
 [$company,$bill,$stamp,$barrier,$index]=array_slice($argv,2);
 quotaProof(str_starts_with($barrier,dirname($socket).'/quota-')&&ctype_digit($index),'Unsafe quota barrier');quotaClock($stamp);file_put_contents($barrier.'.ready-'.$index,'ready');$until=microtime(true)+20;
 while(!is_file($barrier)){if(microtime(true)>$until)throw new RuntimeException('Quota barrier timeout');usleep(10000);}quotaDelete((int)$company,(int)$bill);exit;
}
quotaClock('2028-04-10T10:00:00+00:00');$suffix=bin2hex(random_bytes(8));
$c=Company::create(['name'=>'Synthetic native quota','ntn'=>'NQ-'.$suffix,'product_type'=>'pos','status'=>'approved','company_status'=>'active']);
$p=PricingPlan::create(['name'=>'Business','product_type'=>'pos','price'=>1000,'is_trial'=>false,'invoice_limit'=>2,'user_limit'=>3,'branch_limit'=>1]);
TrialSubscriptionService::ensureSelectedTrial($c->id,'pos',$p->id);$trialBill=quotaBill($c);$paid=quotaPay($c,$p);quotaClock(CarbonImmutable::parse(Policy::display($paid)['paid_starts_at'])->addSecond()->toIso8601String());
quotaProof(PlanLimitService::canCreatePosBill($c->id)['remaining']===2,'Trial bill consumed paid allowance');$bill=quotaBill($c);$original=$bill->getAttributes();
$barrier=dirname($socket).'/quota-'.$suffix;$workers=[];
try{
 for($i=0;$i<2;$i++){$worker=new Process([PHP_BINARY,__FILE__,'worker',(string)$c->id,(string)$bill->id,now()->toIso8601String(),$barrier,(string)$i]);$worker->setTimeout(45);$worker->start();$workers[]=$worker;}
 $until=microtime(true)+20;while(count(glob($barrier.'.ready-*'))!==2){if(microtime(true)>$until)throw new RuntimeException('Quota worker startup timeout');usleep(10000);}file_put_contents($barrier,'go');
 foreach($workers as$worker){$worker->wait();quotaProof($worker->isSuccessful(),'Quota worker failed: '.$worker->getErrorOutput());}
 quotaProof(!PosTransaction::find($bill->id)&&Retention::deletedCount($c->id)===1,'Concurrent deletion lost or duplicated paid slot');
 $saved=DB::table('prospective_pos_quota_retention')->where('company_id',$c->id)->where('transaction_id',$bill->id)->sole();
 quotaDelete($c->id,$bill->id);DB::transaction(fn()=>Retention::capture(collect([$bill]),'single-delete',$bill->id));
 quotaProof($saved==DB::table('prospective_pos_quota_retention')->where('id',$saved->id)->first(),'Exact retry mutated retained evidence');
 try{DB::transaction(fn()=>Retention::capture(collect([$bill]),'day-close',999));throw new RuntimeException('Changed source accepted');}catch(ValidationException $e){quotaProof(isset($e->errors()['subscription']),'Wrong changed source error');}
 quotaProof($saved==DB::table('prospective_pos_quota_retention')->where('id',$saved->id)->first(),'Rejected source replaced evidence');
 quotaDelete($c->id,$trialBill->id);quotaProof(Retention::deletedCount($c->id)===1,'Deleted trial bill consumed paid allowance');
 $renewed=quotaPay($c,$p);quotaProof($paid->lifecycle_snapshot['first_paid_starts_at']===$renewed->lifecycle_snapshot['first_paid_starts_at']&&PlanLimitService::canCreatePosBill($c->id)['remaining']===1,'Renewal reset monthly paid usage');
 // Source record, exact fact and actual deletion must all roll back together.
 $rollback=quotaBill($c);$before=$rollback->fresh()->getAttributes();$sourceId=null;
 try{DB::transaction(function()use($c,$rollback,&$sourceId){$locked=PosTransaction::whereKey($rollback->id)->lockForUpdate()->firstOrFail();$sourceId=DB::table('pos_local_series_resets')->insertGetId(['company_id'=>$c->id,'reset_date'=>now()->toDateString(),'deleted_final_count'=>1,'deleted_provisional_count'=>0,'total_deleted'=>1,'created_at'=>now(),'updated_at'=>now()]);Retention::capture(collect([$locked]),'archive-clear',$sourceId);$locked->delete();throw new RuntimeException('synthetic source rollback');});}catch(RuntimeException $e){quotaProof($e->getMessage()==='synthetic source rollback','Unexpected rollback exception');}
 quotaProof($rollback->fresh()->getAttributes()===$before&&!DB::table('pos_local_series_resets')->where('id',$sourceId)->exists()&&!DB::table('prospective_pos_quota_retention')->where('company_id',$c->id)->where('transaction_id',$rollback->id)->exists(),'Rollback left source, deletion or quota fact');
 DB::transaction(function()use($rollback){Retention::capture(collect([$rollback]),'single-delete',$rollback->id);quotaProof(Retention::deletedCount($rollback->company_id)===1,'Live and retained bill double-counted');});
 quotaProof(PlanLimitService::canCreatePosBill($c->id)['remaining']===0,'Live and retained total inaccurate');
 // An unattributed aggregate is an explicit review hold, not an inferred zero.
 DB::table('pos_local_series_resets')->insert(['company_id'=>$c->id,'reset_date'=>now()->toDateString(),'deleted_final_count'=>1,'deleted_provisional_count'=>0,'total_deleted'=>1,'created_at'=>now(),'updated_at'=>now()]);
 try{Retention::deletedCount($c->id);throw new RuntimeException('Unknown aggregate accepted');}catch(ValidationException $e){quotaProof(isset($e->errors()['subscription']),'Unknown aggregate wrong hold');}
 $legacy=Company::create(['name'=>'Synthetic native legacy quota','ntn'=>'NQL-'.$suffix,'product_type'=>'pos','status'=>'approved','company_status'=>'active']);$legacySub=Subscription::create(['company_id'=>$legacy->id,'pricing_plan_id'=>$p->id,'active'=>true,'billing_cycle'=>'annual','start_date'=>now()->toDateString(),'end_date'=>now()->addYear()->toDateString(),'final_price'=>1000]);$legacyBefore=$legacySub->fresh()->getAttributes();$legacyBill=quotaBill($legacy);
 DB::transaction(fn()=>Retention::capture(collect([$legacyBill]),'single-delete',$legacyBill->id));quotaProof(!DB::table('prospective_pos_quota_retention')->where('company_id',$legacy->id)->exists()&&$legacySub->fresh()->getAttributes()===$legacyBefore&&PlanLimitService::canCreatePosBill($legacy->id)['remaining']===1,'Legacy quota changed');
 $migration=require database_path('migrations/2026_12_27_020000_add_prospective_quota_retention.php');$migration->down();$migration->up();quotaProof($saved==DB::table('prospective_pos_quota_retention')->where('id',$saved->id)->first(),'Operational rollback lost retained fact');
 Http::assertNothingSent();echo "PASS: native prospective quota concurrent capture delete retry retains one paid slot; trial excluded renewal no reset changed source refused atomic rollback unknown aggregate hold legacy preserved\n";
}finally{foreach($workers as$worker)if($worker->isRunning())$worker->stop();foreach(glob($barrier.'*')?:[]as$file)unlink($file);Carbon::setTestNow();CarbonImmutable::setTestNow();}

<?php
declare(strict_types=1);
$db=getenv('DB_DATABASE')?:'';$socket=getenv('DB_SOCKET')?:'';
if(getenv('APP_ENV')!=='rc-mariadb'||!getenv('LD_PRELOAD')||!preg_match('/^taxnest_rc_[a-z0-9_]+_full$/D',$db)||!preg_match('#^/tmp/taxnest-rc-mariadb-[a-z0-9_]+/run/mariadb.sock$#D',$socket)){fwrite(STDERR,"FAIL unsafe DI stock lock order target\n");exit(2);}
require dirname(__DIR__,2).'/vendor/autoload.php';$app=require dirname(__DIR__,2).'/bootstrap/app.php';$app->make(\Illuminate\Contracts\Console\Kernel::class)->bootstrap();
use App\Models\{Company,FbrPosTransaction,PricingPlan,Subscription,Invoice,PosTransaction,Product};
use App\Services\{FbrProspectiveQuotaRetention as Retention,ProspectiveSubscriptionPolicy as Policy,TrialSubscriptionService,SubscriptionAssignmentService as Assignment,PlanLimitService,ProspectiveQuotaGuard as Guard,DiFiscalSubmissionState as Fiscal,DiInvoiceInventory,InventoryService};
use Carbon\{Carbon,CarbonImmutable};
use Illuminate\Support\Facades\{DB,Http,Mail,Notification};
use Illuminate\Validation\ValidationException;
use Symfony\Component\Process\Process;
Http::preventStrayRequests();Mail::fake();Notification::fake();
function quotaProof(bool $ok,string $reason):void{if(!$ok)throw new RuntimeException($reason);}
function quotaClock(string $stamp):void{Carbon::setTestNow(Carbon::parse($stamp));CarbonImmutable::setTestNow(CarbonImmutable::parse($stamp));}
function stockAccept(int $id):void{$invoice=Invoice::withoutGlobalScopes()->findOrFail($id);Fiscal::accepted($invoice,'SYNTHETIC-LOCK-'.$id,'production','synthetic_native_lock_order');DiInvoiceInventory::saveAccepted($invoice);}
if(($argv[1]??'')==='worker'){
 [$mode,$id,$stamp,$barrier,$index]=array_slice($argv,2);quotaProof(str_starts_with($barrier,dirname($socket).'/quota-stock-')&&ctype_digit($index),'Unsafe stock barrier');quotaClock($stamp);
 $locks=[];
 // Force actual overlap at the company mutex, not merely simultaneous startup.
 DB::connection()->beforeExecuting(function($sql)use($barrier,$index){if($index!=='1'||!str_contains(strtolower($sql),'for update')||!str_contains(strtolower($sql),'`companies`'))return;$until=microtime(true)+20;while(!is_file($barrier.'.company-held')){if(microtime(true)>$until)throw new RuntimeException('Leader company lock not observed');usleep(10000);}file_put_contents($barrier.'.contender-attempt','ready');});
 DB::listen(function($query)use(&$locks,$barrier,$index){$sql=strtolower($query->sql);if(!str_contains($sql,'for update'))return;$locks[]=$sql;if(count($locks)===1&&$index==='0'){quotaProof(str_contains($sql,'`companies`'),'Leader locked child before company');file_put_contents($barrier.'.company-held','held');$until=microtime(true)+20;while(!is_file($barrier.'.contender-attempt')){if(microtime(true)>$until)throw new RuntimeException('No competing company lock attempt');usleep(10000);}usleep(200000);}});
 file_put_contents($barrier.'.ready-'.$index,'ready');$until=microtime(true)+20;while(!is_file($barrier)){if(microtime(true)>$until)throw new RuntimeException('Stock barrier timeout');usleep(10000);}
 if($mode==='reserve'){quotaProof(Fiscal::reserve((int)$id,'synthetic_stock_race','production')!==null,'Stock race reservation unavailable');}
 elseif($mode==='receive'){$invoice=Invoice::withoutGlobalScopes()->findOrFail($id);$product=$invoice->items()->sole()->inventory_snapshot['product_id'];InventoryService::addStock($invoice->company_id,$product,'3.0000','2.00','purchase');}
 else stockAccept((int)$id);
 quotaProof($locks!==[]&&str_contains($locks[0],'`companies`'),'First exclusive lock was not company');Http::assertNothingSent();exit;
}
function stockRace(array $jobs,string $socket):void{
 $barrier=dirname($socket).'/quota-stock-'.bin2hex(random_bytes(8));$workers=[];
 try{foreach($jobs as$i=>[$mode,$id]){$worker=new Process([PHP_BINARY,__FILE__,'worker',$mode,(string)$id,now()->toIso8601String(),$barrier,(string)$i]);$worker->setTimeout(45);$worker->start();$workers[]=$worker;}
 $until=microtime(true)+25;while(count(glob($barrier.'.ready-*'))!==count($jobs)){if(microtime(true)>$until)throw new RuntimeException('Stock worker startup timeout');usleep(10000);}file_put_contents($barrier,'go');foreach($workers as$worker){$worker->wait();quotaProof($worker->isSuccessful(),'Stock lock-order worker failed: '.$worker->getErrorOutput());}
 }finally{foreach($workers as$worker)if($worker->isRunning())$worker->stop();foreach(glob($barrier.'*')?:[]as$file)unlink($file);}
}
quotaClock('2028-04-10T10:00:00+00:00');$suffix=bin2hex(random_bytes(8));
$c=Company::create(['name'=>'Synthetic DI quota stock mutex','ntn'=>'QL-'.$suffix,'product_type'=>'di','status'=>'approved','company_status'=>'active','inventory_enabled'=>true]);$p=PricingPlan::create(['name'=>'Business','product_type'=>'di','price'=>1000,'invoice_limit'=>100,'inventory_enabled'=>true,'user_limit'=>3,'branch_limit'=>1]);TrialSubscriptionService::ensureSelectedTrial($c->id,'di',$p->id);$quote=Assignment::computePrice($p,'annual',$c);$paid=Assignment::assign($c->id,$p->id,'annual',paidRenewal:true,verifiedReceived:$quote['final_price']);quotaClock(CarbonImmutable::parse(Policy::display($paid)['paid_starts_at'])->addSecond()->toIso8601String());
$product=Product::withoutGlobalScopes()->create(['company_id'=>$c->id,'name'=>'Synthetic mutex item','hs_code'=>'33049900','uom'=>'KG','is_active'=>true]);
$make=function(string $quantity,?int $source=null)use($c,$product):Invoice{
 $invoice=Invoice::withoutGlobalScopes()->create(['company_id'=>$c->id,'invoice_number'=>'SYN-LOCK-'.bin2hex(random_bytes(6)),'buyer_name'=>'Synthetic','buyer_ntn'=>'','buyer_address'=>'Synthetic','total_amount'=>100,'status'=>'draft','document_type'=>$source?'Credit Note':'Sale Invoice','inventory_enabled_snapshot'=>true,'inventory_environment_snapshot'=>'production']);
 $invoice->items()->create(['hs_code'=>'33049900','description'=>'Synthetic','quantity'=>$quantity,'price'=>100,'tax'=>0,'default_uom'=>'KG','inventory_snapshot'=>['version'=>1,'company_id'=>$c->id,'product_id'=>$product->id,'branch_id'=>null,'base_uom'=>'KG','base_quantity'=>$quantity,'source_invoice_item_id'=>$source]]);return$invoice;
};
try{
 $source=$make('10.0000');stockAccept($source->id);$credit=$make('1.0000',$source->items()->sole()->id);$sale=$make('2.0000');
 stockRace([['callback',$sale->id],['reserve',$credit->id]],$socket);
 quotaProof($credit->fresh()->is_fbr_processing&&$sale->fresh()->inventory_posting_state==='posted','Reserve/callback lost state');
 quotaProof((string)DB::table('inventory_stocks')->where('company_id',$c->id)->where('product_id',$product->id)->value('quantity')==='-12.0000','Reserve/callback changed stock incorrectly');
 $receiveSale=$make('1.0000');stockRace([['receive',$receiveSale->id],['callback',$receiveSale->id]],$socket);
 quotaProof((string)DB::table('inventory_stocks')->where('company_id',$c->id)->where('product_id',$product->id)->value('quantity')==='-10.0000','Receiving/callback race lost exact stock');
 quotaProof(DB::table('inventory_stocks')->where('company_id',$c->id)->where('product_id',$product->id)->count()===1,'Concurrent receiving duplicated nullable stock row');
 $before=DB::table('di_invoice_inventory_postings')->where('company_id',$c->id)->orderBy('id')->get()->toJson();stockAccept($sale->id);stockAccept($receiveSale->id);quotaProof($before===DB::table('di_invoice_inventory_postings')->where('company_id',$c->id)->orderBy('id')->get()->toJson(),'Callback retry duplicated stock posting');
 // Subscription expiry blocks new dispatch, never recording an already-received ACK.
 $late=$make('1.0000');quotaClock(CarbonImmutable::parse(Policy::display($paid)['grace_ends_at'])->addSecond()->toIso8601String());stockRace([['receive',$late->id],['callback',$late->id]],$socket);
 quotaProof($late->fresh()->status==='locked'&&$late->fresh()->inventory_posting_state==='posted','Expired subscription discarded recorded ACK');
 quotaProof((string)DB::table('inventory_stocks')->where('company_id',$c->id)->where('product_id',$product->id)->value('quantity')==='-8.0000','Expired ACK/receiving stock inaccurate');
 Http::assertNothingSent();echo "PASS: native DI quota reservation callback and receiving serialize company before invoice product; exact stock and duplicate ACK preserved including expired subscription without provider calls\n";
}finally{Carbon::setTestNow();CarbonImmutable::setTestNow();}

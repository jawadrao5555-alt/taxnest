<?php
declare(strict_types=1);
$db=getenv('DB_DATABASE')?:'';$socket=getenv('DB_SOCKET')?:'';
if(getenv('APP_ENV')!=='rc-mariadb'||!getenv('LD_PRELOAD')||!preg_match('/^taxnest_rc_[a-z0-9_]+_full$/D',$db)||!preg_match('#^/tmp/taxnest-rc-mariadb-[a-z0-9_]+/run/mariadb.sock$#D',$socket)){fwrite(STDERR,"FAIL unsafe FBR stock lock order target\n");exit(2);}
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
function fbrStockSale(int $company,int $user,int $product,string $uuid):array{
 auth('fbrpos')->setUser(\App\Models\User::findOrFail($user));app()->instance('currentCompanyId',$company);
 $request=\Illuminate\Http\Request::create('/fbr-pos/store','POST',['items'=>[['product_id'=>$product,'item_name'=>'Synthetic stocked item','quantity'=>1,'unit_price'=>100]],'payment_method'=>'cash','cash_received'=>200,'save_as_provisional'=>true,'offline_uuid'=>$uuid]);$request->headers->set('Accept','application/json');$response=app(\App\Http\Controllers\FbrPosController::class)->store($request);quotaProof($response->getStatusCode()===200,'FBR stocked sale failed');return $response->getData(true);
}
if(($argv[1]??'')==='worker'){
 [$mode,$company,$user,$product,$uuid,$stamp,$barrier,$index]=array_slice($argv,2);quotaProof(str_starts_with($barrier,dirname($socket).'/fbr-stock-')&&ctype_digit($index),'Unsafe FBR stock barrier');quotaClock($stamp);$locks=[];
 DB::connection()->beforeExecuting(function($sql)use($barrier,$index){if($index!=='1'||!str_contains(strtolower($sql),'for update')||!str_contains(strtolower($sql),'`companies`'))return;$until=microtime(true)+20;while(!is_file($barrier.'.company-held')){if(microtime(true)>$until)throw new RuntimeException('Receiving company lock not observed');usleep(10000);}file_put_contents($barrier.'.contender-attempt','ready');});
 DB::listen(function($query)use(&$locks,$barrier,$index){$sql=strtolower($query->sql);if(!str_contains($sql,'for update'))return;$locks[]=$sql;if(count($locks)===1&&$index==='0'){quotaProof(str_contains($sql,'`companies`'),'Receiving locked stock before company');file_put_contents($barrier.'.company-held','held');$until=microtime(true)+20;while(!is_file($barrier.'.contender-attempt')){if(microtime(true)>$until)throw new RuntimeException('No competing FBR sale lock');usleep(10000);}usleep(200000);}});
 file_put_contents($barrier.'.ready-'.$index,'ready');$until=microtime(true)+20;while(!is_file($barrier)){if(microtime(true)>$until)throw new RuntimeException('FBR stock barrier timeout');usleep(10000);}
 if($mode==='receive')InventoryService::addStock((int)$company,(int)$product,'3.0000','2.00','purchase');else fbrStockSale((int)$company,(int)$user,(int)$product,$uuid);
 quotaProof($locks!==[]&&str_contains($locks[0],'`companies`'),'First exclusive lock was not company');Http::assertNothingSent();exit;
}
quotaClock('2028-04-10T10:00:00+00:00');$suffix=bin2hex(random_bytes(8));
$c=Company::create(['name'=>'Synthetic FBR stock mutex','ntn'=>'FL-'.$suffix,'product_type'=>'fbrpos','status'=>'approved','company_status'=>'active','inventory_enabled'=>true,'fbr_pos_enabled'=>true,'fbr_reporting_enabled'=>false]);$p=PricingPlan::create(['name'=>'Business','product_type'=>'fbrpos','price'=>1000,'invoice_limit'=>1,'inventory_enabled'=>true,'user_limit'=>3,'branch_limit'=>1]);TrialSubscriptionService::ensureSelectedTrial($c->id,'fbrpos',$p->id);$quote=Assignment::computePrice($p,'annual',$c);$paid=Assignment::assign($c->id,$p->id,'annual',paidRenewal:true,verifiedReceived:$quote['final_price']);quotaClock(CarbonImmutable::parse(Policy::display($paid)['paid_starts_at'])->addSecond()->toIso8601String());
$product=Product::withoutGlobalScopes()->create(['company_id'=>$c->id,'name'=>'Synthetic stocked item','hs_code'=>'33049900','uom'=>'KG','is_active'=>true]);$owner=\App\Models\User::create(['name'=>'Synthetic stock owner','email'=>$suffix.'@fbr-stock.invalid','password'=>bcrypt('SyntheticOnly123!'),'company_id'=>$c->id,'role'=>'company_admin','is_active'=>true]);
InventoryService::addStock($c->id,$product->id,'5.0000','2.00','purchase');$uuid=(string)\Illuminate\Support\Str::uuid();$barrier=dirname($socket).'/fbr-stock-'.$suffix;$workers=[];
try{
 foreach(['receive','sale']as$i=>$mode){$worker=new Process([PHP_BINARY,__FILE__,'worker',$mode,(string)$c->id,(string)$owner->id,(string)$product->id,$uuid,now()->toIso8601String(),$barrier,(string)$i]);$worker->setTimeout(45);$worker->start();$workers[]=$worker;}
 $until=microtime(true)+25;while(count(glob($barrier.'.ready-*'))!==2){if(microtime(true)>$until)throw new RuntimeException('FBR stock startup timeout');usleep(10000);}file_put_contents($barrier,'go');foreach($workers as$worker){$worker->wait();quotaProof($worker->isSuccessful(),'FBR stock worker failed: '.$worker->getErrorOutput());}
 $stocks=DB::table('inventory_stocks')->where('company_id',$c->id)->where('product_id',$product->id);quotaProof($stocks->count()===1&&(string)$stocks->value('quantity')==='7.0000','FBR stock race lost quantity or duplicated row');
 $movements=DB::table('inventory_movements')->where('company_id',$c->id)->where('product_id',$product->id);quotaProof($movements->count()===3,'FBR stock movement count wrong');$saved=$movements->orderBy('id')->get()->toJson();
 $bill=FbrPosTransaction::where('company_id',$c->id)->sole();quotaProof(PlanLimitService::canCreateFbrPosBill($c->id)['allowed']===false,'Last slot not consumed');$result=fbrStockSale($c->id,$owner->id,$product->id,$uuid);quotaProof($result['transaction_id']===$bill->id&&($result['replayed']??false)&&$saved===$movements->orderBy('id')->get()->toJson(),'Replay duplicated sale stock movement');
 quotaProof($bill->fbr_status==='local'&&!$bill->fbr_invoice_number,'FBR stock fixture fiscal output');Http::assertNothingSent();echo "PASS: native FBR receiving overlaps actual last-slot sale with company-first locks exact stock movements and same UUID replay without fiscal calls\n";
}finally{foreach($workers as$worker)if($worker->isRunning())$worker->stop();foreach(glob($barrier.'*')?:[]as$file)unlink($file);Carbon::setTestNow();CarbonImmutable::setTestNow();}

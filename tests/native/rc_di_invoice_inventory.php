<?php
declare(strict_types=1);
$db=getenv('DB_DATABASE')?:'';$socket=getenv('DB_SOCKET')?:'';
if(!preg_match('/^taxnest_rc_[a-z0-9_]+_full$/D',$db)||!preg_match('#^/tmp/taxnest-rc-mariadb-[a-z0-9_]+/run/mariadb.sock$#D',$socket)||getenv('APP_ENV')!=='rc-mariadb'||!getenv('LD_PRELOAD')){fwrite(STDERR,"FAIL unsafe DI inventory target\n");exit(2);}
require dirname(__DIR__,2).'/vendor/autoload.php';$app=require dirname(__DIR__,2).'/bootstrap/app.php';$app->make(\Illuminate\Contracts\Console\Kernel::class)->bootstrap();
use App\Models\{Company,Invoice,Product};use App\Services\{DiInvoiceInventory,DiFiscalSubmissionState};use Illuminate\Support\Facades\{DB,Http};use Symfony\Component\Process\Process;
Http::preventStrayRequests();
function invProof(bool $ok,string $why):void{if(!$ok)throw new RuntimeException($why);}
function invAccept(int $id):void{$invoice=Invoice::withoutGlobalScopes()->findOrFail($id);DiFiscalSubmissionState::accepted($invoice,'SYNTHETIC-STOCK-'.$id,'production','synthetic_native');DiInvoiceInventory::saveAccepted($invoice);}
if(in_array(($argv[1]??''),['worker','reserve-worker'],true)){
 [$id,$barrier,$index]=array_slice($argv,2);invProof(str_starts_with($barrier,dirname($socket).'/di-stock-')&&ctype_digit($index),'Unsafe inventory barrier');
 file_put_contents($barrier.'.ready'.$index,'ready');$until=microtime(true)+20;while(!is_file($barrier)){if(microtime(true)>$until)throw new RuntimeException('Inventory barrier timeout');usleep(10000);}
 try{if($argv[1]==='reserve-worker'){invProof(DiFiscalSubmissionState::reserve((int)$id,'sync','production')!==null,'Reservation was unavailable');}else invAccept((int)$id);echo'200';}catch(\Illuminate\Validation\ValidationException){echo'422';}exit;
}
function invRace(array $ids,string $socket,string $mode='worker'):array{
 $barrier=dirname($socket).'/di-stock-'.bin2hex(random_bytes(8));$workers=[];
 try{foreach($ids as $i=>$id){$p=new Process([PHP_BINARY,__FILE__,$mode,(string)$id,$barrier,(string)$i]);$p->setTimeout(30);$p->start();$workers[]=$p;}
 $until=microtime(true)+20;foreach(array_keys($ids)as$i)while(!is_file($barrier.'.ready'.$i)){if(microtime(true)>$until)throw new RuntimeException('Stock worker startup timeout');usleep(10000);}file_put_contents($barrier,'go');
 $results=[];foreach($workers as$p){$p->wait();invProof($p->isSuccessful(),'Stock worker failed: '.$p->getErrorOutput());$results[]=trim($p->getOutput());}sort($results);return$results;
 }finally{foreach($workers as$p)if($p->isRunning())$p->stop();foreach(glob($barrier.'*')?:[]as$f)unlink($f);}
}
$company=Company::create(['name'=>'Synthetic native DI inventory','ntn'=>bin2hex(random_bytes(6)),'product_type'=>'di','status'=>'approved','company_status'=>'active','inventory_enabled'=>true]);
$product=Product::withoutGlobalScopes()->create(['company_id'=>$company->id,'name'=>'Synthetic native stock item','hs_code'=>'33049900','uom'=>'KG','is_active'=>true]);
$make=function(string $qty,?int $source=null)use($company,$product):Invoice{
 $invoice=Invoice::withoutGlobalScopes()->create(['company_id'=>$company->id,'invoice_number'=>'SYN-STOCK-'.bin2hex(random_bytes(6)),'buyer_name'=>'Synthetic','buyer_ntn'=>'','buyer_address'=>'Synthetic','total_amount'=>100,'status'=>'draft','document_type'=>$source?'Credit Note':'Sale Invoice','inventory_enabled_snapshot'=>true,'inventory_environment_snapshot'=>'production']);
 $invoice->items()->create(['hs_code'=>'33049900','description'=>'Synthetic','quantity'=>$qty,'price'=>100,'tax'=>0,'default_uom'=>'KG','inventory_snapshot'=>['version'=>1,'company_id'=>$company->id,'product_id'=>$product->id,'branch_id'=>null,'base_uom'=>'KG','base_quantity'=>$qty,'source_invoice_item_id'=>$source]]);return$invoice;
};
$sale=$make('10.1234');invProof(invRace([$sale->id,$sale->id],$socket)===['200','200'],'Duplicate acceptance failed');
invProof(DB::table('di_invoice_inventory_postings')->where('invoice_id',$sale->id)->count()===1,'Duplicate sale stock posting');
invProof((string)DB::table('inventory_stocks')->where('company_id',$company->id)->value('quantity')==='-10.1234','Native exact four decimal deduction failed');
$a=$make('7.0000',$sale->items()->sole()->id);$b=$make('7.0000',$sale->items()->sole()->id);
invProof(invRace([$a->id,$b->id],$socket,'reserve-worker')===['200','422'],'Concurrent overreturn reached dispatch reservation');
$winner=$a->fresh()->is_fbr_processing?$a:$b;invAccept($winner->id);
invProof(DB::table('di_inventory_return_reservations')->where('company_id',$company->id)->where('state','consumed')->count()===1,'Return reservation not consumed');
invProof((string)DB::table('inventory_stocks')->where('company_id',$company->id)->value('quantity')==='-3.1234','Concurrent return changed stock incorrectly');
invProof(DB::table('inventory_stocks')->where('company_id',$company->id)->count()===1,'Nullable branch produced duplicate stock rows');
$rollback=$make('0.0001');try{DB::transaction(function()use($rollback){invAccept($rollback->id);throw new RuntimeException('Synthetic rollback');});}catch(RuntimeException){}
invProof($rollback->fresh()->status==='draft'&&!DB::table('di_invoice_inventory_postings')->where('invoice_id',$rollback->id)->exists(),'Atomic finalization rollback failed');
$emptyProduct=Product::withoutGlobalScopes()->create(['company_id'=>$company->id,'name'=>'Synthetic empty row race','hs_code'=>'33049900','uom'=>'KG','is_active'=>true]);
$emptyA=$make('0.0001');$emptyB=$make('0.0001');
foreach([$emptyA,$emptyB]as$empty){$item=$empty->items()->sole();$snapshot=$item->inventory_snapshot;$snapshot['product_id']=$emptyProduct->id;$item->update(['inventory_snapshot'=>$snapshot]);}
invProof(invRace([$emptyA->id,$emptyB->id],$socket)===['200','200'],'Independent empty stock creation failed');
invProof(DB::table('inventory_stocks')->where('company_id',$company->id)->where('product_id',$emptyProduct->id)->count()===1,'Concurrent NULL-branch duplicate stock row');
invProof((string)DB::table('inventory_stocks')->where('company_id',$company->id)->where('product_id',$emptyProduct->id)->value('quantity')==='-0.0002','Exact independent fractional deductions lost');
echo "PASS: native DI frozen inventory duplicate acceptance posts once concurrent overreturn refused exact four decimals and rollback preserved\n";

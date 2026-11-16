<?php
declare(strict_types=1);
$db=getenv('DB_DATABASE')?:'';$socket=getenv('DB_SOCKET')?:'';
if(!preg_match('/^taxnest_rc_[a-z0-9_]+_full$/D',$db)||!preg_match('#^/tmp/taxnest-rc-mariadb-[a-z0-9_]+/run/mariadb.sock$#D',$socket)||getenv('APP_ENV')!=='rc-mariadb'||!getenv('LD_PRELOAD')){fwrite(STDERR,"FAIL unsafe AI budget target\n");exit(2);}
require dirname(__DIR__,2).'/vendor/autoload.php';$app=require dirname(__DIR__,2).'/bootstrap/app.php';$app->make(\Illuminate\Contracts\Console\Kernel::class)->bootstrap();
use App\Models\Company;use App\Services\MadadgarUsage;use Illuminate\Support\Facades\{DB,Http};use Symfony\Component\Process\Process;
Http::preventStrayRequests();
function budgetProof(bool $ok,string $message):void{if(!$ok)throw new RuntimeException($message);}
if(($argv[1]??'')==='worker'){
 [$companyId,$barrier,$index]=array_slice($argv,2);budgetProof(str_starts_with($barrier,dirname($socket).'/ai-budget-')&&ctype_digit($index),'Unsafe budget barrier');
 try{$company=Company::findOrFail((int)$companyId);DB::beginTransaction();DB::table('madadgar_usage_events')->count();file_put_contents($barrier.'.ready'.$index,'ready');$until=microtime(true)+20;while(!is_file($barrier)){if(microtime(true)>$until)throw new RuntimeException('Budget barrier timeout');usleep(10000);}
  try{MadadgarUsage::begin($company,100,600);$status='200';}catch(RuntimeException $e){if($e->getMessage()!=='Madadgar company AI budget unavailable')throw$e;$status='429';}DB::commit();echo$status;
 }catch(Throwable $e){if(DB::transactionLevel())DB::rollBack();fwrite(STDERR,'AI budget worker failure '.get_class($e).PHP_EOL);exit(1);}exit;
}
$company=Company::create(['name'=>'Synthetic AI budget','ntn'=>'AI-'.bin2hex(random_bytes(6)),'product_type'=>'pos']);
DB::table('madadgar_budget_policies')->insert(['company_id'=>$company->id,'model'=>MadadgarUsage::MODEL,'currency'=>'USD','input_per_million'=>'1.000000','output_per_million'=>'1.000000','monthly_budget'=>'0.001000','configured_by'=>1,'created_at'=>now(),'updated_at'=>now()]);
$barrier=dirname($socket).'/ai-budget-'.bin2hex(random_bytes(8));$workers=[];
try{
 for($i=0;$i<2;$i++){$p=new Process([PHP_BINARY,__FILE__,'worker',(string)$company->id,$barrier,(string)$i]);$p->setTimeout(30);$p->start();$workers[]=$p;}
 $until=microtime(true)+20;for($i=0;$i<2;$i++)while(!is_file($barrier.'.ready'.$i)){if(microtime(true)>$until)throw new RuntimeException('Budget startup timeout');usleep(10000);}file_put_contents($barrier,'go');
 $results=[];foreach($workers as$p){$p->wait();budgetProof($p->isSuccessful(),'Budget worker process failure');$results[]=trim($p->getOutput());}sort($results);budgetProof($results===['200','429'],'Concurrent budget overspent');
 budgetProof(DB::table('madadgar_usage_events')->where('company_id',$company->id)->where('state','reserved')->count()===1,'Budget created duplicate allowance');
 $id=DB::table('madadgar_usage_events')->where('company_id',$company->id)->where('state','reserved')->value('id');MadadgarUsage::finish($id,20,null,'provider_transport_error');
 budgetProof((string)DB::table('madadgar_usage_events')->find($id)->reserved_cost==='0.000700','Unknown request lost reserved cost');
 budgetProof(DB::table('madadgar_usage_events')->find($id)->estimated_cost===null,'Unknown cost invented');
 Http::assertNothingSent();echo "PASS: native AI budget current-read concurrent cap unknown-cost retention and no provider calls\n";
}finally{foreach($workers as$p)if($p->isRunning())$p->stop();foreach(glob($barrier.'*')?:[]as$f)unlink($f);}

<?php
declare(strict_types=1);
$db=getenv('DB_DATABASE')?:'';$socket=getenv('DB_SOCKET')?:'';
if(getenv('APP_ENV')!=='rc-mariadb'||!getenv('LD_PRELOAD')||!preg_match('/^taxnest_rc_[a-z0-9_]+_full$/D',$db)||!preg_match('#^/tmp/taxnest-rc-mariadb-[a-z0-9_]+/run/mariadb.sock$#D',$socket)){fwrite(STDERR,"FAIL unsafe pilot fiscal control target\n");exit(2);}
require dirname(__DIR__,2).'/vendor/autoload.php';$app=require dirname(__DIR__,2).'/bootstrap/app.php';$app->make(\Illuminate\Contracts\Console\Kernel::class)->bootstrap();
use App\Models\{AdminUser,AdminAuditLog,Company};
use App\Services\PilotFiscalControl;
use App\Http\Controllers\SaasAdmin\PilotFiscalControlController;
use Illuminate\Support\Facades\{DB,Http};
use Illuminate\Http\Request;
use Symfony\Component\Process\Process;
Http::preventStrayRequests();
function pilotAssert(bool $ok,string $message):void{if(!$ok)throw new RuntimeException($message);}
function pilotUpdate(int $company,bool $on):array{
 $request=Request::create('/admin/companies/'.$company.'/pilot-fiscal-control','POST',['enabled'=>$on]);$request->headers->set('Accept','application/json');
 $response=app(PilotFiscalControlController::class)->update($request,$company);
 return ['status'=>$response->getStatusCode(),'state'=>$response->getData(true)];
}
if(($argv[1]??'')==='worker'){
 [$actor,$company,$on,$barrier,$index]=array_slice($argv,2);pilotAssert(str_starts_with($barrier,dirname($socket).'/pilot-fiscal-')&&ctype_digit($index),'Unsafe pilot barrier');
 try{auth('admin')->setUser(AdminUser::findOrFail((int)$actor));DB::beginTransaction();DB::table('companies')->where('id',(int)$company)->first();
 file_put_contents($barrier.'.ready-'.$index,'ready');$until=microtime(true)+20;while(!is_file($barrier)){if(microtime(true)>$until)throw new RuntimeException('Pilot barrier timeout');usleep(10000);}
 $result=pilotUpdate((int)$company,$on==='1');DB::commit();Http::assertNothingSent();echo json_encode($result,JSON_THROW_ON_ERROR);
 }catch(Throwable $e){if(DB::transactionLevel())DB::rollBack();fwrite(STDERR,'Pilot worker failed '.get_class($e).': '.$e->getMessage());exit(1);}exit;
}
$suffix=bin2hex(random_bytes(8));$actors=[];
foreach([1,2]as$i)$actors[]=AdminUser::create(['name'=>'Synthetic pilot admin','email'=>$suffix.$i.'@pilot-native.invalid','password'=>bcrypt('SyntheticOnly123!'),'role'=>'super_admin']);
$foreign=Company::create(['name'=>'Synthetic unrelated retail','ntn'=>'PFR'.$suffix,'product_type'=>'fbrpos','pos_type'=>'retail','fbr_reporting_enabled'=>true]);$foreignBefore=(array)DB::table('companies')->find($foreign->id);
foreach(['education','hospital']as$category){
 $c=Company::create(['name'=>'Synthetic '.$category,'ntn'=>$category.$suffix,'product_type'=>'fbrpos','pos_type'=>$category,'business_category'=>$category,'fbr_reporting_enabled'=>true]);
 $invoice=DB::table('fbr_pos_transactions')->insertGetId(['company_id'=>$c->id,'invoice_number'=>'SYN-ACK-'.$category.$suffix,'transaction_type'=>'sale','status'=>'completed','fbr_status'=>'success','fbr_invoice_number'=>'SYN-ACK-'.$suffix,'created_at'=>now(),'updated_at'=>now()]);$history=(array)DB::table('fbr_pos_transactions')->find($invoice);
 $barrier=dirname($socket).'/pilot-fiscal-'.bin2hex(random_bytes(8));$workers=[];
 try{foreach([0,1]as$i){$p=new Process([PHP_BINARY,__FILE__,'worker',(string)$actors[$i]->id,(string)$c->id,(string)$i,$barrier,(string)$i]);$p->setTimeout(35);$p->start();$workers[]=$p;}
 $until=microtime(true)+20;foreach([0,1]as$i)while(!is_file($barrier.'.ready-'.$i)){if(microtime(true)>$until)throw new RuntimeException('Pilot startup timeout');usleep(10000);}file_put_contents($barrier,'go');
 $results=[];foreach($workers as$p){$p->wait();pilotAssert($p->isSuccessful(),'Pilot process failure: '.$p->getErrorOutput());$results[]=json_decode($p->getOutput(),true,512,JSON_THROW_ON_ERROR);}
 pilotAssert(array_column($results,'status')===[200,422],'OFF/ON response contract changed');foreach($results as$r)pilotAssert($r['state']['enabled']===false&&$r['state']['reason']==='category_route_unverified','ON armed or unverified flag effective');
 }finally{foreach($workers as$p)if($p->isRunning())$p->stop();foreach(glob($barrier.'*')?:[]as$f)unlink($f);}
 pilotAssert((int)DB::table('companies')->where('id',$c->id)->value('fbr_reporting_enabled')===0,'Concurrent OFF not saved');
 pilotAssert($history===(array)DB::table('fbr_pos_transactions')->find($invoice),'Existing acknowledgement mutated');
 pilotAssert(AdminAuditLog::where('target_type','Company')->where('target_id',$c->id)->whereIn('action',['Pilot fiscal ON rejected','Pilot fiscal OFF confirmed'])->count()===2,'Concurrent control audit not exact');
 // Audit failure must not commit even the raw flag change.
 DB::table('companies')->where('id',$c->id)->update(['fbr_reporting_enabled'=>true]);auth('admin')->setUser($actors[0]);
 AdminAuditLog::creating(fn()=>throw new RuntimeException('synthetic audit failure'));
 try{PilotFiscalControl::update($c->id,false);throw new RuntimeException('Audit failure swallowed');}catch(RuntimeException $e){pilotAssert($e->getMessage()==='synthetic audit failure','Unexpected audit failure');}finally{AdminAuditLog::flushEventListeners();}
 pilotAssert((int)DB::table('companies')->where('id',$c->id)->value('fbr_reporting_enabled')===1,'Audit rollback lost raw state');
 pilotAssert(PilotFiscalControl::read($c->id)['state']['enabled']===false,'Stale raw ON enabled pilot');
 DB::table('admin_users')->where('id',$actors[0]->id)->update(['role'=>'admin']);
 try{PilotFiscalControl::update($c->id,false);throw new RuntimeException('Stale privileged actor accepted');}catch(\Symfony\Component\HttpKernel\Exception\HttpException $e){pilotAssert($e->getStatusCode()===403,'Wrong role denial');}
 DB::table('admin_users')->where('id',$actors[0]->id)->update(['role'=>'super_admin']);
 pilotAssert($history===(array)DB::table('fbr_pos_transactions')->find($invoice),'Rejected operations changed acknowledgement');
}
pilotAssert($foreignBefore===(array)DB::table('companies')->find($foreign->id),'Foreign retail company changed');Http::assertNothingSent();
echo "PASS: native School Hospital concurrent OFF and rejected ON remain disabled with exact audit current actor denial atomic rollback and unchanged acknowledgements foreign tenant no provider calls\n";

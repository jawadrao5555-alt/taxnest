<?php
declare(strict_types=1);
$db=getenv('DB_DATABASE')?:'';$socket=getenv('DB_SOCKET')?:'';
if(!preg_match('/^taxnest_rc_[a-z0-9_]+_full$/D',$db)||!preg_match('#^/tmp/taxnest-rc-mariadb-[a-z0-9_]+/run/mariadb.sock$#D',$socket)||getenv('APP_ENV')!=='rc-mariadb'||!getenv('LD_PRELOAD')){fwrite(STDERR,"FAIL unsafe escalation target\n");exit(2);}
require dirname(__DIR__,2).'/vendor/autoload.php';$app=require dirname(__DIR__,2).'/bootstrap/app.php';$app->make(\Illuminate\Contracts\Console\Kernel::class)->bootstrap();
use App\Models\{Company,User,SystemSetting};use App\Http\Controllers\MadadgarController;use Illuminate\Http\Request;use Illuminate\Support\Facades\{DB,Http};use Symfony\Component\Process\Process;
Http::preventStrayRequests();
function escalationProof(bool $ok,string $message):void{if(!$ok)throw new RuntimeException($message);}
function confirmSynthetic(string $id):array {
 $r=(new MadadgarController)->escalate(Request::create('/pos/madadgar/escalate','POST',['session_id'=>'11111111-1111-4111-8111-111111111111','request_id'=>$id,'title'=>'Synthetic retry','summary'=>'No actual customer','kind'=>'problem']));return ['status'=>$r->status(),'body'=>$r->getData(true)];
}
if(($argv[1]??'')==='worker'){
 [$userId,$barrier,$index,$requestId]=array_slice($argv,2);escalationProof(str_starts_with($barrier,dirname($socket).'/escalation-')&&ctype_digit($index),'Unsafe barrier');
 try{auth('pos')->setUser(User::findOrFail((int)$userId));file_put_contents($barrier.'.ready'.$index,'ready');$until=microtime(true)+20;while(!is_file($barrier)){if(microtime(true)>$until)throw new RuntimeException('Barrier timeout');usleep(10000);}echo json_encode(confirmSynthetic($requestId),JSON_THROW_ON_ERROR);
 }catch(Throwable $e){fwrite(STDERR,'Escalation worker failure '.get_class($e).': '.$e->getMessage().PHP_EOL);exit(1);}exit;
}
SystemSetting::set('madadgar_enabled','1');SystemSetting::set('madadgar_mode','local');
$c=Company::create(['name'=>'Synthetic escalation','ntn'=>'ESC-'.bin2hex(random_bytes(5)),'product_type'=>'pos']);
$u=User::create(['name'=>'Synthetic cashier','email'=>'escalation-'.bin2hex(random_bytes(5)).'@example.invalid','password'=>bcrypt('synthetic-not-login'),'company_id'=>$c->id,'role'=>'user','pos_role'=>'pos_cashier','is_active'=>true]);auth('pos')->setUser($u);
function raceSynthetic(int $uid,array $ids,string $socket):array {
 $barrier=dirname($socket).'/escalation-'.bin2hex(random_bytes(8));$workers=[];
 try{foreach($ids as$i=>$id){$p=new Process([PHP_BINARY,__FILE__,'worker',(string)$uid,$barrier,(string)$i,$id]);$p->setTimeout(30);$p->start();$workers[]=$p;}
 $until=microtime(true)+20;foreach($ids as$i=>$id)while(!is_file($barrier.'.ready'.$i)){if(microtime(true)>$until)throw new RuntimeException('Startup timeout');usleep(10000);}file_put_contents($barrier,'go');$results=[];
 foreach($workers as$p){$p->wait();escalationProof($p->isSuccessful(),'Escalation process failed: '.$p->getErrorOutput());$results[]=json_decode($p->getOutput(),true,512,JSON_THROW_ON_ERROR);}return $results;
 }finally{foreach($workers as$p)if($p->isRunning())$p->stop();foreach(glob($barrier.'*')?:[]as$f)unlink($f);}
}
$id='22222222-2222-4222-8222-222222222222';$results=raceSynthetic($u->id,[$id,$id],$socket);
escalationProof($results[0]['status']===200&&$results[0]===$results[1],'Concurrent same ID duplicated reference');
escalationProof(DB::table('feature_suggestions')->where('user_id',$u->id)->count()===1,'Duplicate suggestion');
escalationProof(DB::table('madadgar_messages')->where('user_id',$u->id)->count()===1,'Duplicate confirmation');
for($i=0;$i<3;$i++)confirmSynthetic((string)\Illuminate\Support\Str::uuid());
$last=raceSynthetic($u->id,[(string)\Illuminate\Support\Str::uuid(),(string)\Illuminate\Support\Str::uuid()],$socket);$statuses=array_column($last,'status');sort($statuses);
escalationProof($statuses===[200,429],'Concurrent cap exceeded');
escalationProof(DB::table('feature_suggestions')->where('user_id',$u->id)->count()===5,'Cap writes mismatch');
escalationProof(confirmSynthetic($id)===$results[0],'Original ref lost at cap');Http::assertNothingSent();
echo "PASS: native escalation same-ID atomic retry, one confirmation, daily cap race, original reference recovered, no external sends\n";

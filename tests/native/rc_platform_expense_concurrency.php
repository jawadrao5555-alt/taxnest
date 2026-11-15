<?php
declare(strict_types=1);
$db=getenv('DB_DATABASE')?:'';$socket=getenv('DB_SOCKET')?:'';
if(getenv('APP_ENV')!=='rc-mariadb'||!getenv('LD_PRELOAD')||!preg_match('/^taxnest_rc_[a-z0-9_]+_full$/D',$db)||!preg_match('#^/tmp/taxnest-rc-mariadb-[a-z0-9_]+/run/mariadb.sock$#D',$socket)){fwrite(STDERR,"FAIL unsafe platform expense target\n");exit(2);}
require dirname(__DIR__,2).'/vendor/autoload.php';$app=require dirname(__DIR__,2).'/bootstrap/app.php';$app->make(\Illuminate\Contracts\Console\Kernel::class)->bootstrap();
use App\Models\{AdminUser,AdminAuditLog,User,Company};
use App\Services\PlatformExpenseLedger;
use Illuminate\Support\Facades\{DB,Http};
use Illuminate\Support\Str;
use Symfony\Component\Process\Process;
use Symfony\Component\HttpKernel\Exception\HttpException;
Http::preventStrayRequests();
function expenseProof(bool $ok,string $message):void{if(!$ok)throw new RuntimeException($message);}
function payload(string $key,?int $reverse=null):array {
 $p=['request_id'=>$key,'entry_date'=>'2026-11-15','reference'=>'SYNTHETIC-RECORD-ONLY','notes'=>'Fictional native acceptance; no payment'];
 return $reverse===null?$p+['category'=>'software','amount'=>'123.45']:$p;
}
function attemptExpense(array $p,?int $reverse=null):array {
 try{$row=PlatformExpenseLedger::record($p,$reverse);return ['status'=>200,'id'=>(int)$row->id];}
 catch(HttpException $e){return ['status'=>$e->getStatusCode()];}
}
if(($argv[1]??'')==='worker'){
 [$adminId,$key,$reverseId,$barrier,$index]=array_slice($argv,2);
 expenseProof(str_starts_with($barrier,dirname($socket).'/platform-expense-')&&ctype_digit($index),'Unsafe expense barrier');
 try{auth('admin')->setUser(AdminUser::findOrFail((int)$adminId));file_put_contents($barrier.'.ready-'.$index,'ready');$until=microtime(true)+20;
 while(!is_file($barrier)){if(microtime(true)>$until)throw new RuntimeException('Expense barrier timeout');usleep(10000);}
 $reverse=(int)$reverseId?:null;echo json_encode(attemptExpense(payload($key,$reverse),$reverse),JSON_THROW_ON_ERROR);
 }catch(Throwable $e){fwrite(STDERR,'Expense worker failure '.get_class($e).': '.$e->getMessage().PHP_EOL);exit(1);}exit;
}
function expenseRace(array $jobs,string $socket):array {
 $barrier=dirname($socket).'/platform-expense-'.bin2hex(random_bytes(8));$workers=[];
 try{foreach($jobs as$i=>[$admin,$key,$reverse]){$p=new Process([PHP_BINARY,__FILE__,'worker',(string)$admin,$key,(string)($reverse??0),$barrier,(string)$i]);$p->setTimeout(35);$p->start();$workers[]=$p;}
 $until=microtime(true)+20;foreach($jobs as$i=>$unused)while(!is_file($barrier.'.ready-'.$i)){if(microtime(true)>$until)throw new RuntimeException('Expense worker startup timeout');usleep(10000);}file_put_contents($barrier,'go');
 $results=[];foreach($workers as$p){$p->wait();expenseProof($p->isSuccessful(),'Expense process failed: '.$p->getErrorOutput());$results[]=json_decode($p->getOutput(),true,512,JSON_THROW_ON_ERROR);}return $results;
 }finally{foreach($workers as$p)if($p->isRunning())$p->stop();foreach(glob($barrier.'*')?:[]as$f)unlink($f);}
}
$suffix=bin2hex(random_bytes(8));$admins=[];
foreach([1,2]as$i)$admins[]=AdminUser::create(['name'=>'Synthetic expense reviewer '.$i,'email'=>$suffix.'-'.$i.'@expense.invalid','password'=>bcrypt('SyntheticOnly123!'),'role'=>'super_admin']);
[$admin,$second]=$admins;$key=(string)Str::uuid();
$first=expenseRace([[$admin->id,$key,null],[$admin->id,$key,null]],$socket);
expenseProof($first[0]['status']===200&&$first[0]===$first[1],'Duplicate expense request did not recover one identity');
$id=$first[0]['id'];$original=(array)DB::table('platform_expense_entries')->find($id);
expenseProof((int)$original['amount_paisa']===12345&&$original['kind']==='expense','Expense amount/kind changed');
auth('admin')->setUser($admin);
foreach(['amount'=>'124.45','reference'=>'CHANGED','category'=>'hosting']as$field=>$value)expenseProof(attemptExpense(array_replace(payload($key),[$field=>$value]))['status']===409,'Changed expense data not rejected');
$reverseKey=(string)Str::uuid();$reversed=expenseRace([[$admin->id,$reverseKey,$id],[$admin->id,$reverseKey,$id]],$socket);
expenseProof($reversed[0]['status']===200&&$reversed[0]===$reversed[1],'Duplicate reversal did not recover one offset');
expenseProof(attemptExpense(payload((string)Str::uuid(),$id),$id)['status']===409,'Second full reversal permitted');
expenseProof(attemptExpense(array_replace(payload($reverseKey,$id),['notes'=>'Changed reversal']),$id)['status']===409,'Changed reversal data accepted');
expenseProof((array)DB::table('platform_expense_entries')->find($id)===$original,'Original expense mutated');
$offset=DB::table('platform_expense_entries')->where('reverses_id',$id)->sole();
expenseProof($offset->kind==='reversal'&&(int)$offset->amount_paisa===12345&&$offset->category===$original['category'],'Offset does not exactly reverse original');
expenseProof(AdminAuditLog::where('target_type','PlatformExpenseEntry')->whereIn('target_id',[$id,$offset->id])->count()===2,'Expense/reversal audit duplicated or missing');
// A different admin must not race a second full reversal past a stale snapshot.
$other=PlatformExpenseLedger::record(payload((string)Str::uuid()));$before=(array)$other;
$competing=expenseRace([[$admin->id,(string)Str::uuid(),$other->id],[$second->id,(string)Str::uuid(),$other->id]],$socket);
$statuses=array_column($competing,'status');sort($statuses);expenseProof($statuses===[200,409],'Different-admin full reversal race failed');
expenseProof(DB::table('platform_expense_entries')->where('reverses_id',$other->id)->count()===1,'More than one full offset');
expenseProof((array)DB::table('platform_expense_entries')->find($other->id)===$before,'Competing reversal changed original');
// Fresh authority: an old authenticated object is not an authorization grant.
DB::table('admin_users')->where('id',$admin->id)->update(['role'=>'support']);
expenseProof(attemptExpense(payload($key))['status']===403,'Demoted admin could recover/write expense');
auth('admin')->forgetUser();
$company=Company::create(['name'=>'Synthetic foreign expense guard','ntn'=>'EX-'.$suffix,'product_type'=>'pos']);
$user=User::create(['name'=>'Synthetic tenant owner','email'=>$suffix.'@expense-tenant.invalid','password'=>bcrypt('SyntheticOnly123!'),'company_id'=>$company->id,'role'=>'company_admin','pos_role'=>'pos_admin','is_active'=>true]);
foreach(['web','pos','fbrpos']as$guard)auth($guard)->setUser($user);
expenseProof(attemptExpense(payload((string)Str::uuid()))['status']===403,'Tenant guard gained platform expense authority');
expenseProof(DB::table('platform_expense_entries')->whereIn('admin_id',[$admin->id,$second->id])->count()===4,'Rejected requests changed ledger');
Http::assertNothingSent();
echo "PASS: native platform expense duplicate request and reversal preserve one immutable original and offset with audit; changed data, current demotion and tenant guards denied; competing admin reversal bounded; no payment executed\n";

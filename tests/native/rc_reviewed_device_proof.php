<?php
declare(strict_types=1);
$db=getenv('DB_DATABASE')?:''; $socket=getenv('DB_SOCKET')?:'';
if(!preg_match('/^taxnest_rc_[a-z0-9_]+_full$/D',$db)||!preg_match('#^/tmp/taxnest-rc-mariadb-[a-z0-9_]+/run/mariadb.sock$#D',$socket)||getenv('APP_ENV')!=='rc-mariadb'||!getenv('LD_PRELOAD')){fwrite(STDERR,"FAIL unsafe reviewed device proof target\n");exit(2);}
require dirname(__DIR__,2).'/vendor/autoload.php';$app=require dirname(__DIR__,2).'/bootstrap/app.php';$app->make(\Illuminate\Contracts\Console\Kernel::class)->bootstrap();
use App\Models\{AdminUser,Company};use App\Services\ReviewedDeviceProof;use Illuminate\Support\Facades\{DB,Http};use Symfony\Component\Process\Process;
Http::preventStrayRequests();
function proof(bool $ok,string $why):void{if(!$ok)throw new RuntimeException($why);}
function fiscalCallback(int $company,int $bill,string $claim): array {
 $r=\Illuminate\Http\Request::create('/api/agent/submit-result','POST',['transaction_id'=>$bill,'success'=>false,'outcome_unknown'=>true,'device_uid'=>'Device-A','submission_claim'=>$claim,'pra_invoice_number'=>'654321ABC20260001','response'=>['FBRInvoiceNumber'=>'654321ABC20260001'],'ims_diagnostic'=>'response_received','ims_response_fields'=>['FBRInvoiceNumber:string']]);
 $r->attributes->set('agent_company',Company::findOrFail($company));
 return app(\App\Http\Controllers\AgentController::class)->submitResult($r)->getData(true);
}
if(($argv[1]??'')==='callback-worker') {
 [$company,$bill,$claim,$barrier,$index]=array_slice($argv,2);
 proof(str_starts_with($barrier,dirname($socket).'/proof-')&&ctype_digit($index),'Unsafe callback barrier');
 file_put_contents($barrier.'.ready'.$index,'ready');$until=microtime(true)+20;
 while(!is_file($barrier)){if(microtime(true)>$until)throw new RuntimeException('Callback barrier timeout');usleep(10000);}
 proof((fiscalCallback((int)$company,(int)$bill,$claim)['ok']??false)===true,'Callback not acknowledged');echo '200';exit;
}
if(($argv[1]??'')==='worker'){
    [$company,$actor,$id,$barrier,$index]=array_slice($argv,2);
    proof(str_starts_with($barrier,dirname($socket).'/proof-')&&ctype_digit($index),'Unsafe worker barrier');
    auth('admin')->setUser(AdminUser::findOrFail($actor));file_put_contents($barrier.'.ready'.$index,'ready');$until=microtime(true)+20;
    while(!is_file($barrier)){if(microtime(true)>$until)throw new RuntimeException('Proof worker barrier timeout');usleep(10000);}
    try{ReviewedDeviceProof::revoke((int)$company,(int)$id,'Synthetic concurrent revocation');echo '200';}
    catch(\Symfony\Component\HttpKernel\Exception\HttpExceptionInterface $e){echo $e->getStatusCode();}exit;
}
$company=Company::create(['name'=>'Synthetic proof native','ntn'=>bin2hex(random_bytes(6)),'product_type'=>'pos','status'=>'approved','company_status'=>'active','pra_reporting_enabled'=>false]);
$admin=AdminUser::create(['name'=>'Synthetic proof reviewer','email'=>bin2hex(random_bytes(8)).'@proof.invalid','password'=>bcrypt('SyntheticOnly123!'),'role'=>'super_admin']);auth('admin')->setUser($admin);
$data=['device_uid'=>'Device-A','pos_id'=>'POS-A','environment'=>'sandbox','evidence_reference'=>'Synthetic independently reviewed fixture only','evidence_sha256'=>str_repeat('a',64),'review_note'=>'Synthetic evidence does not establish device or central acceptance','independent_evidence_reviewed'=>true];
proof(ReviewedDeviceProof::reviewedProof($company->id,'Device-A','POS-A','sandbox')===null,'Missing proof did not HOLD');
$id=ReviewedDeviceProof::approve($company->id,$data);$original=(array)DB::table('reviewed_device_proofs')->find($id);
foreach([['device-a','POS-A','sandbox'],['Device-A','pos-a','sandbox'],['Device-A','POS-A','production'],['Device-B','POS-A','sandbox']] as $scope)proof(ReviewedDeviceProof::reviewedProof($company->id,...$scope)===null,'Native collation/scope alias accepted');
proof((int)ReviewedDeviceProof::reviewedProof($company->id,'Device-A','POS-A','sandbox')->id===$id,'Exact native scope failed');
$barrier=dirname($socket).'/proof-'.bin2hex(random_bytes(8));$workers=[];
try{
    foreach([0,1] as $i){$p=new Process([PHP_BINARY,__FILE__,'worker',(string)$company->id,(string)$admin->id,(string)$id,$barrier,(string)$i]);$p->setTimeout(30);$p->start();$workers[]=$p;}
    $until=microtime(true)+20;while(!is_file($barrier.'.ready0')||!is_file($barrier.'.ready1')){if(microtime(true)>$until)throw new RuntimeException('Proof worker startup timeout');usleep(10000);}file_put_contents($barrier,'go');
    $statuses=[];foreach($workers as $p){$p->wait();proof($p->isSuccessful(),'Native proof worker failed: '.$p->getErrorOutput());$statuses[]=trim($p->getOutput());}sort($statuses);
    proof($statuses===['200','409'],'Concurrent revocation did not serialize');
    proof(ReviewedDeviceProof::reviewedProof($company->id,'Device-A','POS-A','sandbox')===null,'Revoked proof still active');
    proof((array)DB::table('reviewed_device_proofs')->find($id)===$original,'Original review mutated');
    proof(DB::table('reviewed_device_proofs')->where('revokes_id',$id)->count()===1,'Duplicate revocation event');
    DB::table('admin_users')->where('id',$admin->id)->update(['role'=>'support']);
    try{ReviewedDeviceProof::approve($company->id,$data);throw new RuntimeException('Cached superadmin authority accepted');}catch(\Symfony\Component\HttpKernel\Exception\HttpExceptionInterface $e){proof($e->getStatusCode()===403,'Wrong stale authority response');}
    echo "PASS: native reviewed proof exact collation scope default HOLD stale authority and concurrent append-only revocation\n";
}finally{foreach($workers as $p)if($p->isRunning())$p->stop();foreach(glob($barrier.'*')?:[] as $file)unlink($file);}

DB::table('admin_users')->where('id',$admin->id)->update(['role'=>'super_admin']);
$company->forceFill(['product_type'=>'fbrpos','fbr_pos_enabled'=>true,'fbr_reporting_enabled'=>true,'agent_enabled'=>true,'fbr_connection_mode'=>'fiscal_device','fbr_pos_environment'=>'production','fbr_pos_id'=>'654321'])->save();
ReviewedDeviceProof::approve($company->id,array_merge($data,['pos_id'=>'654321','environment'=>'production']));
$claim=bin2hex(random_bytes(32));
$billId=DB::table('fbr_pos_transactions')->insertGetId(['company_id'=>$company->id,'invoice_number'=>'SYNTHETIC-ACK-'.bin2hex(random_bytes(4)),'invoice_mode'=>'fbr','fbr_status'=>'pending','fbr_pos_id_snapshot'=>'654321','fbr_device_uid_snapshot'=>'Device-A','fbr_requested_environment_snapshot'=>'production','fbr_identity_captured_at'=>now(),'fbr_agent_claimed_at'=>now(),'fbr_submission_hash'=>$claim,'total_amount'=>100,'created_at'=>now(),'updated_at'=>now()]);
$bill=\App\Models\FbrPosTransaction::findOrFail($billId);
app(\App\Services\FbrPosSubmissionEvidenceService::class)->recordDispatch($company,$bill,'synthetic','Device-A',$claim);
$barrier=dirname($socket).'/proof-callback-'.bin2hex(random_bytes(8));$workers=[];
try {
 foreach([0,1] as $i){$p=new Process([PHP_BINARY,__FILE__,'callback-worker',(string)$company->id,(string)$billId,$claim,$barrier,(string)$i]);$p->setTimeout(30);$p->start();$workers[]=$p;}
 $until=microtime(true)+20;while(!is_file($barrier.'.ready0')||!is_file($barrier.'.ready1')){if(microtime(true)>$until)throw new RuntimeException('Callback startup timeout');usleep(10000);}file_put_contents($barrier,'go');
 foreach($workers as $p){$p->wait();proof($p->isSuccessful()&&trim($p->getOutput())==='200','Native callback race failed: '.$p->getErrorOutput());}
 $bill->refresh();proof($bill->fbr_invoice_number==='654321ABC20260001'&&$bill->fbr_response_code==='NUMBER_ONLY_REVIEWED','Duplicate callback changed acceptance');
 $evidence=DB::table('fbr_pos_submission_evidence')->where('transaction_id',$billId)->first();proof($evidence->response_code===null&&$evidence->central_verification_state==='unknown','Invented Code100 or central verification');
 $heldId=DB::table('fbr_pos_transactions')->insertGetId(['company_id'=>$company->id,'invoice_number'=>'SYNTHETIC-FROZEN-'.bin2hex(random_bytes(4)),'invoice_mode'=>'fbr','fbr_status'=>'pending','fbr_requested_environment_snapshot'=>'sandbox','total_amount'=>100,'created_at'=>now(),'updated_at'=>now()]);
 $held=\App\Models\FbrPosTransaction::findOrFail($heldId);$result=app(\App\Services\FbrService::class)->submitFbrPosTransaction($held);
 proof($result['status']==='blocked'&&$held->fresh()->fbr_status==='verification_pending'&&$held->fresh()->fbr_submission_hash===null,'Frozen environment changed or dispatched');
 echo "PASS: native standalone duplicate reviewed callbacks preserve one fiscal identity and frozen environment refuses dispatch
";
} finally {foreach($workers as $p)if($p->isRunning())$p->stop();foreach(glob($barrier.'*')?:[] as $file)unlink($file);}

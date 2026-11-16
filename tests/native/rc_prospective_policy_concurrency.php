<?php
declare(strict_types=1);
$db=getenv('DB_DATABASE')?:'';$socket=getenv('DB_SOCKET')?:'';
if(getenv('APP_ENV')!=='rc-mariadb'||!getenv('LD_PRELOAD')||!preg_match('/^taxnest_rc_[a-z0-9_]+_full$/D',$db)||!preg_match('#^/tmp/taxnest-rc-mariadb-[a-z0-9_]+/run/mariadb.sock$#D',$socket)){fwrite(STDERR,"FAIL unsafe prospective policy target\n");exit(2);}
require dirname(__DIR__,2).'/vendor/autoload.php';$app=require dirname(__DIR__,2).'/bootstrap/app.php';$app->make(\Illuminate\Contracts\Console\Kernel::class)->bootstrap();
use App\Models\{AdminUser,AdminAuditLog,Company,User,PaymentProof,PricingPlan,Subscription,SubscriptionInvoice};
use App\Services\{TrialSubscriptionService,SubscriptionAssignmentService,SubscriptionAccessService,SubscriptionBillingHistory,ProspectiveSubscriptionPolicy as Policy};
use App\Http\Controllers\SaasAdmin\AdminPaymentProofController;
use App\Http\Controllers\SubscriptionDocumentController;
use Carbon\{Carbon,CarbonImmutable};
use Illuminate\Support\Facades\{DB,Http,Mail,Notification};
use Illuminate\Http\Request;
use Symfony\Component\Process\Process;
Http::preventStrayRequests();Mail::fake();Notification::fake();
function policyProof(bool $ok,string $reason):void{if(!$ok)throw new RuntimeException($reason);}
function policyClock(string $stamp):void{Carbon::setTestNow(Carbon::parse($stamp));CarbonImmutable::setTestNow(CarbonImmutable::parse($stamp));}
function policyApproval(int $proof,int $plan,int $admin,float $amount):void {
 auth('admin')->setUser(AdminUser::findOrFail($admin));
 $request=Request::create('/admin/payment-proofs/'.$proof.'/approve','POST',['pricing_plan_id'=>$plan,'billing_cycle'=>'annual','verified_received_amount'=>$amount]);
 $request->setLaravelSession(app('session')->driver());app()->instance('request',$request);
 $response=app(AdminPaymentProofController::class)->approve($request,$proof);
 policyProof($response->getStatusCode()===302,'Unexpected policy approval response');
}
if(($argv[1]??'')==='worker'){
 [$proof,$plan,$admin,$amount,$stamp,$barrier,$index]=array_slice($argv,2);
 policyProof(str_starts_with($barrier,dirname($socket).'/prospective-')&&ctype_digit($index),'Unsafe policy barrier');
 policyClock($stamp);file_put_contents($barrier.'.ready-'.$index,'ready');$until=microtime(true)+20;
 while(!is_file($barrier)){if(microtime(true)>$until)throw new RuntimeException('Policy barrier timeout');usleep(10000);}
 policyApproval((int)$proof,(int)$plan,(int)$admin,(float)$amount);exit;
}
$stamp='2028-02-27T18:30:00+00:00';policyClock($stamp);$suffix=bin2hex(random_bytes(8));
$admin=AdminUser::create(['name'=>'Synthetic policy reviewer','email'=>$suffix.'@policy.invalid','password'=>bcrypt('SyntheticOnly123!'),'role'=>'super_admin']);
$plan=PricingPlan::create(['name'=>'Business','product_type'=>'di','price'=>1000,'is_trial'=>false,'invoice_limit'=>2,'user_limit'=>3,'branch_limit'=>1,'inventory_enabled'=>false,'reports_enabled'=>false,'ai_page_limit'=>4]);
$company=Company::create(['name'=>'Synthetic prospective policy','ntn'=>'POL-'.$suffix,'product_type'=>'di','status'=>'approved','company_status'=>'active']);
$trial=TrialSubscriptionService::ensureSelectedTrial($company->id,'di',$plan->id);$trialBefore=$trial->fresh()->getAttributes();$trialTerms=Policy::display($trial);
policyProof((int)CarbonImmutable::parse($trialTerms['trial_starts_at'])->diffInHours(CarbonImmutable::parse($trialTerms['trial_ends_at']))===72,'Trial is not exact72hours');
$foreign=Company::create(['name'=>'Synthetic legacy policy','ntn'=>'LEG-'.$suffix,'product_type'=>'di','status'=>'approved','company_status'=>'active']);
$legacy=Subscription::create(['company_id'=>$foreign->id,'pricing_plan_id'=>$plan->id,'active'=>true,'billing_cycle'=>'annual','start_date'=>'2028-01-01','end_date'=>'2029-01-01','final_price'=>1000]);$legacyBefore=$legacy->fresh()->getAttributes();
$amount=(float)SubscriptionAssignmentService::computePrice($plan,'annual',$company)['final_price'];
$proof=PaymentProof::create(['company_id'=>$company->id,'pricing_plan_id'=>$plan->id,'billing_cycle'=>'annual','amount'=>$amount,'proof_path'=>'synthetic-policy','status'=>'pending']);
// Actual review controller partial verification must leave every purchase row untouched.
try{policyApproval($proof->id,$plan->id,$admin->id,$amount-1);throw new RuntimeException('Partial review was accepted');}
catch(\Illuminate\Validation\ValidationException $e){policyProof(isset($e->errors()['verified_received_amount']),'Wrong partial validation');}
policyProof($trial->fresh()->getAttributes()===$trialBefore&&$proof->fresh()->status==='pending','Partial review mutated trial/proof');
policyProof(SubscriptionInvoice::where('payment_proof_id',$proof->id)->count()===0,'Partial review made payment document');
$barrier=dirname($socket).'/prospective-'.$suffix;$workers=[];
try{
 for($i=0;$i<2;$i++){$p=new Process([PHP_BINARY,__FILE__,'worker',(string)$proof->id,(string)$plan->id,(string)$admin->id,(string)$amount,$stamp,$barrier,(string)$i]);$p->setTimeout(45);$p->start();$workers[]=$p;}
 $until=microtime(true)+20;while(count(glob($barrier.'.ready-*'))!==2){if(microtime(true)>$until)throw new RuntimeException('Policy worker startup timeout');usleep(10000);}file_put_contents($barrier,'go');
 foreach($workers as$p){$p->wait();policyProof($p->isSuccessful(),'Policy worker failed: '.$p->getErrorOutput());}
 $paid=Subscription::where('company_id',$company->id)->where('active',true)->sole();$terms=Policy::display($paid);
 policyProof($terms['paid_starts_at']===$trialTerms['trial_ends_at']&&$terms['phase']==='trial','Early review shortened trial or started term early');
 policyProof(Subscription::where('company_id',$company->id)->count()===2,'Concurrent review duplicated term');
 policyProof($paid->entitlementPlan()->invoice_limit===-1&&!$paid->entitlementPlan()->inventory_enabled&&$paid->entitlementPlan()->user_limit===3,'Early payment changed original trial feature/caps');
 $document=SubscriptionInvoice::where('payment_proof_id',$proof->id)->sole();$receipt=$document->payments()->sole();$savedReceipt=$receipt->getAttributes();$savedInvoice=$document->getAttributes();$savedPaid=$paid->getAttributes();
 policyApproval($proof->id,$plan->id,$admin->id,$amount);
 policyProof($receipt->fresh()->getAttributes()===$savedReceipt&&$document->fresh()->getAttributes()===$savedInvoice&&$paid->fresh()->getAttributes()===$savedPaid,'Repeated reviewed proof changed retained term/documents');
 policyProof(AdminAuditLog::where('target_type','PaymentProof')->where('target_id',$proof->id)->where('action','Payment proof approved')->count()===1,'Reviewed proof duplicated audit');
 policyClock($terms['paid_starts_at']);policyProof(Policy::display($paid)['phase']==='paid'&&$paid->fresh()->entitlementPlan()->invoice_limit===2,'Paid boundary failed');
 policyClock($terms['paid_ends_at']);policyProof(Policy::display($paid)['phase']==='grace'&&SubscriptionAccessService::hasAccess($company)['allowed'],'Paidexpiry grace missing');
 policyProof((int)CarbonImmutable::parse($terms['paid_ends_at'])->diffInHours(CarbonImmutable::parse($terms['grace_ends_at']))===168,'Grace is not exact7days');
 policyClock($terms['grace_ends_at']);policyProof(!SubscriptionAccessService::hasAccess($company)['allowed'],'Newbilling allowed aftergrace');
 $owner=User::create(['name'=>'Synthetic expired owner','email'=>$suffix.'@policy-owner.invalid','password'=>bcrypt('SyntheticOnly123!'),'company_id'=>$company->id,'role'=>'company_admin','is_active'=>true]);auth('web')->setUser($owner);app()->instance('currentCompanyId',$company->id);
 $history=SubscriptionBillingHistory::forCompany($company->id);policyProof($history->contains('id',$document->id),'Aftergrace history disappeared');
 $request=Request::create('/billing/documents/receipt/'.$receipt->id,'GET');$pdf=app(SubscriptionDocumentController::class)->companyReceipt($request,$receipt->id);
 policyProof($pdf->getStatusCode()===200&&str_contains((string)$pdf->headers->get('Content-Type'),'application/pdf'),'Aftergrace receipt read denied');
 policyProof($receipt->fresh()->getAttributes()===$savedReceipt&&$document->fresh()->getAttributes()===$savedInvoice,'History/reprint changed retained documents');
 // A renewal inside paid-expiry grace starts now, not backdated to the old expiry.
 $renewAt=CarbonImmutable::parse($terms['paid_ends_at'])->addDays(3)->toIso8601String();policyClock($renewAt);
 $renewProof=PaymentProof::create(['company_id'=>$company->id,'pricing_plan_id'=>$plan->id,'billing_cycle'=>'annual','amount'=>$amount,'proof_path'=>'synthetic-policy-renewal','status'=>'pending']);
 policyApproval($renewProof->id,$plan->id,$admin->id,$amount);$renewed=Subscription::findOrFail($renewProof->fresh()->subscription_id);
 policyProof(Policy::display($renewed)['paid_starts_at']===$renewAt,'Grace renewal backdated paid term');
 policyProof($legacy->fresh()->getAttributes()===$legacyBefore&&Policy::display($legacy)===null,'Prospective policy changed legacy terms');
 DB::table('admin_users')->where('id',$admin->id)->update(['role'=>'support']);
 try{policyApproval($proof->id,$plan->id,$admin->id,$amount);throw new RuntimeException('Demoted reviewer accepted');}catch(\Symfony\Component\HttpKernel\Exception\HttpException $e){policyProof($e->getStatusCode()===403,'Demotion wrong denial');}
 Http::assertNothingSent();
 echo "PASS: native selected trial concurrent reviewed proof preserves exact72h and one paid term invoice receipt audit; partial rollback, retry, paid grace renewal, expired history reprint, current role and legacy retained; no payment executed\n";
}finally{foreach($workers as$p)if($p->isRunning())$p->stop();foreach(glob($barrier.'*')?:[]as$f)unlink($f);Carbon::setTestNow();CarbonImmutable::setTestNow();}

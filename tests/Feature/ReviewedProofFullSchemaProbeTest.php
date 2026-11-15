<?php
namespace Tests\Feature;
use Tests\TestCase;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\{DB,Http};
use App\Models\{Company,AdminUser,FbrPosTransaction};
use App\Services\{ReviewedDeviceProof,FbrPosSubmissionEvidenceService};
class ReviewedProofFullSchemaProbeTest extends TestCase {
 use RefreshDatabase;
 public function test_exact_native_fixture_callback_with_full_schema():void {
  Http::preventStrayRequests();
  $company=Company::create(['name'=>'Synthetic proof native','ntn'=>bin2hex(random_bytes(6)),'product_type'=>'pos','status'=>'approved','company_status'=>'active','pra_reporting_enabled'=>false]);
  $admin=AdminUser::create(['name'=>'Synthetic proof reviewer','email'=>bin2hex(random_bytes(8)).'@proof.invalid','password'=>bcrypt('SyntheticOnly123!'),'role'=>'super_admin']);auth('admin')->setUser($admin);
  DB::table('pos_agent_devices')->insert(['company_id'=>$company->id,'device_uid'=>'Device-A','created_at'=>now(),'updated_at'=>now()]);
  $company->forceFill(['product_type'=>'fbrpos','fbr_pos_enabled'=>true,'fbr_reporting_enabled'=>true,'agent_enabled'=>true,'fbr_connection_mode'=>'fiscal_device','fbr_pos_environment'=>'production','fbr_pos_id'=>'654321'])->save();
  ReviewedDeviceProof::approve($company->id,['device_uid'=>'Device-A','pos_id'=>'654321','environment'=>'production','evidence_reference'=>'Synthetic','evidence_sha256'=>str_repeat('a',64),'review_note'=>'Synthetic fixture review only','independent_evidence_reviewed'=>true]);
  $claim=bin2hex(random_bytes(32));$id=DB::table('fbr_pos_transactions')->insertGetId(['company_id'=>$company->id,'invoice_number'=>'SYNTHETIC-ACK-'.bin2hex(random_bytes(4)),'invoice_mode'=>'fbr','fbr_status'=>'pending','fbr_pos_id_snapshot'=>'654321','fbr_device_uid_snapshot'=>'Device-A','fbr_requested_environment_snapshot'=>'production','fbr_identity_captured_at'=>now(),'fbr_agent_claimed_at'=>now(),'fbr_submission_hash'=>$claim,'total_amount'=>100,'created_at'=>now(),'updated_at'=>now()]);
  $bill=FbrPosTransaction::findOrFail($id);app(FbrPosSubmissionEvidenceService::class)->recordDispatch($company,$bill,'synthetic','Device-A',$claim);
  $r=\Illuminate\Http\Request::create('/api/agent/submit-result','POST',['transaction_id'=>$id,'success'=>false,'outcome_unknown'=>true,'device_uid'=>'Device-A','submission_claim'=>$claim,'pra_invoice_number'=>'654321ABC20260001','response'=>['FBRInvoiceNumber'=>'654321ABC20260001'],'ims_diagnostic'=>'response_received','ims_response_fields'=>['FBRInvoiceNumber:string']]);
  $r->attributes->set('agent_company',Company::findOrFail($company->id));
  $response=app(\App\Http\Controllers\AgentController::class)->submitResult($r);
  $this->assertSame(200,$response->status());$this->assertTrue($response->getData(true)['ok']);$this->assertSame('NUMBER_ONLY_REVIEWED',$bill->fresh()->fbr_response_code);
  $second=app(\App\Http\Controllers\AgentController::class)->submitResult($r);$this->assertTrue($second->getData(true)['already_submitted']);
  $this->assertSame('654321ABC20260001',$bill->fresh()->fbr_invoice_number);
  $evidence=DB::table('fbr_pos_submission_evidence')->where('transaction_id',$id)->first();$this->assertNull($evidence->response_code);$this->assertSame('unknown',$evidence->central_verification_state);
 }
}

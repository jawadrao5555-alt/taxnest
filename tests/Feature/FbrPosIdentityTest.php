<?php
namespace Tests\Feature;
use App\Models\Company;
use App\Models\FbrPosTransaction;
use App\Services\FbrPosIdentity;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;
class FbrPosIdentityTest extends TestCase {
 protected function setUp(): void {
  parent::setUp(); Schema::dropAllTables();
  Schema::create('companies',function(Blueprint $t){$t->id();$t->string('name');$t->string('fbr_pos_id');$t->timestamps();$t->softDeletes();});
  foreach([1,2] as $id) DB::table('companies')->insert(['id'=>$id,'name'=>'Fictional '.$id,'fbr_pos_id'=>'1234']);
  Schema::create('fbr_pos_transactions',function(Blueprint $t){$t->id();$t->integer('company_id');$t->integer('branch_id')->nullable();$t->integer('terminal_id')->nullable();$t->string('invoice_mode')->nullable();$t->string('fbr_invoice_number')->nullable();$t->timestamps();});
  Schema::create('fbr_pos_terminals',function(Blueprint $t){$t->id();$t->integer('company_id');$t->integer('branch_id')->nullable();});
  (require database_path('migrations/2026_10_13_150001_add_fbr_pos_identity_mapping.php'))->up();
  DB::table('fbr_pos_identities')->insert([
   ['company_id'=>1,'branch_id'=>10,'terminal_id'=>0,'pos_id'=>'1010','device_uid'=>'PC-A'],
   ['company_id'=>1,'branch_id'=>10,'terminal_id'=>20,'pos_id'=>'1020','device_uid'=>'PC-B'],
   ['company_id'=>2,'branch_id'=>10,'terminal_id'=>0,'pos_id'=>'9999','device_uid'=>'OTHER']]);
  DB::table('fbr_pos_terminals')->insert(['id'=>20,'company_id'=>1,'branch_id'=>10]);
 }
 private function bill(array $attrs=[]): FbrPosTransaction {return FbrPosTransaction::create(array_merge(['company_id'=>1,'invoice_mode'=>'fbr'],$attrs));}
 public function test_terminal_precedence_and_immutable_pos_snapshot(): void {
  $bill=$this->bill(['branch_id'=>10,'terminal_id'=>20]);$service=app(FbrPosIdentity::class);
  $this->assertSame(['pos_id'=>'1020','device_uid'=>'PC-B'],$service->capture($bill));
  DB::table('fbr_pos_identities')->where('terminal_id',20)->update(['pos_id'=>'8888','device_uid'=>'CHANGED']);
  $this->assertSame(['pos_id'=>'1020','device_uid'=>'PC-B'],$service->capture($bill->fresh()));
 }
 public function test_legacy_company_default_and_other_tenant_are_preserved(): void {
  $s=app(FbrPosIdentity::class);$this->assertSame(['pos_id'=>'1234','device_uid'=>null],$s->capture($this->bill()));
  $this->assertSame('9999',$s->capture($this->bill(['company_id'=>2,'branch_id'=>10]))['pos_id']);
 }
 public function test_routing_happens_before_limit_and_legacy_cannot_steal_branch(): void {
  for($i=0;$i<25;$i++) $this->bill(['branch_id'=>10]);
  $b=$this->bill(['branch_id'=>10,'terminal_id'=>20]);$default=$this->bill();$s=app(FbrPosIdentity::class);
  $this->assertSame([$b->id,$default->id],$s->route(FbrPosTransaction::where('company_id',1),1,'PC-B')->orderBy('id')->limit(20)->pluck('id')->all());
  $this->assertSame([$default->id],$s->route(FbrPosTransaction::where('company_id',1),1,null)->pluck('id')->all());
  $s->capture($b);$this->assertFalse($s->route(FbrPosTransaction::whereKey($b->id),1,'PC-A')->exists());
 }
 public function test_mapped_identity_keeps_exclusive_claim_and_quarantines_unknown_outcome(): void {
  Schema::table('fbr_pos_transactions',function(Blueprint $t){$t->string('fbr_status')->nullable();$t->string('fbr_submission_hash')->nullable();$t->timestamp('fbr_agent_claimed_at')->nullable();});
  $bill=$this->bill(['branch_id'=>10,'terminal_id'=>20,'fbr_status'=>'pending']);
  $this->assertNull(\App\Services\FbrPosAgentClaim::acquire($bill,'PC-A'));
  $this->assertNull(\App\Services\FbrPosAgentClaim::acquire($bill));
  $claim=\App\Services\FbrPosAgentClaim::acquire($bill,'PC-B');
  $this->assertNotNull($claim);
  $this->assertNull(\App\Services\FbrPosAgentClaim::acquire($bill->fresh(),'PC-B'));
  $this->assertFalse(\App\Services\FbrPosAgentClaim::prepareRetry($bill->fresh()));
  $this->travel(4)->minutes();
  \App\Services\FbrPosAgentClaim::quarantineExpired(1);
  $bill->refresh();
  $this->assertSame('verification_pending',$bill->fbr_status);
  $this->assertSame($claim,$bill->fbr_submission_hash);
  $this->assertSame('1020',$bill->fbr_pos_id_snapshot);
  $this->assertSame('PC-B',$bill->fbr_device_uid_snapshot);
  $this->assertNull(\App\Services\FbrPosAgentClaim::acquire($bill,'PC-B'));
 }
 public function test_foreign_terminal_is_rejected(): void {
  DB::table('fbr_pos_terminals')->insert(['id'=>99,'company_id'=>2,'branch_id'=>10]);
  $this->expectException(ValidationException::class);app(FbrPosIdentity::class)->capture($this->bill(['branch_id'=>10,'terminal_id'=>99]));
 }
 public function test_replacement_requires_rejected_ims_evidence_and_preserves_pos_and_usin(): void {
  Schema::create('pos_agent_devices',function(Blueprint $t){$t->id();$t->integer('company_id');$t->string('device_uid');});
  DB::table('pos_agent_devices')->insert([['company_id'=>1,'device_uid'=>'REPLACEMENT'],['company_id'=>2,'device_uid'=>'FOREIGN']]);
  Schema::create('fbr_pos_owner_reconciliations',function(Blueprint $t){$t->id();$t->integer('company_id');$t->integer('transaction_id');$t->string('decision');$t->string('evidence_source');});
  Schema::table('fbr_pos_transactions',function(Blueprint $t){$t->string('fbr_status')->nullable();$t->string('fbr_submission_hash')->nullable();$t->string('invoice_number')->nullable();});
  $bill=$this->bill(['branch_id'=>10]);$bill->forceFill(['fbr_status'=>'failed','invoice_number'=>'FPOS-UNCHANGED'])->save();
  $actor=(new \App\Models\User)->forceFill(['id'=>7,'company_id'=>1,'role'=>'company_admin']);$s=app(FbrPosIdentity::class);
  try {$s->rebind($actor,$bill->id,'REPLACEMENT','1010','Checked IMS install');$this->fail('Missing rejection must block replacement');} catch(ValidationException $e) {$this->assertNotEmpty($e->errors());}
  DB::table('fbr_pos_owner_reconciliations')->insert(['company_id'=>1,'transaction_id'=>$bill->id,'decision'=>'not_accepted','evidence_source'=>'ims_log']);
  foreach([['FOREIGN','1010'],['REPLACEMENT','9999']] as [$uid,$pos]) {
   try {$s->rebind($actor,$bill->id,$uid,$pos,'Checked IMS install');$this->fail('Foreign PC / changed POS ID must block replacement');} catch(ValidationException $e) {$this->assertNotEmpty($e->errors());}
  }
  $s->rebind($actor,$bill->id,'REPLACEMENT','1010','Checked IMS install');$s->rebind($actor,$bill->id,'REPLACEMENT','1010','Checked IMS install');
  $this->assertSame('REPLACEMENT',$bill->fresh()->fbr_device_uid_snapshot);$this->assertSame('1010',$bill->fresh()->fbr_pos_id_snapshot);$this->assertSame('FPOS-UNCHANGED',$bill->fresh()->invoice_number);
  $this->assertSame(1,DB::table('fbr_pos_identity_rebindings')->count());
 }
 public function test_submitted_and_local_bills_are_not_rewritten(): void {
  $s=app(FbrPosIdentity::class);
  foreach([['invoice_mode'=>'local'],['fbr_invoice_number'=>'9000000000001']] as $attrs) {
   $bill=$this->bill($attrs);$s->capture($bill);$this->assertNull($bill->fresh()->fbr_identity_captured_at);
  }
 }
}

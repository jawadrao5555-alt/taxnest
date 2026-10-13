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
 public function test_foreign_terminal_is_rejected(): void {
  DB::table('fbr_pos_terminals')->insert(['id'=>99,'company_id'=>2,'branch_id'=>10]);
  $this->expectException(ValidationException::class);app(FbrPosIdentity::class)->capture($this->bill(['branch_id'=>10,'terminal_id'=>99]));
 }
 public function test_submitted_and_local_bills_are_not_rewritten(): void {
  $s=app(FbrPosIdentity::class);
  foreach([['invoice_mode'=>'local'],['fbr_invoice_number'=>'9000000000001']] as $attrs) {
   $bill=$this->bill($attrs);$s->capture($bill);$this->assertNull($bill->fresh()->fbr_identity_captured_at);
  }
 }
}

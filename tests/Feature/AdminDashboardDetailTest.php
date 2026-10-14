<?php
namespace Tests\Feature;
use App\Models\{AdminUser, User};
use App\Services\AdminDashboardDetail;
use Illuminate\Support\Facades\{DB,Schema};
use Illuminate\Database\Schema\Blueprint;
use Tests\TestCase;
class AdminDashboardDetailTest extends TestCase {
 protected function setUp(): void {
  parent::setUp();Schema::dropAllTables();
  Schema::create('companies',function(Blueprint $t){$t->id();$t->string('name');$t->string('product_type');$t->string('status');$t->string('agent_api_key')->nullable();$t->softDeletes();$t->timestamps();});
  foreach(['invoices','pos_transactions','fbr_pos_transactions'] as $table) Schema::create($table,function(Blueprint $t){$t->id();$t->integer('company_id');$t->string('invoice_number');$t->string('status');$t->decimal('total_amount',12,2);$t->string('invoice_mode')->nullable();$t->string('fbr_status')->nullable();$t->string('pra_status')->nullable();$t->timestamps();});
  Schema::create('payment_proofs',function(Blueprint $t){$t->id();$t->integer('company_id');$t->string('status');$t->decimal('amount',12,2);$t->timestamps();});
  Schema::create('subscriptions',function(Blueprint $t){$t->id();$t->integer('company_id');$t->boolean('active');$t->decimal('final_price',12,2);$t->timestamps();});
  Schema::create('users',function(Blueprint $t){$t->id();$t->integer('company_id');$t->string('name');$t->string('role');$t->string('password');$t->timestamps();});
  Schema::create('system_settings',function(Blueprint $t){$t->id();$t->string('key');$t->text('value')->nullable();$t->timestamps();});
  foreach(['di','pos','fbrpos'] as $id=>$type) DB::table('companies')->insert(['id'=>$id+1,'name'=>$id===0?'=Fictional Company':'Company '.$type,'product_type'=>$type,'status'=>'approved','agent_api_key'=>'MUST-NOT-EXPORT','created_at'=>'2026-10-01']);
  DB::table('companies')->insert(['id'=>4,'name'=>'Bin fixture','product_type'=>'di','status'=>'suspended','deleted_at'=>now()]);
  foreach(['invoices','pos_transactions','fbr_pos_transactions'] as $table) foreach(['locked','completed','draft'] as $id=>$status) DB::table($table)->insert(['company_id'=>1,'invoice_number'=>'INV-'.$id,'status'=>$status,'total_amount'=>($id+1)*100,'created_at'=>'2026-10-'.str_pad((string)($id+1),2,'0',STR_PAD_LEFT)]);
  DB::table('users')->insert(['company_id'=>1,'name'=>'Fixture owner','role'=>'company_admin','password'=>'MUST-NOT-EXPORT']);
  DB::table('subscriptions')->insert(['company_id'=>1,'active'=>true,'final_price'=>800]);
  DB::table('payment_proofs')->insert(['company_id'=>1,'status'=>'pending','amount'=>900]);
 }
 private function admin(): void {$this->actingAs((new AdminUser)->forceFill(['id'=>50,'role'=>'super_admin','name'=>'Synthetic admin']),'admin');}
 public function test_all_thirteen_screens_render_and_csv_is_safe(): void {
  $this->admin();foreach(AdminDashboardDetail::LABELS as $metric=>$label) {
   $this->get('/admin/dashboard/details/'.$metric)->assertOk()->assertSee($label.' details');
   $response=$this->get('/admin/dashboard/details/'.$metric.'?export=csv');$response->assertOk();$csv=$response->streamedContent();
   $this->assertStringNotContainsString('MUST-NOT-EXPORT',$csv);
  }
  $this->assertStringContainsString("'=Fictional Company",$this->get('/admin/dashboard/details/total_companies?export=csv')->streamedContent());
 }
 public function test_count_and_revenue_match_existing_tile_semantics(): void {
  $this->admin();$s=app(AdminDashboardDetail::class);
  $d=$s->definition('di_invoices');$this->assertSame(3,$d['q']->count());$this->assertSame(100.0,$s->revenue($d['q'],$d));
  $d=$s->definition('pos_transactions');$this->assertSame(1,$d['q']->count());$this->assertSame(200.0,$s->revenue($d['q'],$d));
  $this->assertSame(3,$s->definition('total_companies')['q']->count());$this->assertSame(1,$s->definition('binned_companies')['q']->count());
  $this->get('/admin/dashboard/details/di_invoices?from=2026-10-02&to=2026-10-03&min=200&status=draft')->assertOk()->assertViewHas('count',1)->assertViewHas('revenue',0.0);
  $this->get('/admin/dashboard/details/total_companies?q=Company%20pos')->assertOk()->assertViewHas('count',1);
  $this->get('/admin/dashboard/details/di_invoices?to=2026-10-02&max=250')->assertOk()->assertViewHas('count',2);
 }
 public function test_tenant_login_does_not_grant_platform_drilldown_and_bad_metrics_fail(): void {
  $this->actingAs((new User)->forceFill(['id'=>1,'company_id'=>1,'role'=>'company_admin']),'web');
  $this->get('/admin/dashboard/details/total_companies')->assertRedirect('/admin/login');
  $this->admin();$this->getJson('/admin/dashboard/details/unknown')->assertNotFound();
  $this->getJson('/admin/dashboard/details/total_companies?sort=agent_api_key')->assertUnprocessable();
 }
}

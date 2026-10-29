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
  foreach(['invoices'=>1,'pos_transactions'=>2,'fbr_pos_transactions'=>3] as $table=>$companyId) foreach(['locked','completed','draft'] as $id=>$status) DB::table($table)->insert(['company_id'=>$companyId,'invoice_number'=>'INV-'.$id,'status'=>$status,'total_amount'=>($id+1)*100,'created_at'=>'2026-10-'.str_pad((string)($id+1),2,'0',STR_PAD_LEFT)]);
  DB::table('users')->insert(['company_id'=>1,'name'=>'Fixture owner','role'=>'company_admin','password'=>'MUST-NOT-EXPORT']);
  DB::table('subscriptions')->insert(['company_id'=>1,'active'=>true,'final_price'=>800]);
  DB::table('payment_proofs')->insert(['company_id'=>1,'status'=>'pending','amount'=>900]);
 }
 public function test_invoice_report_controls_are_product_specific_and_do_not_imply_fiscal_success(): void {
  $this->admin();
  $this->get('/admin/dashboard/details/pos_transactions')->assertOk()
   ->assertSee('Transaction status')->assertSee('Saved invoice mode')
   ->assertDontSee('name="product"',false)->assertDontSee('Subscription dates')
   ->assertSee('Company ID: 2');
  $this->get('/admin/dashboard/details/di_invoices')->assertOk()
   ->assertSee('Invoice status')->assertSee('aria-label="DI environment"',false)
   ->assertDontSee('name="product"',false)->assertDontSee('Subscription dates')
   ->assertSee('FBR invoice number');
  $this->get('/admin/dashboard/details/total_users')->assertOk()->assertSee('Role');
  $this->get('/admin/dashboard/details/active_subscriptions')->assertOk()->assertSee('Subscription dates');
 }
 public function test_unknown_saved_modes_remain_selectable_and_company_scope_matches_csv(): void {
  $this->admin();
  DB::table('pos_transactions')->where('status','completed')->update(['invoice_mode'=>'legacy_custom','pra_status'=>'legacy_waiting']);
  $r=$this->get('/admin/dashboard/details/pos_transactions?company_id=2&mode=legacy_custom&fiscal_status=legacy_waiting');
  $r->assertOk()->assertViewHas('count',1)->assertSee('value="legacy_custom"',false)->assertSee('value="legacy_waiting"',false);
  $this->get('/admin/dashboard/details/pos_transactions?company_id=1&mode=legacy_custom')->assertOk()->assertViewHas('count',0);
  $csv=$this->get('/admin/dashboard/details/pos_transactions?company_id=2&mode=legacy_custom&export=csv')->streamedContent();
  $this->assertStringContainsString('legacy_custom',$csv);
  $this->assertStringNotContainsString('subscription_start',$csv);
 }
 public function test_di_environment_is_separate_from_fiscal_identity_and_does_not_rewrite_history(): void {
  $this->admin();
  DB::table('invoices')->where('id',1)->update(['fbr_status'=>'production']);
  DB::table('invoices')->where('id',2)->update(['fbr_status'=>'sandbox']);
  $before=DB::table('invoices')->get()->toJson();
  $this->get('/admin/dashboard/details/di_invoices?environment=production')->assertOk()->assertViewHas('count',1)
   ->assertSee('No fiscal number recorded')->assertSee('does not by itself prove FBR reporting');
  $this->get('/admin/dashboard/details/di_invoices?environment=sandbox')->assertOk()->assertViewHas('count',1);
  $this->getJson('/admin/dashboard/details/di_invoices?environment=submitted')->assertUnprocessable();
  $csv=$this->get('/admin/dashboard/details/di_invoices?environment=production&export=csv')->streamedContent();
  $this->assertStringContainsString('production',$csv);
  $this->assertStringNotContainsString('sandbox',$csv);
  $this->assertSame($before,DB::table('invoices')->get()->toJson());
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
 public function test_detail_navigation_and_selected_status_are_explicit(): void {
  $this->admin();
  $response=$this->get('/admin/dashboard/details/total_companies?status=approved&product=di');
  $response->assertOk()->assertSee('aria-current="page"', false)
   ->assertSee('data-selected="true"', false)->assertSee('Currently showing', false)
   ->assertViewHas('count',1);
  $this->get('/admin/dashboard/details/total_companies?status=suspended')->assertOk()
   ->assertSee('Clear status', false)->assertViewHas('count',0);
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
 public function test_product_isolation_covers_rows_totals_search_and_csv(): void {
  $this->admin();
  foreach(['invoices'=>['di_invoices',2],'pos_transactions'=>['pos_transactions',1],'fbr_pos_transactions'=>['fbrpos_transactions',1]] as $table=>[$metric,$wrongCompany]) {
   DB::table($table)->insert(['company_id'=>$wrongCompany,'invoice_number'=>'CROSS-PRODUCT-SECRET','status'=>$table==='invoices'?'locked':'completed','total_amount'=>999999,'created_at'=>now()]);
   DB::table($table)->insert(['company_id'=>999,'invoice_number'=>'ORPHAN-SECRET','status'=>'completed','total_amount'=>999999,'created_at'=>now()]);
   $this->get('/admin/dashboard/details/'.$metric)->assertOk()->assertDontSee('CROSS-PRODUCT-SECRET')->assertDontSee('ORPHAN-SECRET');
   $this->get('/admin/dashboard/details/'.$metric.'?q=CROSS-PRODUCT-SECRET')->assertViewHas('count',0);
   $this->assertStringNotContainsString('SECRET',$this->get('/admin/dashboard/details/'.$metric.'?export=csv')->streamedContent());
  }
  $service=app(AdminDashboardDetail::class);
  $this->assertSame(3,$service->definition('di_invoices')['q']->count());
  $this->assertSame(1,$service->definition('pos_transactions')['q']->count());
  $this->assertSame(3,$service->definition('fbrpos_transactions')['q']->count());
  $this->get('/admin/dashboard/details/di_invoices?product=pos')->assertViewHas('count',0);
 }
 public function test_admin_preview_and_download_require_product_company_and_typed_record(): void {
  $this->admin(); app()->instance('currentCompanyId',999);
  $before=[];foreach(['invoices','pos_transactions','fbr_pos_transactions'] as $table)$before[$table]=DB::table($table)->get()->toJson();
  $this->mock(\App\Services\AdminInvoiceDocument::class,function($mock){
   $mock->shouldReceive('pdf')->times(6)->andReturnUsing(function($record,$company,$product){
    $this->assertSame($product,$company->product_type);
    $this->assertSame((int)$company->id,(int)$record->company_id);
    $this->assertSame(['di'=>'invoices','pos'=>'pos_transactions','fbrpos'=>'fbr_pos_transactions'][$product],$record->getTable());
    return \Barryvdh\DomPDF\Facade\Pdf::loadHTML('<html><body>Stored invoice '.$record->invoice_number.'</body></html>')->setPaper('a4');
   });
  });
  foreach(['di'=>1,'pos'=>2,'fbrpos'=>3] as $product=>$company)foreach(['preview','download'] as $action){
   $r=$this->get('/admin/dashboard/invoices/'.$product.'/'.$company.'/1/'.$action);
   $r->assertOk()->assertHeader('Content-Type','application/pdf')->assertHeader('X-Frame-Options','SAMEORIGIN');
   $this->assertStringStartsWith('%PDF-',$r->getContent());
   $this->assertStringContainsString($action==='preview'?'inline':'attachment',$r->headers->get('Content-Disposition'));
  }
  foreach(['di'=>1,'pos'=>2,'fbrpos'=>3] as $product=>$company)foreach(['preview','download'] as $action){
   $this->get('/admin/dashboard/invoices/'.$product.'/'.($company===1?2:1).'/1/'.$action)->assertNotFound();
   $this->get('/admin/dashboard/invoices/'.$product.'/'.$company.'/999/'.$action)->assertNotFound();
  }
  $this->getJson('/admin/dashboard/invoices/health/1/1/preview')->assertNotFound();
  DB::table('companies')->insert(['id'=>5,'name'=>'Second DI Company','product_type'=>'di','status'=>'approved']);
  foreach(['preview','download'] as $action)$this->get('/admin/dashboard/invoices/di/5/1/'.$action)->assertNotFound();
  Schema::table('pos_transactions',fn(Blueprint $t)=>$t->boolean('is_archived')->default(false));
  DB::table('pos_transactions')->where('id',1)->update(['is_archived'=>true]);
  foreach(['preview','download'] as $action)$this->get('/admin/dashboard/invoices/pos/2/1/'.$action)->assertNotFound();
  DB::table('pos_transactions')->where('id',1)->update(['is_archived'=>false]);
  Schema::table('pos_transactions',fn(Blueprint $t)=>$t->dropColumn('is_archived'));

  foreach($before as $table=>$data)$this->assertSame($data,DB::table($table)->get()->toJson());
 }
 public function test_tenant_session_cannot_preview_or_download_admin_documents(): void {
  $this->actingAs((new User)->forceFill(['id'=>1,'company_id'=>1,'role'=>'company_admin']),'web');
  foreach(['preview','download'] as $action)$this->get('/admin/dashboard/invoices/di/1/1/'.$action)->assertRedirect('/admin/login');
 }
 public function test_tenant_login_does_not_grant_platform_drilldown_and_bad_metrics_fail(): void {
  $this->actingAs((new User)->forceFill(['id'=>1,'company_id'=>1,'role'=>'company_admin']),'web');
  $this->get('/admin/dashboard/details/total_companies')->assertRedirect('/admin/login');
  $this->admin();$this->getJson('/admin/dashboard/details/unknown')->assertNotFound();
  $this->getJson('/admin/dashboard/details/total_companies?sort=agent_api_key')->assertUnprocessable();
 }
}

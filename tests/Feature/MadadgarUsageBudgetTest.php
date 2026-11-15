<?php
namespace Tests\Feature;
use App\Models\{Company,User,AdminUser,SystemSetting};
use App\Services\{MadadgarUsage,MadadgarService};
use App\Http\Controllers\MadadgarUsageController;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\{DB,Http};
use Illuminate\Http\Request;
use Tests\TestCase;
class MadadgarUsageBudgetTest extends TestCase {
 use RefreshDatabase;
 private Company $company;
 protected function setUp():void{parent::setUp();Http::preventStrayRequests();$this->company=Company::create(['name'=>'Synthetic AI company','ntn'=>'AI-TEST','product_type'=>'pos','status'=>'approved']);}
 private function policy(?string $budget='0.001000',?string $input='1.000000',?string $output='1.000000'):void{DB::table('madadgar_budget_policies')->insert(['company_id'=>$this->company->id,'model'=>MadadgarUsage::MODEL,'currency'=>'USD','input_per_million'=>$input,'output_per_million'=>$output,'monthly_budget'=>$budget,'configured_by'=>1,'created_at'=>now(),'updated_at'=>now()]);}
 public function test_unknown_rates_and_usage_are_not_reported_as_zero_and_no_private_payload_is_stored():void {
  $id=MadadgarUsage::begin($this->company,100,600);MadadgarUsage::finish($id,21,null,'provider_transport_error');$event=DB::table('madadgar_usage_events')->find($id);
  $this->assertNull($event->estimated_cost);$this->assertNull($event->input_tokens);$this->assertNull($event->reserved_cost);$this->assertSame('error',$event->state);
  $this->assertArrayNotHasKey('prompt',(array)$event);$this->assertArrayNotHasKey('answer',(array)$event);Http::assertNothingSent();
 }
 public function test_configured_cap_reserves_and_unknown_outcome_does_not_release_allowance():void {
  $this->policy();$id=MadadgarUsage::begin($this->company,100,600);MadadgarUsage::finish($id,12,null,'provider_transport_error');
  try{MadadgarUsage::begin($this->company,100,600);$this->fail('Budget exceeded');}catch(\RuntimeException $e){$this->assertSame('Madadgar company AI budget unavailable',$e->getMessage());}
  $this->assertSame('0.0007',(string)DB::table('madadgar_usage_events')->find($id)->reserved_cost);$this->assertSame(1,DB::table('madadgar_usage_events')->where('state','budget_blocked')->count());
 }
 public function test_reported_usage_settles_exact_estimate_once_and_snapshots_rates():void {
  $this->policy();$id=MadadgarUsage::begin($this->company,100,600);DB::table('madadgar_budget_policies')->update(['input_per_million'=>'999']);
  MadadgarUsage::finish($id,7,['prompt_tokens'=>100,'completion_tokens'=>100]);$before=(array)DB::table('madadgar_usage_events')->find($id);
  MadadgarUsage::finish($id,100,['prompt_tokens'=>900,'completion_tokens'=>900]);$this->assertSame($before,(array)DB::table('madadgar_usage_events')->find($id));$this->assertEquals('0.000200',$before['estimated_cost']);
 }
 public function test_cap_without_rates_blocks_before_http_and_local_support_remains_observable():void {
  $this->policy('1',null,null);try{MadadgarUsage::begin($this->company,100);$this->fail();}catch(\RuntimeException){}
  MadadgarUsage::local($this->company,'local',2);$this->assertSame(1,DB::table('madadgar_usage_events')->where('state','local')->count());$this->assertSame('budget_rates_unavailable',DB::table('madadgar_usage_events')->where('state','budget_blocked')->value('error_code'));Http::assertNothingSent();
 }
 public function test_real_provider_wrapper_uses_only_mock_and_records_usage_not_content():void {
  config(['services.openai.key'=>'synthetic-only-key']);$this->policy('1');Http::fake(['api.openai.com/*'=>Http::response(['choices'=>[['message'=>['content'=>'Synthetic reply']]],'usage'=>['prompt_tokens'=>12,'completion_tokens'=>8]],200)]);
  MadadgarService::chat([['role'=>'user','content'=>'How do I use a receipt printer?']],'pos',$this->company);
  $event=DB::table('madadgar_usage_events')->sole();$this->assertSame(12,$event->input_tokens);$this->assertSame(8,$event->output_tokens);$this->assertSame('completed',$event->state);
  $this->assertStringNotContainsString('Synthetic reply',json_encode($event));$this->assertStringNotContainsString('synthetic-only-key',json_encode($event));Http::assertSent(fn($r)=>$r['max_tokens']===600);
 }
 public function test_owner_view_is_company_scoped_and_stale_authority_is_denied():void {
  $owner=User::create(['name'=>'Owner','email'=>'ai-owner@example.test','password'=>'synthetic','company_id'=>$this->company->id,'role'=>'company_admin','pos_role'=>'pos_admin','is_active'=>true]);auth('pos')->setUser($owner);
  MadadgarUsage::local($this->company,'local',3);$other=Company::create(['name'=>'Other','ntn'=>'AI-OTHER','product_type'=>'pos']);MadadgarUsage::local($other,'local',4);
  $this->app->instance('currentCompanyId',$this->company->id);view()->share('errors',new \Illuminate\Support\ViewErrorBag());$r=Request::create('/pos/madadgar/usage');$view=(new MadadgarUsageController)->show($r);$this->assertCount(1,$view->getData()['events']);$rendered=$view->render();$this->assertStringContainsString('Synthetic AI company',$rendered);$this->assertStringNotContainsString('Other budget',$rendered);
  $owner->fresh()->update(['role'=>'employee']);$this->expectException(\Symfony\Component\HttpKernel\Exception\HttpException::class);(new MadadgarUsageController)->show($r);
 }
 public function test_budget_configuration_requires_fresh_sensitive_admin_and_preserves_provider_settings():void {
  $admin=AdminUser::create(['name'=>'Synthetic admin','email'=>'ai-admin@example.test','password'=>'synthetic','role'=>'super_admin','is_active'=>true]);auth('admin')->setUser($admin);
  SystemSetting::set('madadgar_enabled','0');$before=DB::table('system_settings')->where('key','madadgar_enabled')->value('value');
  $r=Request::create('/admin/companies/'.$this->company->id.'/ai-usage','POST',['input_per_million'=>'1.000000','output_per_million'=>'2.000000','monthly_budget'=>'3.000000']);$r->setLaravelSession(session()->driver());
  (new MadadgarUsageController)->configure($r,$this->company->id);
  $this->assertEquals('3.000000',DB::table('madadgar_budget_policies')->value('monthly_budget'));$this->assertSame($before,DB::table('system_settings')->where('key','madadgar_enabled')->value('value'));$this->assertSame(1,DB::table('audit_logs')->where('action','madadgar_budget_configured')->count());
  $admin->fresh()->update(['role'=>'support']);$this->expectException(\Symfony\Component\HttpKernel\Exception\HttpException::class);(new MadadgarUsageController)->configure($r,$this->company->id);
 }
 public function test_reported_usage_over_bound_holds_future_budget_and_foreign_tenant_has_separate_allowance():void {
  $this->policy('1');$id=MadadgarUsage::begin($this->company,100,600);MadadgarUsage::finish($id,1,['prompt_tokens'=>101,'completion_tokens'=>1]);
  $this->assertSame('usage_bound_exceeded',DB::table('madadgar_usage_events')->find($id)->error_code);
  try{MadadgarUsage::begin($this->company,100,600);$this->fail();}catch(\RuntimeException){}
  $other=Company::create(['name'=>'Other budget','ntn'=>'AI-BUDGET-OTHER','product_type'=>'pos']);$otherId=MadadgarUsage::begin($other,100,600);$this->assertNotNull($otherId);$this->assertNull(DB::table('madadgar_usage_events')->find($otherId)->estimated_cost);
 }

 public function test_zero_cap_blocks_actual_provider_boundary_without_changing_credentials():void {
  config(['services.openai.key'=>'synthetic-only-key']);$this->policy('0');
  try{MadadgarService::chat([['role'=>'user','content'=>'How do I use a receipt printer?']],'pos',$this->company);$this->fail('Provider boundary bypassed cap');}catch(\RuntimeException $e){$this->assertSame('Madadgar company AI budget unavailable',$e->getMessage());}
  Http::assertNothingSent();$this->assertSame('synthetic-only-key',config('services.openai.key'));$this->assertSame(1,DB::table('madadgar_usage_events')->where('state','budget_blocked')->count());
 }

}

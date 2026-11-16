<?php
namespace Tests\Feature;
use App\Models\{Company,PricingPlan,Invoice,User};
use App\Services\{ProspectiveSubscriptionPolicy as Policy,TrialSubscriptionService,SubscriptionAssignmentService as Assignment,DiFiscalSubmissionState as Fiscal};
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\{DB,Http};
use Illuminate\Validation\ValidationException;
use Tests\TestCase;
class ProspectiveAtomicQuotaTest extends TestCase {
 use RefreshDatabase;
 private function buyer(string $product='di'): Company {
  Http::preventStrayRequests();$this->travelTo(Carbon::parse('2028-06-10 10:00:00',config('app.timezone')));
  $c=Company::create(['name'=>'Synthetic atomicquota','ntn'=>'AQ-'.uniqid(),'product_type'=>$product,'status'=>'approved','company_status'=>'active']);
  $p=PricingPlan::create(['name'=>'Business','product_type'=>$product,'price'=>1000,'invoice_limit'=>1,'user_limit'=>3,'branch_limit'=>1]);
  TrialSubscriptionService::ensureSelectedTrial($c->id,$product,$p->id);
  $q=Assignment::computePrice($p,'annual',$c);$paid=Assignment::assign($c->id,$p->id,'annual',paidRenewal:true,verifiedReceived:$q['final_price']);
  $this->travelTo(Carbon::parse(Policy::display($paid)['paid_starts_at'])->addSecond());return $c;
 }
 private function draft(Company $c): Invoice {
  $id=DB::table('invoices')->insertGetId(['company_id'=>$c->id,'buyer_name'=>'Synthetic','invoice_number'=>'AQ-'.uniqid(),'status'=>'draft','created_at'=>now(),'updated_at'=>now()]);
  return Invoice::withoutGlobalScopes()->findOrFail($id);
 }
 public function test_distinct_di_claims_share_last_slot_and_same_invoice_retry_cannot_claim_twice(): void {
  $c=$this->buyer();$a=$this->draft($c);$b=$this->draft($c);
  self::assertNotNull(Fiscal::reserve($a->id,'synthetic','production'));
  self::assertNull(Fiscal::reserve($a->id,'same','production'));
  try {Fiscal::reserve($b->id,'second','production');self::fail('Over quota claim allowed');}catch(ValidationException $e){self::assertArrayHasKey('subscription',$e->errors());}
  self::assertFalse((bool)$b->fresh()->is_fbr_processing);
  self::assertSame(1,DB::table('invoices')->where('is_fbr_processing',true)->count());Http::assertNothingSent();
 }
 public function test_ambiguous_claim_keeps_slot_and_expired_retry_cannot_relabel_environment(): void {
  $c=$this->buyer();$a=$this->draft($c);$b=$this->draft($c);Fiscal::reserve($a->id,'synthetic','production');
  $this->travel(Fiscal::LEASE_SECONDS+1)->seconds();self::assertNull(Fiscal::reserve($a->id,'changed','sandbox'));
  self::assertSame('production',$a->fresh()->fiscal_submission_environment);
  self::assertSame('pending_verification',$a->fresh()->status);
  $this->expectException(ValidationException::class);Fiscal::reserve($b->id,'second','production');
 }
 public function test_unknown_fiscal_evidence_cannot_be_freed_or_replayed_by_stale_compatibility_status(): void {
  $c=$this->buyer();$a=$this->draft($c);$b=$this->draft($c);
  $claim=Fiscal::reserve($a->id,'synthetic','production');Fiscal::verificationRequired($claim,'production','synthetic_timeout');$claim->save();
  DB::table('invoices')->where('id',$a->id)->update(['status'=>'failed','is_fbr_processing'=>false]);
  self::assertNull(Fiscal::reserve($a->id,'unsafe_retry','production'));
  $this->expectException(ValidationException::class);Fiscal::reserve($b->id,'second','production');
 }
 public function test_definite_rejection_releases_but_sandbox_never_consumes_production_slot(): void {
  $c=$this->buyer();$a=$this->draft($c);$b=$this->draft($c);
  $claimed=Fiscal::reserve($a->id,'synthetic','production');Fiscal::rejected($claimed);$claimed->save();
  self::assertNotNull(Fiscal::reserve($b->id,'second','production'));
  $sandbox=$this->draft($c);self::assertNotNull(Fiscal::reserve($sandbox->id,'sandbox','sandbox'));
  Http::assertNothingSent();
 }
 public function test_trial_and_early_paid_confirmation_context_keep_unlimited_bill_count(): void {
  $c=$this->buyer('pos');$sub=\App\Models\Subscription::where('company_id',$c->id)->where('active',true)->first();
  $this->travelTo(Carbon::parse($sub->lifecycle_snapshot['trial_starts_at'])->addHour());
  DB::transaction(function()use($c){$context=\App\Services\ProspectiveQuotaGuard::context($c->id);self::assertSame(-1,$context['limit']);self::assertSame('trial',Policy::display($context['subscription'])['phase']);});
 }
 public static function localKinds(): array { return [['education','school_fee_receipts'],['hospital','hospital_invoices']]; }
 #[\PHPUnit\Framework\Attributes\DataProvider('localKinds')]
 public function test_actual_local_collection_distinct_payment_is_blocked_but_same_key_recovers(string $kind,string $table): void {
  $c=$this->buyer('fbrpos');$c->forceFill(['pos_type'=>$kind,'business_category'=>$kind])->save();
  $owner=User::factory()->create(['company_id'=>$c->id,'role'=>'company_admin','is_active'=>true]);$this->actingAs($owner,'fbrpos');
  app()->instance('currentCompanyId',$c->id);app()->bind('currentBranchId',fn()=>null);
  $data=['request_key'=>(string)\Illuminate\Support\Str::uuid(),'expected_branch_key'=>0,'amount'=>'100.00','payment_method'=>'cash'];
  if($kind==='education'){$data+=['student_reference'=>'SYN-Q','fee_head'=>'tuition','fee_period'=>'2028-06','cash_confirmed'=>'1'];$path='/fbr-pos/school-fees';$controller=app(\App\Http\Controllers\FbrPosSchoolFeeController::class);}
  else{$data+=['patient_reference'=>'SYN-Q','service_head'=>'consultation','total_amount'=>'100.00','payment_confirmed'=>'1'];$path='/fbr-pos/hospital';$controller=app(\App\Http\Controllers\FbrPosHospitalController::class);}
  $send=function($payload)use($controller,$path){$r=\Illuminate\Http\Request::create($path,'POST',$payload,[],[],['HTTP_ACCEPT'=>'application/json']);$r->setLaravelSession(app('session.store'));app()->instance('request',$r);return $controller->store($r);};
  self::assertSame(200,$send($data)->getStatusCode());
  self::assertSame(200,$send($data)->getStatusCode());
  try{$send(array_replace($data,['request_key'=>(string)\Illuminate\Support\Str::uuid()]));self::fail('Second distinct collection exceeded quota');}catch(ValidationException $e){self::assertArrayHasKey('subscription',$e->errors());}
  self::assertSame(1,DB::table($table)->where('company_id',$c->id)->count());
  Http::assertNothingSent();
 }
 public function test_trial_created_pra_provisional_promotion_preserves_original_bill_time_basis(): void {
  $c=$this->buyer('pos');$sub=\App\Models\Subscription::where('company_id',$c->id)->where('active',true)->first();
  $bill=new \App\Models\PosTransaction();$bill->forceFill(['company_id'=>$c->id,'invoice_number'=>'SYN-PROVISIONAL','status'=>'completed','invoice_mode'=>'local','pra_status'=>'local','transaction_type'=>'sale','created_at'=>Carbon::parse($sub->lifecycle_snapshot['trial_starts_at'])->setTimezone(config('app.timezone'))])->save();
  $promote=new \ReflectionMethod(\App\Http\Controllers\PosController::class,'promoteLocalToPosSerial');
  self::assertTrue($promote->invoke(app(\App\Http\Controllers\PosController::class),$bill,$c->id,null));
  self::assertSame('pra',$bill->fresh()->invoice_mode);
  self::assertSame(1,\App\Services\PlanLimitService::canCreatePosBill($c->id)['remaining']);
  Http::assertNothingSent();
 }
 public function test_expired_access_never_blocks_recording_real_acknowledgement_or_stock_receiving(): void {
  $c=$this->buyer();$c->forceFill(['inventory_enabled'=>true])->save();
  $product=\App\Models\Product::withoutGlobalScopes()->create(['company_id'=>$c->id,'name'=>'Synthetic lock order','hs_code'=>'33049900','uom'=>'KG','is_active'=>true]);
  $invoice=$this->draft($c);$invoice->forceFill(['document_type'=>'Sale Invoice','inventory_enabled_snapshot'=>true,'inventory_environment_snapshot'=>'production'])->save();
  $invoice->items()->create(['hs_code'=>'33049900','description'=>'Synthetic','quantity'=>'1.0000','price'=>100,'tax'=>0,'default_uom'=>'KG','inventory_snapshot'=>['version'=>1,'company_id'=>$c->id,'product_id'=>$product->id,'branch_id'=>null,'base_uom'=>'KG','base_quantity'=>'1.0000','source_invoice_item_id'=>null]]);
  $claim=Fiscal::reserve($invoice->id,'synthetic','production');
  $sub=\App\Models\Subscription::where('company_id',$c->id)->where('active',true)->first();
  $this->travelTo(Carbon::parse($sub->lifecycle_snapshot['grace_ends_at'])->addDay());
  self::assertFalse(\App\Services\SubscriptionAccessService::hasAccess($c)['allowed']);
  Fiscal::accepted($claim,'SYNTHETIC-ACK-EXPIRED','production','synthetic_test');\App\Services\DiInvoiceInventory::saveAccepted($claim);
  self::assertSame('locked',$claim->fresh()->status);self::assertSame('posted',$claim->fresh()->inventory_posting_state);
  \App\Services\InventoryService::addStock($c->id,$product->id,'2.0000','2.00','purchase');
  self::assertEquals('1.0000',number_format((float)DB::table('inventory_stocks')->where('company_id',$c->id)->value('quantity'),4,'.',''));
  self::assertSame(2,DB::table('inventory_movements')->where('company_id',$c->id)->count());Http::assertNothingSent();
 }
 public function test_actual_pra_checkout_rechecks_a_raced_last_slot_before_persisting(): void {
  $c=$this->buyer('pos');$owner=User::factory()->create(['company_id'=>$c->id,'role'=>'company_admin','is_active'=>true]);
  $injected=false;$baseline=DB::transactionLevel();
  DB::listen(function($event)use($c,&$injected,$baseline){
   if(!$injected && str_contains($event->sql,'from "companies"') && DB::transactionLevel()>$baseline){
    $injected=true;DB::table('pos_transactions')->insert(['company_id'=>$c->id,'invoice_number'=>'SYNTHETIC-RACE','status'=>'completed','invoice_mode'=>'pra','transaction_type'=>'sale','created_at'=>now(),'updated_at'=>now()]);
   }
  });
  $response=$this->actingAs($owner,'pos')->postJson('/pos/invoice/store',['items'=>[['type'=>'product','name'=>'Synthetic','quantity'=>1,'unit_price'=>100,'is_tax_exempt'=>false,'_manual'=>1]],'payment_method'=>'cash','discount_type'=>'amount','discount_value'=>0]);
  self::assertTrue($injected,'Race was injected between precheck and locked recheck');
  self::assertSame(0,DB::table('pos_transactions')->where('company_id',$c->id)->count(),'Whole checkout transaction including synthetic competitor rolls back; no second bill persisted');
  $response->assertStatus(422)->assertJsonValidationErrors('subscription');Http::assertNothingSent();
 }
}

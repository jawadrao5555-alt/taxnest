<?php
namespace Tests\Feature;
use App\Models\{Company,Invoice,Product,PricingPlan,Subscription,User};
use App\Services\{DiInvoiceInventory,DiFiscalSubmissionState,DiSellingPacking,IntegrityHashService};
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\{DB,Http};
use Tests\TestCase;
class DiInvoiceInventoryTest extends TestCase {
 use RefreshDatabase;
 private Company $company;private Product $product;private string $key;private User $owner;
 protected function setUp():void {
  parent::setUp();Http::preventStrayRequests();
  $this->company=Company::create(['name'=>'Synthetic stock DI','ntn'=>'DISTOCK','product_type'=>'di','status'=>'approved','company_status'=>'active','province'=>'Punjab','inventory_enabled'=>true]);
  $this->key='dik_'.$this->company->id.'_stocktest';$this->company->forceFill(['di_api_key_hash'=>hash('sha256',$this->key)])->save();
  $plan=PricingPlan::create(['name'=>'Synthetic DI','product_type'=>'di','price'=>1000,'invoice_limit'=>-1,'is_trial'=>false]);
  Subscription::create(['company_id'=>$this->company->id,'pricing_plan_id'=>$plan->id,'active'=>true,'start_date'=>now()->subDay(),'end_date'=>now()->addYear()]);
  $this->product=Product::withoutGlobalScopes()->create(['company_id'=>$this->company->id,'name'=>'Synthetic stock product','hs_code'=>'33049900','uom'=>'KG','default_price'=>100,'default_tax_rate'=>18,'schedule_type'=>'standard','is_active'=>true,'selling_packings'=>DiSellingPacking::definitions([['label'=>'25 KG bag','factor'=>'25']], 'KG')]);
  $this->owner=User::create(['name'=>'Synthetic stock owner','email'=>'stock@test.invalid','password'=>'unused','role'=>'company_admin','is_active'=>true,'company_id'=>$this->company->id]);
 }
 private function payload(string $qty='2',bool $packing=true):array {
  return ['client_reference'=>'stock-'.bin2hex(random_bytes(6)),'mode'=>'draft','buyer_name'=>'Synthetic buyer','buyer_address'=>'Lahore','document_type'=>'Sale Invoice','destination_province'=>'Punjab','items'=>[['product_id'=>$this->product->id,'selling_packing_id'=>$packing?$this->product->selling_packings[0]['id']:null,'hs_code'=>$this->product->hs_code,'default_uom'=>'KG','description'=>'Synthetic stock product','quantity'=>$qty,'price'=>$packing?'2500':'100','tax'=>round((float)$qty*($packing?2500:100)*.18,2),'tax_rate'=>18,'schedule_type'=>'standard']]];
 }
 private function draft(array $input):Invoice {
  $this->withHeader('Authorization','Bearer '.$this->key)->postJson('/api/di/v1/invoices',$input)->assertCreated();
  return Invoice::withoutGlobalScopes()->where('company_id',$this->company->id)->where('client_reference',$input['client_reference'])->firstOrFail();
 }
 private function accept(Invoice $invoice):void {
  if(in_array($invoice->status,['draft','failed'],true)){DiFiscalSubmissionState::reserve($invoice->id,'sync','production');$invoice->refresh();}
  DiFiscalSubmissionState::accepted($invoice,'SYNTHETIC-'.$invoice->id,'production','synthetic_test');
  $invoice->integrity_hash=IntegrityHashService::generate($invoice);DiInvoiceInventory::saveAccepted($invoice);
 }
 private function stock():string {return number_format((float)DB::table('inventory_stocks')->where('company_id',$this->company->id)->value('quantity'),4,'.','');}
 public function test_real_api_draft_and_manual_confirmation_post_frozen_packing_once():void {
  $invoice=$this->draft($this->payload());$snapshot=$invoice->items()->sole()->inventory_snapshot;
  $this->assertSame('50.0000',$snapshot['base_quantity']);$this->assertSame(0,DB::table('inventory_movements')->count());
  $this->product->update(['selling_packings'=>DiSellingPacking::definitions([['label'=>'Changed','factor'=>'50']],'KG')]);
  $this->company->update(['inventory_enabled'=>false]);
  $invoice=DiFiscalSubmissionState::reserve($invoice->id,'sync','production');DiFiscalSubmissionState::verificationRequired($invoice,'production','synthetic_unknown');$invoice->save();
  $this->actingAs($this->owner)->post('/invoice/'.$invoice->id.'/confirm-fbr',['action'=>'confirm','fbr_invoice_number'=>'SYNTHETIC-12345','verification_reason'=>'Synthetic local fixture acknowledgement only'])->assertSessionHasNoErrors();
  $this->assertSame('locked',$invoice->fresh()->status);$this->assertSame('-50.0000',$this->stock());
  $this->assertNull(DB::table('di_invoice_inventory_postings')->where('invoice_id',$invoice->id)->value('unit_cost_snapshot'));
  $this->product->update(['uom'=>'LTR']); // Later master changes cannot demote a completed posting.
  $this->accept($invoice->fresh());$this->assertSame(1,DB::table('inventory_movements')->count());$this->assertSame($snapshot,$invoice->fresh()->items()->sole()->inventory_snapshot);
  $this->assertSame('posted',$invoice->fresh()->inventory_posting_state);
  $this->assertTrue(IntegrityHashService::verify($invoice->fresh()));
 }
 public function test_base_units_preserve_four_decimal_precision_and_partial_return_only_once():void {
  $sale=$this->draft($this->payload('1.2345',false));$this->accept($sale);$this->assertSame('-1.2345',$this->stock());
  $input=$this->payload('0.1234',false);$input['document_type']='Credit Note';$input['reference_invoice_number']=$sale->invoice_number;$input['items'][0]['source_invoice_item_id']=$sale->items()->sole()->id;
  $credit=$this->draft($input);$this->accept($credit);$this->accept($credit->fresh());
  $this->assertSame('-1.1111',$this->stock());$this->assertSame(2,DB::table('di_invoice_inventory_postings')->count());
  $input['client_reference'].='-over';$input['items'][0]['quantity']='2';$input['items'][0]['tax']=36;
  $this->postJson('/api/di/v1/invoices',$input)->assertStatus(422);
  $this->assertSame('-1.1111',$this->stock());$this->assertSame(2,DB::table('inventory_movements')->count());
 }
 public function test_disabled_and_historical_records_do_not_backfill_after_optin():void {
  $this->company->update(['inventory_enabled'=>false]);$off=$this->draft($this->payload());
  $this->company->update(['inventory_enabled'=>true]);$this->accept($off);$this->assertSame(0,DB::table('inventory_movements')->count());
  $old=$this->draft($this->payload());DB::table('invoices')->where('id',$old->id)->update(['inventory_enabled_snapshot'=>null]);$this->accept($old->fresh());
  $this->assertSame(0,DB::table('inventory_movements')->count());
 }
 public function test_foreign_product_branch_precision_and_original_unposted_return_are_rejected():void {
  $input=$this->payload('1',false);$foreign=Company::create(['name'=>'Foreign','ntn'=>'DISTOCKOTHER','product_type'=>'di']);
  $p=Product::withoutGlobalScopes()->create(['company_id'=>$foreign->id,'name'=>'Foreign','hs_code'=>'33049900','uom'=>'KG']);$input['items'][0]['product_id']=$p->id;
  $this->withHeader('Authorization','Bearer '.$this->key)->postJson('/api/di/v1/invoices',$input)->assertStatus(422);
  $input=$this->payload('1.00001',false);$this->postJson('/api/di/v1/invoices',$input)->assertStatus(422);
  $sale=$this->draft($this->payload('1',false));$sale->forceFill(['inventory_enabled_snapshot'=>null])->save();$this->accept($sale);
  $input=$this->payload('1',false);$input['document_type']='Credit Note';$input['reference_invoice_number']=$sale->invoice_number;$input['items'][0]['source_invoice_item_id']=$sale->items()->sole()->id;
  $this->postJson('/api/di/v1/invoices',$input)->assertStatus(422);$this->assertSame(0,DB::table('inventory_movements')->count());
 }
 public function test_transaction_rollback_and_snapshot_tampering_are_visible():void {
  $invoice=$this->draft($this->payload());
  try{DB::transaction(function()use($invoice){$this->accept($invoice);throw new \RuntimeException('Synthetic rollback');});}catch(\RuntimeException){}
  $this->assertSame('draft',$invoice->fresh()->status);$this->assertSame(0,DB::table('inventory_movements')->count());
  $this->accept($invoice->fresh());$item=$invoice->fresh()->items()->sole();$s=$item->inventory_snapshot;$s['base_quantity']='999.0000';$item->update(['inventory_snapshot'=>$s]);
  $this->assertFalse(IntegrityHashService::verify($invoice->fresh()));
 }
 public function test_branch_isolation_return_lookup_and_legacy_two_decimal_stock_are_preserved():void {
  $branch=\App\Models\Branch::withoutGlobalScopes()->create(['company_id'=>$this->company->id,'name'=>'Synthetic branch','code'=>'SYN1']);
  $input=$this->payload('0.1234',false);$input['branch_id']=$branch->id;
  DB::table('inventory_stocks')->insert(['company_id'=>$this->company->id,'product_id'=>$this->product->id,'branch_id'=>$branch->id,'quantity'=>'10.2500','avg_purchase_price'=>'2.00','last_purchase_price'=>'2.00','min_stock_level'=>0,'created_at'=>now(),'updated_at'=>now()]);
  $sale=$this->draft($input);$this->accept($sale);
  $this->assertSame('10.1266',$this->stock());$this->assertSame(1,DB::table('inventory_stocks')->count());
  $this->actingAs($this->owner)->getJson('/invoices/return-items?reference='.urlencode($sale->invoice_number))->assertOk()->assertJsonPath('items.0.source_invoice_item_id',$sale->items()->sole()->id)->assertJsonPath('items.0.quantity','0.1234');
  $input['client_reference'].='return';$input['document_type']='Credit Note';$input['reference_invoice_number']=$sale->invoice_number;$input['items'][0]['source_invoice_item_id']=$sale->items()->sole()->id;unset($input['branch_id']);
  $this->postJson('/api/di/v1/invoices',$input)->assertStatus(422);
  $input['branch_id']=$branch->id;$credit=$this->draft($input);$this->accept($credit);$this->assertSame('10.2500',$this->stock());
  $this->assertSame('2.00',number_format((float)DB::table('inventory_movements')->where('type','return_in')->value('unit_price'),2,'.',''));
 }
 public function test_edit_keeps_eligibility_and_product_identity_without_reinterpreting_legacy_drafts():void {
  $input=$this->payload('1.2345',false);$invoice=$this->draft($input);$line=$invoice->items()->sole();
  unset($input['mode'],$input['client_reference']);$input['items'][0]['invoice_item_id']=$line->id;unset($input['items'][0]['product_id']);
  $input['buyer_address']='Updated synthetic address';
  $this->actingAs($this->owner)->put('/invoice/'.$invoice->id,$input)->assertSessionHasNoErrors()->assertRedirect('/invoice/'.$invoice->id);
  $this->assertSame($line->inventory_snapshot,$invoice->fresh()->items()->sole()->inventory_snapshot);
  $this->assertSame(0,DB::table('inventory_movements')->count());
 }

 public function test_unexpected_stock_failure_preserves_acknowledgement_and_local_only_retry():void {
  $invoice=$this->draft($this->payload('1.2345',false));
  DB::statement("CREATE TRIGGER synthetic_stock_failure BEFORE INSERT ON inventory_movements BEGIN SELECT RAISE(ABORT, 'synthetic stock failure'); END");
  $this->accept($invoice);$invoice->refresh();
  $this->assertSame('locked',$invoice->status);$this->assertSame('SYNTHETIC-'.$invoice->id,$invoice->fbr_invoice_number);
  $this->assertSame('reconciliation_hold',$invoice->inventory_posting_state);$this->assertSame(0,DB::table('inventory_movements')->count());$this->assertSame(0,DB::table('inventory_stocks')->count());
  $this->assertNull(DiFiscalSubmissionState::reserve($invoice->id,'sync','sandbox'));
  DB::statement('DROP TRIGGER synthetic_stock_failure');
  $this->actingAs($this->owner)->post('/invoice/'.$invoice->id.'/retry-inventory')->assertSessionHasNoErrors();
  $this->assertSame('posted',$invoice->fresh()->inventory_posting_state);$this->assertSame('-1.2345',$this->stock());$this->assertSame('SYNTHETIC-'.$invoice->id,$invoice->fresh()->fbr_invoice_number);
  Http::assertNothingSent();
 }
 public function test_return_dispatch_reservation_survives_unknown_outcome_and_release_requires_explicit_rejection():void {
  $sale=$this->draft($this->payload('10',false));$this->accept($sale);
  $input=$this->payload('7',false);$input['document_type']='Credit Note';$input['reference_invoice_number']=$sale->invoice_number;$input['items'][0]['source_invoice_item_id']=$sale->items()->sole()->id;
  $credit=$this->draft($input);$reserved=DiFiscalSubmissionState::reserve($credit->id,'sync','production');$this->assertNotNull($reserved);
  $this->assertSame('reserved',DB::table('di_inventory_return_reservations')->where('invoice_id',$credit->id)->value('state'));
  DiFiscalSubmissionState::verificationRequired($reserved,'production','synthetic_timeout');$reserved->save();
  try{DB::transaction(fn()=>DiInvoiceInventory::releaseForEdit($reserved));$this->fail('Unknown outcome released');}catch(\Illuminate\Validation\ValidationException){}
  $this->assertSame('reserved',DB::table('di_inventory_return_reservations')->where('invoice_id',$credit->id)->value('state'));
  DiFiscalSubmissionState::rejected($reserved);$reserved->save();
  DB::transaction(fn()=>DiInvoiceInventory::releaseForEdit($reserved));
  $this->assertSame('released',DB::table('di_inventory_return_reservations')->where('invoice_id',$credit->id)->value('state'));
  DB::statement("CREATE TRIGGER synthetic_return_failure BEFORE INSERT ON inventory_movements BEGIN SELECT RAISE(ABORT, 'synthetic return failure'); END");
  $this->accept($reserved->fresh());
  $this->assertSame('locked',$credit->fresh()->status);$this->assertSame('reconciliation_hold',$credit->fresh()->inventory_posting_state);
  $this->assertSame('reserved',DB::table('di_inventory_return_reservations')->where('invoice_id',$credit->id)->value('state'));
  DB::statement('DROP TRIGGER synthetic_return_failure');DiInvoiceInventory::retryStock($credit->fresh());
  $this->assertSame('consumed',DB::table('di_inventory_return_reservations')->where('invoice_id',$credit->id)->value('state'));

 }

 public function test_sandbox_acceptance_never_changes_physical_stock_even_after_environment_flip():void {
  $invoice=$this->draft($this->payload('2',false));
  $reserved=DiFiscalSubmissionState::reserve($invoice->id,'sync','sandbox');
  DiFiscalSubmissionState::accepted($reserved,'SYNTHETIC-SANDBOX','sandbox','synthetic_test');$reserved->integrity_hash=IntegrityHashService::generate($reserved);DiInvoiceInventory::saveAccepted($reserved);
  $this->assertSame('sandbox_no_stock',$reserved->inventory_posting_state);$this->assertSame('sandbox',$reserved->inventory_environment_snapshot);$this->assertSame(0,DB::table('inventory_movements')->count());
  $this->company->forceFill(['fbr_environment'=>'production'])->save();
  DiFiscalSubmissionState::accepted($reserved,'SYNTHETIC-PRODUCTION-CORRECTION','production','synthetic_test');$reserved->integrity_hash=IntegrityHashService::generate($reserved);DiInvoiceInventory::saveAccepted($reserved);
  $this->assertSame('sandbox_no_stock',$reserved->inventory_posting_state);$this->assertSame('sandbox',$reserved->inventory_environment_snapshot);$this->assertSame(0,DB::table('inventory_stocks')->count());
 }

 public function test_known_stock_identity_failure_is_refused_before_fiscal_reservation():void {
  $invoice=$this->draft($this->payload('1',false));$this->product->delete();
  try{DiFiscalSubmissionState::reserve($invoice->id,'sync','production');$this->fail('Deleted product dispatched');}catch(\Illuminate\Validation\ValidationException){}
  $this->assertFalse($invoice->fresh()->is_fbr_processing);$this->assertNull($invoice->fresh()->fbr_invoice_number);$this->assertSame('draft',$invoice->fresh()->status);Http::assertNothingSent();
 }
 public function test_local_stock_retry_denies_employee_and_view_only_sessions():void {
  $invoice=$this->draft($this->payload());$this->accept($invoice);
  $employee=User::create(['name'=>'Synthetic employee','email'=>'stock-employee@test.invalid','password'=>'unused','role'=>'employee','is_active'=>true,'company_id'=>$this->company->id]);
  $this->actingAs($employee)->post('/invoice/'.$invoice->id.'/retry-inventory')->assertForbidden();
  $request=\Illuminate\Http\Request::create('/invoice/'.$invoice->id.'/retry-inventory','POST');$request->setUserResolver(fn()=>$this->owner);$request->setLaravelSession(app('session')->driver());$request->session()->put('impersonation.readonly',true);
  try{app(\App\Http\Controllers\InvoiceInventoryController::class)->retry($request,$invoice);$this->fail('View-only stock write accepted');}catch(\Symfony\Component\HttpKernel\Exception\HttpExceptionInterface $e){$this->assertSame(403,$e->getStatusCode());}
  $this->assertSame(1,DB::table('inventory_movements')->count());Http::assertNothingSent();
 }

 public function test_stale_owner_demotion_inactivity_or_company_transfer_cannot_retry_stock():void {
  $invoice=$this->draft($this->payload());$this->accept($invoice);
  $request=\Illuminate\Http\Request::create('/invoice/'.$invoice->id.'/retry-inventory','POST');$request->setUserResolver(fn()=>$this->owner);$request->setLaravelSession(app('session')->driver());
  $foreign=Company::create(['name'=>'Other synthetic','ntn'=>'STALEOWNER','product_type'=>'di']);
  foreach([['role'=>'employee'],['is_active'=>false],['company_id'=>$foreign->id]] as $change) {
   DB::table('users')->where('id',$this->owner->id)->update(array_merge(['role'=>'company_admin','is_active'=>true,'company_id'=>$this->company->id],$change));
   try{app(\App\Http\Controllers\InvoiceInventoryController::class)->retry($request,$invoice);$this->fail('Stale owner authority accepted');}catch(\Symfony\Component\HttpKernel\Exception\HttpExceptionInterface $e){$this->assertSame(403,$e->getStatusCode());}
  }
  $this->assertSame(1,DB::table('inventory_movements')->count());Http::assertNothingSent();
 }
 public function test_fatal_stock_exception_rethrows_and_parent_rollback_preserves_prior_ack_log_and_lease():void {
  $invoice=$this->draft($this->payload('1',false));$invoice=DiFiscalSubmissionState::reserve($invoice->id,'sync','production');
  $log=\App\Models\FbrLog::create(['invoice_id'=>$invoice->id,'request_payload'=>'{}','response_payload'=>json_encode(['invoiceNumber'=>'SYNTHETIC-DURABLE-ACK']),'status'=>'success','environment_used'=>'production']);
  DB::statement("CREATE TRIGGER synthetic_fatal_stock_failure BEFORE INSERT ON inventory_movements BEGIN SELECT RAISE(ABORT, 'Deadlock found when trying to get lock'); END");
  DB::statement('SAVEPOINT synthetic_parent_before_ack');
  DiFiscalSubmissionState::accepted($invoice,'SYNTHETIC-DURABLE-ACK','production','synthetic_test');
  try{DiInvoiceInventory::saveAccepted($invoice);$this->fail('Fatal transaction failure swallowed');}catch(\Illuminate\Database\DeadlockException){}
  // SQLite ABORT does not reproduce InnoDB's automatic whole-transaction deadlock rollback.
  // Model that boundary explicitly; the assertion above tests real Laravel exception propagation.
  DB::statement('ROLLBACK TO SAVEPOINT synthetic_parent_before_ack');
  DB::statement('RELEASE SAVEPOINT synthetic_parent_before_ack');
  DB::statement('DROP TRIGGER synthetic_fatal_stock_failure');
  $this->assertSame('SYNTHETIC-DURABLE-ACK',json_decode($log->fresh()->response_payload,true)['invoiceNumber']);
  $this->assertNotSame('locked',$invoice->fresh()->status);$this->assertTrue($invoice->fresh()->is_fbr_processing);$this->assertSame(0,DB::table('inventory_movements')->count());
  $this->assertNull(DiFiscalSubmissionState::reserve($invoice->id,'sync','production'));
  $this->travel(11)->minutes();$this->assertNull(DiFiscalSubmissionState::reserve($invoice->id,'sync','production'));$this->assertSame('pending_verification',$invoice->fresh()->status);Http::assertNothingSent();
 }

 public function test_generic_di_receiving_deduction_and_purchase_reversal_preserve_four_decimal_balances():void {
  \App\Services\InventoryService::addStock($this->company->id,$this->product->id,'999999999.1234','2.00','opening');
  \App\Services\InventoryService::addStock($this->company->id,$this->product->id,'0.0001','2.00','purchase');
  $this->assertSame('999999999.1235',$this->stock());
  \App\Services\InventoryService::deductStock($this->company->id,$this->product->id,'0.0002','2.00','sale');
  $this->assertSame('999999999.1233',$this->stock());
  \App\Services\InventoryService::reversePurchase($this->company->id,$this->product->id,'0.0001','2.00');
  $this->assertSame('999999999.1232',$this->stock());$this->assertSame(1,DB::table('inventory_stocks')->count());
  $this->assertSame('999999999.1232',number_format((float)DB::table('inventory_movements')->orderByDesc('id')->value('balance_after'),4,'.',''));
 }

}

<?php
namespace Tests\Feature\Reporting;
use App\Services\Reporting\{FbrRetainedRefundEventEvidence as Evidence,DiagnosticBillEvidence,DiagnosticSourceEvidence};
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\{DB,Schema};
use Tests\TestCase;
class FbrRetainedRefundEventEvidenceTest extends TestCase {
 protected function setUp():void {
  parent::setUp();Schema::dropAllTables();config(['app.timezone'=>'UTC','reporting_connector.timezone'=>'UTC']);
  Schema::create('companies',function(Blueprint $t){$t->id();$t->string('name');$t->string('product_type');});
  Schema::create('branches',function(Blueprint $t){$t->id();$t->integer('company_id');});
  Schema::create('fbr_pos_transactions',function(Blueprint $t){$t->id();$t->integer('company_id');$t->integer('branch_id');$t->string('transaction_type');$t->integer('parent_transaction_id')->nullable();});
  Schema::create('pos_transactions',function(Blueprint $t){$t->id();$t->integer('company_id');});
  Schema::create('agent_core_events',function(Blueprint $t){$t->id();$t->integer('company_id');$t->string('event_type');$t->json('payload');$t->json('event_scope');$t->string('projection_status');$t->json('projection_result');$t->timestamp('created_at');});
  Schema::create('agent_core_aggregate_mappings',function(Blueprint $t){$t->id();$t->integer('company_id');$t->integer('branch_id');$t->string('local_type');$t->string('local_aggregate_id');$t->string('cloud_type');$t->integer('cloud_id');});
  DB::table('companies')->insert([['id'=>1,'name'=>'Synthetic FBR','product_type'=>'fbrpos'],['id'=>2,'name'=>'Foreign FBR','product_type'=>'fbrpos'],['id'=>3,'name'=>'Synthetic PRA','product_type'=>'pos']]);
  DB::table('branches')->insert([['id'=>10,'company_id'=>1],['id'=>20,'company_id'=>2],['id'=>30,'company_id'=>1]]);
  DB::table('fbr_pos_transactions')->insert(['id'=>100,'company_id'=>1,'branch_id'=>10,'transaction_type'=>'sale']);
  DB::table('pos_transactions')->insert(['id'=>100,'company_id'=>3]);$this->event(1);
 }
 private function event(int $id,string $date='2028-01-01 12:00:00'):void {
  DB::table('fbr_pos_transactions')->insert(['id'=>100+$id,'company_id'=>1,'branch_id'=>10,'transaction_type'=>'return','parent_transaction_id'=>100]);
  DB::table('agent_core_aggregate_mappings')->insert(['company_id'=>1,'branch_id'=>10,'local_type'=>'refund','local_aggregate_id'=>'opaque-'.$id,'cloud_type'=>'fbr_pos_transaction','cloud_id'=>100+$id]);
  DB::table('agent_core_events')->insert(['id'=>$id,'company_id'=>1,'event_type'=>'customer.refund.posted','payload'=>json_encode(['schema'=>'local-core.customer-ledger.v1','command_type'=>'refund.record','aggregate_id'=>'opaque-'.$id,'data'=>['secret'=>'PRIVATE-NOT-RETURNED']]),'event_scope'=>json_encode(['company_id'=>1,'branch_id'=>10]),'projection_status'=>'projected','projection_result'=>json_encode(['return_transaction_id'=>100+$id,'refund_total'=>12345]),'created_at'=>$date]);
 }
 private function source(?int $branch=null,int $after=0,int $limit=25):array{return Evidence::source(1,$branch,'2028-01-01 00:00:00','2028-01-01 23:59:59',$after,$limit,[]);}
 public function test_exact_registered_return_parent_mapping_ids_only_and_pra_namespace_never_inferred():void {
  $r=Evidence::bill(1,100,0,25);self::assertSame('PARTIAL',$r['status']);self::assertSame([['id'=>1]],$r['rows']);self::assertSame([] ,Evidence::bill(3,100,0,25)['rows']);
  $source=$this->source(10);self::assertSame('PARTIAL',$source['status']);self::assertSame(['id','event_at'],array_keys($source['rows'][0]));self::assertStringNotContainsString('PRIVATE',json_encode($source));self::assertStringNotContainsString('12345',json_encode($source));
  $report=(new DiagnosticBillEvidence)->read(['company_name'=>'Synthetic FBR','bill_id'=>100],[1]);self::assertSame([['id'=>1]],$report['sources']['projected_refund_events']['rows']);self::assertFalse($report['complete']);self::assertContains('general_or_unretained_offline_agent_events',$report['unimplemented_links']);
 }
 public function test_forged_tenant_branch_scope_parent_or_mapping_fail_closed():void {
  foreach([
   ['agent_core_events',['event_scope'=>json_encode(['company_id'=>2,'branch_id'=>10])]],
   ['agent_core_events',['event_scope'=>json_encode(['company_id'=>1,'branch_id'=>20])]],
   ['fbr_pos_transactions',['company_id'=>2]],['fbr_pos_transactions',['branch_id'=>30]],
   ['agent_core_aggregate_mappings',['cloud_type'=>'pos_transaction']],['agent_core_aggregate_mappings',['branch_id'=>20]],
  ] as [$table,$change]) {
   DB::beginTransaction();DB::table($table)->where('id',$table==='fbr_pos_transactions'?101:1)->update($change);
   self::assertSame('INCONSISTENT_RETAINED_LINKS',$this->source()['status']);self::assertSame([],Evidence::bill(1,100,0,25)['rows']);DB::rollBack();
  }
 }
 public function test_unsupported_alias_schema_event_and_nonprojected_result_are_not_accepted():void {
  foreach([
   ['projection_status'=>'accepted'],['projection_status'=>'retryable'],['event_type'=>'sale.refunded'],
   ['payload'=>json_encode(['schema'=>'local-core.sale.v1','command_type'=>'refund.record','aggregate_id'=>'opaque-1'])],
   ['payload'=>json_encode(['schema'=>'local-core.customer-ledger.v1','command_type'=>'stock.restored','aggregate_id'=>'opaque-1'])],
  ] as $change) {
   DB::beginTransaction();DB::table('agent_core_events')->where('id',1)->update($change);self::assertSame('INCONSISTENT_RETAINED_LINKS',$this->source()['status']);DB::rollBack();
  }
 }
 public function test_json_numeric_coercion_and_opaque_case_aliases_never_make_links():void {
  foreach([
   ['projection_result'=>json_encode(['return_transaction_id'=>'101'])],
   ['projection_result'=>json_encode(['return_transaction_id'=>'101garbage'])],
   ['event_scope'=>json_encode(['company_id'=>'01','branch_id'=>10])],
   ['event_scope'=>json_encode(['company_id'=>1,'branch_id'=>'10 '])],
   ['payload'=>json_encode(['schema'=>'local-core.customer-ledger.v1','command_type'=>'refund.record','aggregate_id'=>'OPAQUE-1'])],
  ] as $change) {DB::beginTransaction();DB::table('agent_core_events')->where('id',1)->update($change);self::assertSame('INCONSISTENT_RETAINED_LINKS',$this->source()['status']);DB::rollBack();}
  DB::table('agent_core_events')->where('id',1)->update(['event_scope'=>json_encode(['company_id'=>'1','branch_id'=>'10'])]);self::assertSame('PARTIAL',$this->source()['status']);
 }
 public function test_deleted_parent_return_and_mapping_remain_unknown_not_reconstructed():void {
  foreach([['fbr_pos_transactions',100],['fbr_pos_transactions',101],['agent_core_aggregate_mappings',1]] as [$table,$id]) {
   DB::beginTransaction();DB::table($table)->where('id',$id)->delete();self::assertSame('INCONSISTENT_RETAINED_LINKS',$this->source()['status']);DB::rollBack();
  }
 }
 public function test_id_cursor_late_arrival_and_branch_filter_are_exact():void {
  $this->event(2);$first=$this->source(10,0,1);self::assertSame(1,$first['pagination']['next_after_id']);self::assertTrue($first['pagination']['has_more']);
  $this->event(3,'2028-01-01 01:00:00');$next=$this->source(10,1,5);self::assertSame([2,3],array_column($next['rows'],'id'));self::assertSame([], $this->source(30)['rows']);
  self::assertSame(3,Evidence::observation(1,'2028-01-01 00:00:00','2028-01-01 23:59:59')['retained_rows']);
 }
 public function test_missing_schema_and_no_retained_links_are_explicit():void {
  DB::table('agent_core_events')->delete();self::assertSame('NO_RETAINED_EVENTS',$this->source()['status']);Schema::drop('agent_core_aggregate_mappings');self::assertSame('UNAVAILABLE',$this->source()['status']);
 }
 public function test_registered_source_uses_bounded_adapter():void {
  $r=(new DiagnosticSourceEvidence)->read(['company_name'=>'Synthetic FBR','source'=>Evidence::SOURCE,'date_from'=>'2028-01-01','date_to'=>'2028-01-01','branch_id'=>10],[1]);self::assertSame('PARTIAL',$r['status']);self::assertSame([1],array_column($r['rows'],'id'));
 }
}

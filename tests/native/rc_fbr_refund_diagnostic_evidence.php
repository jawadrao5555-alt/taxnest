<?php
declare(strict_types=1);
$db=getenv('DB_DATABASE')?:'';$socket=getenv('DB_SOCKET')?:'';
if(getenv('APP_ENV')!=='rc-mariadb'||!getenv('LD_PRELOAD')||!preg_match('/^taxnest_rc_[a-z0-9_]+_full$/D',$db)||!preg_match('#^/tmp/taxnest-rc-mariadb-[a-z0-9_]+/run/mariadb.sock$#D',$socket)){fwrite(STDERR,"FAIL unsafe FBR refund diagnostic target\n");exit(2);}
require dirname(__DIR__,2).'/vendor/autoload.php';$app=require dirname(__DIR__,2).'/bootstrap/app.php';$app->make(\Illuminate\Contracts\Console\Kernel::class)->bootstrap();
use App\Models\Company;
use App\Services\Reporting\{FbrRetainedRefundEventEvidence as Evidence,DiagnosticBillEvidence,DiagnosticSourceEvidence};
use Illuminate\Support\Facades\{DB,Http};
use Illuminate\Support\Str;
Http::preventStrayRequests();
function refundProof(bool $ok,string $reason):void{if(!$ok)throw new RuntimeException($reason);}
$suffix=bin2hex(random_bytes(8));$stamp=now()->format('Y-m-d H:i:s');$day=now()->toDateString();
$company=Company::create(['name'=>'Synthetic native refund '.$suffix,'ntn'=>'REF-'.$suffix,'product_type'=>'fbrpos','fbr_reporting_enabled'=>false,'agent_enabled'=>false,'inventory_enabled'=>false]);$foreign=Company::create(['name'=>'Synthetic foreign refund '.$suffix,'ntn'=>'REFF-'.$suffix,'product_type'=>'fbrpos']);$pra=Company::create(['name'=>'Synthetic PRA collision '.$suffix,'ntn'=>'REFP-'.$suffix,'product_type'=>'pos']);
$branch=DB::table('branches')->insertGetId(['company_id'=>$company->id,'name'=>'Synthetic main','created_at'=>$stamp,'updated_at'=>$stamp]);$otherBranch=DB::table('branches')->insertGetId(['company_id'=>$company->id,'name'=>'Synthetic second','created_at'=>$stamp,'updated_at'=>$stamp]);$foreignBranch=DB::table('branches')->insertGetId(['company_id'=>$foreign->id,'name'=>'Synthetic foreign','created_at'=>$stamp,'updated_at'=>$stamp]);
$parent=max((int)DB::table('fbr_pos_transactions')->max('id'),(int)DB::table('pos_transactions')->max('id'))+100;
DB::table('fbr_pos_transactions')->insert(['id'=>$parent,'company_id'=>$company->id,'branch_id'=>$branch,'invoice_number'=>'SYN-REF-'.$suffix,'transaction_type'=>'sale','status'=>'completed','invoice_mode'=>'local','fbr_status'=>'local','created_at'=>$stamp,'updated_at'=>$stamp]);
DB::table('pos_transactions')->insert(['id'=>$parent,'company_id'=>$pra->id,'invoice_number'=>'SYN-REF-PRA-'.$suffix,'transaction_type'=>'sale','status'=>'completed','invoice_mode'=>'local','pra_status'=>'local','created_at'=>$stamp,'updated_at'=>$stamp]);
$actor=\App\Models\User::create(['company_id'=>$company->id,'name'=>'Synthetic refund owner','email'=>$suffix.'@refund-native.invalid','password'=>bcrypt('SyntheticOnly123!'),'role'=>'company_admin','pos_role'=>'pos_admin','is_active'=>true]);
DB::table('agent_core_aggregate_mappings')->insert(['company_id'=>$company->id,'branch_id'=>$branch,'local_type'=>'order','local_aggregate_id'=>'native-order-'.$suffix,'cloud_type'=>'fbr_pos_transaction','cloud_id'=>$parent,'created_at'=>$stamp,'updated_at'=>$stamp]);
$make=function(int $ordinal,?string $eventTime=null)use($company,$parent,$branch,$suffix,$stamp,$actor):array{
 // Canonical string scope and refund.record payload follow the existing
 // AgentCoreLedgerRefundProjectorTest acceptance fixture, but the inbox and
 // registered projector below produce the stored event/result/mapping here.
 $line=DB::table('fbr_pos_transaction_items')->insertGetId(['transaction_id'=>$parent,'item_name'=>'Synthetic refund line','quantity'=>1,'unit_price'=>100,'subtotal'=>100,'tax_amount'=>0,'total'=>100,'created_at'=>$stamp,'updated_at'=>$stamp]);
 DB::table('fbr_pos_transactions')->where('id',$parent)->update(['subtotal'=>100*$ordinal,'total_amount'=>100*$ordinal,'payment_method'=>'cash']);
 $lineAggregate='native-line-'.$suffix.'-'.$ordinal;
 DB::table('agent_core_aggregate_mappings')->insert(['company_id'=>$company->id,'branch_id'=>$branch,'local_type'=>'sale_line','local_aggregate_id'=>$lineAggregate,'cloud_type'=>'fbr_pos_transaction_item','cloud_id'=>$line,'created_at'=>$stamp,'updated_at'=>$stamp]);
 $opaque='native-refund-'.$suffix.'-'.$ordinal;$device='synthetic-native-refund';
 $wire=['event_id'=>(string)Str::uuid(),'idempotency_key'=>(string)Str::uuid(),'event_type'=>'customer.refund.posted','occurred_at'=>now()->toIso8601String(),
  'scope'=>['company_id'=>(string)$company->id,'branch_id'=>(string)$branch,'user_id'=>(string)$actor->id],
  'payload'=>['schema'=>'local-core.customer-ledger.v1','command_type'=>'refund.record','aggregate_id'=>$opaque,'aggregate_revision'=>1,
   'data'=>['order_id'=>'native-order-'.$suffix,'amount_cents'=>10000,'method'=>'cash','line_ids'=>[$lineAggregate],'private'=>'PRIVATE-REFUND-DATA']]];
 app(\App\Services\AgentCoreEventInboxService::class)->accept($company,$device,[$wire]);
 $stored=\App\Models\AgentCoreEvent::where('company_id',$company->id)->where('event_id',$wire['event_id'])->sole();
 refundProof((array)$stored->event_scope==$wire['scope']&&is_string($stored->event_scope['company_id'])&&is_string($stored->event_scope['branch_id']),'Inbox changed canonical refund scope serialization');
 $outcome=app(\App\Services\AgentCoreProjectorRegistry::class)->project($company,$device,$stored,$wire);
 refundProof($outcome->status==='projected'&&is_int($outcome->result['return_transaction_id']??null),'Registered refund projector failed to produce integer return identity');
 $stored->forceFill(['projection_status'=>$outcome->status,'projection_result'=>$outcome->result])->save();
 if($eventTime!==null)DB::table('agent_core_events')->where('id',$stored->id)->update(['created_at'=>$eventTime]);
 $return=$outcome->result['return_transaction_id'];$event=$stored->id;
 $mapping=(int)DB::table('agent_core_aggregate_mappings')->where('company_id',$company->id)->where('branch_id',$branch)->where('local_type','refund')->where('local_aggregate_id',$opaque)->value('id');
 return compact('return','mapping','event','opaque');
};
$first=$make(1);$source=fn(?int $branchId=null,int $after=0,int $limit=25)=>Evidence::source($company->id,$branchId,$day.' 00:00:00',$day.' 23:59:59',$after,$limit,[]);
$before=DB::table('agent_core_events')->where('id',$first['event'])->first();$report=app(DiagnosticBillEvidence::class)->read(['company_name'=>$company->name,'bill_id'=>$parent],[$company->id]);
refundProof($report['version']===8&&$report['sources']['projected_refund_events']['rows']===[['id'=>$first['event']]],'Exact FBR refund chain unavailable');refundProof(in_array('general_or_unretained_offline_agent_events',$report['unimplemented_links'],true),'General offline gap falsely closed');
refundProof(Evidence::bill($pra->id,$parent,0,25)['rows']===[],'Same PRA bill ID inferred FBR link');$safe=$source($branch);refundProof(array_keys($safe['rows'][0])===['id','event_at']&&!str_contains(json_encode($safe),'PRIVATE')&&!str_contains(json_encode($safe),'refund_total'),'Refund source exposed payload or amount');
$payload=fn(array $changes)=>json_encode(array_replace(json_decode($before->payload,true),$changes));
$cases=[
 ['agent_core_events',$first['event'],['projection_status'=>'accepted']],
 ['agent_core_events',$first['event'],['projection_status'=>'retryable']],
 ['agent_core_events',$first['event'],['event_type'=>'sale.refunded']],
 ['agent_core_events',$first['event'],['event_type'=>'CUSTOMER.REFUND.POSTED']],
 ['agent_core_events',$first['event'],['payload'=>$payload(['schema'=>'local-core.sale.v1'])]],
 ['agent_core_events',$first['event'],['payload'=>$payload(['schema'=>'LOCAL-CORE.CUSTOMER-LEDGER.V1'])]],
 ['agent_core_events',$first['event'],['payload'=>$payload(['command_type'=>'REFUND.RECORD'])]],
 ['agent_core_events',$first['event'],['payload'=>$payload(['aggregate_id'=>strtoupper($first['opaque'])])]],
 ['agent_core_events',$first['event'],['projection_result'=>json_encode(['return_transaction_id'=>(string)$first['return']])]],
 ['agent_core_events',$first['event'],['event_scope'=>json_encode(['company_id'=>'0'.$company->id,'branch_id'=>$branch])]],
 ['agent_core_events',$first['event'],['event_scope'=>json_encode(['company_id'=>$company->id,'branch_id'=>$branch.' '])]],
 ['agent_core_events',$first['event'],['event_scope'=>json_encode(['company_id'=>$foreign->id,'branch_id'=>$branch])]],
 ['fbr_pos_transactions',$first['return'],['company_id'=>$foreign->id]],
 ['fbr_pos_transactions',$first['return'],['branch_id'=>$otherBranch]],
 ['agent_core_aggregate_mappings',$first['mapping'],['cloud_type'=>'pos_transaction']],
 ['agent_core_aggregate_mappings',$first['mapping'],['cloud_type'=>'FBR_POS_TRANSACTION']],
 ['agent_core_aggregate_mappings',$first['mapping'],['local_type'=>'REFUND']],
 ['agent_core_aggregate_mappings',$first['mapping'],['branch_id'=>$foreignBranch]],
];
foreach($cases as$index=>[$table,$id,$changes]){DB::beginTransaction();try{DB::table($table)->where('id',$id)->update($changes);refundProof($source()['status']==='INCONSISTENT_RETAINED_LINKS'&&Evidence::bill($company->id,$parent,0,25)['rows']===[],'Forged refund chain accepted case '.$index);}finally{DB::rollBack();}}
foreach([['fbr_pos_transactions',$parent],['fbr_pos_transactions',$first['return']],['agent_core_aggregate_mappings',$first['mapping']]]as[$table,$id]){DB::beginTransaction();try{DB::table($table)->where('id',$id)->delete();refundProof($source()['status']==='INCONSISTENT_RETAINED_LINKS','Deleted refund chain reconstructed');}finally{DB::rollBack();}}
refundProof($before==DB::table('agent_core_events')->where('id',$first['event'])->first(),'Diagnostic reads or rollback altered event');
DB::table('agent_core_events')->where('id',$first['event'])->update(['event_scope'=>json_encode(['company_id'=>(string)$company->id,'branch_id'=>(string)$branch])]);refundProof($source()['status']==='PARTIAL','Canonical numeric scope strings rejected');
$second=$make(2);$page=$source($branch,0,1);refundProof($page['pagination']['next_after_id']===$first['event'],'Refund cursor wrong');$late=$make(3,$day.' 00:00:01');$next=$source($branch,$first['event']);refundProof(array_column($next['rows'],'id')===[$second['event'],$late['event']],'Late refund ID cursor omitted row');refundProof($source($otherBranch)['rows']===[],'Refund source crossed branch');
$registered=app(DiagnosticSourceEvidence::class)->read(['company_name'=>$company->name,'source'=>Evidence::SOURCE,'date_from'=>$day,'branch_id'=>$branch],[$company->id]);refundProof($registered['registry_version']===15&&count($registered['rows'])===3&&!$registered['coverage']['seven_day_complete'],'Registered refund source scope or version incorrect');
Http::assertNothingSent();echo "PASS: native FBR registered projected refund JSON integer binary identity exact tenant branch return-parent chain rejects forged aliases and missing links; IDs-only cursor includes late arrivals without provider calls\n";

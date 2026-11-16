<?php
namespace App\Services\Reporting;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\{DB,Schema};
use Illuminate\Support\Carbon;
/** Exact registered projected refund links only; no generic offline/physical claim. */
final class FbrRetainedRefundEventEvidence
{
 public const SOURCE='fbrpos.projected_refund_events';
 public static function available():bool {
  foreach(['agent_core_events'=>['id','company_id','event_type','payload','event_scope','projection_status','projection_result','created_at'],
   'agent_core_aggregate_mappings'=>['company_id','branch_id','local_type','local_aggregate_id','cloud_type','cloud_id'],
   'fbr_pos_transactions'=>['id','company_id','branch_id','transaction_type','parent_transaction_id'],
   'branches'=>['id','company_id'],'companies'=>['id','product_type']] as $table=>$cols)
   if(!Schema::hasTable($table)||array_diff($cols,Schema::getColumnListing($table)))return false;
  return true;
 }
 private static function candidates(int $company):Builder {
  return DB::table('agent_core_events as e')->where('e.company_id',$company)->whereJsonContainsKey('e.projection_result->return_transaction_id');
 }
 public static function validIds(int $company,?int $bill=null,?int $branch=null):Builder {
  $q=self::candidates($company)->join('companies as c','c.id','=','e.company_id')
   ->join('fbr_pos_transactions as r','r.id','=','e.projection_result->return_transaction_id')
   ->join('fbr_pos_transactions as p','p.id','=','r.parent_transaction_id')
   ->join('branches as b','b.id','=','r.branch_id')
   ->join('agent_core_aggregate_mappings as m',function($j){$j->on('m.cloud_id','=','r.id')->on('m.company_id','=','e.company_id')->on('m.branch_id','=','r.branch_id')->on('m.local_aggregate_id','=','e.payload->aggregate_id');})
   ->where('c.product_type','fbrpos')->where('e.projection_status','projected')
   ->where('e.event_type','customer.refund.posted')->where('e.payload->schema','local-core.customer-ledger.v1')->where('e.payload->command_type','refund.record')
   ->where('r.company_id',$company)->where('p.company_id',$company)->where('b.company_id',$company)
   ->where('r.transaction_type','return')->where('p.transaction_type','sale')
   ->whereColumn('p.branch_id','r.branch_id')->whereColumn('e.event_scope->branch_id','r.branch_id')->whereColumn('e.event_scope->company_id','e.company_id')
   ->where('m.local_type','refund')->where('m.cloud_type','fbr_pos_transaction');
  // JSON numeric/string coercion and case-insensitive MySQL collations must
  // not turn a forged opaque ID or noncanonical scope into a retained link.
  if(DB::connection()->getDriverName()==='mysql') {
   $q->whereRaw("JSON_TYPE(JSON_EXTRACT(e.projection_result, '$.return_transaction_id')) = 'INTEGER'")
     ->whereRaw("JSON_TYPE(JSON_EXTRACT(e.payload, '$.aggregate_id')) = 'STRING'")
     ->whereRaw("BINARY JSON_UNQUOTE(JSON_EXTRACT(e.payload, '$.aggregate_id')) = BINARY m.local_aggregate_id");
   foreach(['company_id'=>'e.company_id','branch_id'=>'r.branch_id'] as $key=>$column)
    $q->whereRaw("JSON_TYPE(JSON_EXTRACT(e.event_scope, '$.$key')) IN ('INTEGER','STRING')")
      ->whereRaw("BINARY JSON_UNQUOTE(JSON_EXTRACT(e.event_scope, '$.$key')) = BINARY CAST($column AS CHAR)");
  } else {
   $q->whereRaw("json_type(e.projection_result, '$.return_transaction_id') = 'integer'")
     ->whereRaw("json_type(e.payload, '$.aggregate_id') = 'text'")
     ->whereRaw("json_extract(e.payload, '$.aggregate_id') COLLATE BINARY = m.local_aggregate_id COLLATE BINARY");
   foreach(['company_id'=>'e.company_id','branch_id'=>'r.branch_id'] as $key=>$column)
    $q->whereRaw("json_type(e.event_scope, '$.$key') IN ('integer','text')")
      ->whereRaw("CAST(json_extract(e.event_scope, '$.$key') AS TEXT) = CAST($column AS TEXT)");
  }
  // Keep registry identifiers byte-exact even when retained columns use a
  // case-insensitive/padded collation. Bound constants, no user SQL or casts.
  $exact=['c.product_type'=>'fbrpos','e.projection_status'=>'projected',
   'e.event_type'=>'customer.refund.posted','m.local_type'=>'refund',
   'm.cloud_type'=>'fbr_pos_transaction','r.transaction_type'=>'return','p.transaction_type'=>'sale'];
  $mysql=DB::connection()->getDriverName()==='mysql';
  foreach(['schema'=>'local-core.customer-ledger.v1','command_type'=>'refund.record'] as $key=>$value)
   $exact[$mysql ? "JSON_UNQUOTE(JSON_EXTRACT(e.payload, '$.$key'))" : "json_extract(e.payload, '$.$key')"]=$value;
  foreach($exact as $expression=>$expected)
   $q->whereRaw($mysql ? "BINARY $expression = BINARY ?" : "$expression COLLATE BINARY = ?",[$expected]);
  if(Schema::hasColumn('companies','deleted_at'))$q->whereNull('c.deleted_at');
  if(Schema::hasColumn('fbr_pos_transactions','deleted_at'))$q->whereNull('r.deleted_at')->whereNull('p.deleted_at');
  if($bill!==null)$q->where('p.id',$bill);
  if($branch!==null)$q->where('r.branch_id',$branch);
  return $q->select('e.id')->distinct();
 }
 private static function window(int $company,string $from,string $to):Builder {return self::candidates($company)->whereBetween('e.created_at',[$from,$to]);}
 public static function observation(int $company,string $from,string $to):array {
  if(!self::available())return ['status'=>'UNAVAILABLE'];
  $q=self::window($company,$from,$to);
  if((clone $q)->whereNotIn('e.id',self::validIds($company))->exists())return ['status'=>'INCONSISTENT_RETAINED_LINKS'];
  $r=$q->selectRaw('COUNT(*) as n, MIN(e.created_at) as first_at, MAX(e.created_at) as last_at')->first();
  return ['status'=>$r->n?'OBSERVED':'NO_RETAINED_EVENTS','retained_rows'=>(int)$r->n,'first_source_at'=>$r->first_at,'last_source_at'=>$r->last_at];
 }
 public static function source(int $company,?int $branch,string $from,string $to,int $after,int $limit,array $meta):array {
  $empty=['observed'=>null,'rows'=>[],'pagination'=>['has_more'=>false,'next_after_id'=>null]];
  $meta['refund_evidence']=['basis'=>'registered_projected_return_and_original_bill_chain','general_offline_events'=>'UNKNOWN','physical_fiscal_acceptance'=>'NOT_VERIFIED','payloads'=>'NOT_RETURNED'];
  try {
   if(!self::available())return $meta+['status'=>'UNAVAILABLE']+$empty;
   $q=self::window($company,$from,$to);
   if((clone $q)->whereNotIn('e.id',self::validIds($company))->exists())return $meta+['status'=>'INCONSISTENT_RETAINED_LINKS']+$empty;
   $q->whereIn('e.id',self::validIds($company,null,$branch));
   $n=(clone $q)->count();$rows=$q->where('e.id','>',$after)->orderBy('e.id')->limit($limit+1)->get(['e.id','e.created_at']);$page=$rows->take($limit);$more=$rows->count()>$limit;
   return $meta+['status'=>$n?'PARTIAL':'NO_RETAINED_EVENTS','observed'=>['retained_rows_in_window'=>$n,'page_rows'=>$page->count(),'timestamp_column'=>'created_at'],
    'rows'=>$page->map(fn($r)=>['id'=>(int)$r->id,'event_at'=>Carbon::parse($r->created_at,config('app.timezone'))->setTimezone(config('reporting_connector.timezone','Asia/Karachi'))->toIso8601String()])->values()->all(),
    'pagination'=>['has_more'=>$more,'next_after_id'=>$more?(int)$page->last()->id:null]];
  }catch(\Illuminate\Database\QueryException){return $meta+['status'=>'SOURCE_QUERY_FAILED']+$empty;}
 }
 public static function bill(int $company,int $bill,int $after,int $limit):array {
  $empty=['rows'=>[],'has_more'=>false,'next_after_id'=>null];
  try {
   if(!self::available())return ['status'=>'UNAVAILABLE']+$empty;
   if(self::candidates($company)->whereNotIn('e.id',self::validIds($company))->exists())return ['status'=>'INCONSISTENT_RETAINED_LINKS']+$empty;
   $ids=self::validIds($company,$bill)->where('e.id','>',$after)->orderBy('e.id')->limit($limit+1)->pluck('e.id');$page=$ids->take($limit);$more=$ids->count()>$limit;
   return ['status'=>$ids->isEmpty()?'NO_RETAINED_LINKS':'PARTIAL','rows'=>$page->map(fn($id)=>['id'=>(int)$id])->values()->all(),'has_more'=>$more,'next_after_id'=>$more?(int)$page->last():null];
  }catch(\Illuminate\Database\QueryException){return ['status'=>'SOURCE_QUERY_FAILED']+$empty;}
 }
}

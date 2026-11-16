<?php
namespace App\Services\Reporting;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\{DB,Schema};
use Illuminate\Support\Carbon;
/** Explicit retained payment events only; never guesses payment-to-bill links. */
final class RetainedPaymentEvidence
{
 public const SOURCES=['di.payment_events','fbrpos.payment_allocations'];
 public static function available(string $source):bool {
  $tables=$source==='di.payment_events'?['customer_ledgers'=>['id','company_id','type','invoice_id','created_at']]:[
   'fbr_khata_settlements'=>['id','company_id','customer_id','settlement_ledger_id','credit_ledger_id','created_at'],
   'fbr_customer_ledgers'=>['id','company_id','customer_id','entry_type','transaction_id'],
   'fbr_pos_transactions'=>['id','company_id','customer_id'],
   'pos_customers'=>['id','company_id'],
  ];foreach($tables as$table=>$columns)if(!Schema::hasTable($table)||array_diff($columns,Schema::getColumnListing($table)))return false;
  if($source==='fbrpos.payment_allocations' && Schema::hasColumn('fbr_pos_transactions','branch_id') && (!Schema::hasTable('branches') || !Schema::hasColumns('branches',['id','company_id'])))return false;
  return true;
 }
 private static function fbrChain(int $company,string $kind,?int $bill=null,?int $branch=null):Builder {
  $q=DB::table('fbr_khata_settlements as a')->join('pos_customers as customer',function($j){$j->on('customer.id','=','a.customer_id')->on('customer.company_id','=','a.company_id');})->join('fbr_customer_ledgers as pay',function($j){$j->on('pay.id','=','a.settlement_ledger_id')->on('pay.company_id','=','a.company_id')->on('pay.customer_id','=','a.customer_id');})
   ->join('fbr_customer_ledgers as credit',function($j){$j->on('credit.id','=','a.credit_ledger_id')->on('credit.company_id','=','a.company_id')->on('credit.customer_id','=','a.customer_id');})
   ->join('fbr_pos_transactions as bill',function($j){$j->on('bill.id','=','credit.transaction_id')->on('bill.company_id','=','a.company_id')->on('bill.customer_id','=','a.customer_id');})
   ->where('a.company_id',$company)->where('pay.entry_type',$kind)->where('credit.entry_type','udhaar');
  if($bill!==null)$q->where('bill.id',$bill);
  if($branch!==null)$q->where('bill.branch_id',$branch);
  if(Schema::hasColumn('fbr_pos_transactions','branch_id'))$q->where(function($q)use($company){$q->whereNull('bill.branch_id')->orWhereExists(fn($b)=>$b->selectRaw('1')->from('branches as branch')->whereColumn('branch.id','bill.branch_id')->where('branch.company_id',$company));});
  return $q->select('a.id');
 }
 public static function candidates(string $source,int $company):Builder {
  if($source==='di.payment_events')return DB::table('customer_ledgers as e')->where('e.company_id',$company)->where('e.type','payment');
  // A properly attributed return adjustment is deliberately not a receipt.
  return DB::table('fbr_khata_settlements as e')->where('e.company_id',$company)->whereNotIn('e.id',self::fbrChain($company,'return_adjust'));
 }
 public static function validIds(string $source,int $company,?int $bill=null,?int $branch=null):Builder {
  return $source==='di.payment_events'?DB::table('customer_ledgers')->where('company_id',$company)->where('type','payment')->whereNull('invoice_id')->select('id'):self::fbrChain($company,'wasooli',$bill,$branch);
 }
 public static function window(string $source,int $company,string $from,string $to):Builder{return self::candidates($source,$company)->whereBetween('e.created_at',[$from,$to]);}
 public static function observation(string $source,int $company,string $from,string $to):array {
  if(!self::available($source))return ['status'=>'UNAVAILABLE'];
  $q=self::window($source,$company,$from,$to);
  if((clone$q)->whereNotIn('e.id',self::validIds($source,$company))->exists())return ['status'=>'INCONSISTENT_RETAINED_LINKS'];
  $row=$q->selectRaw('COUNT(*) as retained_rows, MIN(e.created_at) as first_source_at, MAX(e.created_at) as last_source_at')->first();
  return ['status'=>(int)$row->retained_rows?'OBSERVED':'NO_RETAINED_EVENTS','retained_rows'=>(int)$row->retained_rows,'first_source_at'=>$row->first_source_at,'last_source_at'=>$row->last_source_at];
 }
 public static function source(string $source,int $company,?int $branch,string $from,string $to,int $after,int $limit,array $meta):array {
  $empty=['observed'=>null,'rows'=>[],'pagination'=>['has_more'=>false,'next_after_id'=>null]];
  $meta['payment_evidence']=['basis'=>$source==='di.payment_events'?'tenant_payment_event_without_invoice_link':'explicit_wasooli_credit_allocation','bill_linkage'=>$source==='di.payment_events'?'UNAVAILABLE':'explicit_retained_chain','cash_or_bank_acceptance'=>'NOT_VERIFIED','amounts'=>'NOT_RETURNED'];
  try{
   if(!self::available($source))return $meta+['status'=>'UNAVAILABLE']+$empty;
   if($branch!==null&&($source==='di.payment_events'||!Schema::hasColumn('fbr_pos_transactions','branch_id')))return $meta+['status'=>'BRANCH_SCOPE_UNAVAILABLE']+$empty;
   $q=self::window($source,$company,$from,$to);
   if((clone$q)->whereNotIn('e.id',self::validIds($source,$company))->exists())return $meta+['status'=>'INCONSISTENT_RETAINED_LINKS']+$empty;
   if($branch!==null)$q->whereIn('e.id',self::validIds($source,$company,null,$branch));
   $extent=(clone$q)->selectRaw('COUNT(*) as n, MIN(e.created_at) as first_at, MAX(e.created_at) as last_at')->first();
   $rows=$q->where('e.id','>',$after)->orderBy('e.id')->limit($limit+1)->get(['e.id','e.created_at']);$page=$rows->take($limit);$more=$rows->count()>$limit;
   return $meta+['status'=>$extent->n?'PARTIAL':'NO_RETAINED_EVENTS','observed'=>['retained_rows_in_window'=>(int)$extent->n,'earliest_in_window'=>$extent->first_at,'latest_in_window'=>$extent->last_at,'timestamp_column'=>'created_at','page_rows'=>$page->count()],
    'rows'=>$page->map(fn($r)=>['id'=>(int)$r->id,'event_at'=>Carbon::parse($r->created_at,config('app.timezone'))->setTimezone(config('reporting_connector.timezone','Asia/Karachi'))->toIso8601String()])->values()->all(),
    'pagination'=>['has_more'=>$more,'next_after_id'=>$more?(int)$page->last()->id:null]];
  }catch(\Illuminate\Database\QueryException){return $meta+['status'=>'SOURCE_QUERY_FAILED']+$empty;}
 }
 public static function bill(int $company,int $bill,int $after,int $limit):array {
  $empty=['rows'=>[],'has_more'=>false,'next_after_id'=>null];$source='fbrpos.payment_allocations';
  try{
   if(!self::available($source))return ['status'=>'UNAVAILABLE']+$empty;
   // Check the full tenant chain before presenting a subset; corrupt links do
   // not become reassuring zero-payment evidence for a selected bill.
   if(self::candidates($source,$company)->whereNotIn('e.id',self::validIds($source,$company))->exists())return ['status'=>'INCONSISTENT_RETAINED_LINKS']+$empty;
   $ids=self::validIds($source,$company,$bill)->where('a.id','>',$after)->orderBy('a.id')->limit($limit+1)->pluck('a.id');$page=$ids->take($limit);$more=$ids->count()>$limit;
   return ['status'=>$ids->isEmpty()?'NO_RETAINED_LINKS':'PARTIAL','rows'=>$page->map(fn($id)=>['id'=>(int)$id])->values()->all(),'has_more'=>$more,'next_after_id'=>$more?(int)$page->last():null];
  }catch(\Illuminate\Database\QueryException){return ['status'=>'SOURCE_QUERY_FAILED']+$empty;}
 }
}

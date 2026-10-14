<?php
namespace App\Services;
use App\Models\{Company, Invoice, PosTransaction, FbrPosTransaction, PaymentProof, Subscription, User};
use Illuminate\Database\Eloquent\Builder;
class AdminDashboardDetail {
 public const LABELS=['total_companies'=>'Total Companies','di_companies'=>'DI Companies','pos_companies'=>'PRA POS','fbrpos_companies'=>'FBR POS','pending_companies'=>'Pending','pending_payment_proofs'=>'Pending Payments','suspended_companies'=>'Suspended','binned_companies'=>'In Bin','di_invoices'=>'DI Invoices','pos_transactions'=>'PRA POS Transactions','fbrpos_transactions'=>'FBR POS Transactions','active_subscriptions'=>'Active Subscriptions','total_users'=>'Total Users'];
 public function definition(string $metric): array {
  abort_unless(isset(self::LABELS[$metric]),404);
  $companies=in_array($metric,['total_companies','di_companies','pos_companies','fbrpos_companies','pending_companies','suspended_companies','binned_companies']);
  $q=match($metric){
   'total_companies'=>Company::query(), 'di_companies'=>Company::where('product_type','di'), 'pos_companies'=>Company::where('product_type','pos'), 'fbrpos_companies'=>Company::where('product_type','fbrpos'),
   'pending_companies'=>Company::where('status','pending'),'suspended_companies'=>Company::where('status','suspended'),'binned_companies'=>Company::onlyTrashed(),
   'di_invoices'=>Invoice::query(),'pos_transactions'=>PosTransaction::where('status','completed'),'fbrpos_transactions'=>FbrPosTransaction::query(),
   'pending_payment_proofs'=>PaymentProof::where('status','pending'),'active_subscriptions'=>Subscription::where('active',true),'total_users'=>User::query()};
  $reference=$companies?'name':match($metric){'di_invoices','pos_transactions','fbrpos_transactions'=>'invoice_number','total_users'=>'name',default=>null};
  $status=match($metric){'total_users'=>'role','active_subscriptions'=>'active',default=>'status'};
  $amount=match($metric){'di_invoices','pos_transactions','fbrpos_transactions'=>'total_amount','pending_payment_proofs'=>'amount','active_subscriptions'=>'final_price',default=>null};
  return compact('q','companies','reference','status','amount')+['title'=>self::LABELS[$metric],'metric'=>$metric];
 }
 public function filtered(array $d,array $filters): Builder {
  $q=clone $d['q'];$table=$q->getModel()->getTable();
  if(!empty($filters['q'])) $q->where(function($q) use($d,$filters,$table){
   if($d['reference']) $q->where($table.'.'.$d['reference'],'like','%'.$filters['q'].'%');
   if(ctype_digit($filters['q'])) $q->orWhere($table.'.id',(int)$filters['q']);
   if(!$d['companies']) $q->orWhereHas('company',fn($q)=>$q->where('name','like','%'.$filters['q'].'%'));
  });
  foreach(['from'=>'>=','to'=>'<='] as $key=>$op) if(!empty($filters[$key])) $q->whereDate($table.'.created_at',$op,$filters[$key]);
  if(isset($filters['status']) && $filters['status']!=='') $q->where($table.'.'.$d['status'],$filters['status']);
  if(!empty($filters['company_id'])) $q->where($table.'.'.($d['companies']?'id':'company_id'),$filters['company_id']);
  if(!empty($filters['product'])) {
   if($d['companies']) $q->where('product_type',$filters['product']);
   else $q->whereHas('company',fn($q)=>$q->where('product_type',$filters['product']));
  }
  if($d['amount']) foreach(['min'=>'>=','max'=>'<='] as $key=>$op) if(isset($filters[$key]) && $filters[$key]!=='') $q->where($table.'.'.$d['amount'],$op,$filters[$key]);
  if(in_array($d['metric'],['pos_transactions','fbrpos_transactions'])) {
   if(!empty($filters['mode'])) $q->where('invoice_mode',$filters['mode']);
   $fiscal=$d['metric']==='pos_transactions'?'pra_status':'fbr_status';
   if(!empty($filters['fiscal_status'])) $q->where($fiscal,$filters['fiscal_status']);
  }
  return $q;
 }
 public function revenue(Builder $q,array $d): ?float {
  if(!$d['amount']) return null;
  $sum=clone $q; if($d['metric']==='di_invoices') $sum->where('status','locked');
  return (float)$sum->sum($d['amount']);
 }
 /** Explicit fields only: never export keys, passwords, bank proof files or raw fiscal responses. */
 public function row($record,array $d): array {
  $company=$d['companies']?$record:$record->company;
  return ['id'=>$record->id,'company'=>$company?->name ?? 'Unavailable / removed company','company_id'=>$d['companies']?$record->id:$record->company_id,
   'product'=>$company?->product_type ?? '', 'reference'=>$d['reference']?$record->{$d['reference']}:('Record '.$record->id),
   'status'=>$d['status']==='active'?($record->active?'Active':'Inactive'):$record->{$d['status']},
   'amount'=>$d['amount']?$record->{$d['amount']}:null,'created_at'=>$record->created_at?->format('Y-m-d H:i:s'),
   'fiscal_number'=>$record->fbr_invoice_number ?? $record->pra_invoice_number ?? '', 'fiscal_status'=>$record->fbr_status ?? $record->pra_status ?? '',
   'mode'=>$record->invoice_mode ?? '', 'subscription_start'=>$record->start_date?->format('Y-m-d'), 'subscription_end'=>$record->end_date?->format('Y-m-d')];
 }
}

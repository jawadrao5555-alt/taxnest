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
   'di_invoices'=>Invoice::whereHas('company',fn($q)=>$q->where('product_type','di')),'pos_transactions'=>PosTransaction::whereHas('company',fn($q)=>$q->where('product_type','pos'))->where('status','completed'),'fbrpos_transactions'=>FbrPosTransaction::whereHas('company',fn($q)=>$q->where('product_type','fbrpos')),
   'pending_payment_proofs'=>PaymentProof::where('status','pending'),'active_subscriptions'=>Subscription::where('active',true),'total_users'=>User::query()};
  $reference=$companies?'name':match($metric){'di_invoices','pos_transactions','fbrpos_transactions'=>'invoice_number','total_users'=>'name',default=>null};
  $status=match($metric){'total_users'=>'role','active_subscriptions'=>'active',default=>'status'};
  $amount=match($metric){'di_invoices','pos_transactions','fbrpos_transactions'=>'total_amount','pending_payment_proofs'=>'amount','active_subscriptions'=>'final_price',default=>null};
  $isInvoice=in_array($metric,['di_invoices','pos_transactions','fbrpos_transactions']);
  $fixedProduct=match($metric){'di_invoices','di_companies'=>'di','pos_transactions','pos_companies'=>'pos','fbrpos_transactions','fbrpos_companies'=>'fbrpos',default=>null};
  $statusLabel=match($metric){'di_invoices'=>'Invoice status','pos_transactions','fbrpos_transactions'=>'Transaction status','total_users'=>'Role','active_subscriptions'=>'Subscription status',default=>'Status'};
  $explanation=match($metric){
   'di_invoices'=>'DI companies only. Revenue includes locked invoices. Production/Sandbox describes the saved environment; it does not by itself prove FBR reporting. A fiscal number is shown separately.',
   'pos_transactions'=>'PRA POS companies and completed transactions only. Saved invoice mode is routing, not proof of reporting. Check the fiscal number and saved PRA status separately.',
   'fbrpos_transactions'=>'FBR POS companies only, including all existing transaction statuses and modes. Saved mode is routing, not proof of reporting. Check the fiscal number and saved FBR status separately.',
   default=>'All-time totals appear when no filters are selected. Dates use record creation time. Counts and CSV use the selected filters.'};
  return compact('q','companies','reference','status','amount')+['title'=>self::LABELS[$metric],'metric'=>$metric,'is_invoice'=>$isInvoice,'fixed_product'=>$fixedProduct,'status_label'=>$statusLabel,'explanation'=>$explanation,'column_count'=>($isInvoice || $metric==='active_subscriptions')?8:7];
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
   if(!empty($filters['billing_state'])) {
    $state=AdminPosBillDisposition::sql($d['fixed_product']);
    if($filters['billing_state']==='local_unreported') $q->whereRaw("($state) <> ?",['reported']);
    else $q->whereRaw("($state) = ?",[$filters['billing_state']]);
   }
   if(!empty($filters['branch_id'])) $q->where($table.'.branch_id',$filters['branch_id']);
   if(!empty($filters['payment_method'])) $q->where($table.'.payment_method',$filters['payment_method']);
   foreach(['business_from'=>'>=','business_to'=>'<='] as $key=>$op) if(!empty($filters[$key])) $q->whereDate($table.'.business_date',$op,$filters[$key]);

  }
  if($d['metric']==='di_invoices' && !empty($filters['environment'])) $q->where('fbr_status',$filters['environment']);
  return $q;
 }
 /** Existing values remain selectable: never silently discard unknown legacy states. */
 public function filterOptions(array $d): array {
  if(!in_array($d['metric'],['pos_transactions','fbrpos_transactions'])) return [];
  $options=[];
  foreach(['mode'=>'invoice_mode','payment_method'=>'payment_method','fiscal_status'=>$d['metric']==='pos_transactions'?'pra_status':'fbr_status'] as $key=>$column) {
   $options[$key]=(clone $d['q'])->whereNotNull($column)->where($column,'!=','')->distinct()->orderBy($column)->pluck($column)->all();
  }
  return $options;
 }
 public function revenue(Builder $q,array $d): ?float {
  if(!$d['amount']) return null;
  $sum=clone $q; if($d['metric']==='di_invoices') $sum->where('status','locked');
  return (float)$sum->sum($d['amount']);
 }
 /** Explicit fields only: never export keys, passwords, bank proof files or raw fiscal responses. */
 public function row($record,array $d): array {
  $company=$d['companies']?$record:$record->company;
  $row=['id'=>$record->id,'company'=>$company?->name ?? 'Unavailable / removed company','company_id'=>$d['companies']?$record->id:$record->company_id,
   'product'=>$company?->product_type ?? '', 'reference'=>$d['reference']?$record->{$d['reference']}:('Record '.$record->id),
   'status'=>$d['status']==='active'?($record->active?'Active':'Inactive'):$record->{$d['status']},
   'amount'=>$d['amount']?$record->{$d['amount']}:null,'created_at'=>$record->created_at?->format('Y-m-d H:i:s'),
   'fiscal_number'=>$record->fbr_invoice_number ?? $record->pra_invoice_number ?? '', 'fiscal_status'=>$record->fbr_status ?? $record->pra_status ?? '',
   'mode'=>$record->invoice_mode ?? '', 'subscription_start'=>$record->start_date?->format('Y-m-d'), 'subscription_end'=>$record->end_date?->format('Y-m-d')];
  if($d['metric']!=='active_subscriptions') unset($row['subscription_start'],$row['subscription_end']);
  if(!$d['is_invoice']) unset($row['fiscal_number'],$row['fiscal_status'],$row['mode']);
  if(in_array($d['metric'],['pos_transactions','fbrpos_transactions'])) $row['billing_state']=AdminPosBillDisposition::LABELS[AdminPosBillDisposition::state($record,$d['fixed_product'])];
  if($d['metric']==='di_invoices') $row['environment']=in_array($record->fbr_status,['production','sandbox'],true)?$record->fbr_status:'Not recorded / other';
  return $row;
 }
}

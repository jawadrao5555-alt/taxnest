<?php
namespace App\Services;
use App\Models\{Company,Invoice,Subscription,PosTransaction};
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
/** v1-only current-read capacity check, inside the same transaction as claim/write. */
final class ProspectiveQuotaGuard {
 /** Lock ordering only: recording a real acknowledgement must not depend on access/cap status. */
 public static function lockCompanyForWrite(int $companyId): bool {
  if(!ProspectiveSubscriptionPolicy::available())return false;
  $hint=Subscription::where('company_id',$companyId)->latest('id')->first();
  if(!$hint || !$hint->hasLifecycleSnapshot())return false;
  if(DB::transactionLevel()<1)throw new \LogicException('Invoice lock ordering requires a transaction.');
  Company::withoutGlobalScopes()->whereKey($companyId)->lockForUpdate()->firstOrFail();
  return true;
 }
 public static function lockCompanyForInvoiceWork(int $companyId): void {self::lockCompanyForWrite($companyId);}
 public static function context(int $companyId, bool $requireAccess=true): ?array {
  if(DB::transactionLevel()<1) throw new \LogicException('Quota guard requires the write transaction.');
  if(!ProspectiveSubscriptionPolicy::available())return null;
  $hint=Subscription::where('company_id',$companyId)->where('active',true)->latest('id')->first() ?? Subscription::where('company_id',$companyId)->latest('id')->first();
  if(!$hint || !$hint->hasLifecycleSnapshot()) return null; // SQL NULL legacy stays unchanged.
  $company=Company::whereKey($companyId)->lockForUpdate()->firstOrFail();
  $sub=Subscription::where('company_id',$companyId)->where('active',true)->latest('id')->lockForUpdate()->first();
  $policy=ProspectiveSubscriptionPolicy::snapshot($sub);
  if(!$policy) self::deny('Subscription policy evidence requires review.');
  if($requireAccess) self::assertAccess(['company'=>$company,'subscription'=>$sub]);
  $limit=$company->invoice_limit_override!==null?(int)$company->invoice_limit_override:(int)($sub->entitlementPlan()->invoice_limit??($company->product_type==='di'?0:-1));
  if($company->is_internal_account || ($company->invoice_limit_override===null && $company->product_type!=='di' && $limit<=0)) $limit=-1;
  $from=CarbonImmutable::now(config('app.timezone'))->startOfMonth();
  if(!empty($policy['first_paid_starts_at']))$from=$from->max(CarbonImmutable::parse($policy['first_paid_starts_at'])->setTimezone(config('app.timezone')));
  return ['company'=>$company,'subscription'=>$sub,'policy'=>$policy,'from'=>$from,'limit'=>$limit];
 }
 public static function assertAccess(?array $context): void {
  if(!$context)return;
  $access=SubscriptionAccessService::hasAccess($context['company'],false,$context['subscription']);
  if(!($access['allowed']??false))self::deny($access['reason']??'Subscription access denied.');
 }
 public static function deny(string $reason='Monthly package bill limit reached.'): never { throw ValidationException::withMessages(['subscription'=>$reason]); }
 public static function assertPra(int $companyId, ?array $context=null): void {
  $context??=self::context($companyId); if(!$context)return; self::assertAccess($context); if($context['limit']<0)return;
  $live=PosTransaction::withoutGlobalScope('hide_archived')->where('company_id',$companyId)->where('status','completed')
   ->where(fn($q)=>$q->whereNull('invoice_mode')->orWhere('invoice_mode','!=','local'))
   ->where(fn($q)=>$q->whereNull('transaction_type')->orWhere('transaction_type','!=','return'))
   ->whereBetween('created_at',[$context['from'],now()->endOfMonth()])->orderBy('id')->lockForUpdate()->get(['id'])->count();
  $deleted=ProspectiveQuotaRetention::deletedCount($companyId,true,$context['from']);
  if($live+$deleted >= $context['limit'])self::deny();
 }
 public static function assertLocalCollection(int $companyId, ?array $context): void {
  if(!$context)return; self::assertAccess($context); if($context['limit']<0)return;
  $table=$context['company']->isSchoolFeePilot()?'school_fee_receipts':($context['company']->isHospitalCollectionPilot()?'hospital_invoices':null);
  if(!$table)self::deny('Collection category requires review.');
  $count=DB::table($table)->where('company_id',$companyId)->whereBetween('created_at',[$context['from'],now()->endOfMonth()])->orderBy('id')->lockForUpdate()->get(['id'])->count();
  if ($table === 'hospital_invoices' && \Illuminate\Support\Facades\Schema::hasTable('hospital_invoice_corrections')) {
   $count += DB::table('hospital_invoice_corrections')->where('company_id', $companyId)->where('action', 'void')
    ->whereBetween('invoice_created_at', [$context['from'], now()->endOfMonth()])->count();
  }
  if($count >= $context['limit'])self::deny();
 }
 public static function assertDi(Invoice $invoice, ?string $environment, ?array $context): void {
  if(!$context)return;
  self::assertAccess($context);
  foreach(['submitted_at','fiscal_submission_environment','fiscal_submission_state'] as $column) if(!\Illuminate\Support\Facades\Schema::hasColumn('invoices',$column))self::deny('Fiscal claim evidence schema requires review.');
  if(!in_array($environment,['production','sandbox','demo'],true))self::deny('Requested fiscal environment is required before quota reservation.');
  if($environment!=='production' || $context['limit']<0)return;
  // The canonical persisted fiscal lease IS the reservation. Unknown outcomes
  // keep capacity; explicit rejection/simulation releases it through existing state.
  $rows=Invoice::withoutGlobalScopes()->where('company_id',$invoice->company_id)
   ->whereRaw('COALESCE(submitted_at,created_at) >= ?',[$context['from']])
   ->whereRaw('COALESCE(submitted_at,created_at) <= ?',[now()->endOfMonth()])
   ->where(function($q){$q->whereIn('fbr_status',PlanLimitService::COUNTED_FBR_STATUSES)
     ->orWhere(fn($w)=>$w->where('fiscal_submission_environment','production')->where(fn($x)=>$x->where('is_fbr_processing',true)->orWhere('status','pending_verification')->orWhereIn('fiscal_submission_state',[DiFiscalSubmissionState::SUBMITTING,DiFiscalSubmissionState::VERIFICATION_REQUIRED])));})
   ->orderBy('id')->lockForUpdate()->get(['id']);
  if($rows->count()>=$context['limit'])self::deny();
 }
}

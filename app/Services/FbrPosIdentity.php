<?php
namespace App\Services;
use App\Models\Company;
use App\Models\FbrPosTransaction;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Validation\ValidationException;
class FbrPosIdentity {
 public function ready(): bool { return Schema::hasTable('fbr_pos_identities') && Schema::hasColumn('fbr_pos_transactions','fbr_identity_captured_at'); }
 public function resolve(FbrPosTransaction $bill): array {
  if ($bill->fbr_identity_captured_at) return ['pos_id'=>$bill->fbr_pos_id_snapshot,'device_uid'=>$bill->fbr_device_uid_snapshot];
  $company=$bill->company;
  if (!$company || (int)$company->id !== (int)$bill->company_id) throw ValidationException::withMessages(['identity'=>'Company identity is unavailable.']);
  if ($bill->terminal_id) {
   $terminal=DB::table('fbr_pos_terminals')->where('company_id',$bill->company_id)->where('id',$bill->terminal_id)->first();
   if (!$terminal || ($terminal->branch_id && (int)$terminal->branch_id !== (int)$bill->branch_id)) throw ValidationException::withMessages(['identity'=>'Terminal does not belong to this company and branch.']);
  }
  $mapping=$this->ready() ? DB::table('fbr_pos_identities')->where('company_id',$bill->company_id)->whereIn('branch_id',[0,(int)$bill->branch_id])
   ->where(function($q) use($bill) { $q->where('terminal_id',0); if($bill->terminal_id) $q->orWhere('terminal_id',$bill->terminal_id); })
   ->orderByDesc('terminal_id')->orderByDesc('branch_id')->first() : null;
  $pos=(string)($mapping->pos_id ?? preg_replace('/[^0-9]/','',(string)$company->fbr_pos_id));
  return ['pos_id'=>$pos,'device_uid'=>$mapping->device_uid ?? null];
 }
 public function capture(FbrPosTransaction $bill): array {
  if (!$this->ready()) return $this->resolve($bill);
  return DB::transaction(function() use($bill) {
   $locked=FbrPosTransaction::withoutGlobalScopes()->where('company_id',$bill->company_id)->whereKey($bill->id)->lockForUpdate()->firstOrFail();
   $identity=$this->resolve($locked);
   if (!$locked->fbr_identity_captured_at && !$locked->fbr_invoice_number && $locked->invoice_mode !== 'local' && $identity['pos_id'] !== '') {
    $locked->forceFill(['fbr_pos_id_snapshot'=>$identity['pos_id'],'fbr_device_uid_snapshot'=>$identity['device_uid'],'fbr_identity_captured_at'=>now()])->save();
   }
   $bill->setRawAttributes($locked->getAttributes(),true);
   return $identity;
  });
 }
 /** Filter BEFORE the poll's limit; a busy branch must not starve another. */
 public function route(Builder $query, int $companyId, ?string $uid): Builder {
  if (!$this->ready()) return $query;
  $maps=DB::table('fbr_pos_identities')->where('company_id',$companyId)->get();
  $terminals=$maps->where('terminal_id','>',0)->pluck('terminal_id')->all();
  $branches=$maps->where('terminal_id',0)->pluck('branch_id')->all();
  return $query->where(function($q) use($uid,$maps,$terminals,$branches) {
   $q->where(function($q) use($uid) { $q->whereNotNull('fbr_identity_captured_at')->where(function($q) use($uid) { $q->whereNull('fbr_device_uid_snapshot'); if($uid) $q->orWhere('fbr_device_uid_snapshot',$uid); }); });
   $q->orWhere(function($q) use($uid,$maps,$terminals,$branches) {
    $q->whereNull('fbr_identity_captured_at')->where(function($q) use($uid,$maps,$terminals,$branches) {
     $q->where(function($q) use($terminals,$branches) {
      $q->where(fn($q)=>$q->whereNull('terminal_id')->orWhereNotIn('terminal_id',$terminals));
      $q->where(fn($q)=>$q->whereNull('branch_id')->orWhereNotIn('branch_id',$branches));
     });
     if($uid) foreach($maps->where('device_uid',$uid) as $map) $q->orWhere(function($q) use($map,$terminals) {
      if($map->terminal_id) $q->where('terminal_id',$map->terminal_id);
      else $q->where('branch_id',$map->branch_id)->where(fn($q)=>$q->whereNull('terminal_id')->orWhereNotIn('terminal_id',$terminals));
     });
    });
   });
  });
 }
}

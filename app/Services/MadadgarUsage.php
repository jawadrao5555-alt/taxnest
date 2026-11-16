<?php
namespace App\Services;
use App\Models\Company;
use Brick\Math\{BigDecimal,RoundingMode};
use Illuminate\Support\Facades\{DB,Schema};

/** Metadata only. Never store questions, answers, tool arguments, keys or raw errors. */
final class MadadgarUsage {
 public const MODEL='gpt-4o-mini';
 public static function available():bool{return Schema::hasTable('madadgar_usage_events');}
 private static function decimal(mixed $v):BigDecimal{return BigDecimal::of((string)$v);}
 private static function price(int $input,int $output,mixed $inputRate,mixed $outputRate):string {
  return (string)self::decimal($inputRate)->multipliedBy($input)->plus(self::decimal($outputRate)->multipliedBy($output))->dividedBy('1000000',6,RoundingMode::CEILING);
 }
 public static function begin(?Company $company,int $inputBound,int $outputBound=600):?int {
  if(!$company)return null;
  if(!self::available()){
   // Only a genuinely pre-feature installation may retain legacy behaviour.
   // Partial schema loss must never turn a configured cap into unlimited calls.
   if(Schema::hasTable('madadgar_budget_policies') || (Schema::hasTable('migrations') && DB::table('migrations')->where('migration','2026_12_24_000000_create_madadgar_usage_budget')->exists()))throw new \RuntimeException('Madadgar company AI budget unavailable');
   return null;
  }
  if($inputBound<0||$inputBound>100000||$outputBound!==600)throw new \RuntimeException('Unsupported AI usage envelope');
  $result=DB::transaction(function()use($company,$inputBound,$outputBound){
   Company::whereKey($company->id)->lockForUpdate()->firstOrFail();
   $policy=DB::table('madadgar_budget_policies')->where('company_id',$company->id)->lockForUpdate()->first();
   $rates=$policy && $policy->model===self::MODEL && $policy->input_per_million!==null && $policy->output_per_million!==null;
   $reserve=$rates?self::price($inputBound,$outputBound,$policy->input_per_million,$policy->output_per_million):null;
   $period=now('UTC')->format('Y-m');$reason=null;
   if($policy?->monthly_budget!==null){
    if(!$rates)$reason='budget_rates_unavailable';
    else {
     $used=BigDecimal::zero();
     // Current reads after company mutex prevent REPEATABLE READ overspending.
     foreach(DB::table('madadgar_usage_events')->where('company_id',$company->id)->where('period',$period)->where('source','openai')->orderBy('id')->lockForUpdate()->get()as$row){
      if(in_array($row->state,['budget_blocked'],true))continue;
      if($row->error_code==='usage_bound_exceeded'){$reason='budget_usage_unknown';break;}
      if($row->estimated_cost===null && $row->reserved_cost===null){$reason='budget_usage_unknown';break;}
      $used=$used->plus(self::decimal($row->estimated_cost??$row->reserved_cost));
     }
     if(!$reason && $used->plus(self::decimal($reserve))->isGreaterThan($policy->monthly_budget))$reason='budget_exhausted';
    }
   }
   $id=DB::table('madadgar_usage_events')->insertGetId(['company_id'=>$company->id,'period'=>$period,'source'=>'openai','model'=>self::MODEL,'input_bound'=>$inputBound,'output_bound'=>$outputBound,'state'=>$reason?'budget_blocked':'reserved','error_code'=>$reason,'input_rate'=>$rates?$policy->input_per_million:null,'output_rate'=>$rates?$policy->output_per_million:null,'reserved_cost'=>$reason?null:$reserve,'currency'=>$rates?'USD':null,'created_at'=>now(),'updated_at'=>now()]);
   return ['id'=>$id,'reason'=>$reason];
  });
  return self::checked($result);
 }
 private static function checked(array $result):int {
  if($result['reason'])throw new \RuntimeException('Madadgar company AI budget unavailable');
  return $result['id'];
 }
 public static function finish(?int $id,int $latency,?array $usage,?string $error=null):void {
  if(!$id)return;
  if($error!==null&&!in_array($error,['provider_transport_error','provider_http_error','provider_malformed'],true))$error='provider_transport_error';
  DB::transaction(function()use($id,$latency,$usage,$error){
   $event=DB::table('madadgar_usage_events')->where('id',$id)->first();if(!$event)return;
   Company::whereKey($event->company_id)->lockForUpdate()->firstOrFail();
   $event=DB::table('madadgar_usage_events')->where('id',$id)->lockForUpdate()->first();if($event->state!=='reserved')return;
   $input=$usage['prompt_tokens']??null;$output=$usage['completion_tokens']??null;
   $known=is_int($input)&&$input>=0&&is_int($output)&&$output>=0;
   if($known&&($input>$event->input_bound||$output>$event->output_bound))$error='usage_bound_exceeded';
   $cost=$known&&$event->input_rate!==null&&$event->output_rate!==null?self::price($input,$output,$event->input_rate,$event->output_rate):null;
   DB::table('madadgar_usage_events')->where('id',$id)->update(['state'=>$error?'error':($known?'completed':'usage_unknown'),'error_code'=>$error,'latency_ms'=>max(0,min($latency,4294967295)),'input_tokens'=>$known?$input:null,'output_tokens'=>$known?$output:null,'estimated_cost'=>$cost,'updated_at'=>now()]);
  });
 }
 public static function local(?Company $company,string $source,int $latency):bool {
  try {
   if(!$company||!self::available())return false;
   DB::table('madadgar_usage_events')->insert(['company_id'=>$company->id,'period'=>now('UTC')->format('Y-m'),'source'=>in_array($source,['local','cache','fallback','scoped'],true)?$source:'scoped','state'=>'local','latency_ms'=>max(0,min($latency,4294967295)),'created_at'=>now(),'updated_at'=>now()]);
   return true;
  } catch(\Throwable) {
   // Non-provider diagnostics must not destroy a completed safe scoped answer.
   // No raw error, prompt, answer or identifiers are written to the fallback log.
   try { \Illuminate\Support\Facades\Log::warning('Madadgar local usage observation unavailable'); } catch(\Throwable) {}
   return false;
  }
 }
}

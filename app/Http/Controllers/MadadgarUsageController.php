<?php
namespace App\Http\Controllers;
use App\Models\{AdminUser,Company,FeatureSuggestion};
use App\Services\MadadgarUsage;
use Brick\Math\BigDecimal;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
final class MadadgarUsageController extends Controller {
 private function company(Request $r,?int $companyId):Company {
  if($companyId!==null){AdminUser::requireSensitiveConfigurationAdministrator();return Company::findOrFail($companyId);}
  $panel=$r->is('fbr-pos/*')?'fbrpos':'pos';$session=auth($panel)->user();$actor=$session?->fresh();
  abort_unless($actor&&$actor->is_active&&$actor->company_id===$session->company_id&&$actor->role==='company_admin'&&in_array($actor->pos_role,[null,'','pos_admin'],true),403);
  $company=Company::findOrFail($actor->company_id);abort_unless($company->product_type===$panel,403);return $company;
 }
 public function show(Request $r,?int $companyId=null){
  $company=$this->company($r,$companyId);$r->validate(['month'=>'nullable|date_format:Y-m']);$month=$r->input('month',now('UTC')->format('Y-m'));
  $events=DB::table('madadgar_usage_events')->where('company_id',$company->id)->where('period',$month)->orderByDesc('id')->limit(200)->get();
  $policy=DB::table('madadgar_budget_policies')->where('company_id',$company->id)->first();
  // Totals cover the complete scoped month, independent of the bounded event list.
  $summary=DB::table('madadgar_usage_events')->where('company_id',$company->id)->where('period',$month)->selectRaw("COUNT(*) AS events, SUM(source = 'openai' AND state != 'budget_blocked') AS provider_calls, SUM(state = 'budget_blocked') AS blocked, SUM(state = 'error') AS errors, SUM(input_tokens) AS input_tokens, SUM(output_tokens) AS output_tokens, AVG(latency_ms) AS latency_ms, SUM(estimated_cost) AS known_cost, SUM(CASE WHEN state != 'budget_blocked' THEN COALESCE(estimated_cost,reserved_cost) ELSE NULL END) AS committed_allowance, SUM(CASE WHEN source = 'openai' AND state != 'budget_blocked' AND estimated_cost IS NULL THEN 1 ELSE 0 END) AS unknown_cost_events")->first();
  $escalations=FeatureSuggestion::where('company_id',$company->id)->where('source','madadgar')->where('created_at','>=',$month.'-01')->where('created_at','<',\Carbon\CarbonImmutable::parse($month.'-01','UTC')->addMonth())->orderByDesc('id')->limit(100)->get(['id','status','created_at','updated_at']);
  return view('madadgar.usage',compact('company','events','policy','summary','escalations','month')+['admin'=>$companyId!==null]);
 }
 public function configure(Request $r,int $companyId){
  abort_if($r->session()->get('impersonation.readonly'),403);$actor=AdminUser::requireSensitiveConfigurationAdministrator();
  $data=$r->validate(['input_per_million'=>'nullable|numeric|decimal:0,6|min:0|max:999999.999999','output_per_million'=>'nullable|numeric|decimal:0,6|min:0|max:999999.999999','monthly_budget'=>'nullable|numeric|decimal:0,6|min:0|max:999999999.999999']);
  DB::transaction(function()use($data,$companyId,$actor){Company::whereKey($companyId)->lockForUpdate()->firstOrFail();$fresh=AdminUser::whereKey($actor->id)->lockForUpdate()->first();abort_unless($fresh&&$fresh->canManageSensitiveConfiguration(),403);$old=DB::table('madadgar_budget_policies')->where('company_id',$companyId)->first();$values=$data+['model'=>MadadgarUsage::MODEL,'currency'=>'USD','configured_by'=>$actor->id,'updated_at'=>now()];if(!$old)$values['created_at']=now();DB::table('madadgar_budget_policies')->updateOrInsert(['company_id'=>$companyId],$values);\App\Models\AuditLog::create(['company_id'=>$companyId,'action'=>'madadgar_budget_configured','entity_type'=>'company','entity_id'=>$companyId,'old_values'=>$old?(array)$old:null,'new_values'=>$data+['currency'=>'USD','model'=>MadadgarUsage::MODEL,'admin_actor_id'=>$actor->id]]);});
  return back()->with('success','AI rates/budget saved. Provider credentials and enablement unchanged.');
 }
}

<?php
namespace App\Http\Controllers\SaasAdmin;
use App\Http\Controllers\Controller;
use App\Services\AdminDashboardDetail;
use Illuminate\Http\Request;
class AdminDashboardDetailController extends Controller {
 public function show(Request $request,string $metric,AdminDashboardDetail $service) {
  abort_unless(auth('admin')->check(),403);
  $filters=$request->validate(['q'=>'nullable|string|max:100','from'=>'nullable|date_format:Y-m-d','to'=>$request->filled('from')?'nullable|date_format:Y-m-d|after_or_equal:from':'nullable|date_format:Y-m-d','status'=>'nullable|string|max:40','product'=>'nullable|in:di,pos,fbrpos,health,erps',
   'company_id'=>'nullable|integer|min:1','min'=>'nullable|numeric','max'=>$request->filled('min')?'nullable|numeric|gte:min':'nullable|numeric','mode'=>'nullable|string|max:30','fiscal_status'=>'nullable|string|max:40',
   'environment'=>'nullable|in:production,sandbox',
   'billing_state'=>'nullable|in:reported,local_unreported,local_final,pending_decision,reporting_pending,reporting_failed,unclassified',
   'branch_id'=>'nullable|integer|min:1','payment_method'=>'nullable|string|max:60',
   'business_from'=>'nullable|date_format:Y-m-d','business_to'=>$request->filled('business_from')?'nullable|date_format:Y-m-d|after_or_equal:business_from':'nullable|date_format:Y-m-d',
   'per_page'=>'nullable|in:25,50,100','sort'=>'nullable|in:newest,oldest,amount_high,amount_low','export'=>'nullable|in:csv']);
  $d=$service->definition($metric); $filterOptions=$service->filterOptions($d); $q=$service->filtered($d,$filters);
  $count=(clone $q)->count();$revenue=$service->revenue($q,$d);
  $statuses=(clone $d['q'])->distinct()->pluck($d['status']);
  $breakdown=(clone $q)->selectRaw($d['status'].' as state, COUNT(*) as records')->groupBy($d['status'])->get();
  $sort=$filters['sort']??'newest';
  if($d['amount'] && str_starts_with($sort,'amount_')) $q->orderBy($d['amount'],$sort==='amount_high'?'desc':'asc');
  $q->orderBy('id',$sort==='oldest'?'asc':'desc');
  if(!$d['companies']) $q->with('company');
  if(($filters['export']??null)==='csv') return response()->streamDownload(function() use($q,$service,$d){
   $out=fopen('php://output','w');$columns=array_keys($service->row(new \App\Models\User,$d));fputcsv($out,$columns,',','"','');
   foreach($q->lazy(500) as $record){$row=$service->row($record,$d);foreach($row as &$cell) if(is_string($cell) && preg_match('/^[=+@\-\t\r]/',$cell)) $cell="'".$cell;unset($cell);fputcsv($out,array_values($row),',','"','');} fclose($out);
  },'taxnest-'.$metric.'.csv',['Content-Type'=>'text/csv; charset=UTF-8']);
  $records=$q->paginate((int)($filters['per_page']??25))->withQueryString();$rows=$records->getCollection()->map(fn($r)=>$service->row($r,$d));
  return view('saas-admin.dashboard-detail',compact('d','filters','count','revenue','statuses','breakdown','records','rows','filterOptions'));
 }
}

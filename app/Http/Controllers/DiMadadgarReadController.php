<?php
namespace App\Http\Controllers;
use App\Models\{Company,User};
use App\Services\{MadadgarCompanyReadTools,MadadgarAnswerScope};
use Illuminate\Http\Request;
final class DiMadadgarReadController extends Controller
{
    public function show(Request $request)
    {
        $session=auth('web')->user();$actor=$session?User::find($session->id):null;
        $company=$actor?Company::find($actor->company_id):null;
        abort_unless($actor && $actor->is_active && $actor->role==='company_admin' && in_array($actor->pos_role,[null,'','pos_admin'],true) && $company?->product_type==='di' && (int)$session->company_id===(int)$company->id,403);
        $answer=null;
        if($request->isMethod('post')){
            $v=$request->validate(['question'=>'required|in:today billing,today tax breakdown,today payments']);
            $answer=MadadgarCompanyReadTools::answer($v['question'],'di',$company,$actor);
            if($answer && isset($answer['read_evidence'])) $answer['provenance']=MadadgarAnswerScope::metadata($answer,'di',$company,$actor);
        }
        return response()->view('madadgar.di-read',['answer'=>$answer])->header('Cache-Control','private, no-store');
    }
}

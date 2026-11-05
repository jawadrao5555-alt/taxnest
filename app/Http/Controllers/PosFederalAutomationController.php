<?php
namespace App\Http\Controllers;
use App\Models\{Company, PosFederalRule, PosFederalOutbox};
use App\Services\PosFederalAutomation;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class PosFederalAutomationController extends Controller
{
    private function company(Request $request): Company
    {
        $user=auth('pos')->user();
        abort_unless($user && $user->is_active && $user->isCompanyAdmin(),403);
        $company=Company::where('product_type','pos')->where('status','approved')->where('company_status','active')->findOrFail($user->company_id);
        abort_unless(app(PosFederalAutomation::class)->available(),404);
        if (!$request->isMethod('GET')) abort_unless(is_string($request->input('_token')) && hash_equals($request->session()->token(),$request->input('_token')),419);
        return $company;
    }
    public function index(Request $request, PosFederalAutomation $service)
    {
        $company=$this->company($request); $selected=$service->selected($company->id);
        $rule=$selected ? PosFederalRule::where('company_id',$company->id)->where('channel',$selected[0])->where('environment',$selected[1]->environment)->first() : null;
        $rows=PosFederalOutbox::where('company_id',$company->id)->latest('id')->paginate(30);
        return response()->view('pos.federal-automation',compact('company','selected','rule','rows'))->header('Cache-Control','no-store, private');
    }
    public function save(Request $request, PosFederalAutomation $service)
    {
        $company=$this->company($request);
        $data=$request->validate(['channel'=>'required|in:di,ims','environment'=>'required|in:sandbox,production','enabled'=>'required|boolean']);
        $selected=$service->selected($company->id);
        abort_unless($selected && $selected[0]===$data['channel'] && $selected[1]->environment===$data['environment'],409,'Connection changed. Refresh the automatic reporting page.');
        $mapping=[];
        if ($data['enabled']) {
            $mapping=$request->validate([
                'hs_code'=>[$data['channel']==='di' ? 'required' : 'nullable','regex:/^[0-9]{4}\.[0-9]{4}$/'],
                'tax_rate'=>[$data['channel']==='di' ? 'required' : 'nullable','numeric','between:0,100','regex:/^\d{1,3}(?:\.\d{1,2})?$/'],
                'uom'=>($data['channel']==='di' ? 'required' : 'nullable').'|string|max:100',
                'sale_type'=>($data['channel']==='di' ? 'required' : 'nullable').'|string|max:150',
                'buyer_province'=>($data['channel']==='di' ? 'required' : 'nullable').'|string|max:80',
                'buyer_address'=>($data['channel']==='di' ? 'required' : 'nullable').'|string|max:500',
                'scenario_id'=>($data['channel']==='di' && $data['environment']==='sandbox') ? ['required','regex:/^SN[0-9]{3}$/'] : 'nullable|string|max:5',
                'sro_schedule'=>'nullable|string|max:100','sro_item'=>'nullable|string|max:100',
                'all_items_confirmed'=>'accepted','mapping_reviewed'=>'accepted',
            ]);
            unset($mapping['all_items_confirmed'],$mapping['mapping_reviewed']);
            if ($data['channel']==='ims' && !$request->filled('tax_rate')) {
                $mapping['tax_source']='original_bill';
                unset($mapping['tax_rate']);
            }
        }
        DB::transaction(function() use($company,$data,$mapping,$service) {
            Company::whereKey($company->id)->lockForUpdate()->firstOrFail();
            $current=$service->selected($company->id);
            abort_unless($current && $current[0]===$data['channel'] && $current[1]->environment===$data['environment'],409);
            $rule=PosFederalRule::firstOrNew(['company_id'=>$company->id,'channel'=>$data['channel'],'environment'=>$data['environment']]);
            $rule->fill(['enabled'=>(bool)$data['enabled'],'version'=>($rule->version ?? 0)+1,
                'mapping'=>$data['enabled'] ? $mapping : ($rule->mapping ?? []),
                'reviewed_by'=>auth('pos')->id(),'reviewed_at'=>now()])->save();
        });
        return redirect()->route('pos.federal-auto')->with('success','Automatic reporting saved for future finalized bills. Existing bills were not resent.');
    }
}

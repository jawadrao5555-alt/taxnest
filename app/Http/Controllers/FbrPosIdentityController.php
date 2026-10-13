<?php
namespace App\Http\Controllers;
use App\Models\Branch;
use App\Models\FbrPosTerminal;
use App\Models\PosAgentDevice;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
class FbrPosIdentityController extends Controller {
 public function index(Request $request) {
  $owner=Auth::guard('fbrpos')->user(); abort_unless($owner && $owner->role==='company_admin',403); $companyId=(int)$owner->company_id;
  if($request->isMethod('post')) {
   $data=$request->validate(['branch_id'=>['required','integer','min:0'],'terminal_id'=>['required','integer','min:0'],
    'pos_id'=>['required','regex:/^[1-9][0-9]{0,9}$/'],'device_uid'=>['required',Rule::exists('pos_agent_devices','device_uid')->where('company_id',$companyId)]]);
   $data['branch_id']=(int)$data['branch_id']; $data['terminal_id']=(int)$data['terminal_id'];
   abort_if($data['branch_id']===0 && $data['terminal_id']===0,422,'Select a branch or terminal.');
   if($data['branch_id']) abort_unless(Branch::withoutGlobalScopes()->where('company_id',$companyId)->where('is_active',true)->whereKey($data['branch_id'])->exists(),422,'Choose an active company branch.');
   if($data['terminal_id']) abort_unless(FbrPosTerminal::where('company_id',$companyId)->where('is_active',true)->whereKey($data['terminal_id'])->where(fn($q)=>$q->whereNull('branch_id')->orWhere('branch_id',$data['branch_id']))->exists(),422,'Choose a terminal in this branch.');
   DB::table('fbr_pos_identities')->updateOrInsert(['company_id'=>$companyId,'branch_id'=>$data['branch_id'],'terminal_id'=>$data['terminal_id']],['pos_id'=>$data['pos_id'],'device_uid'=>$data['device_uid'],'updated_at'=>now(),'created_at'=>now()]);
   return redirect()->route('fbrpos.identities')->with('success','Mapping saved for new invoices. Previously captured invoices keep their original identity.');
  }
  return view('fbr-pos.identities',['branches'=>Branch::withoutGlobalScopes()->where('company_id',$companyId)->where('is_active',true)->get(),
   'terminals'=>FbrPosTerminal::where('company_id',$companyId)->where('is_active',true)->get(),'devices'=>PosAgentDevice::where('company_id',$companyId)->get(),
   'mappings'=>DB::table('fbr_pos_identities')->where('company_id',$companyId)->get()]);
 }
}

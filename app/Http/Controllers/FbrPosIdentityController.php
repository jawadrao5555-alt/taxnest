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
  if($request->isMethod('post') && $request->has('replacement_bill')) {
   $data=$request->validate(['replacement_bill'=>'required|integer','device_uid'=>'required|string|max:64','pos_id'=>'required|regex:/^[1-9][0-9]{0,9}$/',
    'evidence_reference'=>['required','string','max:160','regex:/^[\\pL\\pN ._\\-\\/():]+$/u'],'confirmed'=>'accepted']);
   app(\App\Services\FbrPosIdentity::class)->rebind($owner,(int)$data['replacement_bill'],$data['device_uid'],$data['pos_id'],$data['evidence_reference']);
   return redirect()->route('fbrpos.identities')->with('success','Replacement PC recorded for the unchanged POS ID and USIN. The assigned agent can now retry this confirmed rejection.');
  }
  if($request->isMethod('post')) {
   if($request->has('remove_mapping')) {
    DB::table('fbr_pos_identities')->where('company_id',$companyId)->where('id',$request->integer('remove_mapping'))->delete();
    return redirect()->route('fbrpos.identities')->with('success','Mapping removed for new invoices. Saved invoice identities remain unchanged.');
   }
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
   'mappings'=>DB::table('fbr_pos_identities')->where('company_id',$companyId)->get(),
   'replacementBills'=>\Illuminate\Support\Facades\Schema::hasTable('fbr_pos_owner_reconciliations') ? \App\Models\FbrPosTransaction::withoutGlobalScopes()->where('company_id',$companyId)
    ->where('fbr_status','failed')->whereNull('fbr_invoice_number')->whereNull('fbr_submission_hash')->whereNotNull('fbr_identity_captured_at')
    ->whereExists(fn($q)=>$q->selectRaw('1')->from('fbr_pos_owner_reconciliations as r')->whereColumn('r.transaction_id','fbr_pos_transactions.id')->where('r.company_id',$companyId)->where('r.decision','not_accepted')->where('r.evidence_source','ims_log'))
    ->orderByDesc('id')->limit(20)->get() : collect()]);
 }
}

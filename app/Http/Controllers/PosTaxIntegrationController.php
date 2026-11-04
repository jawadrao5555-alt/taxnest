<?php
namespace App\Http\Controllers;
use App\Models\{Company, PosImsProfile, PosImsSubmission, PosDiSandboxProfile, PosTransaction};
use App\Services\{PosImsReportingService, PosTaxIntegrationEndpoints};
use App\Support\PraDiSandboxGate;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\{DB, Validator};

class PosTaxIntegrationController extends Controller
{
    private function company(Request $request): Company
    {
        $u=auth('pos')->user();abort_unless($u && $u->isCompanyAdmin() && $u->is_active,403);
        $c=Company::where('product_type','pos')->findOrFail($u->company_id);
        abort_unless($c->status==='approved' && $c->company_status==='active',403);
        if (!$request->isMethod('GET')) {
            $t=$request->input('_token');abort_unless(is_string($t) && hash_equals($request->session()->token(),$t),419);
        }
        return $c;
    }
    public function index(Request $request)
    {
        $company=$this->company($request);
        $diProfile=PosDiSandboxProfile::where('company_id',$company->id)->first();
        $imsProfile=PosImsProfile::where('company_id',$company->id)->first();
        return response()->view('pos.tax-integrations',compact('company','diProfile','imsProfile'))->header('Cache-Control','no-store, private');
    }
    public function ims(Request $request)
    {
        $company=$this->company($request);abort_unless(PraDiSandboxGate::setupAvailable(),404);
        $profile=PosImsProfile::where('company_id',$company->id)->first();
        $records=PosImsSubmission::where('company_id',$company->id)->latest('id')->limit(30)->get();
        $devices=\Illuminate\Support\Facades\Schema::hasTable('pos_agent_devices') ? \App\Models\PosAgentDevice::where('company_id',$company->id)->orderBy('id')->get() : collect();
        $deviceOptions=$devices->map(fn($d)=>['uid'=>$d->device_uid,'online'=>$d->isOnline(),'services'=>$d->local_fbr_ims ?? []])->values();
        $sales=PosTransaction::where('company_id',$company->id)->where('status','completed')->latest('id')->limit(30)->get();
        return response()->view('pos.ims.setup',compact('company','profile','records','sales','devices','deviceOptions'))->header('Cache-Control','no-store, private');
    }
    public function saveIms(Request $request)
    {
        $tokens=$request->only(['sandbox_token','production_token']);
        foreach (array_keys($tokens) as $k) { $request->request->remove($k); if($request->isJson())$request->json()->remove($k); }
        $c=$this->company($request);abort_unless(PraDiSandboxGate::setupAvailable(),404);
        $data=$request->validate(['enabled'=>'required|boolean','environment'=>'required|in:sandbox,production',
            'connection_mode'=>'required|in:cloud,fiscal_device',
            'sandbox_pos_id'=>['nullable','regex:/^[1-9][0-9]{0,8}$/'], 'production_pos_id'=>['nullable','regex:/^[1-9][0-9]{0,8}$/'],
            'sandbox_url'=>'nullable|string|max:500','production_url'=>'nullable|string|max:500',
            'device_uid'=>['nullable','string','max:100','regex:/^[a-zA-Z0-9_-]+$/'],
            'local_ims_url'=>'nullable|string|max:500','confirm_setup'=>'sometimes|accepted','confirm_production'=>'sometimes|accepted']);
        Validator::make($tokens,['sandbox_token'=>['nullable','string','max:8192','regex:/^[\x21-\x7E]+$/'],
            'production_token'=>['nullable','string','max:8192','regex:/^[\x21-\x7E]+$/']])->validate();
        foreach(['sandbox','production'] as $env) $data[$env.'_url']=PosTaxIntegrationEndpoints::checked('ims',$env,$data[$env.'_url'] ?? null);
        if (!empty($data['local_ims_url'])) Validator::make($data,['local_ims_url'=>['regex:~^http://(?:127\.0\.0\.1|localhost):[0-9]{4,5}/(?:api/IMSFiscal/GetInvoiceNumberByModel|TcpService/IMSHttp)$~']])->validate();
        if (!empty($data['local_ims_url'])) {
            $port=(int)parse_url($data['local_ims_url'],PHP_URL_PORT);
            abort_unless($port>=1024 && $port<=65535,422,'Use the designated local FBR IMS port.');
        }
        DB::transaction(function() use($c,$data,$tokens){
            Company::whereKey($c->id)->lockForUpdate()->firstOrFail();$p=PosImsProfile::firstOrNew(['company_id'=>$c->id]);
            foreach(['sandbox','production'] as $env) {
                foreach(['pos_id','url'] as $field) if(array_key_exists($env.'_'.$field,$data))$p->{$env.'_'.$field}=$data[$env.'_'.$field];
                if(is_string($tokens[$env.'_token'] ?? null) && $tokens[$env.'_token']!=='')$p->{$env.'_token'}=$tokens[$env.'_token'];
            }
            if (!empty($data['device_uid'])) {
                abort_unless($p->device_uid === $data['device_uid'] || \App\Models\PosAgentDevice::where('company_id',$c->id)->where('device_uid',$data['device_uid'])->exists(),422,'Select a connected company PC.');
            }
            foreach(['device_uid','local_ims_url'] as $k) if(array_key_exists($k,$data))$p->$k=$data[$k];
            if($data['enabled']) {
                Validator::make($data,['confirm_setup'=>'accepted'])->validate();
                Validator::make(['pos_id'=>$p->{$data['environment'].'_pos_id'}],['pos_id'=>'required'])->validate();
                if($data['connection_mode']==='cloud') Validator::make(['token'=>$p->{$data['environment'].'_token'}],['token'=>'required'])->validate();
                else {
                    Validator::make(['device_uid'=>$p->device_uid,'local_ims_url'=>$p->local_ims_url],['device_uid'=>'required','local_ims_url'=>'required'])->validate();
                    abort_unless($c->agent_enabled,422,'Pair the Desktop Agent first. Its existing PRA mode will be preserved.');
                }
                if($data['environment']==='production') {
                    abort_unless(config('pra_di_sandbox.production_enabled'),422);
                    Validator::make($data,['confirm_production'=>'accepted'])->validate();$p->production_confirmed_at=now();
                }
                PosDiSandboxProfile::where('company_id',$c->id)->update(['enabled'=>false]);
            }
            $p->fill(['enabled'=>(bool)$data['enabled'],'environment'=>$data['environment'],'connection_mode'=>$data['connection_mode'],'updated_by'=>auth('pos')->id()])->save();
        });
        return redirect()->route('pos.tax-integrations.ims')->with('success','FBR POS settings saved. No invoice sent. When enabled, this selects IMS as the federal reporting channel; DI credentials and history are retained.');
    }
    private function record(Company $c,int $id): PosImsSubmission
    { return PosImsSubmission::where('company_id',$c->id)->findOrFail($id); }
    public function capture(Request $request,PosImsReportingService $svc)
    {
        $c=$this->company($request);$d=$request->validate(['transaction_id'=>'required|integer','environment'=>'required|in:sandbox,production','confirm_copy'=>'accepted']);
        $r=$svc->capture($c,$d['transaction_id'],auth('pos')->id(),$d['environment']);return redirect()->route('pos.tax-integrations.ims.show',$r->id);
    }
    public function show(Request $request,int $id,PosImsReportingService $svc)
    {
        $record=$this->record($this->company($request),$id);$payload=$record->payload ?? $svc->template($record);
        return response()->view('pos.ims.show',compact('record','payload'))->header('Cache-Control','no-store, private');
    }
    public function prepare(Request $request,int $id,PosImsReportingService $svc)
    {
        $c=$this->company($request);$this->record($c,$id);
        $d=$request->validate(['payload_json'=>'required|json|max:100000','mapping_reviewed'=>'accepted']);
        $p=json_decode($d['payload_json'],true,32);abort_unless(is_array($p) && json_last_error()===JSON_ERROR_NONE,422,'Enter an IMS JSON object of supported depth.');
        $svc->prepare($c->id,$id,$p);return redirect()->route('pos.tax-integrations.ims.show',$id)->with('success','IMS payload saved. Nothing sent.');
    }
    public function submit(Request $request,int $id,PosImsReportingService $svc)
    {
        $c=$this->company($request);$this->record($c,$id);$request->validate(['confirm_submit'=>'accepted']);
        $svc->submit($c->id,$id,auth('pos')->id());return redirect()->route('pos.tax-integrations.ims.show',$id);
    }
    public function refreshConnection(Request $request,int $id,PosImsReportingService $svc)
    {
        $c=$this->company($request);$this->record($c,$id);$request->validate(['confirm_connection'=>'accepted']);
        $svc->refreshConnection($c->id,$id,auth('pos')->id());
        return redirect()->route('pos.tax-integrations.ims.show',$id)->with('success','Current IMS connection selected for this undispatched/rejected bill. Original financial amounts are preserved. Review and save the mapping again.');
    }
    public function receipt(Request $request,int $id)
    {
        $record=$this->record($this->company($request),$id);$width=$request->query('width')==='58'?58:80;
        return response()->view('pos.ims.receipt',compact('record','width'))->header('Cache-Control','no-store, private');
    }
    public function agentClaim(Request $request,PosImsReportingService $svc)
    {
        $c=$request->attributes->get('agent_company');
        $d=$request->validate(['device_uid'=>'required|string|max:100']);
        return response()->json(['invoice'=>$svc->claim($c->id,$d['device_uid'],in_array('soap',(array)$request->input('supported_protocols',[]),true))])->header('Cache-Control','no-store, private');
    }
    public function agentResult(Request $request,PosImsReportingService $svc)
    {
        $c=$request->attributes->get('agent_company');$d=$request->validate(['id'=>'required|integer','device_uid'=>'required|string|max:100','claim'=>'required|string|size:64','response'=>'nullable|array|max:10']);
        $r=$svc->callback($c->id,$d['id'],$d['device_uid'],$d['claim'],$d['response'] ?? null);
        return response()->json(['ok'=>true,'state'=>$r->state]);
    }
}

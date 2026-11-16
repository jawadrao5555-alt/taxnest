<?php
namespace App\Http\Controllers\SaasAdmin;
use App\Http\Controllers\Controller;
use App\Services\PilotFiscalControl;
use Illuminate\Http\Request;
final class PilotFiscalControlController extends Controller
{
    public function show(Request $request,int $companyId)
    {
        $data=PilotFiscalControl::read($companyId);
        return ($request->expectsJson()?response()->json($data['state']):response()->view('saas-admin.pilot-fiscal-control',$data))
            ->header('Cache-Control','private, no-store');
    }
    public function update(Request $request,int $companyId)
    {
        // Authorize before validation so tenant users cannot probe this control.
        PilotFiscalControl::read($companyId);
         $v=$request->validate(['enabled'=>['required_without:route','boolean'], 'route'=>['sometimes','required','in:off,ims,di']]);
        $route=$v['route']??null;
        $on=$route !== null ? $route !== 'off' : (bool)$v['enabled'];
        if ($route !== null && array_key_exists('enabled',$v) && (bool)$v['enabled'] !== $on) {
            throw \Illuminate\Validation\ValidationException::withMessages(['route'=>'Route and enabled selection conflict.']);
        }
        $state=PilotFiscalControl::update($companyId,$on,$route);
        if($request->expectsJson())return response()->json($state,$on?422:200)->header('Cache-Control','private, no-store');
        if($on)return response()->view('saas-admin.pilot-fiscal-control',PilotFiscalControl::read($companyId)+['rejected'=>true],422)->header('Cache-Control','private, no-store');
        return redirect()->route('saas.admin.pilot-fiscal-control',$companyId)->with('success','Fiscal submission remains OFF. Existing records are unchanged.');
    }
}

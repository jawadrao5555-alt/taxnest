<?php
namespace App\Http\Controllers\SaasAdmin;
use App\Http\Controllers\Controller;
use App\Models\Company;
use App\Services\ReviewedDeviceProof;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
final class ReviewedDeviceProofController extends Controller
{
    public function index(int $companyId)
    {
        ReviewedDeviceProof::requireReviewer();
        $company=Company::findOrFail($companyId);
        $proofs=DB::table('reviewed_device_proofs')->where('company_id',$companyId)->orderByDesc('id')->paginate(30);
        $devices = Schema::hasTable('pos_agent_devices')
            ? DB::table('pos_agent_devices')->where('company_id', $companyId)->pluck('device_uid')
            : collect();
        return response()->view('saas-admin.reviewed-device-proofs',compact('company','proofs','devices'))->header('Cache-Control','private, no-store');
    }
    public function store(Request $request,int $companyId)
    {
        ReviewedDeviceProof::approve($companyId,$request->all());
        return back()->with('success','Reviewed device evidence recorded. Reporting settings were not changed.');
    }
    public function revoke(Request $request,int $companyId,int $id)
    {
        ReviewedDeviceProof::revoke($companyId,$id,(string)$request->input('reason'));
        return back()->with('success','Proof revoked. Original review remains in the evidence history.');
    }
}

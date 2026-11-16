<?php
namespace App\Http\Controllers;

use App\Models\{Company, User, Branch};
use App\Services\PlanLimitService;
use Illuminate\Support\Facades\Auth;

/** Local collection teams never load or disclose stored authentication material. */
final class PilotTeamController extends Controller
{
    public function index()
    {
        $user = Auth::guard('fbrpos')->user();
        abort_unless($user && $user->is_active, 403);
        $company = Company::findOrFail($user->company_id);
        if (!$company->isLocalCollectionPilot()) {
            return app(FbrPosController::class)->fbrTeam();
        }
        abort_unless($user->role === 'company_admin' || $user->pos_role === 'pos_admin', 403);
        abort_unless((int) app('currentCompanyId') === (int) $user->company_id, 403);
        $team = User::query()->select(['id','company_id','name','email','username','pos_role','is_active','default_branch_id'])
            ->where('company_id',$company->id)->whereIn('pos_role',['pos_admin','pos_manager','pos_cashier'])
            ->orderBy('name')->get();
        $branches = Branch::query()->select(['id','company_id','name'])->where('company_id',$company->id)->orderBy('name')->get();
        return response()->view('fbr-pos.pilot-team', [
            'company'=>$company, 'team'=>$team, 'branches'=>$branches,
            'quota'=>PlanLimitService::canAddPosUser($company->id),
            'readOnly'=>(bool)session('impersonation.readonly',false),
        ])->header('Cache-Control','private, no-store');
    }
}

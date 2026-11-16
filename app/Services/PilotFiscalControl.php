<?php
namespace App\Services;

use App\Models\{AdminUser,AdminAuditLog,Company};
use Illuminate\Support\Facades\DB;

/** Administrative OFF control only. No verified category-specific transport exists. */
final class PilotFiscalControl
{
    private static function actor(bool $lock=false): AdminUser
    {
        abort_if(session('impersonation.readonly'),403);
        $id=auth('admin')->id();abort_unless($id,403);
        $q=AdminUser::whereKey($id);if($lock)$q->lockForUpdate();
        $actor=$q->first();abort_unless($actor?->isSuperAdmin(),403);return $actor;
    }
    private static function company(int $id,bool $lock=false): Company
    {
        $q=Company::whereKey($id);if($lock)$q->lockForUpdate();
        $company=$q->firstOrFail();abort_unless($company->isLocalCollectionPilot(),404);return $company;
    }
    private static function state(Company $company): array
    {
        return ['company_id'=>$company->id,'category'=>$company->isSchoolFeePilot()?'school':'hospital',
            'enabled'=>false,'selected_route'=>'off','status'=>'blocked','reason'=>'category_route_unverified',
            'required_evidence'=>['Official category-specific reporting route, payload and authentication contract.',
                'Category-specific sandbox acceptance and timeout/retry/duplicate acknowledgement verification.',
                'Reviewed new-bill eligibility and immutable acknowledgement handling before activation.'],
            'historical_submission'=>false,'readiness'=>PilotFiscalReadiness::forCompany($company)];
    }
    public static function read(int $id): array
    {
        self::actor();$company=self::company($id);return ['company'=>$company,'state'=>self::state($company)];
    }
    public static function update(int $id,bool $enabled,?string $route=null): array
    {
        self::actor();
        abort_unless($route === null || in_array($route, ['off','ims','di'], true),422);
        abort_if($route !== null && $enabled !== ($route !== 'off'),422);
        return DB::transaction(function()use($id,$enabled,$route){
            $actor=self::actor(true);$company=self::company($id,true);
            // OFF changes only this raw flag, never credentials, invoices, acknowledgements or queues.
            if(!$enabled){$company->fbr_reporting_enabled=false;$company->save();}
            AdminAuditLog::log($actor->id,$enabled?'Pilot fiscal ON rejected':'Pilot fiscal OFF confirmed','Company',$id,
                ['requested_route'=>$route ?? ($enabled ? 'unspecified' : 'off'),'requested_enabled'=>$enabled,'effective_enabled'=>false,'reason'=>'category_route_unverified',
                 'category'=>self::state($company)['category'],'historical_submission'=>false]);
            // Return rejection after committing its audit; do not arm a later automatic activation.
            return self::state($company);
        },3);
    }
}

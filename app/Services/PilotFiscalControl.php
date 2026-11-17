<?php
namespace App\Services;

use App\Models\{AdminUser,AdminAuditLog,Company};
use Illuminate\Support\Facades\DB;

/** Company-scoped School/Hospital fiscal switch. IMS reuses standalone FBR POS transport. */
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
        $configured = $company->fbrPosIntegrationConfigured();
        $enabled = (bool) $company->fbr_reporting_enabled && $configured;
        return ['company_id'=>$company->id,'category'=>$company->isSchoolFeePilot()?'school':'hospital',
            'enabled'=>$enabled,'selected_route'=>$enabled?'ims':'off','status'=>$enabled?'on':($configured?'ready_off':'setup_pending'),
            'reason'=>$enabled?null:($configured?'owner_off':'ims_setup_incomplete'),
            'required_evidence'=>$configured?[
                'Authorized sandbox acceptance must still be verified separately from synthetic CI.',
            ]:array_map(fn($key)=>'Missing FBR IMS setup: '.$key, $company->fbrIntegrationMissing()),
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
            if ($enabled) {
                if (($route ?? 'ims') !== 'ims') {
                    AdminAuditLog::log($actor->id,'Pilot fiscal ON rejected','Company',$id,
                        ['requested_route'=>$route,'requested_enabled'=>true,'effective_enabled'=>false,'reason'=>'only_ims_available','category'=>self::state($company)['category'],'historical_submission'=>false]);
                    return self::state($company);
                }
                $posId=preg_replace('/[^0-9]/','',(string)($company->fbr_pos_id ?? ''));
                if ($posId==='' || (int)$posId===0 || !(bool)$company->agent_enabled) {
                    if ((bool)$company->getRawOriginal('fbr_reporting_enabled')) {$company->fbr_reporting_enabled=false;$company->save();}
                    AdminAuditLog::log($actor->id,'Pilot fiscal ON rejected','Company',$id,
                        ['requested_route'=>'ims','requested_enabled'=>true,'effective_enabled'=>false,'reason'=>'ims_setup_incomplete','category'=>self::state($company)['category'],'historical_submission'=>false]);
                    return self::state($company);
                }
                $company->fbr_pos_enabled=true;
                $company->fbr_connection_mode='fiscal_device';
                $company->fbr_reporting_enabled=true;
            } else {
                // OFF never rewrites historical receipt snapshots or acknowledgements.
                $company->fbr_reporting_enabled=false;
            }
            $company->save();
            AdminAuditLog::log($actor->id,$enabled?'Pilot fiscal IMS enabled':'Pilot fiscal OFF confirmed','Company',$id,
                ['requested_route'=>$route ?? ($enabled ? 'ims' : 'off'),'requested_enabled'=>$enabled,'effective_enabled'=>$enabled,'reason'=>$enabled?null:'owner_off',
                 'category'=>self::state($company)['category'],'historical_submission'=>false]);
            return self::state($company);
        },3);
    }
}

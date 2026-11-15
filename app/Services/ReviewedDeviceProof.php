<?php
namespace App\Services;

use App\Models\{AdminUser, Company};
use Illuminate\Support\Facades\{DB, Schema, Validator};

/** Human-reviewed evidence only. Never accepts heartbeat claims or enables reporting. */
final class ReviewedDeviceProof
{
    public static function requireReviewer(): AdminUser
    {
        $session = auth('admin')->user();
        $actor = $session instanceof AdminUser ? AdminUser::find($session->id) : null;
        abort_unless($actor && $actor->isSuperAdmin(),403,'A current SaaS super-admin must review device evidence.');
        return $actor;
    }

    public static function reviewedProof(int $companyId, string $deviceUid, string $posId, string $environment): ?object
    {
        if (!Schema::hasTable('reviewed_device_proofs') || !in_array($environment,['sandbox','production'],true)
            || $deviceUid === '' || $posId === '') return null;
        $row=DB::table('reviewed_device_proofs')->where('company_id',$companyId)->where('device_uid',$deviceUid)
            ->where('pos_id',$posId)->where('environment',$environment)->orderByDesc('id')->first();
        // MariaDB may use a case-insensitive collation. Fingerprints remain exact byte-for-byte.
        if (!$row || $row->action !== 'approve' || $row->device_uid !== $deviceUid || $row->pos_id !== $posId || $row->environment !== $environment) return null;
        return $row;
    }

    public static function approve(int $companyId, array $data): int
    {
        $actor=self::requireReviewer();
        $data=Validator::make($data,[
            'device_uid'=>'required|string|max:128|regex:/^[A-Za-z0-9_.:-]+$/',
            'pos_id'=>'required|string|max:64|regex:/^[A-Za-z0-9_.:-]+$/',
            'environment'=>'required|in:sandbox,production',
            'evidence_reference'=>'required|string|max:300',
            'evidence_sha256'=>'required|regex:/^[a-fA-F0-9]{64}$/',
            'review_note'=>'required|string|min:10|max:2000',
            'independent_evidence_reviewed'=>'accepted',
        ])->validate();
        return DB::transaction(function() use($companyId,$data,$actor) {
            Company::whereKey($companyId)->lockForUpdate()->firstOrFail();
            unset($data['independent_evidence_reviewed']);
            $data['evidence_sha256']=strtolower($data['evidence_sha256']);
            return DB::table('reviewed_device_proofs')->insertGetId($data+['company_id'=>$companyId,'action'=>'approve','actor_id'=>$actor->id,'created_at'=>now()]);
        });
    }

    public static function revoke(int $companyId, int $proofId, string $reason): int
    {
        $actor=self::requireReviewer();
        Validator::make(['reason'=>$reason],['reason'=>'required|string|min:10|max:2000'])->validate();
        return DB::transaction(function() use($companyId,$proofId,$reason,$actor) {
            Company::whereKey($companyId)->lockForUpdate()->firstOrFail();
            $proof=DB::table('reviewed_device_proofs')->where('company_id',$companyId)->where('id',$proofId)->where('action','approve')->first();
            abort_unless($proof,404);
            $latest=self::reviewedProof($companyId,$proof->device_uid,$proof->pos_id,$proof->environment);
            abort_unless($latest && $latest->id === $proof->id,409,'Proof is already revoked or superseded.');
            return DB::table('reviewed_device_proofs')->insertGetId([
                'company_id'=>$companyId,'device_uid'=>$proof->device_uid,'pos_id'=>$proof->pos_id,'environment'=>$proof->environment,
                'action'=>'revoke','actor_id'=>$actor->id,'evidence_reference'=>$proof->evidence_reference,'evidence_sha256'=>$proof->evidence_sha256,
                'review_note'=>$reason,'revokes_id'=>$proofId,'created_at'=>now(),
            ]);
        });
    }
}

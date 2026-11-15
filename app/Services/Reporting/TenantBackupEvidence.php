<?php
namespace App\Services\Reporting;

use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Validation\ValidationException;

/** Authenticated producer assertions, never direct proof of a usable backup or restore. */
final class TenantBackupEvidence
{
    public function ingest(string $source, string $token, array $input): array
    {
        $enrollment = config('backup_evidence.sources.'.$source);
        abort_unless(\Illuminate\Support\Str::isUuid($source) && is_array($enrollment)
            && ($enrollment['enabled'] ?? false) === true && strlen($token) >= 32 && strlen($token) <= 512
            && preg_match('/^[a-f0-9]{64}$/D', (string)($enrollment['token_sha256'] ?? ''))
            && hash_equals($enrollment['token_sha256'], hash('sha256',$token)), 401, 'Backup evidence source not authorized.');
        $rateKey='backup-evidence:'.hash('sha256',$source);
        abort_if(\Illuminate\Support\Facades\RateLimiter::tooManyAttempts($rateKey,60),429,'Evidence rate exceeded.');
        \Illuminate\Support\Facades\RateLimiter::hit($rateKey,60);
        if (array_diff(array_keys($input), ['company_id','event_id','reported_status','event_at','reported_retained_until'])) {
            throw ValidationException::withMessages(['evidence'=>'Unsupported evidence fields.']);
        }
        $d=validator($input,[
            'company_id'=>'required|integer|min:1','event_id'=>'required|uuid',
            'reported_status'=>'required|in:retained,failed,deleted',
            'event_at'=>'required|date_format:Y-m-d\TH:i:sP',
            'reported_retained_until'=>'nullable|date_format:Y-m-d\TH:i:sP',
        ])->validate();
        abort_unless(in_array((int)$d['company_id'],array_map('intval',$enrollment['company_ids']??[]),true),403,'Company is outside source enrollment.');
        $company=DB::table('companies')->where('id',$d['company_id']);
        if(Schema::hasColumn('companies','deleted_at'))$company->whereNull('deleted_at');
        abort_unless($company->exists(),403,'Company is unavailable.');
        $now=Carbon::now(config('app.timezone'));
        $event=Carbon::parse($d['event_at'])->setTimezone(config('app.timezone'));
        $until=empty($d['reported_retained_until'])?null:Carbon::parse($d['reported_retained_until'])->setTimezone(config('app.timezone'));
        if($event->gt($now->copy()->addMinutes(5)) || $event->lt($now->copy()->subDays(90)) || ($until && ($until->lt($event) || $d['reported_status']!=='retained'))) {
            throw ValidationException::withMessages(['event_at'=>'Invalid evidence time or retention window.']);
        }
        $row=['company_id'=>(int)$d['company_id'],'source_id'=>$source,'event_id'=>$d['event_id'],
            'reported_status'=>$d['reported_status'],'event_at'=>$event->format('Y-m-d H:i:s'),
            'reported_retained_until'=>$until?->format('Y-m-d H:i:s')];
        $hash=hash('sha256',json_encode($row,JSON_THROW_ON_ERROR));
        return DB::transaction(function() use($row,$hash,$now){
            DB::table('tenant_backup_evidence')->insertOrIgnore($row+[
                'content_hash'=>$hash,'provenance'=>'authenticated_producer_report','received_at'=>$now->format('Y-m-d H:i:s'),
                'evidence_expires_at'=>$now->copy()->addDays(max(7,min(365,(int)config('backup_evidence.evidence_retention_days',90))))->format('Y-m-d H:i:s'),
            ]);
            $saved=DB::table('tenant_backup_evidence')->where('source_id',$row['source_id'])->where('event_id',$row['event_id'])->first();
            abort_unless($saved && hash_equals($saved->content_hash,$hash),409,'Event identity already has different evidence.');
            return ['id'=>(int)$saved->id,'received_at'=>$saved->received_at,'provenance'=>'authenticated_producer_report','backup_verified'=>false,'restore_verified'=>false];
        });
    }
}

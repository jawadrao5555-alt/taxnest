<?php
namespace App\Services;

use App\Models\{Company, PosTransaction, PosFederalRule, PosFederalOutbox, PosImsProfile, PosDiSandboxProfile, PosImsSubmission, PosDiSandboxSubmission};
use Illuminate\Support\Facades\{DB, Schema};

/** Durable reporting intent only. Never posts sale, stock, payment or ledger rows. */
class PosFederalAutomation
{
    public function available(): bool
    {
        return config('pra_di_sandbox.automatic_enabled', true)
            && Schema::hasTable('pos_federal_rules') && Schema::hasTable('pos_federal_outbox')
            && Schema::hasTable('pos_ims_profiles') && Schema::hasTable('pos_di_sandbox_profiles');
    }

    public function assertSaleMutable(PosTransaction $sale): void
    {
        abort_if(Schema::hasTable('pos_federal_outbox') && PosFederalOutbox::where('company_id',$sale->company_id)
            ->where('source_transaction_id',$sale->id)->exists(),409,
            'This bill is queued for federal reporting. Use a return/credit note to correct it; do not edit or delete its original sale.');
    }

    public function selected(int $companyId): ?array
    {
        $ims=PosImsProfile::where('company_id',$companyId)->where('enabled',true)->first();
        $di=PosDiSandboxProfile::where('company_id',$companyId)->where('enabled',true)->first();
        if ((bool)$ims === (bool)$di) return null; // ambiguous/disabled routing never guessed
        return [$ims ? 'ims' : 'di', $ims ?: $di];
    }

    public function connectionHash(string $channel, $profile): string
    {
        $env=$profile->environment;
        $identity=$channel==='ims'
            ? [$profile->connection_mode,$profile->{$env.'_pos_id'},$profile->device_uid,$profile->local_ims_url,$profile->{$env.'_url'}]
            : [$profile->{$env.'_url'}];
        return hash('sha256',json_encode([$channel,$env,$identity],JSON_THROW_ON_ERROR));
    }

    public function enqueue(PosTransaction $sale): void
    {
        if ($sale->status!=='completed' || $sale->isDeliberateProvisional() || !$this->available()) return;
        $company=Company::find($sale->company_id);
        if (!$company || $company->product_type!=='pos' || $company->status!=='approved' || $company->company_status!=='active') return;
        $selected=$this->selected($company->id);
        if (!$selected) return;
        [$channel,$profile]=$selected;
        $rule=PosFederalRule::where('company_id',$company->id)->where('channel',$channel)
            ->where('environment',$profile->environment)->where('enabled',true)->first();
        if (!$rule) return; // all existing customers default to their existing flow
        PosFederalOutbox::firstOrCreate(['company_id'=>$company->id,'source_transaction_id'=>$sale->id], [
            'channel'=>$channel,'environment'=>$profile->environment,'state'=>'queued',
            'rule_snapshot'=>['id'=>$rule->id,'version'=>$rule->version,'mapping'=>$rule->mapping,'actor'=>$rule->reviewed_by],
            'connection_hash'=>$this->connectionHash($channel,$profile),
        ]);
    }

    /** Claim once before any possible external I/O. Stale processing is never blindly retried. */
    public function process(int $id): void
    {
        if (!$this->available()) return;
        $row=DB::transaction(function() use($id) {
            $row=PosFederalOutbox::whereKey($id)->lockForUpdate()->first();
            if (!$row || $row->state!=='queued') return null;
            $row->update(['state'=>'processing','started_at'=>now()]);
            return $row;
        });
        if (!$row) return;
        try {
            $selected=$this->selected($row->company_id);
            abort_unless($selected && $selected[0]===$row->channel && $selected[1]->environment===$row->environment,409);
            abort_unless(hash_equals($row->connection_hash,$this->connectionHash(...$selected)),409);
            abort_unless(PosFederalRule::where('company_id',$row->company_id)->whereKey($row->rule_snapshot['id'])->where('enabled',true)->exists(),409);
            $company=Company::findOrFail($row->company_id);
            $svc=app($row->channel==='ims' ? PosImsReportingService::class : PraDiSandboxService::class);
            $actor=(int)$row->rule_snapshot['actor'];
            $record=$svc->capture($company,$row->source_transaction_id,$actor,$row->environment);
            $row->update(['submission_id'=>$record->id]);
            // Existing manual/accepted/ambiguous evidence wins. Automation never overwrites it.
            if ($record->state!=='draft' || $record->payload || $record->attempted_at) {
                $row->update(['state'=>'linked_existing']); return;
            }
            $payload=app(PosFederalPayload::class)->build($row,$record,$svc);
            $svc->prepare($row->company_id,$record->id,$payload);
            $record=$svc->submit($row->company_id,$record->id,$actor);
            $row->update(['state'=>'dispatched','error_code'=>null]);
        } catch (\Throwable $e) {
            $record=$row->submission_id ? ($row->channel==='ims' ? PosImsSubmission::class : PosDiSandboxSubmission::class)::where('company_id',$row->company_id)->find($row->submission_id) : null;
            $uncertain=$record && in_array($record->state,['submitting','verification_required','queued_agent','accepted','local_accepted'],true);
            $row->update(['state'=>$uncertain ? 'verification_required' : 'blocked','error_code'=>$uncertain ? 'VERIFY_OUTCOME' : 'REVIEW_SETUP_OR_SNAPSHOT']);
            report($e); // existing redacted server evidence; no raw exception in customer receipt
        }
    }
}

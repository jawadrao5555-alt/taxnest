<?php

namespace App\Services;

use App\Models\FbrPosTransaction;
use App\Models\User;
use App\Models\Company;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

final class FbrPosOwnerReconciliation
{
    public static function authorize(User $actor, int $companyId): void
    {
        abort_unless((int) $actor->company_id === $companyId && $actor->role === 'company_admin', 403);
    }

    public static function eligible(FbrPosTransaction $bill): bool
    {
        if ($bill->invoice_mode === 'local') return false;
        if ($bill->fbr_status === 'verification_pending') return true;
        return $bill->fbr_status === 'pending' && $bill->fbr_submission_hash && $bill->fbr_agent_claimed_at
            && \Carbon\Carbon::parse($bill->fbr_agent_claimed_at)->lte(now()->subMinutes(3));
    }

    public static function fingerprint(FbrPosTransaction $bill): string
    {
        $company = Company::findOrFail($bill->company_id);
        return hash('sha256', json_encode([$bill->id, $bill->company_id, $bill->invoice_number,
            $bill->invoice_mode, $bill->fbr_status, $bill->fbr_invoice_number, $bill->fbr_submission_hash,
            $bill->fbr_agent_claimed_at, $bill->total_amount, $bill->updated_at?->format('Y-m-d H:i:s.u'),
            $company->fbr_pos_environment, $company->fbr_pos_id]));
    }

    public function resolve(User $actor, int $companyId, int $id, array $data): FbrPosTransaction
    {
        self::authorize($actor, $companyId);
        $data = validator($data, [
            'request_key' => 'required|uuid', 'fingerprint' => 'required|string|size:64',
            'decision' => 'required|in:accepted,not_accepted',
            'evidence_source' => 'required|in:ims_log,fbr_portal',
            'evidence_reference' => ['required', 'string', 'max:160', 'regex:/^[\pL\pN ._\-\/():]+$/u'],
            'environment' => 'required|in:production,sandbox',
            'fiscal_number' => ['nullable', 'string', 'regex:/^(?=.*[0-9])[A-Za-z0-9\-]{6,100}$/D'],
            'confirmed' => 'accepted',
        ])->validate();
        $requested = strtolower((string) Company::findOrFail($companyId)->fbr_pos_environment) === 'production' ? 'production' : 'sandbox';
        if ($data['environment'] !== $requested) {
            throw ValidationException::withMessages(['environment' => 'Checked IMS environment differs from the company environment. Resolve the configuration mismatch before release.']);
        }
        if ($data['decision'] === 'not_accepted' && $data['evidence_source'] !== 'ims_log') {
            throw ValidationException::withMessages(['evidence_source' => 'A missing portal record is not proof of rejection. Check the local IMS submission log.']);
        }
        if ($data['decision'] === 'accepted' && empty($data['fiscal_number'])) {
            throw ValidationException::withMessages(['fiscal_number' => 'Enter the actual fiscal invoice number from the checked evidence.']);
        }
        return DB::transaction(function () use ($actor, $companyId, $id, $data) {
            Company::where('id', $companyId)->lockForUpdate()->firstOrFail();
            $bill = FbrPosTransaction::withoutGlobalScopes()->where('company_id', $companyId)->lockForUpdate()->findOrFail($id);
            $audit = DB::table('fbr_pos_owner_reconciliations')->where('company_id', $companyId)
                ->where('transaction_id', $id)->where('request_key', $data['request_key'])->first();
            $fiscalHash = empty($data['fiscal_number']) ? null : hash('sha256', $data['fiscal_number']);
            if ($audit) {
                if ($audit->decision !== $data['decision'] || $audit->fiscal_number_hash !== $fiscalHash
                    || $audit->evidence_source !== $data['evidence_source'] || $audit->evidence_reference !== $data['evidence_reference']
                    || $audit->environment !== $data['environment']) {
                    throw ValidationException::withMessages(['request_key' => 'This confirmation was already used for different evidence.']);
                }
                return $bill;
            }
            if (!self::eligible($bill) || !hash_equals(self::fingerprint($bill), $data['fingerprint'])) {
                throw ValidationException::withMessages(['fingerprint' => 'The invoice changed or no longer needs reconciliation. Reload it.']);
            }
            if ($bill->fbr_invoice_number && ($data['decision'] !== 'accepted' || $bill->fbr_invoice_number !== $data['fiscal_number'])) {
                throw ValidationException::withMessages(['fiscal_number' => 'An existing fiscal number cannot be removed or replaced.']);
            }
            $patch = ['fbr_status' => $data['decision'] === 'accepted' ? 'submitted' : 'failed', 'fbr_submission_hash' => null];
            if ($bill->getAttribute('fbr_agent_claimed_at') !== null) $patch['fbr_agent_claimed_at'] = null;
            if ($data['decision'] === 'accepted') $patch['fbr_invoice_number'] = $data['fiscal_number'];
            // No fabricated Code 100, response or automatic central-verification claim.
            $bill->update($patch);
            DB::table('fbr_pos_owner_reconciliations')->insert([
                'company_id' => $companyId, 'transaction_id' => $id, 'actor_id' => $actor->id,
                'request_key' => $data['request_key'], 'decision' => $data['decision'],
                'evidence_source' => $data['evidence_source'], 'evidence_reference' => $data['evidence_reference'],
                'environment' => $data['environment'], 'fiscal_number_hash' => $fiscalHash,
                'before_fingerprint' => $data['fingerprint'], 'checked_at' => now(), 'created_at' => now(),
            ]);
            return $bill;
        });
    }
}

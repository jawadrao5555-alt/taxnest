<?php

namespace App\Services;

use App\Models\FranchiseCommission;
use App\Models\PaymentProof;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;

class FranchiseCommissionService
{
    /** Repair only proofs with a frozen franchise snapshot; never guess legacy attribution. */
    public static function reconcile(int $franchiseId): int
    {
        $created = 0;
        PaymentProof::where('status', 'verified')
            ->where('franchise_id_at_verification', $franchiseId)
            ->orderBy('id')->chunkById(100, function ($proofs) use (&$created) {
                foreach ($proofs as $proof) {
                    if (!FranchiseCommission::where('payment_proof_id', $proof->id)->exists()) {
                        self::recordForProof($proof);
                        if (FranchiseCommission::where('payment_proof_id', $proof->id)->exists()) $created++;
                    }
                }
            });
        return $created;
    }

    /** A verified package receipt is the only automatic earning event. */
    public static function recordForProof(PaymentProof $proof): void
    {
        try {
            if (!Schema::hasTable('franchise_commissions')
                || $proof->status !== 'verified' || $proof->kind() !== 'subscription'
                || (float) $proof->amount <= 0) {
                return;
            }

            // NULL means the payment predated this policy or had no active
            // franchise at verification. Never infer historical attribution.
            if (!$proof->franchise_id_at_verification) {
                return;
            }
            $base = round((float) ($proof->distributor_net_amount ?? $proof->amount), 2);
            $rate = round((float) $proof->franchise_rate_at_verification, 2);
            $conflict = (bool) $proof->franchise_attribution_conflict;
            FranchiseCommission::firstOrCreate(['payment_proof_id' => $proof->id], [
                'franchise_id' => $proof->franchise_id_at_verification,
                'company_id' => $proof->company_id,
                'company_name' => \App\Models\Company::withTrashed()->find($proof->company_id)?->name ?? 'Company #'.$proof->company_id,
                'base_amount' => $base,
                'rate_percent' => $rate,
                'amount' => $conflict ? 0 : round($base * $rate / 100, 2),
                'status' => $conflict ? 'attribution_conflict' : 'pending',
                'earned_at' => $proof->verified_at ?? now(),
            ]);
        } catch (\Throwable $e) {
            // Never undo an already verified customer payment for accounting UI.
            Log::warning('Franchise commission record failed', [
                'payment_proof_id' => $proof->id, 'error' => $e->getMessage(),
            ]);
        }
    }
}

<?php

namespace App\Services;

use App\Models\Agent;
use App\Models\AgentCommission;
use App\Models\Company;
use App\Models\PaymentProof;
use App\Models\AdminAuditLog;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;

/**
 * Automatic agent commission bookkeeping.
 *
 * Earn lines are created from VERIFIED payment proofs (the only "cleared
 * payment" record in the SaaS billing flow). The company's FIRST verified
 * proof counts as a NEW sale (rate_new); every later one is a RENEWAL
 * (rate_renewal). Rates are frozen on the ledger line at creation time —
 * changing an agent's Schedule A rates only affects future lines.
 *
 * PACKAGE proofs only. The extra-branch add-on (Rs 10,000/branch/year) rides
 * on the same payment_proofs table but is NOT a package sale: commissioning it
 * would both invent ledger lines and — worse — let an add-on proof count as the
 * company's "earlier" payment, silently demoting a real new sale to the lower
 * renewal rate. Every proof query here stays on the subscription lane.
 */
class AgentCommissionService
{
    /** Called right after a payment proof flips to verified. Never breaks approval. */
    public static function recordForProof(PaymentProof $proof): void
    {
        try {
            if (!Schema::hasTable('agent_commissions')) {
                return;
            }
            if ($proof->isExtraBranch()) {
                return;
            }
            self::createEarnLine($proof);
        } catch (\Throwable $e) {
            Log::warning('Agent commission record failed', [
                'payment_proof_id' => $proof->id,
                'error' => $e->getMessage(),
            ]);
        }
    }

    /**
     * Backfill safety net: make sure every verified proof of this agent's
     * companies has a ledger line (covers proofs verified before the agent
     * was linked to the company, or before this feature deployed).
     */
    public static function syncForAgent(Agent $agent): void
    {
        try {
            $companyIds = Company::withTrashed()->where('agent_id', $agent->id)->pluck('id');
            if ($companyIds->isEmpty()) {
                return;
            }

            $proofs = PaymentProof::subscriptionKind()
                ->whereIn('company_id', $companyIds)
                ->where('status', 'verified')
                ->orderBy('verified_at')
                ->orderBy('id')
                ->get();

            $existing = AgentCommission::where('agent_id', $agent->id)
                ->whereNotNull('payment_proof_id')
                ->pluck('payment_proof_id')
                ->all();
            $existing = array_flip($existing);

            foreach ($proofs as $proof) {
                if (!isset($existing[$proof->id])) {
                    self::createEarnLine($proof);
                }
            }
        } catch (\Throwable $e) {
            Log::warning('Agent commission sync failed', [
                'agent_id' => $agent->id,
                'error' => $e->getMessage(),
            ]);
        }
    }

    private static function createEarnLine(PaymentProof $proof): void
    {
        if ($proof->status !== 'verified' || (float) $proof->amount <= 0) {
            return;
        }

        $company = Company::withTrashed()->find($proof->company_id);
        if (!$company || !$company->agent_id) {
            return;
        }

        $agent = Agent::find($company->agent_id);
        if (!$agent) {
            return;
        }

        // Decision guard — one decision line (earn OR skipped) per proof, ever.
        if (AgentCommission::where('payment_proof_id', $proof->id)
            ->whereIn('type', ['new', 'renewal', 'skipped'])->exists()) {
            return;
        }

        $when = $proof->verified_at ?? $proof->created_at ?? now();

        // Terminated agents earn NO commission (agreement clause). The decision
        // is PERSISTED as an amount-0 'skipped' line at the payment's clearing
        // time, so a later reactivation + backfill can never retroactively award
        // commission for the terminated period.
        if (!$agent->wasActiveAt($when)) {
            $line = AgentCommission::create([
                'agent_id' => $agent->id,
                'company_id' => $company->id,
                'company_name' => $company->name,
                'payment_proof_id' => $proof->id,
                'type' => 'skipped',
                'base_amount' => round((float) $proof->amount, 2),
                'rate_percent' => 0,
                'amount' => 0,
                'period_month' => $when->copy()->startOfMonth()->toDateString(),
                'description' => "No commission — agent terminated when payment cleared (proof #{$proof->id})",
            ]);
            self::auditCreated($line);
            return;
        }

        // First cleared PACKAGE payment for the company = NEW sale; later =
        // renewal. Add-on proofs are excluded so they can never demote a real
        // new sale to the renewal rate.
        $earlierProofExists = PaymentProof::subscriptionKind()
            ->where('company_id', $company->id)
            ->where('status', 'verified')
            ->where('id', '!=', $proof->id)
            ->where(function ($q) use ($proof) {
                $ts = $proof->verified_at ?? $proof->created_at;
                $q->where('verified_at', '<', $ts)
                  ->orWhere(function ($q2) use ($ts, $proof) {
                      $q2->where('verified_at', '=', $ts)->where('id', '<', $proof->id);
                  });
            })
            ->exists();

        $type = $earlierProofExists ? 'renewal' : 'new';
        $rate = (float) ($type === 'new' ? $agent->rate_new : $agent->rate_renewal);
        $base = (float) $proof->amount;

        $line = AgentCommission::create([
            'agent_id' => $agent->id,
            'company_id' => $company->id,
            'company_name' => $company->name,
            'payment_proof_id' => $proof->id,
            'type' => $type,
            'base_amount' => round($base, 2),
            'rate_percent' => round($rate, 2),
            'amount' => round($base * $rate / 100, 2),
            'period_month' => $when->copy()->startOfMonth()->toDateString(),
            'description' => ($type === 'new' ? 'New sale' : 'Renewal') . " — payment proof #{$proof->id}",
        ]);
        self::auditCreated($line);
    }

    private static function auditCreated(AgentCommission $line): void
    {
        $adminId = auth('admin')->id();
        if ($adminId) {
            AdminAuditLog::log($adminId, 'Agent commission created', 'AgentCommission', $line->id, [
                'agent_id' => $line->agent_id,
                'company_id' => $line->company_id,
                'payment_proof_id' => $line->payment_proof_id,
                'type' => $line->type,
                'amount' => (float) $line->amount,
            ]);
        }
    }
}

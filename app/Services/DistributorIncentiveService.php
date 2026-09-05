<?php
namespace App\Services;

use App\Models\Agent;
use App\Models\AgentCommission;
use App\Models\AgentIncentiveAward;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/** Creates one immutable, idempotent quarterly award after the 30-day hold. */
class DistributorIncentiveService
{
    public static function award(Agent $agent, string $quarter): ?AgentIncentiveAward
    {
        if (!preg_match('/^(\d{4})-Q([1-4])$/', $quarter, $m)) return null;
        $start = Carbon::create((int)$m[1], ((int)$m[2]-1)*3+1, 1)->startOfDay();
        $end = $start->copy()->addMonths(3);
        // Wait until every sale in the completed quarter has cleared the
        // contractual 30-day quality window before freezing its single award.
        if (now()->lt($end->copy()->addDays(30))) return null;

        return DB::transaction(function () use ($agent, $quarter, $start, $end) {
            $existing = AgentIncentiveAward::where('agent_id', $agent->id)
                ->where('quarter', $quarter)->lockForUpdate()->first();
            if ($existing) return $existing;

            // Quarter and maturity are based on the payment's verified_at,
            // never on a delayed/backfilled commission row's created_at.
            $lines = AgentCommission::query()
                ->join('payment_proofs as pp', 'pp.id', '=', 'agent_commissions.payment_proof_id')
                ->where('agent_commissions.agent_id', $agent->id)
                ->where('agent_commissions.type', 'new')
                ->where('agent_commissions.commission_year', 1)
                ->where('pp.status', 'verified')
                ->where('pp.verified_at', '>=', $start)
                ->where('pp.verified_at', '<', $end)
                ->where('pp.verified_at', '<=', now()->subDays(30))
                ->select('agent_commissions.*', 'pp.verified_at as payment_verified_at')
                ->get()
                ->filter(fn ($line) => !AgentCommission::where('payment_proof_id', $line->payment_proof_id)
                    ->where('type', 'clawback')->exists())
                ->unique('company_id')->values();

            $count = $lines->count();
            $tier = 0;
            $policy = DistributorPolicyService::policy();
            foreach ($policy['tiers'] as $candidate) {
                if ($count >= (int) $candidate['companies']) {
                    $tier = max($tier, (float) $candidate['rate']);
                }
            }
            if (!$tier) return null;

            $base = (float) $lines->sum('base_amount');
            // The unique (agent_id, quarter) key is the final concurrency
            // guard. Eloquent's create-or-first path returns the winning row
            // with wasRecentlyCreated=false when another request wins.
            return AgentIncentiveAward::firstOrCreate(
                ['agent_id' => $agent->id, 'quarter' => $quarter],
                [
                    'qualified_companies' => $count,
                    'rate_percent' => $tier,
                    'base_amount' => $base,
                    'amount' => round($base * $tier / 100, 2),
                    'snapshot' => [
                        'commission_ids' => $lines->pluck('id')->all(),
                        'payment_proof_ids' => $lines->pluck('payment_proof_id')->all(),
                        'verified_at' => $lines->pluck('payment_verified_at')->map(fn ($at) => (string) $at)->all(),
                        'policy' => $policy,
                    ],
                ]
            );
        }, 3);
    }
}
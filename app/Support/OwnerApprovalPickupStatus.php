<?php

namespace App\Support;

use App\Models\OwnerDeploymentApprovalRequest;
use Carbon\CarbonInterface;
use Illuminate\Support\Collection;

/**
 * Owner-facing pickup / recovery copy for the single-admin-approval flow.
 *
 * Does not start GitHub workflows. Immediate automatic dispatch would need a
 * GitHub App installation token with actions:write — that is an owner
 * security decision and is not stored in this application.
 */
final class OwnerApprovalPickupStatus
{
    public const WAITING_STATUSES = ['approved', 'dispatching'];

    public static function delayWarnMinutes(): int
    {
        return max(5, (int) config('deployment_approval.schedule_delay_warn_minutes', 20));
    }

    public static function summarize($requests, ?array $heartbeat = null): array
    {
        $heartbeat ??= OwnerApprovalPollerHeartbeat::snapshot();
        $last = $heartbeat['recorded_at'] ?? null;
        $last = $last instanceof CarbonInterface ? $last : null;
        $age = $last?->diffInMinutes(now());
        $waiting = Collection::make($requests)
            ->filter(fn ($row) => $row instanceof OwnerDeploymentApprovalRequest
                && in_array($row->status, self::WAITING_STATUSES, true)
                && $row->isUnexpired());
        $delayed = $last === null || ($age !== null && $age >= self::delayWarnMinutes());
        $recoveryNeeded = $delayed && $waiting->isNotEmpty();

        return [
            'last_poller_at' => $last,
            'poller_age_minutes' => $age,
            'poller_run_id' => (int) ($heartbeat['run_id'] ?? 0),
            'poller_run_url' => self::githubRunUrl((int) ($heartbeat['run_id'] ?? 0)),
            'waiting_count' => $waiting->count(),
            'delayed_schedule' => $delayed,
            'recovery_needed' => $recoveryNeeded,
            'immediate_dispatch_requires_app_token' => true,
            'headline' => $recoveryNeeded
                ? 'GitHub scheduled pickup is delayed'
                : ($waiting->isNotEmpty()
                    ? 'Waiting for the next GitHub scheduled pickup'
                    : 'Relay poller status'),
            'guidance' => $recoveryNeeded
                ? 'Do not approve again. GitHub cron is not a guaranteed timer. Open GitHub Actions → Approval Relay Dispatch → Run workflow. Immediate auto-dispatch would require a GitHub App installation token, which this app does not store.'
                : ($waiting->isNotEmpty()
                    ? 'One TaxNest Admin approval is enough. The scheduled relay will lease this request, start Owner Merge & Deploy, then exact-SHA deploy. Do not approve again.'
                    : 'The relay records a heartbeat on every OIDC poll, including empty claims. A stale heartbeat means GitHub did not schedule the poller — not that a PAT is missing.'),
        ];
    }

    public static function forRequest(OwnerDeploymentApprovalRequest $row, array $poller): array
    {
        $status = strtolower((string) $row->status);
        $leaseExpired = $row->dispatch_lease_expires_at !== null
            && $row->dispatch_lease_expires_at->isPast();
        $recoveryNeeded = (bool) ($poller['recovery_needed'] ?? false);

        return match ($status) {
            'pending' => [
                'label' => 'Password approval needed',
                'tone' => 'amber',
                'message' => 'Enter your current admin password once. Do not start any GitHub workflow yourself.',
            ],
            'approved' => [
                'label' => $recoveryNeeded ? 'Pickup delayed' : 'Queued for automatic pickup',
                'tone' => $recoveryNeeded ? 'amber' : 'cyan',
                'message' => $recoveryNeeded
                    ? 'The poller heartbeat is stale. Do not approve again. Use GitHub Actions → Approval Relay Dispatch → Run workflow.'
                    : 'Waiting for the next GitHub scheduled Approval Relay. Do not approve again.',
            ],
            'dispatching' => [
                'label' => $leaseExpired ? 'Stale lease — next poller will retry' : 'Relay leased',
                'tone' => $leaseExpired || $recoveryNeeded ? 'amber' : 'cyan',
                'message' => $leaseExpired
                    ? 'The previous dispatch lease expired without a claim. The next poller will re-lease this exact PR and SHA. Do not approve again.'
                    : 'Owner Merge & Deploy is being started for this exact PR and HEAD SHA.',
            ],
            'claimed' => [
                'label' => 'Merge in progress',
                'tone' => 'cyan',
                'message' => 'The owner workflow claimed this approval. Exact-SHA squash merge is running.',
            ],
            'merged', 'deploy_registered', 'deploying' => [
                'label' => 'Production deploy in progress',
                'tone' => 'cyan',
                'message' => 'The squash SHA is bound. Deploy Production must use that exact SHA.',
            ],
            'succeeded' => [
                'label' => 'Deployed',
                'tone' => 'emerald',
                'message' => 'The recorded squash SHA was deployed. Live verification belongs to the Actions run.',
            ],
            'failed' => [
                'label' => 'Failed',
                'tone' => 'rose',
                'message' => $row->isUnexpired()
                    ? 'See the failure summary. A recoverable request returns to approved for the next poller. Do not invent a second approval unless this request expired.'
                    : 'This approval expired. A fresh TaxNest Admin approval is required for the same PR and current HEAD SHA.',
            ],
            default => [
                'label' => ucfirst($status),
                'tone' => 'slate',
                'message' => 'Release status is recorded on this request.',
            ],
        };
    }

    public static function githubRunUrl(int $runId): ?string
    {
        if ($runId <= 0) {
            return null;
        }

        return 'https://github.com/'.config('deployment_approval.repository').'/actions/runs/'.$runId;
    }
}

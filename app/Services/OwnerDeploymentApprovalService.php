<?php

namespace App\Services;

use App\Models\AdminAuditLog;
use App\Models\OwnerDeploymentApprovalRequest;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;

class OwnerDeploymentApprovalService
{
    public const REPOSITORY = 'jawadrao5555-alt/taxnest';

    public function validatePullRequest(int $number, string $sha, ?string $expectedMergeSha = null): array
    {
        if (!preg_match('/^[0-9a-f]{40}$/i', $sha)) {
            throw new \InvalidArgumentException('HEAD SHA must be exactly 40 hexadecimal characters.');
        }

        $headers = [
            'Accept' => 'application/vnd.github+json',
            'User-Agent' => 'TaxNest-owner-approval-relay',
            'X-GitHub-Api-Version' => '2022-11-28',
        ];
        $pr = Http::withHeaders($headers)
            ->timeout(10)
            ->get('https://api.github.com/repos/'.self::REPOSITORY.'/pulls/'.$number);

        if (!$pr->successful()) {
            throw new \InvalidArgumentException('GitHub pull request could not be validated.');
        }

        $data = $pr->json();

        $commonBindingInvalid = ($data['draft'] ?? true)
            || ($data['base']['ref'] ?? null) !== 'main'
            || ($data['base']['repo']['full_name'] ?? null) !== self::REPOSITORY
            || ($data['head']['repo']['full_name'] ?? null) !== self::REPOSITORY
            || !hash_equals(strtolower($sha), strtolower((string) ($data['head']['sha'] ?? '')));
        $stateInvalid = $expectedMergeSha
            ? (($data['state'] ?? null) !== 'closed'
                || !($data['merged'] ?? false)
                || !hash_equals(strtolower($expectedMergeSha), strtolower((string) ($data['merge_commit_sha'] ?? ''))))
            : (($data['state'] ?? null) !== 'open');

        if ($commonBindingInvalid || $stateInvalid) {
            throw new \InvalidArgumentException('Pull request is not an eligible TaxNest deployment.');
        }

        if ($expectedMergeSha) {
            $main = Http::withHeaders($headers)
                ->timeout(10)
                ->get('https://api.github.com/repos/'.self::REPOSITORY.'/commits/main');
            if (
                !$main->successful()
                || !hash_equals(strtolower($expectedMergeSha), strtolower((string) $main->json('sha', '')))
            ) {
                throw new \InvalidArgumentException('Approved merge is no longer the current main tip.');
            }
        }

        $checks = Http::withHeaders($headers)
            ->timeout(10)
            ->get('https://api.github.com/repos/'.self::REPOSITORY.'/commits/'.$sha.'/check-runs?per_page=100');
        $runs = collect($checks->json('check_runs', []));

        $validate = $runs->firstWhere('name', 'validate');
        if (
            !$checks->successful()
            || !$validate
            || ($validate['status'] ?? null) !== 'completed'
            || ($validate['conclusion'] ?? null) !== 'success'
        ) {
            throw new \InvalidArgumentException('The required validate check must succeed.');
        }

        return $data;
    }

    public function create(array $input, int $adminId): OwnerDeploymentApprovalRequest
    {
        $number = (int) $input['pull_request_number'];
        $sha = strtolower($input['head_sha']);

        $existing = OwnerDeploymentApprovalRequest::query()
            ->where('repository', self::REPOSITORY)
            ->where('pull_request_number', $number)
            ->where('head_sha', $sha)
            ->whereIn('status', ['pending', 'approved', 'dispatching', 'claimed', 'merged', 'deploy_registered', 'deploying'])
            ->where('expires_at', '>', now())
            ->latest()
            ->first();

        $recoverySource = $existing?->merge_sha ? $existing : OwnerDeploymentApprovalRequest::query()
            ->where('repository', self::REPOSITORY)
            ->where('pull_request_number', $number)
            ->where('head_sha', $sha)
            ->whereNotNull('merge_sha')
            ->where(function ($query) {
                $query->where('status', 'failed')
                    ->orWhere(function ($expiredMerged) {
                        $expiredMerged->where('status', 'merged')
                            ->whereNull('deployment_run_id')
                            ->where('expires_at', '<=', now());
                    });
            })
            ->latest()
            ->first();

        $this->validatePullRequest($number, $sha, $recoverySource?->merge_sha);

        if ($existing) {
            return $existing;
        }

        $attributes = [
            'pull_request_number' => $number,
            'head_sha' => $sha,
            'repository' => self::REPOSITORY,
            'status' => 'pending',
            'requested_admin_id' => $adminId,
            'expires_at' => now()->addMinutes((int) config('deployment_approval.approval_ttl_minutes', 30)),
            'merge_sha' => $recoverySource?->merge_sha,
        ];

        $row = OwnerDeploymentApprovalRequest::create($attributes);
        AdminAuditLog::log($adminId, 'owner_deployment_requested', self::class, null, [
            'request_id' => $row->request_id,
            'repository' => $row->repository,
            'pull_request_number' => $row->pull_request_number,
            'head_sha' => $row->head_sha,
        ]);

        return $row;
    }

    public function approve(OwnerDeploymentApprovalRequest $row, int $adminId): OwnerDeploymentApprovalRequest
    {
        $this->validatePullRequest($row->pull_request_number, $row->head_sha, $row->merge_sha);

        $row = DB::transaction(function () use ($row, $adminId) {
            $locked = OwnerDeploymentApprovalRequest::lockForUpdate()->findOrFail($row->request_id);

            if (!$locked->isUnexpired()) {
                throw new \InvalidArgumentException('This approval request has expired.');
            }
            if ($locked->status === 'approved') {
                return $locked;
            }
            if ($locked->status !== 'pending') {
                throw new \InvalidArgumentException("A {$locked->status} request cannot be approved.");
            }

            $locked->update([
                'status' => 'approved',
                'approved_admin_id' => $adminId,
                'approved_at' => now(),
                'expires_at' => now()->addMinutes((int) config('deployment_approval.approval_ttl_minutes', 30)),
            ]);

            return $locked->fresh();
        });

        AdminAuditLog::log($adminId, 'owner_deployment_approved', self::class, null, [
            'request_id' => $row->request_id,
            'repository' => $row->repository,
            'pull_request_number' => $row->pull_request_number,
            'head_sha' => $row->head_sha,
        ]);

        return $row;
    }

    public function leaseApprovedRequests(): array
    {
        return DB::transaction(function (): array {
            $now = now();
            $rows = OwnerDeploymentApprovalRequest::query()
                ->where('expires_at', '>', $now)
                ->where(function ($query) use ($now) {
                    $query->where('status', 'approved')
                        ->orWhere(function ($retry) use ($now) {
                            $retry->where('status', 'dispatching')
                                ->where('dispatch_lease_expires_at', '<=', $now);
                        });
                })
                ->orderBy('created_at')
                ->lockForUpdate()
                ->limit(10)
                ->get();

            return $rows->map(function (OwnerDeploymentApprovalRequest $row): array {
                $row->update([
                    'status' => 'dispatching',
                    'dispatch_lease_id' => (string) Str::uuid(),
                    'dispatch_lease_expires_at' => now()->addMinutes(
                        (int) config('deployment_approval.dispatch_lease_minutes', 10)
                    ),
                ]);

                return [
                    'approval_request_id' => $row->request_id,
                    'pull_number' => $row->pull_request_number,
                    'expected_head_sha' => $row->head_sha,
                ];
            })->all();
        });
    }

    public function claimForMerge(array $input, array $claims = []): array
    {
        $row = OwnerDeploymentApprovalRequest::findOrFail($input['approval_request_id']);

        if (
            $input['repository'] !== $row->repository
            || (int) $input['pull_number'] !== $row->pull_request_number
            || !hash_equals(strtolower($row->head_sha), strtolower($input['expected_head_sha']))
        ) {
            abort(409, 'Approval binding mismatch.');
        }

        $this->validatePullRequest($row->pull_request_number, $row->head_sha, $row->merge_sha);
        $receipt = bin2hex(random_bytes(32));

        DB::transaction(function () use ($row, $receipt, $claims): void {
            $locked = OwnerDeploymentApprovalRequest::lockForUpdate()->findOrFail($row->request_id);

            abort_unless(
                $locked->status === 'dispatching'
                && $locked->isUnexpired()
                && $locked->dispatch_lease_expires_at?->isFuture()
                && !$locked->provenance_receipt_hash,
                409,
                'Approval request is not claimable.'
            );

            $locked->update([
                'status' => 'claimed',
                'claimed_at' => now(),
                'provenance_receipt_hash' => hash('sha256', $receipt),
                'owner_workflow_sha' => strtolower($claims['workflow_sha'] ?? ''),
                'owner_workflow_run_id' => (int)($claims['run_id'] ?? 0),
                'owner_workflow_run_attempt' => (int)($claims['run_attempt'] ?? 0),
            ]);
        });

        return [
            'approval_request_id' => $row->request_id,
            'provenance_receipt' => $receipt,
        ];
    }

    public function recordMerge(string $requestId, string $receipt, string $mergeSha, array $claims = []): void
    {
        DB::transaction(function () use ($requestId, $receipt, $mergeSha, $claims): void {
            $row = OwnerDeploymentApprovalRequest::lockForUpdate()->findOrFail($requestId);
            $receiptHash = hash('sha256', $receipt);
            $mergeSha = strtolower($mergeSha);

            abort_unless($row->isUnexpired() && hash_equals((string) $row->provenance_receipt_hash, $receiptHash)
                && ($claims['workflow_sha'] ?? '') === $row->owner_workflow_sha
                && (int)($claims['run_id'] ?? 0) === (int)$row->owner_workflow_run_id
                && (int)($claims['run_attempt'] ?? 0) === (int)$row->owner_workflow_run_attempt, 409, 'Invalid receipt or owner workflow.');

            if ($row->status === 'merged' && hash_equals((string) $row->merge_sha, $mergeSha)) {
                return;
            }

            abort_unless(
                $row->status === 'claimed'
                && (!$row->merge_sha || hash_equals((string) $row->merge_sha, $mergeSha)),
                409,
                'Merge result cannot be recorded.'
            );
            $row->update(['status' => 'merged', 'merge_sha' => $mergeSha]);
        });
    }

    public function recordOwnerWorkflowFailure(
        string $requestId,
        array $input,
        array $claims
    ): OwnerDeploymentApprovalRequest {
        $snapshot = OwnerDeploymentApprovalRequest::findOrFail($requestId);
        if (!in_array($snapshot->status, ['claimed', 'merged'], true)) {
            return $snapshot;
        }
        abort_unless($this->ownerWorkflowMatches($snapshot, $claims), 409, 'Owner workflow mismatch.');

        $recovery = ['recoverable' => true, 'merge_sha' => $snapshot->merge_sha];
        if ($snapshot->isUnexpired() && $snapshot->status === 'claimed' && !$snapshot->merge_sha) {
            $recovery = $this->resolveOwnerFailureRecovery($snapshot);
        }

        return DB::transaction(function () use ($requestId, $input, $claims, $recovery) {
            $row = OwnerDeploymentApprovalRequest::lockForUpdate()->findOrFail($requestId);

            if (!in_array($row->status, ['claimed', 'merged'], true)) {
                return $row;
            }

            abort_unless($this->ownerWorkflowMatches($row, $claims), 409, 'Owner workflow mismatch.');

            $recoverable = $row->isUnexpired() && $recovery['recoverable'];

            $row->update([
                'status' => $recoverable ? 'approved' : 'failed',
                'dispatch_lease_id' => null,
                'dispatch_lease_expires_at' => null,
                'claimed_at' => null,
                'provenance_receipt_hash' => null,
                'provenance_receipt_used_at' => null,
                'owner_workflow_sha' => null,
                'owner_workflow_run_id' => null,
                'owner_workflow_run_attempt' => null,
                'merge_sha' => $recovery['merge_sha'],
                'failure_summary' => $recoverable
                    ? ($input['failure_summary'] ?? 'Owner Merge & Deploy failed before handoff completed.')
                    : ($row->isUnexpired()
                        ? 'Owner Merge & Deploy failed after the PR became ineligible for a safe retry.'
                        : 'Owner approval expired before Owner Merge & Deploy completed. Fresh approval is required.'),
            ]);

            return $row->fresh();
        });
    }

    private function ownerWorkflowMatches(OwnerDeploymentApprovalRequest $row, array $claims): bool
    {
        return ($claims['workflow_sha'] ?? '') === $row->owner_workflow_sha
            && (int) ($claims['run_id'] ?? 0) === (int) $row->owner_workflow_run_id
            && (int) ($claims['run_attempt'] ?? 0) === (int) $row->owner_workflow_run_attempt;
    }

    private function resolveOwnerFailureRecovery(OwnerDeploymentApprovalRequest $row): array
    {
        $headers = [
            'Accept' => 'application/vnd.github+json',
            'User-Agent' => 'TaxNest-owner-approval-relay',
            'X-GitHub-Api-Version' => '2022-11-28',
        ];
        $response = Http::withHeaders($headers)
            ->timeout(10)
            ->get('https://api.github.com/repos/'.self::REPOSITORY.'/pulls/'.$row->pull_request_number);

        if (!$response->successful()) {
            throw new \RuntimeException('GitHub pull request state could not be recovered.');
        }

        $pr = $response->json();
        $bindingMatches = !($pr['draft'] ?? true)
            && ($pr['base']['ref'] ?? null) === 'main'
            && ($pr['base']['repo']['full_name'] ?? null) === self::REPOSITORY
            && ($pr['head']['repo']['full_name'] ?? null) === self::REPOSITORY
            && hash_equals(strtolower($row->head_sha), strtolower((string) ($pr['head']['sha'] ?? '')));

        if (!$bindingMatches) {
            return ['recoverable' => false, 'merge_sha' => null];
        }
        if (($pr['state'] ?? null) === 'open') {
            return ['recoverable' => true, 'merge_sha' => null];
        }

        $mergeSha = strtolower((string) ($pr['merge_commit_sha'] ?? ''));
        if (
            ($pr['state'] ?? null) !== 'closed'
            || !($pr['merged'] ?? false)
            || preg_match('/^[0-9a-f]{40}$/', $mergeSha) !== 1
        ) {
            return ['recoverable' => false, 'merge_sha' => null];
        }

        try {
            $this->validatePullRequest($row->pull_request_number, $row->head_sha, $mergeSha);
        } catch (\InvalidArgumentException) {
            return ['recoverable' => false, 'merge_sha' => $mergeSha];
        }

        return ['recoverable' => true, 'merge_sha' => $mergeSha];
    }

    public function registerDeployRun(array $input, array $claims): void
    {
        DB::transaction(function () use ($input, $claims): void {
            $row = OwnerDeploymentApprovalRequest::lockForUpdate()->findOrFail($input['approval_request_id']);
            $deploymentRunId = (int) $input['deployment_run_id'];
            $receiptMatches = hash_equals(
                (string) $row->provenance_receipt_hash,
                hash('sha256', $input['provenance_receipt'])
            );

            if (
                $row->status === 'deploy_registered'
                && $receiptMatches
                && (int) $row->deployment_run_id === $deploymentRunId
                && (int) $row->deployment_run_attempt === (int)($input['deployment_run_attempt'] ?? 0)
                && hash_equals((string)$row->handoff_nonce_hash, hash('sha256', $input['handoff_nonce']))
                && hash_equals((string)$row->merge_sha, strtolower($input['merge_sha']))
                && ($claims['workflow_sha'] ?? '') === $row->owner_workflow_sha
                && (int)($claims['run_id'] ?? 0) === (int)$row->owner_workflow_run_id
                && (int)($claims['run_attempt'] ?? 0) === (int)$row->owner_workflow_run_attempt
            ) {
                return;
            }

            abort_unless(
                $row->isUnexpired()
                && $row->status === 'merged'
                && !$row->provenance_receipt_used_at
                && !$row->deployment_run_id
                && $row->repository === $input['repository']
                && $receiptMatches
                && hash_equals((string) $row->merge_sha, strtolower($input['merge_sha']))
                && ($claims['workflow_sha'] ?? '') === $row->owner_workflow_sha
                && (int)($claims['run_id'] ?? 0) === (int)$row->owner_workflow_run_id
                && (int)($claims['run_attempt'] ?? 0) === (int)$row->owner_workflow_run_attempt,
                409,
                'Deployment run binding invalid.'
            );
            abort_unless(
                (int) ($claims['run_id'] ?? 0) > 0
                && (int) ($claims['run_attempt'] ?? 0) > 0
                && $deploymentRunId > 0
                && preg_match('/^[0-9a-f]{32}$/', $input['handoff_nonce'] ?? '') === 1,
                403
            );

            $row->update([
                'deployment_run_id' => $deploymentRunId,
                'deployment_run_attempt' => (int)($input['deployment_run_attempt'] ?? 1),
                'handoff_nonce_hash' => hash('sha256', $input['handoff_nonce']),
                'status' => 'deploy_registered',
                'provenance_receipt_used_at' => now(),
            ]);
        });
    }

    public function verifyRegistered(array $input, array $claims): void
    {
        DB::transaction(function () use ($input, $claims): void {
            $row = OwnerDeploymentApprovalRequest::lockForUpdate()->findOrFail($input['approval_request_id']);
            $bindingMatches = $row->repository === $input['repository']
                && hash_equals((string)$row->merge_sha, strtolower($input['target_sha']))
                && (int)$row->deployment_run_id === (int)$input['run_id']
                && (int)$row->deployment_run_attempt === (int)$input['run_attempt']
                && (int)$row->deployment_run_id === (int)($claims['run_id'] ?? 0)
                && (int)$row->deployment_run_attempt === (int)($claims['run_attempt'] ?? 0);
            $bindingMatches = $bindingMatches
                && hash_equals((string)$row->handoff_nonce_hash, hash('sha256', $input['handoff_nonce']))
                && strtolower((string)($claims['workflow_sha'] ?? '')) === strtolower($input['target_sha']);

            if ($row->status === 'deploying' && $bindingMatches) {
                return;
            }

            abort_unless(
                $row->isUnexpired() && $row->status === 'deploy_registered' && $bindingMatches,
                409,
                'Deployment provenance mismatch.'
            );
            $row->update(['status'=>'deploying','deployment_workflow_sha'=>strtolower($claims['workflow_sha'])]);
        });
    }

    public function recordDeploymentResult(string $requestId, array $input, array $claims): OwnerDeploymentApprovalRequest
    {
        return DB::transaction(function () use ($requestId, $input, $claims) {
            $row = OwnerDeploymentApprovalRequest::lockForUpdate()->findOrFail($requestId);

            abort_unless(
                (int) ($input['run_id'] ?? 0) === (int) $row->deployment_run_id
                && (int) ($input['run_attempt'] ?? 0) === (int) $row->deployment_run_attempt
                && (int) ($claims['run_id'] ?? 0) === (int) $row->deployment_run_id
                && (int) ($claims['run_attempt'] ?? 0) === (int) $row->deployment_run_attempt,
                409,
                'Deployment run mismatch.'
            );
            $workflowSha = strtolower((string) ($claims['workflow_sha'] ?? ''));
            $expectedWorkflowSha = strtolower((string) ($row->deployment_workflow_sha ?: $row->merge_sha));
            abort_unless(
                $expectedWorkflowSha !== '' && hash_equals($expectedWorkflowSha, $workflowSha),
                409,
                'Deployment workflow mismatch.'
            );
            abort_unless(
                hash_equals((string) $row->handoff_nonce_hash, hash('sha256', $input['handoff_nonce'])),
                409,
                'Deployment handoff mismatch.'
            );

            $result = $input['outcome'] === 'success' ? 'success' : 'failure';
            $deployedSha = isset($input['deployed_sha']) ? strtolower($input['deployed_sha']) : null;

            if ($row->status === 'succeeded' || $row->status === 'failed') {
                abort_unless($row->deploy_result === $result && ($result !== 'success' || hash_equals((string) $row->deployed_sha, (string) $deployedSha)), 409);
                return $row;
            }

            if ($result === 'success') {
                abort_unless(
                    $row->status === 'deploying'
                    && $deployedSha
                    && hash_equals((string) $row->merge_sha, $deployedSha),
                    409,
                    'Successful deployment SHA does not match the approved merge.'
                );
            }

            abort_unless(in_array($row->status, ['deploy_registered','deploying'], true), 409, 'Deployment is not active.');
            $row->update([
                'status' => $result === 'success' ? 'succeeded' : 'failed',
                'deploy_result' => $result,
                'deployment_workflow_sha' => $row->deployment_workflow_sha ?: $workflowSha,
                'deployed_sha' => $deployedSha,
                'workflow_run_url' => $input['run_url'] ?? null,
                'failure_summary' => $result === 'failure' ? ($input['failure_summary'] ?? $input['outcome']) : null,
            ]);

            return $row->fresh();
        });
    }
}
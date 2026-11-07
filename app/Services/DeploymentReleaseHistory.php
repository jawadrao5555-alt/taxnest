<?php

namespace App\Services;

use App\Models\OwnerDeploymentApprovalRequest;

class DeploymentReleaseHistory
{
    public function __construct(private DeploymentGitHubReader $github) {}

    public function recent(): array
    {
        $repository = OwnerDeploymentApprovalService::REPOSITORY;
        $main = $this->github->get('commits/main', 'release_history_main')->json('sha');
        $pulls = collect($this->github
            ->get('pulls?state=closed&base=main&sort=updated&direction=desc&per_page=50', 'release_history')
            ->json())
            ->filter(fn ($pr) => !empty($pr['merged_at'])
                && preg_match('/^[a-f0-9]{40}$/', (string) ($pr['merge_commit_sha'] ?? '')))
            ->sortByDesc('merged_at')->take(25);

        $attempts = OwnerDeploymentApprovalRequest::query()
            ->where('repository', $repository)
            ->whereIn('pull_request_number', $pulls->pluck('number'))
            ->latest()->get()->groupBy('pull_request_number');

        return $pulls->map(function ($pr) use ($attempts, $main) {
            $sha = $pr['merge_commit_sha'];
            $history = $attempts->get($pr['number'], collect());
            $matching = $history->where('merge_sha', $sha);
            $verified = $matching->first(fn ($row) => $row->status === 'succeeded'
                && $row->deploy_result === 'success' && $row->deployed_sha === $sha && $row->deployment_run_id);
            $latest = $matching->first();
            $active = $history->contains(fn ($row) => in_array($row->status, ['deploy_registered', 'deploying'], true)
                || (in_array($row->status, ['pending', 'approved', 'dispatching', 'claimed', 'merged'], true)
                    && $row->isUnexpired()));
            $trusted = ($pr['head']['repo']['full_name'] ?? null) === OwnerDeploymentApprovalService::REPOSITORY
                && OwnerDeploymentApprovalService::isTrustedHeadBranch($pr['head']['ref'] ?? null);

            return [
                'number' => (int) $pr['number'],
                'title' => $pr['title'],
                'merged_at' => $pr['merged_at'],
                'sha' => $sha,
                'approval_recorded' => $matching->contains(fn ($row) => $row->approved_at !== null),
                'deployment' => $verified ? 'Succeeded' : ($latest ? ucfirst(str_replace('_', ' ', $latest->status)) : 'No deployment record'),
                'live_verified' => (bool) $verified,
                'run_id' => ($verified ?? $latest)?->deployment_run_id,
                'can_request' => $sha === $main && $trusted && !$verified && !$active,
            ];
        })->values()->all();
    }
}

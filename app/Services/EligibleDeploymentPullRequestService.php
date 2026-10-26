<?php

namespace App\Services;

use App\Exceptions\DeploymentGitHubUnavailable;
use Illuminate\Support\Facades\Cache;

class EligibleDeploymentPullRequestService
{
    private const REPOSITORY = OwnerDeploymentApprovalService::REPOSITORY;

    private const CACHE_KEY = 'deployment-eligibility:v2:'.self::REPOSITORY;

    private ?string $availabilityWarning = null;

    private int $cacheSeconds = 60;

    /** @return list<array{number:int,title:string,head_sha:string,head_ref:string,url:string}> */
    public function eligible(): array
    {
        $snapshot = Cache::get(self::CACHE_KEY);
        if (is_array($snapshot)) {
            $this->availabilityWarning = $snapshot['warning'];

            return $snapshot['pull_requests'];
        }
        $this->availabilityWarning = null;
        $this->cacheSeconds = 60;
        $pullRequests = $this->fetchEligible();
        // This cache is display-only. resolve() always rechecks GitHub before approval.
        Cache::put(self::CACHE_KEY, [
            'pull_requests' => $pullRequests,
            'warning' => $this->availabilityWarning,
        ], $this->cacheSeconds);

        return $pullRequests;
    }

    private function fetchEligible(): array
    {
        try {
            $response = app(DeploymentGitHubReader::class)->get('pulls?state=open&base=main&per_page=50', 'pull_list');
        } catch (DeploymentGitHubUnavailable $e) {
            $this->cacheSeconds = $e->retryAfter;
            $this->availabilityWarning = $e->getMessage();
            return [];
        }

        return collect($response->json())
            ->filter(fn ($pr) => $this->basicEligibility($pr))
            ->map(function ($pr) {
                try {
                    // The list already contains the exact branch/repository/HEAD binding.
                    return $this->checkedCandidate($pr);
                } catch (\InvalidArgumentException) {
                    return null;
                }
            })
            ->filter()
            ->values()
            ->all();
    }

    public function availabilityWarning(): ?string
    {
        return $this->availabilityWarning;
    }

    /** @return array{number:int,title:string,head_sha:string,head_ref:string,url:string} */
    public function resolve(int $number): array
    {
        $this->availabilityWarning = null;
        $response = app(DeploymentGitHubReader::class)->get('pulls/'.$number, 'pull_request');
        if (! $this->basicEligibility($response->json())) {
            throw new \InvalidArgumentException('Pull request is not an eligible open cursor/* or replit/* release.');
        }

        $pr = $response->json();

        return $this->checkedCandidate($pr);
    }

    private function checkedCandidate(array $pr): array
    {
        // Stop the list scan after an upstream failure instead of multiplying requests.
        if ($this->availabilityWarning !== null) {
            throw new \InvalidArgumentException($this->availabilityWarning);
        }
        $sha = strtolower((string) data_get($pr, 'head.sha'));
        try {
            $checks = app(DeploymentGitHubReader::class)->get('commits/'.$sha.'/check-runs?per_page=100', 'check_runs');
        } catch (DeploymentGitHubUnavailable $e) {
            $this->cacheSeconds = $e->retryAfter;
            $this->availabilityWarning = $e->getMessage();
            throw $e;
        }
        $validate = collect($checks->json('check_runs', []))->firstWhere('name', 'validate');
        if (! $validate
            || ($validate['status'] ?? null) !== 'completed'
            || ($validate['conclusion'] ?? null) !== 'success') {
            throw new \InvalidArgumentException('The required validate check is not green yet.');
        }

        return [
            'number' => (int) $pr['number'],
            'title' => (string) $pr['title'],
            'head_sha' => $sha,
            'head_ref' => (string) data_get($pr, 'head.ref'),
            'url' => (string) $pr['html_url'],
        ];
    }

    private function basicEligibility(array $pr): bool
    {
        return ($pr['state'] ?? null) === 'open'
            && ! ($pr['draft'] ?? true)
            && data_get($pr, 'base.ref') === 'main'
            && data_get($pr, 'base.repo.full_name') === self::REPOSITORY
            && data_get($pr, 'head.repo.full_name') === self::REPOSITORY
            && OwnerDeploymentApprovalService::isTrustedHeadBranch(data_get($pr, 'head.ref'))
            && preg_match('/^[0-9a-f]{40}$/i', (string) data_get($pr, 'head.sha')) === 1;
    }

}

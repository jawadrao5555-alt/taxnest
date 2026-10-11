<?php

namespace App\Services;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;

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
            $response = $this->github()->get('https://api.github.com/repos/'.self::REPOSITORY.'/pulls', [
                'state' => 'open',
                'base' => 'main',
                'per_page' => 50,
            ]);
        } catch (ConnectionException) {
            $this->availabilityWarning = 'GitHub is temporarily unreachable. Existing approval history is still available, but no new release can be approved until eligibility is verified.';

            return [];
        }

        if (! $response->successful()) {
            $this->setRetryDelay($response);
            $this->availabilityWarning = 'GitHub eligibility returned HTTP '.$response->status().'. Existing approval history is still available; retry after GitHub recovers.';

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
        try {
            $response = $this->github()->get('https://api.github.com/repos/'.self::REPOSITORY.'/pulls/'.$number);
        } catch (ConnectionException) {
            throw new \InvalidArgumentException('GitHub eligibility check is temporarily unavailable. No approval was created.');
        }
        if (! $response->successful()) {
            throw new \InvalidArgumentException('GitHub PR verification returned HTTP '.$response->status().'. No approval was created; retry after GitHub recovers.');
        }
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
            $checks = $this->github()->get('https://api.github.com/repos/'.self::REPOSITORY.'/commits/'.$sha.'/check-runs', [
                'per_page' => 100,
            ]);
        } catch (ConnectionException) {
            $this->availabilityWarning = 'GitHub check status is temporarily unavailable. No approval was created; retry after GitHub recovers.';
            throw new \InvalidArgumentException($this->availabilityWarning);
        }
        if (! $checks->successful()) {
            $this->setRetryDelay($checks);
            $this->availabilityWarning = 'GitHub check verification returned HTTP '.$checks->status().'. Release eligibility could not be verified. Retry after GitHub recovers; refreshing repeatedly will not help.';
            throw new \InvalidArgumentException($this->availabilityWarning);
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

    private function github()
    {
        return Http::withHeaders([
            'Accept' => 'application/vnd.github+json',
            'User-Agent' => 'TaxNest-owner-approval-relay',
            'X-GitHub-Api-Version' => '2022-11-28',
        ])->connectTimeout(2)->timeout(5);
    }

    private function setRetryDelay(\Illuminate\Http\Client\Response $response): void
    {
        $retry = $response->header('Retry-After');
        $reset = $response->header('X-RateLimit-Reset');
        $seconds = ctype_digit((string) $retry) ? (int) $retry : 60;
        if (in_array($response->status(), [403, 429], true) && ctype_digit((string) $reset)) {
            $seconds = max($seconds, (int) $reset - now()->timestamp);
        }
        $this->cacheSeconds = min(3600, max(60, $seconds));
    }
}

<?php

namespace App\Services;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;

class EligibleDeploymentPullRequestService
{
    private const REPOSITORY = OwnerDeploymentApprovalService::REPOSITORY;

    private ?string $availabilityWarning = null;

    /** @return list<array{number:int,title:string,head_sha:string,head_ref:string,url:string}> */
    public function eligible(): array
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
            $this->availabilityWarning = 'GitHub eligibility returned HTTP '.$response->status().'. Existing approval history is still available; retry after GitHub recovers.';

            return [];
        }

        return collect($response->json())
            ->filter(fn ($pr) => $this->basicEligibility($pr))
            ->map(function ($pr) {
                try {
                    return $this->resolve((int) $pr['number']);
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
        try {
            $response = $this->github()->get('https://api.github.com/repos/'.self::REPOSITORY.'/pulls/'.$number);
        } catch (ConnectionException) {
            throw new \InvalidArgumentException('GitHub eligibility check is temporarily unavailable. No approval was created.');
        }
        if (! $response->successful() || ! $this->basicEligibility($response->json())) {
            throw new \InvalidArgumentException('Pull request is not an eligible open cursor/* or replit/* release.');
        }

        $pr = $response->json();
        $sha = strtolower((string) data_get($pr, 'head.sha'));
        try {
            $checks = $this->github()->get('https://api.github.com/repos/'.self::REPOSITORY.'/commits/'.$sha.'/check-runs', [
                'per_page' => 100,
            ]);
        } catch (ConnectionException) {
            throw new \InvalidArgumentException('GitHub check status is temporarily unavailable. No approval was created.');
        }
        $validate = collect($checks->json('check_runs', []))->firstWhere('name', 'validate');
        if (! $checks->successful()
            || ! $validate
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
}

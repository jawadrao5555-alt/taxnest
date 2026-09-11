<?php

namespace App\Services;

use Illuminate\Support\Facades\Http;

class EligibleDeploymentPullRequestService
{
    private const REPOSITORY = OwnerDeploymentApprovalService::REPOSITORY;

    /** @return list<array{number:int,title:string,head_sha:string,head_ref:string,url:string}> */
    public function eligible(): array
    {
        $response = $this->github()->get('https://api.github.com/repos/'.self::REPOSITORY.'/pulls', [
            'state' => 'open',
            'base' => 'main',
            'per_page' => 50,
        ]);

        if (! $response->successful()) {
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

    /** @return array{number:int,title:string,head_sha:string,head_ref:string,url:string} */
    public function resolve(int $number): array
    {
        $response = $this->github()->get('https://api.github.com/repos/'.self::REPOSITORY.'/pulls/'.$number);
        if (! $response->successful() || ! $this->basicEligibility($response->json())) {
            throw new \InvalidArgumentException('Pull request is not an eligible open cursor/* release.');
        }

        $pr = $response->json();
        $sha = strtolower((string) data_get($pr, 'head.sha'));
        $checks = $this->github()->get('https://api.github.com/repos/'.self::REPOSITORY.'/commits/'.$sha.'/check-runs', [
            'per_page' => 100,
        ]);
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
            && str_starts_with((string) data_get($pr, 'head.ref'), 'cursor/')
            && preg_match('/^[0-9a-f]{40}$/i', (string) data_get($pr, 'head.sha')) === 1;
    }

    private function github()
    {
        return Http::withHeaders([
            'Accept' => 'application/vnd.github+json',
            'User-Agent' => 'TaxNest-owner-approval-relay',
            'X-GitHub-Api-Version' => '2022-11-28',
        ])->timeout(10);
    }
}

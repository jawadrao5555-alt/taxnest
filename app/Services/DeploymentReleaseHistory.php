<?php

namespace App\Services;

use App\Exceptions\DeploymentGitHubUnavailable;
use App\Models\OwnerDeploymentApprovalRequest;
use Carbon\CarbonImmutable;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;

class DeploymentReleaseHistory
{
    public const MODULES = [
        'admin' => 'Admin & deployment',
        'pra' => 'PRA POS',
        'fbr-pos' => 'FBR POS',
        'di' => 'Digital Invoice',
        'erp' => 'ERP & inventory',
        'healthcare' => 'Healthcare',
        'billing' => 'Billing',
        'other' => 'Other',
    ];

    private const MODULE_PATTERNS = [
        'admin' => '/\b(admin|deploy(?:ment)?|relay|security|onboarding|support|diagnostics|release)\b/i',
        'pra' => '/\b(pra|nestpos|kot|restaurant|waiter|cashier)\b/i',
        'fbr-pos' => '/\bfbr[\s_-]+pos\b/i',
        'di' => '/\b(di|digital[\s_-]+invoic(?:e|ing))\b/i',
        'erp' => '/\b(erp|erps|inventory|stock|ims|warehouse)\b/i',
        'healthcare' => '/\b(health|healthcare|hospital|medicine|pharmacy|clinical)\b/i',
        'billing' => '/\b(billing|subscription|pricing|renewal|payment)\b/i',
    ];

    public const STATES = [
        'open' => 'Open',
        'draft' => 'Draft',
        'merged' => 'Merged',
        'closed' => 'Closed without merge',
    ];

    public const DEPLOYMENTS = [
        'verified' => 'Live verified',
        'progress' => 'Deployment in progress',
        'failed' => 'Deployment failed',
        'missing' => 'No deployment record',
    ];

    private const REPOSITORY = OwnerDeploymentApprovalService::REPOSITORY;

    public function __construct(private DeploymentGitHubReader $github) {}

    public function paginate(array $filters): LengthAwarePaginator
    {
        $pulls = collect(Cache::remember('deployment-pr-history:v1:'.self::REPOSITORY, 300, fn () => $this->fetchPulls()))
            ->filter(fn (array $pr) => $this->matches($pr, $filters));

        $attempts = OwnerDeploymentApprovalRequest::query()
            ->where('repository', self::REPOSITORY)
            ->whereIntegerInRaw('pull_request_number', $pulls->pluck('number')->all())
            ->latest()->orderByDesc('request_id')->get()->groupBy('pull_request_number');

        $rows = $pulls->map(function (array $pr) use ($attempts) {
            $history = $attempts->get($pr['number'], collect());
            $matching = $pr['merge_sha'] ? $history->where('merge_sha', $pr['merge_sha']) : collect();
            $approvals = $pr['merge_sha']
                ? $matching
                : $history->where('head_sha', $pr['head_sha']);
            $verified = $matching->first(fn ($row) => $row->status === 'succeeded'
                && $row->deploy_result === 'success' && $row->deployed_sha === $pr['merge_sha'] && $row->deployment_run_id);
            $latest = $matching->first();
            $active = $history->contains(fn ($row) => in_array($row->status, ['deploy_registered', 'deploying'], true)
                || (in_array($row->status, ['pending', 'approved', 'dispatching', 'claimed', 'merged'], true)
                    && $row->isUnexpired()));
            $deployment = match (true) {
                $verified !== null => 'verified',
                in_array($latest?->status, ['merged', 'deploy_registered', 'deploying'], true) => 'progress',
                $latest?->status === 'failed' => 'failed',
                default => 'missing',
            };

            return $pr + [
                'approval_recorded' => $approvals->contains(fn ($row) => $row->approved_at !== null),
                'deployment' => $deployment,
                'live_verified' => $verified !== null,
                'run_id' => ($verified ?? $latest)?->deployment_run_id,
                'can_request' => $pr['merge_sha'] && $pr['trusted'] && !$verified && !$active,
            ];
        })->filter(fn (array $pr) => empty($filters['deployment']) || $pr['deployment'] === $filters['deployment'])
            ->sortByDesc('number')->values();

        $page = LengthAwarePaginator::resolveCurrentPage();
        $pageRows = $rows->forPage($page, 25)->values();
        if ($pageRows->contains('can_request', true)) {
            $main = $this->github->get('commits/main', 'release_history_main')->json('sha');
            $pageRows = $pageRows->map(fn (array $pr) => array_replace($pr, [
                'can_request' => $pr['can_request'] && $pr['merge_sha'] === $main,
            ]));
        }

        return new LengthAwarePaginator($pageRows, $rows->count(), 25, $page, [
            'path' => LengthAwarePaginator::resolveCurrentPath(),
            'query' => ['history' => 1] + $filters,
        ]);
    }

    private function fetchPulls(): array
    {
        $pulls = [];
        // Creation order keeps existing pages stable when a new PR is opened.
        for ($page = 1; ; $page++) {
            $response = $this->github->get(
                'pulls?state=all&sort=created&direction=asc&per_page=100&page='.$page,
                'release_history',
            );
            $items = $response->json();
            if (!is_array($items) || !array_is_list($items)) {
                throw new DeploymentGitHubUnavailable('release_history');
            }
            $countBefore = count($pulls);
            foreach ($items as $pr) {
                if (!is_array($pr) || !is_int($pr['number'] ?? null) || $pr['number'] < 1) {
                    throw new DeploymentGitHubUnavailable('release_history');
                }
                $pulls[$pr['number']] = $this->normalize($pr);
            }

            if (count($items) < 100) {
                return array_values($pulls);
            }
            // An upstream page that repeats itself must not keep the request open.
            if (count($pulls) === $countBefore) {
                throw new DeploymentGitHubUnavailable('release_history');
            }
        }
    }

    private function normalize(array $pr): array
    {
        $number = $pr['number'];
        $title = (string) ($pr['title'] ?? '');
        $body = trim(preg_replace('/<!--.*?-->/s', '', (string) ($pr['body'] ?? '')));
        $summary = collect(preg_split('/\R/', $body))
            ->map(fn (string $line) => trim($line))
            ->first(fn (string $line) => $line !== '' && !preg_match('/^#{1,6}\s/', $line));
        $mergedAt = $pr['merged_at'] ?? null;
        $activityAt = $mergedAt ?? $pr['updated_at'] ?? $pr['created_at'] ?? null;
        $base = (string) data_get($pr, 'base.ref', '');
        $branch = (string) data_get($pr, 'head.ref', '');
        $labels = implode(' ', array_column($pr['labels'] ?? [], 'name'));
        $modules = array_keys(array_filter(self::MODULE_PATTERNS,
            fn (string $pattern) => preg_match($pattern, $title.' '.$labels.' '.$branch)));
        $mergeSha = strtolower((string) ($pr['merge_commit_sha'] ?? ''));
        preg_match_all(
            '~(?<![\w/])#(\d+)|https://github\.com/'.preg_quote(self::REPOSITORY, '~').'/issues/(\d+)~i',
            $title.' '.$body,
            $references,
            PREG_SET_ORDER,
        );
        $state = match (true) {
            $mergedAt !== null => 'merged',
            ($pr['state'] ?? '') === 'closed' => 'closed',
            (bool) ($pr['draft'] ?? false) => 'draft',
            default => 'open',
        };

        return [
            'number' => $number,
            'title' => $title,
            'description' => $body,
            'summary' => $summary ? Str::limit($summary, 240) : '',
            'state' => $state,
            'activity_date' => $activityAt ? CarbonImmutable::parse($activityAt)->timezone(config('app.timezone'))->toDateString() : '',
            'references' => array_values(array_unique(array_map(
                fn (array $reference) => (int) ($reference[2] ?? $reference[1]),
                $references,
            ))),
            'base' => $base,
            'modules' => $modules ?: ['other'],
            'head_sha' => strtolower((string) data_get($pr, 'head.sha')),
            'merge_sha' => $mergedAt && $base === 'main' && preg_match('/^[a-f0-9]{40}$/D', $mergeSha) ? $mergeSha : null,
            'trusted' => data_get($pr, 'head.repo.full_name') === self::REPOSITORY
                && OwnerDeploymentApprovalService::isTrustedHeadBranch($branch),
        ];
    }

    private function matches(array $pr, array $filters): bool
    {
        if ((!empty($filters['state']) && $pr['state'] !== $filters['state'])
            || (!empty($filters['module']) && !in_array($filters['module'], $pr['modules'], true))
            || (!empty($filters['from']) && ($pr['activity_date'] === '' || $pr['activity_date'] < $filters['from']))
            || (!empty($filters['to']) && ($pr['activity_date'] === '' || $pr['activity_date'] > $filters['to']))) {
            return false;
        }

        $query = trim($filters['q'] ?? '');
        if ($query === '') {
            return true;
        }
        if (preg_match('/^(?:(?:pr|issue)\s+)?#?(\d+)$/iD', $query, $number)) {
            return $pr['number'] === (int) $number[1]
                || in_array((int) $number[1], $pr['references'], true);
        }

        return Str::contains($pr['title'].' '.$pr['description'], $query, ignoreCase: true);
    }
}

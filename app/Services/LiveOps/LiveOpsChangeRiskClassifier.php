<?php

namespace App\Services\LiveOps;

/**
 * Classify whether an autonomous change may auto-deploy or must fail closed.
 *
 * AUTO_DEPLOY: ordinary UI/print/retry/queue/logging/non-destructive app bugs.
 * HIGH_RISK_BLOCKED: tax rules, auth, tenant isolation, destructive DB, secrets, infra.
 */
class LiveOpsChangeRiskClassifier
{
    public const AUTO_DEPLOY = 'AUTO_DEPLOY';

    public const HIGH_RISK_BLOCKED = 'HIGH_RISK_BLOCKED';

    /**
     * @param  list<string>  $paths
     * @return array{class:string,reason:string,blocked_paths:list<string>,allowed_paths:list<string>}
     */
    public function classifyPaths(array $paths): array
    {
        $deny = config('live_ops.risk.high_risk_path_deny', []);
        $allow = config('live_ops.risk.auto_deploy_path_allow', []);
        $blocked = [];
        $allowed = [];

        foreach ($paths as $path) {
            $path = ltrim(str_replace('\\', '/', (string) $path), '/');
            if ($path === '' || str_starts_with($path, 'docs/') || $path === 'docs') {
                continue;
            }
            if ($this->matchesAny($path, $deny)) {
                $blocked[] = $path;
                continue;
            }
            if ($this->matchesAny($path, $allow)) {
                $allowed[] = $path;
                continue;
            }
            $blocked[] = $path;
        }

        if ($blocked) {
            return [
                'class' => self::HIGH_RISK_BLOCKED,
                'reason' => 'High-risk or unclassified path(s): '.implode(', ', array_slice($blocked, 0, 8)),
                'blocked_paths' => $blocked,
                'allowed_paths' => $allowed,
            ];
        }
        if (!$allowed) {
            return [
                'class' => self::HIGH_RISK_BLOCKED,
                'reason' => 'No classifiable application files in the change set.',
                'blocked_paths' => [],
                'allowed_paths' => [],
            ];
        }

        return [
            'class' => self::AUTO_DEPLOY,
            'reason' => 'All changed files are ordinary safe application paths.',
            'blocked_paths' => [],
            'allowed_paths' => $allowed,
        ];
    }

    /**
     * @param  array<string, mixed>  $diagnosis
     * @return array{class:string,reason:string,operational_actions:list<string>}
     */
    public function classifyDiagnosis(array $diagnosis): array
    {
        $cause = (string) ($diagnosis['likely_root_cause'] ?? '');
        $recommend = is_array($diagnosis['recommended_fix'] ?? null) ? $diagnosis['recommended_fix'] : [];
        $risk = (string) ($recommend['risk'] ?? 'none');
        $actions = is_array($recommend['suggested_actions'] ?? null) ? $recommend['suggested_actions'] : [];
        $lowActions = $this->onlyLowActions($actions);

        if ($lowActions && in_array($cause, [
            'pra_submission_errors',
            'printer_or_print_pipeline',
            'pra_backlog_pending_agent_sync',
            'agent_offline',
            'printer_not_configured',
        ], true)) {
            return [
                'class' => self::AUTO_DEPLOY,
                'reason' => 'Low-risk operational subset is allow-listed; medium actions stay owner-gated.',
                'operational_actions' => $lowActions,
            ];
        }

        if ($risk === 'medium') {
            return [
                'class' => self::HIGH_RISK_BLOCKED,
                'reason' => 'Medium operational actions (printer rebind / agent command) stay owner-gated.',
                'operational_actions' => $actions,
            ];
        }

        if ($cause === 'no_obvious_fault_from_available_signals' || $cause === '') {
            return [
                'class' => self::AUTO_DEPLOY,
                'reason' => 'No proven production defect; investigate-only (no deploy).',
                'operational_actions' => [],
            ];
        }

        return [
            'class' => $risk === 'low' ? self::AUTO_DEPLOY : self::HIGH_RISK_BLOCKED,
            'reason' => $risk === 'low' ? 'Low-risk operational path.' : 'Not an ordinary safe auto-deploy diagnosis.',
            'operational_actions' => $this->onlyLowActions($actions),
        ];
    }

    /**
     * @param  list<string>  $actions
     * @return list<string>
     */
    private function onlyLowActions(array $actions): array
    {
        $low = config('live_ops.remediation_actions.low', []);
        $out = [];
        foreach ($actions as $action) {
            $name = strtoupper(trim(explode(':', (string) $action)[0]));
            if (in_array($name, $low, true)) {
                $out[] = (string) $action;
            }
        }

        return $out;
    }

    /**
     * @param  list<string>  $globs
     */
    private function matchesAny(string $path, array $globs): bool
    {
        foreach ($globs as $glob) {
            if ($this->matchGlob($path, (string) $glob)) {
                return true;
            }
        }

        return false;
    }

    private function matchGlob(string $path, string $glob): bool
    {
        $glob = ltrim(str_replace('\\', '/', $glob), '/');
        if ($glob !== '' && !str_contains($glob, '*')) {
            return $path === $glob || str_starts_with($path, rtrim($glob, '/').'/');
        }

        $regex = '#^'.str_replace(['\*\*/', '\*\*', '\*'], ['(.+/)?', '.*', '[^/]*'], preg_quote($glob, '#')).'$#';

        return (bool) preg_match($regex, $path);
    }
}

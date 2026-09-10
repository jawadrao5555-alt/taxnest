<?php

namespace App\Services\LiveOps;

/**
 * Map owner natural-language requests onto allow-listed Live Ops intents.
 * Never accepts arbitrary workflows, shell, SQL, or SSH.
 */
class LiveOpsOwnerCommandParser
{
    public const INTENT_DAILY_REPORT = 'DAILY_REPORT';

    public const INTENT_COMPANY_CHECK = 'COMPANY_CHECK';

    public const INTENT_COMPANY_SOLVE = 'COMPANY_SOLVE';

    public const INTENT_FLEET_CHECK_AND_SOLVE = 'FLEET_CHECK_AND_SOLVE';

    public const INTENT_DIAGNOSTIC = 'DIAGNOSTIC';

    /**
     * @return array{
     *   ok:bool,
     *   intent:?string,
     *   operation:?string,
     *   company_name:?string,
     *   date_from:?string,
     *   date_to:?string,
     *   focus:?string,
     *   error:?string,
     *   owner_text:string
     * }
     */
    public function parse(string $text): array
    {
        $original = trim($text);
        $stripped = $this->stripPrefix($original);
        $collapsed = trim(preg_replace('/\s+/u', ' ', $stripped) ?? $stripped);

        if ($collapsed === '' || mb_strlen($collapsed) > 500) {
            return $this->fail($original, 'Command is empty or too long.');
        }

        if ($this->isUnsafe($collapsed)) {
            return $this->fail($original, 'Command rejected: unauthorized or unsafe operation.');
        }

        $lower = mb_strtolower($collapsed);
        [$dateFrom, $dateTo] = $this->extractDates($lower);

        [$hasFleet, $hasSolve] = $this->fleetFlags($lower);
        if ($hasFleet && $hasSolve) {
            return $this->ok(
                $original,
                self::INTENT_FLEET_CHECK_AND_SOLVE,
                'DAILY_OPS',
                null,
                $dateFrom,
                $dateTo,
                'fleet'
            );
        }
        if ($hasFleet) {
            return $this->ok(
                $original,
                self::INTENT_DAILY_REPORT,
                'DAILY_OPS',
                null,
                $dateFrom,
                $dateTo,
                'fleet'
            );
        }

        if ($this->isDailyReport($lower)) {
            return $this->ok(
                $original,
                self::INTENT_DAILY_REPORT,
                'DAILY_OPS',
                null,
                $dateFrom,
                $dateTo,
                null
            );
        }

        $allowed = config('live_ops.diagnostic_operations', []);
        $asOp = strtoupper(str_replace([' ', '-'], '_', $collapsed));
        if (in_array($asOp, $allowed, true)) {
            $scope = config('live_ops.operation_scopes.'.$asOp);
            if ($scope === 'company') {
                return $this->fail($original, $asOp.' needs a company name (example: Pizza Master check karo).');
            }

            return $this->ok($original, self::INTENT_DIAGNOSTIC, $asOp, null, $dateFrom, $dateTo, null);
        }

        $solve = $this->matchCompanySolve($collapsed, $lower);
        if ($solve !== null) {
            if ($solve === '') {
                return $this->fail($original, 'Name the company to solve (example: ZFC ka printing issue solve karo).');
            }

            return $this->ok(
                $original,
                self::INTENT_COMPANY_SOLVE,
                'COMPANY_DIAGNOSTIC',
                $solve,
                $dateFrom,
                $dateTo,
                $this->focusOf($lower)
            );
        }

        $check = $this->matchCompanyCheck($collapsed, $lower);
        if ($check !== null) {
            if ($check === '') {
                return $this->fail($original, 'Name the company to check (example: Pizza Master check karo).');
            }

            return $this->ok(
                $original,
                self::INTENT_COMPANY_CHECK,
                'COMPANY_DIAGNOSTIC',
                $check,
                $dateFrom,
                $dateTo,
                $this->focusOf($lower)
            );
        }

        return $this->fail(
            $original,
            'Unrecognized command. Try: "Aaj ki report do", "Pizza Master check karo", "ZFC ka printing issue solve karo", or "Sab companies check karo aur jahan issue ho solve karo".'
        );
    }

    private function stripPrefix(string $text): string
    {
        $prefix = config('live_ops.autonomous.github_issue_prefix', '[TAXNEST-OPS]');
        if (str_starts_with(mb_strtoupper($text), mb_strtoupper($prefix))) {
            return trim(mb_substr($text, mb_strlen($prefix)));
        }

        return $text;
    }

    private function isUnsafe(string $text): bool
    {
        $lower = mb_strtolower($text);
        $blocked = [
            'deploy-production',
            'skip_elaan',
            'allow_settings',
            'production_ssh',
            'private_key',
            'rm -rf',
            'drop table',
            'truncate ',
            'workflow_dispatch',
            'gh workflow',
            'curl |',
            'bash -c',
            '/bin/sh',
            'ssh ',
            'scp ',
            'eval (',
            'os.system',
            'passthru',
            'live-ops-remediate.yml',
            'owner-merge-and-deploy.yml',
        ];
        foreach ($blocked as $needle) {
            if (str_contains($lower, $needle)) {
                return true;
            }
        }
        if (preg_match('/[;&|`$<>]|\$\(|\)\s*\{/', $text)) {
            return true;
        }

        return false;
    }

    private function isDailyReport(string $lower): bool
    {
        foreach ([
            'aaj ki report',
            'aaj ki reporting',
            "today's report",
            'todays report',
            'today report',
            'daily report',
            'daily ops',
            'daily-ops',
        ] as $needle) {
            if (str_contains($lower, $needle)) {
                return true;
            }
        }

        return $lower === 'report do' || $lower === 'aaj report do';
    }

    /**
     * @return array{0:bool,1:bool}
     */
    private function fleetFlags(string $lower): array
    {
        $hasFleet = str_contains($lower, 'sab companies')
            || str_contains($lower, 'all companies')
            || str_contains($lower, 'saari companies')
            || str_contains($lower, 'every company');
        $hasSolve = str_contains($lower, 'solve')
            || str_contains($lower, 'jahan issue')
            || str_contains($lower, 'jahaan issue')
            || preg_match('/\bfix\b/', $lower);

        return [$hasFleet, (bool) $hasSolve];
    }

    private function matchCompanySolve(string $collapsed, string $lower): ?string
    {
        if (!preg_match('/\b(solve|fix|theek|thik)\b/u', $lower) && !str_contains($lower, 'solve karo')) {
            return null;
        }
        if (preg_match('/^(.+?)\s+ka\s+(?:printing\s+)?(?:issue|problem).*\b(solve|fix)/iu', $collapsed, $m)) {
            return $this->cleanCompanyToken($m[1]);
        }
        if (preg_match('/^(?:solve|fix)\s+(?:printing\s+(?:for|on)\s+)?(.+)$/iu', $collapsed, $m)) {
            return $this->cleanCompanyToken($m[1]);
        }
        if (preg_match('/^(.+?)\s+(?:printing\s+)?(?:issue|problem)\s+(?:solve|fix)/iu', $collapsed, $m)) {
            return $this->cleanCompanyToken($m[1]);
        }

        return null;
    }

    private function matchCompanyCheck(string $collapsed, string $lower): ?string
    {
        if (preg_match('/^(.+?)\s+check\s+karo\b/iu', $collapsed, $m)) {
            return $this->cleanCompanyToken($m[1]);
        }
        if (preg_match('/^(?:check|inspect|diagnose)\s+(.+)$/iu', $collapsed, $m)) {
            return $this->cleanCompanyToken($m[1]);
        }
        if (preg_match('/^(.+?)\s+ka\s+(?:health|status|report)\b/iu', $collapsed, $m)) {
            return $this->cleanCompanyToken($m[1]);
        }
        if (str_contains($lower, 'check karo') || preg_match('/\b(check|inspect|diagnose)\b/u', $lower)) {
            return $this->cleanCompanyToken(preg_replace('/\b(check|inspect|diagnose|karo|please|status|health)\b/iu', ' ', $collapsed) ?? $collapsed);
        }

        return null;
    }

    private function cleanCompanyToken(string $token): string
    {
        $token = trim($token);
        $token = preg_replace('/^(please|pls|kindly)\s+/iu', '', $token) ?? $token;
        $token = preg_replace('/\b(ka|ki|ke|the|printing|print|issue|problem|ko)\b/iu', ' ', $token) ?? $token;
        $token = trim(preg_replace('/\s+/u', ' ', $token) ?? $token);

        return $token;
    }

    private function focusOf(string $lower): ?string
    {
        if (str_contains($lower, 'print')) {
            return 'printing';
        }
        if (str_contains($lower, 'pra') || str_contains($lower, 'fbr')) {
            return 'pra';
        }
        if (str_contains($lower, 'agent')) {
            return 'agent';
        }
        if (str_contains($lower, 'bill')) {
            return 'billing';
        }

        return null;
    }

    /**
     * @return array{0:?string,1:?string}
     */
    private function extractDates(string $lower): array
    {
        if (preg_match('/\b(\d{4}-\d{2}-\d{2})\s+(?:to|se|-)\s+(\d{4}-\d{2}-\d{2})\b/', $lower, $m)) {
            return [$m[1], $m[2]];
        }
        if (preg_match('/\b(\d{4}-\d{2}-\d{2})\b/', $lower, $m)) {
            return [$m[1], $m[1]];
        }

        return [null, null];
    }

    /**
     * @return array{ok:bool,intent:?string,operation:?string,company_name:?string,date_from:?string,date_to:?string,focus:?string,error:?string,owner_text:string}
     */
    private function ok(
        string $original,
        string $intent,
        string $operation,
        ?string $companyName,
        ?string $dateFrom,
        ?string $dateTo,
        ?string $focus,
    ): array {
        $allowed = config('live_ops.diagnostic_operations', []);
        if (!in_array($operation, $allowed, true)) {
            return $this->fail($original, 'Disallowed operation.');
        }

        return [
            'ok' => true,
            'intent' => $intent,
            'operation' => $operation,
            'company_name' => $companyName !== null && $companyName !== '' ? $companyName : null,
            'date_from' => $dateFrom,
            'date_to' => $dateTo,
            'focus' => $focus,
            'error' => null,
            'owner_text' => $original,
        ];
    }

    /**
     * @return array{ok:bool,intent:?string,operation:?string,company_name:?string,date_from:?string,date_to:?string,focus:?string,error:?string,owner_text:string}
     */
    private function fail(string $original, string $error): array
    {
        return [
            'ok' => false,
            'intent' => null,
            'operation' => null,
            'company_name' => null,
            'date_from' => null,
            'date_to' => null,
            'focus' => null,
            'error' => $error,
            'owner_text' => $original,
        ];
    }
}

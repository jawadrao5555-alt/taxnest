<?php

namespace App\Console\Commands;

use App\Services\LiveOps\LiveOpsDiagnosticsService;
use Illuminate\Console\Command;

/**
 * Trusted-runner entrypoint for Live Ops diagnostics.
 * Cloud Agents must NOT run this against production; Actions SSH/runner does.
 */
class LiveOpsDiagnoseCommand extends Command
{
    protected $signature = 'live-ops:diagnose
        {--operation=COMPANY_DIAGNOSTIC : Allow-listed diagnostic operation}
        {--company-id= : NestPOS company id}
        {--company-name= : Exact company name or account_code}
        {--date-from= : YYYY-MM-DD}
        {--date-to= : YYYY-MM-DD}
        {--requester=cloud-agent : Requester label for audit}
        {--output= : Optional file path for JSON report}';

    protected $description = 'Run allow-listed Live Ops diagnostic (read-only, redacted)';

    public function handle(LiveOpsDiagnosticsService $diagnostics): int
    {
        try {
            $report = $diagnostics->run((string) $this->option('operation'), [
                'company_id' => $this->option('company-id') !== null && $this->option('company-id') !== ''
                    ? (int) $this->option('company-id') : null,
                'company_name' => $this->option('company-name') ?: null,
                'date_from' => $this->option('date-from') ?: null,
                'date_to' => $this->option('date-to') ?: null,
                'requester' => $this->option('requester') ?: 'cli',
            ]);
        } catch (\InvalidArgumentException $e) {
            $this->error($e->getMessage());

            return self::FAILURE;
        } catch (\Throwable $e) {
            $this->error('Diagnostic failed: '.$e->getMessage());

            return self::FAILURE;
        }

        $json = json_encode($report, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        $out = $this->option('output');
        if ($out) {
            file_put_contents($out, $json."\n");
            $this->info('Wrote '.$out);
        } else {
            $this->line($json);
        }

        return self::SUCCESS;
    }
}

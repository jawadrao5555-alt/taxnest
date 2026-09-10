<?php

namespace App\Console\Commands;

use App\Services\LiveOps\LiveOpsAutonomousEngine;
use Illuminate\Console\Command;

/**
 * Trusted-runner entry: owner natural-language command → diagnose (+ low-risk ops).
 * Cloud Agents must NOT run this against production; Actions SSH/runner does.
 */
class LiveOpsOwnerCommand extends Command
{
    protected $signature = 'live-ops:owner-command
        {--text= : Owner natural-language request}
        {--requester=owner-command : Audit requester label}
        {--source=github-actions : Request source label}
        {--request-id= : Optional request id}
        {--output= : Optional JSON output path}';

    protected $description = 'Parse an owner Live Ops command and run allow-listed diagnostics';

    public function handle(LiveOpsAutonomousEngine $engine): int
    {
        $text = (string) $this->option('text');
        if (trim($text) === '') {
            $this->error('text is required');

            return self::FAILURE;
        }

        try {
            $result = $engine->handle($text, [
                'requester' => $this->option('requester') ?: 'cli',
                'source' => $this->option('source') ?: 'cli',
                'request_id' => $this->option('request-id') ?: null,
            ]);
        } catch (\Throwable $e) {
            $this->error('Owner command failed: '.$e->getMessage());

            return self::FAILURE;
        }

        $json = json_encode($result, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        $out = $this->option('output');
        if ($out) {
            file_put_contents($out, $json."\n");
            $this->info('Wrote '.$out);
        } else {
            $this->line($json);
        }

        return !empty($result['ok']) || in_array($result['status'] ?? '', ['BLOCKED', 'RESOLVED', 'DIAGNOSED'], true)
            ? self::SUCCESS
            : self::FAILURE;
    }
}

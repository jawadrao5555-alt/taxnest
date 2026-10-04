<?php

namespace App\Services;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;

/** Server-clock, append-only attempt history. Never stores documents or claim tokens. */
final class PrintJobEvidence
{
    public static function record(int $companyId, string $event, ?int $jobId = null, ?int $attempt = null, array $context = []): void
    {
        try {
            $allowed = ['device_uid', 'agent_version', 'target_printer', 'type', 'order_id', 'user_id',
                'request_id', 'reason', 'outcome', 'stage', 'content_ms', 'content_attempts', 'print_ms', 'gap_seconds', 'printer_names'];
            $safe = array_intersect_key($context, array_flip($allowed));
            foreach ($safe as $key => $value) {
                if ($key === 'printer_names') {
                    $safe[$key] = array_slice(array_values(array_filter((array) $value, 'is_string')), 0, 50);
                    $safe[$key] = array_map(fn ($name) => mb_substr($name, 0, 255), $safe[$key]);
                } elseif (is_string($value)) $safe[$key] = mb_substr($value, 0, $key === 'target_printer' ? 255 : 100);
                elseif (!is_numeric($value) && $value !== null) unset($safe[$key]);
            }
            Log::info('PRINT_EVIDENCE', ['company_id' => $companyId, 'job_id' => $jobId, 'attempt' => $attempt,
                'event' => $event, 'context' => $safe, 'clock' => 'server']);
            if (Schema::hasTable('pos_print_evidence')) DB::table('pos_print_evidence')->insert([
                'company_id' => $companyId, 'job_id' => $jobId, 'attempt' => $attempt,
                'event' => $event, 'context' => json_encode($safe), 'received_at' => now(),
            ]);
        } catch (\Throwable $ignored) {
            // Diagnostics must not alter claim, print, or failover behavior.
        }
    }
}

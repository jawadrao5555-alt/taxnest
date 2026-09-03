<?php

namespace App\Services;

use App\Models\PosPrintJob;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * Best-effort hint for the local realtime gateway.
 *
 * The print-job API remains the source of truth: an unavailable relay merely
 * means that the agent's existing poll will discover the job shortly after.
 */
class PrintJobWakePublisher
{
    private const HEALTH_KEY = 'print_job_wake_health';
    private const WARNING_THROTTLE_KEY = 'print_job_wake_warning_throttle';

    public function publish(PosPrintJob $job): void
    {
        $baseUrl = trim((string) config('print.realtime_gateway_url', ''));
        $secret = trim((string) config('print.realtime_gateway_secret', ''));

        // Explicit opt-in: installations without the local relay retain pure
        // polling and make no outbound request at all.
        if ($baseUrl === '' || $secret === '') {
            return;
        }

        try {
            $url = $this->wakeUrl($baseUrl);

            $response = Http::acceptJson()
                ->asJson()
                // The relay is on the same host. Never make billing wait on it.
                ->connectTimeout(0.10)
                ->timeout(0.25)
                ->withHeaders(['X-Wake-Secret' => $secret])
                ->post($url, [
                    'company_id' => $job->company_id,
                    'device_uid' => $job->device_uid,
                    'job_id' => $job->getKey(),
                ]);

            if (!$response->successful()) {
                throw new \RuntimeException('Wake gateway returned HTTP ' . $response->status());
            }

            $this->recordHealth(['ok' => true, 'at' => now()->toIso8601String()]);
        } catch (\Throwable $e) {
            $this->recordFailure($job, $e);
        }
    }

    private function wakeUrl(string $baseUrl): string
    {
        $parts = parse_url($baseUrl);
        $host = strtolower((string) ($parts['host'] ?? ''));
        if (!in_array($host, ['127.0.0.1', 'localhost', '::1'], true)
            || !in_array(strtolower((string) ($parts['scheme'] ?? '')), ['http', 'https'], true)) {
            throw new \InvalidArgumentException('Print wake gateway must use a loopback HTTP URL.');
        }

        return rtrim($baseUrl, '/') . '/internal/wake';
    }

    private function recordFailure(PosPrintJob $job, \Throwable $e): void
    {
        // Both the health marker and warning are best-effort. A cache/log
        // problem must be just as harmless as a gateway problem.
        try {
            $this->recordHealth([
                'ok' => false,
                'at' => now()->toIso8601String(),
                'error' => mb_substr($e->getMessage(), 0, 200),
            ]);

            if (Cache::add(self::WARNING_THROTTLE_KEY, true, now()->addMinutes(5))) {
                Log::warning('Print job realtime wake failed; agent polling remains active.', [
                    'company_id' => $job->company_id,
                    'device_uid' => $job->device_uid,
                    'job_id' => $job->getKey(),
                    'error' => $e->getMessage(),
                ]);
            }
        } catch (\Throwable $ignored) {
            // Fail open, including when the telemetry backend is unavailable.
        }
    }

    private function recordHealth(array $state): void
    {
        try {
            Cache::put(self::HEALTH_KEY, $state, now()->addMinutes(10));
        } catch (\Throwable $ignored) {
            // Health information is advisory only.
        }
    }
}
<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Symfony\Component\HttpFoundation\Response;

/**
 * Slow-request telemetry (Task: POS "har feature loading", Aug 2026).
 *
 * Any web request slower than the threshold (config app.slow_request_ms,
 * default 2000ms) is written to the dedicated `slow_requests` daily log with
 * route, company_id, user, total duration and DB query time — so the next
 * "loading hoti hai" complaint can be answered with exact routes + timings.
 *
 * Overhead is near-zero: fast requests only pay two float additions per DB
 * query; logging happens in terminate() AFTER the response is sent, and the
 * whole block is wrapped so telemetry can never break a real response.
 * No query bindings / tokens / PII are logged — path + ids only.
 */
class SlowRequestLogger
{
    /** Listener registered once per PHP process (statics reset per request). */
    protected static bool $listening = false;

    protected static float $dbMs = 0.0;

    protected static int $dbQueries = 0;

    public function handle(Request $request, Closure $next): Response
    {
        self::$dbMs = 0.0;
        self::$dbQueries = 0;

        if (! self::$listening) {
            self::$listening = true;
            DB::listen(function ($query) {
                self::$dbMs += (float) $query->time; // ms
                self::$dbQueries++;
            });
        }

        return $next($request);
    }

    /**
     * Runs after the response has been sent — user never waits on this.
     */
    public function terminate(Request $request, Response $response): void
    {
        try {
            $thresholdMs = (float) config('app.slow_request_ms', 2000);
            if ($thresholdMs <= 0) {
                return; // telemetry disabled
            }

            $start = defined('LARAVEL_START')
                ? LARAVEL_START
                : (float) ($request->server('REQUEST_TIME_FLOAT') ?: microtime(true));
            $durationMs = (microtime(true) - $start) * 1000.0;

            if ($durationMs < $thresholdMs) {
                return; // fast request — no work, no log
            }

            $companyId = null;
            try {
                if (app()->bound('currentCompanyId')) {
                    $companyId = app('currentCompanyId');
                }
            } catch (\Throwable $e) {
                // never let telemetry throw
            }

            // Which panel user (if any) — only guards already resolved during
            // the request are read, so this never triggers a fresh DB lookup
            // (important when the request was slow BECAUSE the DB is down).
            $userTag = null;
            foreach (['pos', 'fbrpos', 'admin', 'franchise', 'web'] as $guard) {
                try {
                    if (auth($guard)->hasUser()) {
                        $userTag = $guard . ':' . auth($guard)->id();
                        break;
                    }
                } catch (\Throwable $e) {
                    // guard not usable — skip
                }
            }

            Log::channel('slow_requests')->info('slow_request', [
                'method' => $request->method(),
                'path' => '/' . ltrim($request->path(), '/'), // no query string — may carry PII
                'route' => optional($request->route())->getName(),
                'status' => $response->getStatusCode(),
                'duration_ms' => (int) round($durationMs),
                'db_ms' => (int) round(self::$dbMs),
                'db_queries' => self::$dbQueries,
                'company_id' => $companyId,
                'user' => $userTag,
                'peak_mem_mb' => (int) round(memory_get_peak_usage(true) / 1048576),
            ]);
        } catch (\Throwable $e) {
            // Telemetry must never break or delay a response. Swallow everything.
        }
    }
}

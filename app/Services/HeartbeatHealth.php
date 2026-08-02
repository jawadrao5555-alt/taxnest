<?php

namespace App\Services;

use App\Models\SystemSetting;
use Illuminate\Support\Carbon;

/**
 * Cron / queue-worker liveness for the SaaS admin panel.
 *
 * Two heartbeats are written on prod:
 *  - scheduler_last_heartbeat: every 15 min by a Schedule::call closure —
 *    proves the `schedule:run` crontab entry exists and fires.
 *  - queue_last_heartbeat: a job dispatched every 5 min and written only when
 *    a QUEUE WORKER processes it — proves the queue-worker cron is alive.
 *
 * If the owner/hosting ever resets the crontab, queued emails (consultant
 * alerts etc.) silently pile up in the jobs table. This service turns the
 * passive System Control readout into an active warning: the admin layout
 * renders a red banner whenever either heartbeat goes stale, and a scheduled
 * watchdog emails admins when the queue worker dies while cron is still alive.
 *
 * All reads are exception-safe: liveness checks must never break page render.
 */
class HeartbeatHealth
{
    /** Queue heartbeat is written every 5 min — 15 min means ≥2 missed beats. */
    public const QUEUE_STALE_MINUTES = 15;

    /** Scheduler heartbeat is written every 15 min — 45 min means ≥2 missed beats. */
    public const SCHEDULER_STALE_MINUTES = 45;

    private const NOTIFIED_KEY = 'heartbeat_watchdog_last_notified_at';

    /**
     * Stale-heartbeat state for the admin banner, or null when all healthy.
     *
     * @return array{queue_stale: bool, scheduler_stale: bool, queue_at: ?Carbon, scheduler_at: ?Carbon}|null
     */
    public static function warning(): ?array
    {
        try {
            $schedulerAt = self::parse(SystemSetting::get('scheduler_last_heartbeat'));
            $queueAt = self::parse(SystemSetting::get('queue_last_heartbeat'));

            $schedulerStale = $schedulerAt !== null
                && $schedulerAt->lt(now()->subMinutes(self::SCHEDULER_STALE_MINUTES));

            // Queue is stale when its heartbeat is old, or was never recorded
            // while the scheduler IS demonstrably alive (fresh installs and dev
            // environments without any heartbeats stay quiet).
            $schedulerAlive = $schedulerAt !== null && !$schedulerStale;
            $queueStale = ($queueAt !== null && $queueAt->lt(now()->subMinutes(self::QUEUE_STALE_MINUTES)))
                || ($queueAt === null && $schedulerAlive);

            if (!$queueStale && !$schedulerStale) {
                return null;
            }

            return [
                'queue_stale' => $queueStale,
                'scheduler_stale' => $schedulerStale,
                'queue_at' => $queueAt,
                'scheduler_at' => $schedulerAt,
            ];
        } catch (\Throwable $e) {
            // Never let liveness bookkeeping break an admin page.
            return null;
        }
    }

    /**
     * Whether the watchdog email may fire now (throttled to once per 12h).
     */
    public static function shouldNotify(): bool
    {
        try {
            $last = self::parse(SystemSetting::get(self::NOTIFIED_KEY));
            return $last === null || $last->lt(now()->subHours(12));
        } catch (\Throwable $e) {
            return false;
        }
    }

    public static function markNotified(): void
    {
        try {
            SystemSetting::set(
                self::NOTIFIED_KEY,
                now()->toDateTimeString(),
                'Last time the stale-heartbeat watchdog emailed admins (auto-managed).'
            );
        } catch (\Throwable $ignored) {
        }
    }

    private static function parse($raw): ?Carbon
    {
        if (!is_string($raw) || trim($raw) === '') {
            return null;
        }
        try {
            return Carbon::parse($raw);
        } catch (\Throwable $e) {
            return null;
        }
    }
}

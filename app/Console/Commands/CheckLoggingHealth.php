<?php

namespace App\Console\Commands;

use App\Models\AdminUser;
use App\Services\LogHealth;
use App\Services\MailHealth;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;

/**
 * Daily logging-health probe (catch a silent LOG_LEVEL / dead log file
 * BETWEEN deploys — deploy-live.sh only checks this at deploy time).
 *
 * Ground truth is an end-to-end probe: write a Log::warning line with a nonce
 * and verify it actually landed in the CONFIGURED file destination(s) — the
 * default channel is resolved (including one level of stack indirection) and
 * `single`/`daily` drivers map to their real file paths (daily = the rotated
 * laravel-YYYY-MM-DD.log for today). Non-file channels (stderr, syslog, ...)
 * cannot be probed this way and are never declared dead for lacking a file.
 * The configured level is also checked directly so the report can NAME the
 * cause when warnings are being dropped.
 *
 * Because a dead logging pipeline can never surface its own Log:: alerts,
 * failures are recorded in a SystemSetting flag (LogHealth → red admin
 * banner) and emailed to admins SYNCHRONOUSLY via the noreply SMTP
 * (no queue dependency), throttled to once per 12h.
 */
class CheckLoggingHealth extends Command
{
    protected $signature = 'logs:health-check';

    protected $description = 'Verify Log::warning lines actually reach the configured log file (LOG_LEVEL / dead-log watchdog between deploys)';

    /** Log levels on which Log::warning still gets written. */
    public const OK_LEVELS = ['debug', 'info', 'notice', 'warning'];

    public function handle(): int
    {
        $issues = [];

        // 1. Configured level (config, not env() — env() is empty once the
        //    config is cached, which it always is on live).
        $default = (string) config('logging.default', 'stack');
        $level = $this->effectiveLevel($default);
        $levelOk = $level === null || in_array($level, self::OK_LEVELS, true);
        if (!$levelOk) {
            $issues[] = "LOG_LEVEL is '{$level}' — Log::warning lines are being silently dropped. Set LOG_LEVEL=warning (or lower) in .env, then php artisan config:cache.";
        }

        // 2. End-to-end probe: does a warning actually land in the configured
        //    file destination(s)? Only file-based channels can be verified.
        $files = $this->probeFiles($default);
        $nonce = 'log-health-probe-' . Str::random(16);
        try {
            Log::warning('[log-health] daily probe ' . $nonce);
        } catch (\Throwable $e) {
            $issues[] = 'Log::warning threw: ' . mb_substr($e->getMessage(), 0, 200);
        }

        foreach ($files as $file) {
            clearstatcache(true, $file);
            $rel = str_replace(storage_path() . '/', 'storage/', $file);
            if (!is_file($file)) {
                $issues[] = "Configured log file {$rel} is MISSING even after a probe write — check LOG_CHANNEL and storage/logs permissions.";
            } elseif (!$this->tailContains($file, $nonce)) {
                // File exists but our warning never arrived. If the level
                // already explained it, don't double-report the root cause.
                if ($levelOk) {
                    $issues[] = "Probe Log::warning line did NOT appear in {$rel} — logging is silently dead (check LOG_CHANNEL, file permissions, or a stale config cache).";
                }
            }
        }

        if (empty($issues)) {
            LogHealth::recordSuccess();
            $probed = empty($files)
                ? 'no file-based channel to probe'
                : 'probe verified in ' . implode(', ', array_map('basename', $files));
            $this->info('Logging health OK (level=' . ($level ?? 'unknown') . ', ' . $probed . ').');

            return self::SUCCESS;
        }

        LogHealth::recordFailure($issues);
        foreach ($issues as $issue) {
            $this->error($issue);
        }
        $this->notifyAdmins($issues);

        return self::FAILURE;
    }

    /**
     * Effective minimum level for the given channel (resolving one level of
     * stack indirection). Null when it cannot be determined.
     */
    public function effectiveLevel(string $channel): ?string
    {
        $cfg = config("logging.channels.{$channel}");
        if (!is_array($cfg)) {
            return null;
        }

        if (($cfg['driver'] ?? '') === 'stack') {
            // The stack writes to each member at that member's own level; the
            // loosest member decides whether warnings survive at all.
            $best = null;
            $rank = ['debug' => 0, 'info' => 1, 'notice' => 2, 'warning' => 3, 'error' => 4, 'critical' => 5, 'alert' => 6, 'emergency' => 7];
            foreach ((array) ($cfg['channels'] ?? []) as $member) {
                $memberLevel = config("logging.channels.{$member}.level");
                if (!is_string($memberLevel)) {
                    continue;
                }
                $memberLevel = strtolower(trim($memberLevel));
                if ($best === null || ($rank[$memberLevel] ?? 99) < ($rank[$best] ?? 99)) {
                    $best = $memberLevel;
                }
            }

            return $best;
        }

        $level = $cfg['level'] ?? null;

        return is_string($level) ? strtolower(trim($level)) : null;
    }

    /**
     * Real file paths the default channel writes to today. Resolves one level
     * of stack indirection; `single` → its path, `daily` → today's rotated
     * file (path-YYYY-MM-DD.ext, Monolog RotatingFileHandler default);
     * non-file drivers (stderr, syslog, slack, ...) yield nothing — they can
     * never be reported as dead just for lacking a file.
     *
     * @return string[]
     */
    public function probeFiles(string $channel): array
    {
        $cfg = config("logging.channels.{$channel}");
        if (!is_array($cfg)) {
            return [];
        }

        if (($cfg['driver'] ?? '') === 'stack') {
            $files = [];
            foreach ((array) ($cfg['channels'] ?? []) as $member) {
                foreach ($this->probeFiles((string) $member) as $f) {
                    $files[] = $f;
                }
            }

            return array_values(array_unique($files));
        }

        $driver = (string) ($cfg['driver'] ?? '');
        $path = $cfg['path'] ?? null;
        if (!is_string($path) || $path === '') {
            return [];
        }

        if ($driver === 'single') {
            return [$path];
        }

        if ($driver === 'daily') {
            // Monolog RotatingFileHandler default: {filename}-{Y-m-d}.{ext}
            $ext = pathinfo($path, PATHINFO_EXTENSION);
            $base = $ext !== '' ? substr($path, 0, -(strlen($ext) + 1)) : $path;
            $dated = $base . '-' . now()->format('Y-m-d') . ($ext !== '' ? '.' . $ext : '');

            return [$dated];
        }

        // stderr, syslog, errorlog, slack, monolog, custom... — not a probe-able file.
        return [];
    }

    /** Whether the last chunk of the file contains the needle. */
    private function tailContains(string $path, string $needle): bool
    {
        try {
            $size = filesize($path);
            if ($size === false) {
                return false;
            }
            $fh = fopen($path, 'rb');
            if ($fh === false) {
                return false;
            }
            $read = min($size, 65536);
            fseek($fh, -$read, SEEK_END);
            $tail = (string) fread($fh, $read);
            fclose($fh);

            return str_contains($tail, $needle);
        } catch (\Throwable $e) {
            return false;
        }
    }

    /**
     * Synchronous admin email via the noreply SMTP — a dead log pipeline (and
     * possibly a dead queue) must not be a dependency of its own alert.
     * Throttled to once per 12h; the admin banner covers the rest.
     */
    private function notifyAdmins(array $issues): void
    {
        try {
            if (!LogHealth::shouldNotify()) {
                return;
            }

            $emails = AdminUser::whereNotNull('email')
                ->where('email', '!=', '')
                ->pluck('email')->unique()->values();
            if ($emails->isEmpty()) {
                return;
            }

            $body = "The daily logging health check on the live server FAILED.\n\n"
                . "Problems found:\n- " . implode("\n- ", $issues) . "\n\n"
                . "Why this matters: while logging is muted, scheduler/guard warnings (Cloudflare guards, day-close, trial reminders, FBR errors) vanish silently — the next deploy would have been the first time anyone noticed.\n\n"
                . "Fix on live: check LOG_LEVEL in the .env (should be warning or lower), then php artisan config:cache, and verify storage/logs is writable.\n"
                . 'System status: ' . route('saas.admin.system') . "\n\n"
                . 'TaxNest';

            Mail::raw($body, function ($m) use ($emails) {
                $m->to($emails->all())->subject('WARNING: TaxNest live logging has gone silent');
            });

            MailHealth::recordSuccess();
            LogHealth::markNotified();
        } catch (\Throwable $e) {
            // Can't Log:: this reliably (logging may be the thing that's
            // broken) — record it on the mail-health banner instead.
            MailHealth::recordFailure('Logging-health alert email', $e);
        }
    }
}

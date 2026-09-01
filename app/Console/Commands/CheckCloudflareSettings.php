<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;

/**
 * Nightly guard: other dangerous Cloudflare zone settings must stay safe.
 *
 * Rocket Loader has its own homepage-marker guard (cloudflare:check-rocket-loader).
 * This command READS the remaining risky settings straight from the Cloudflare
 * zone-settings API and auto-fixes anything that drifted:
 *
 *   - minify            => html/css/js all OFF (Auto Minify mangles inline JS/HTML)
 *   - ssl               => 'strict' (Full (strict); Flexible causes redirect loops)
 *   - browser_cache_ttl => 0 ("Respect Existing Headers"; anything else overrides
 *                            our cache-control and serves stale POS assets)
 *
 * Behaviour mirrors the Rocket Loader guard:
 *   - drift detected + PATCH succeeded  -> "detected + auto-fixed" admin email
 *   - any API failure (read or fix)     -> URGENT manual-fix admin email
 *   - everything already correct        -> silent success, no mail
 *   - token/zone not configured         -> logged warning, FAILURE, no mail
 *     (dev/test environments have no token; live always does)
 *
 * Cloudflare has been deprecating Auto Minify — if the API says the setting no
 * longer exists (404 / unknown-setting error) it is treated as "nothing to
 * guard" for that setting, not an incident.
 *
 * Runs SYNCHRONOUSLY from the scheduler (no queue worker required on cPanel).
 */
class CheckCloudflareSettings extends Command
{
    protected $signature = 'cloudflare:check-settings';

    protected $description = 'Read dangerous Cloudflare zone settings (Auto Minify, SSL mode, Browser Cache TTL) via API and auto-fix drift.';

    private const API_BASE = 'https://api.cloudflare.com/client/v4/zones';

    /**
     * setting id => [desired value, human label, why it matters].
     */
    private const GUARDED = [
        'minify' => [
            'desired' => ['css' => 'off', 'html' => 'off', 'js' => 'off'],
            'label' => 'Auto Minify (HTML/CSS/JS)',
            'why' => 'Auto Minify rewrites HTML/JS and can break the POS sale screen scripts.',
        ],
        'ssl' => [
            'desired' => 'strict',
            'label' => 'SSL mode',
            'why' => 'Anything below Full (strict) risks redirect loops / weakened TLS to origin.',
        ],
        'browser_cache_ttl' => [
            'desired' => 0,
            'label' => 'Browser Cache TTL',
            'why' => 'Must be "Respect Existing Headers" (0) or Cloudflare overrides our cache-control and browsers keep stale POS assets.',
        ],
    ];

    public function handle(): int
    {
        $token = (string) config('services.cloudflare.api_token', '');
        $zoneId = (string) config('services.cloudflare.zone_id', '');

        if ($token === '' || $zoneId === '') {
            Log::warning('Cloudflare settings guard skipped: CLOUDFLARE_API_TOKEN / CLOUDFLARE_ZONE_ID not configured');
            $this->warn('Skipped — Cloudflare API token/zone not configured.');

            return self::FAILURE;
        }

        $fixed = [];   // label => ['from' => .., 'to' => ..]
        $failed = [];  // label => reason

        foreach (self::GUARDED as $settingId => $spec) {
            $this->checkOne($token, $zoneId, $settingId, $spec, $fixed, $failed);
        }

        if ($fixed === [] && $failed === []) {
            $this->info('OK — all guarded Cloudflare settings are correct.');

            return self::SUCCESS;
        }

        if ($fixed !== []) {
            Log::warning('Cloudflare settings drift auto-fixed', ['fixed' => $fixed]);
        }
        if ($failed !== []) {
            Log::error('Cloudflare settings guard FAILED for some settings', ['failed' => $failed]);
        }

        $this->alertAdmins($fixed, $failed);

        return self::FAILURE;
    }

    /**
     * GET one setting; if it drifted, PATCH the desired value.
     * Appends to $fixed / $failed by reference.
     */
    private function checkOne(string $token, string $zoneId, string $settingId, array $spec, array &$fixed, array &$failed): void
    {
        $url = self::API_BASE . "/{$zoneId}/settings/{$settingId}";
        $label = $spec['label'];
        $desired = $spec['desired'];

        try {
            $get = Http::withToken($token)->timeout(30)->get($url);
        } catch (\Throwable $e) {
            $failed[$label] = "read failed: {$e->getMessage()}";
            $this->error("{$label}: read threw — {$e->getMessage()}");

            return;
        }

        $json = $get->json();

        if ($this->settingGone($get->status(), $json)) {
            // Setting retired by Cloudflare (e.g. Auto Minify deprecation) — nothing to guard.
            Log::info("Cloudflare settings guard: '{$settingId}' not available on this zone (retired setting) — skipped");
            $this->line("{$label}: not available on this zone — skipped.");

            return;
        }

        if (! $get->successful() || ! (bool) ($json['success'] ?? false)) {
            $failed[$label] = 'read rejected: HTTP ' . $get->status() . ' ' . json_encode($json['errors'] ?? null);
            $this->error("{$label}: read rejected — HTTP {$get->status()}.");

            return;
        }

        $current = $json['result']['value'] ?? null;

        if ($this->matchesDesired($current, $desired)) {
            $this->info("{$label}: OK.");

            return;
        }

        // Drift — PATCH the safe value.
        $this->error("{$label}: WRONG (" . $this->stringify($current) . ') — auto-fixing.');

        try {
            $patch = Http::withToken($token)->timeout(30)->patch($url, ['value' => $desired]);
        } catch (\Throwable $e) {
            $failed[$label] = 'drift detected (' . $this->stringify($current) . ") but fix threw: {$e->getMessage()}";

            return;
        }

        $patchJson = $patch->json();
        if ($patch->successful() && (bool) ($patchJson['success'] ?? false)) {
            $fixed[$label] = ['from' => $this->stringify($current), 'to' => $this->stringify($desired)];
            $this->info("{$label}: auto-fixed to " . $this->stringify($desired) . '.');

            return;
        }

        $failed[$label] = 'drift detected (' . $this->stringify($current) . ') but fix rejected: HTTP '
            . $patch->status() . ' ' . json_encode($patchJson['errors'] ?? null);
        $this->error("{$label}: fix rejected — HTTP {$patch->status()}.");
    }

    /**
     * True when Cloudflare says the setting no longer exists on this zone
     * (retired settings return 404 or an "unknown/invalid setting" error).
     */
    private function settingGone(int $status, ?array $json): bool
    {
        if ($status === 404) {
            return true;
        }

        foreach (($json['errors'] ?? []) as $err) {
            $msg = strtolower((string) ($err['message'] ?? ''));
            if (str_contains($msg, 'unknown setting') || str_contains($msg, 'invalid setting')) {
                return true;
            }
        }

        return false;
    }

    /**
     * Compare current vs desired. For array desired (minify), every desired
     * key must match; scalars compare loosely so "0" == 0 works.
     */
    private function matchesDesired(mixed $current, mixed $desired): bool
    {
        if (is_array($desired)) {
            if (! is_array($current)) {
                return false;
            }
            foreach ($desired as $k => $v) {
                if (($current[$k] ?? null) != $v) {
                    return false;
                }
            }

            return true;
        }

        return $current == $desired;
    }

    private function stringify(mixed $value): string
    {
        return is_scalar($value) || $value === null ? var_export($value, true) : (string) json_encode($value);
    }

    /**
     * Email every admin. Mirrors the Rocket Loader guard: synchronous send,
     * failures logged + MailHealth-recorded.
     */
    private function alertAdmins(array $fixed, array $failed): void
    {
        try {
            $emails = \App\Models\AdminUser::whereNotNull('email')
                ->where('email', '!=', '')
                ->pluck('email')->unique()->values();
            if ($emails->isEmpty()) {
                Log::warning('Cloudflare settings alert: no admin emails configured');

                return;
            }

            $lines = [];
            foreach ($fixed as $label => $info) {
                $lines[] = "- {$label}: was {$info['from']} -> AUTOMATICALLY fixed to {$info['to']}";
            }
            foreach ($failed as $label => $reason) {
                $lines[] = "- {$label}: PROBLEM — {$reason}";
            }
            $details = implode("\n", $lines);

            $whyLines = [];
            foreach (self::GUARDED as $spec) {
                $whyLines[] = "- {$spec['label']}: {$spec['why']}";
            }
            $why = implode("\n", $whyLines);

            if ($failed === []) {
                $subject = 'FIXED: dangerous Cloudflare settings drifted — auto-fixed';
                $body = "The nightly Cloudflare settings guard found dangerous settings changed in the\n"
                    . "Cloudflare dashboard and has AUTOMATICALLY fixed them via the Cloudflare API:\n\n"
                    . $details . "\n\n"
                    . "Someone changed these in the Cloudflare dashboard — please find out who/why\n"
                    . "so it does not happen again.\n\n"
                    . "Why these settings matter:\n{$why}\n\n"
                    . "(See docs/cloudflare-setup-guide.md — required Cloudflare settings.)\n\n"
                    . 'TaxNest automated check';
            } else {
                $subject = 'URGENT: Cloudflare settings wrong — manual fix needed';
                $body = "WARNING: the nightly Cloudflare settings guard could not verify/fix some\n"
                    . "dangerous Cloudflare settings via the API — manual check needed NOW:\n\n"
                    . $details . "\n\n"
                    . "Fix NOW in https://dash.cloudflare.com -> taxnest.pk:\n"
                    . "1. Speed -> Optimization: Auto Minify — ALL OFF (HTML/CSS/JS)\n"
                    . "2. SSL/TLS: mode Full (strict)\n"
                    . "3. Caching: Browser Cache TTL = \"Respect Existing Headers\"\n\n"
                    . "Also check the CLOUDFLARE_API_TOKEN (Zone Settings Edit permission) —\n"
                    . "if it expired/was revoked, the auto-fix cannot work.\n\n"
                    . "Why these settings matter:\n{$why}\n\n"
                    . "(See docs/cloudflare-setup-guide.md — required Cloudflare settings.)\n\n"
                    . 'TaxNest automated check';
            }

            Mail::raw($body, function ($m) use ($emails, $subject) {
                $m->to($emails->all())
                    ->subject($subject);
            });

            \App\Services\MailHealth::recordSuccess();
        } catch (\Throwable $e) {
            Log::warning('Cloudflare settings alert email failed', ['error' => $e->getMessage()]);
            \App\Services\MailHealth::recordFailure('Cloudflare settings alert', $e);
        }
    }
}

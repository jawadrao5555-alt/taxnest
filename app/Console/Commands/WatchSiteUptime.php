<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;

/**
 * Uptime watchdog (Aug 2026): a shop reported a Cloudflare "SSL handshake
 * failed" (Error 525) page at 03:08 AM PKT. The origin access log proved the
 * server was serving normally that same minute, so only the Cloudflare -> origin
 * leg failed — and nobody knew until the owner happened to photograph the screen
 * hours later. Detection cannot depend on a human noticing.
 *
 * Every run does TWO probes and uses the pair to CLASSIFY the outage, which is
 * the part a plain "is the site up" ping cannot do:
 *
 *   edge   = GET the public URL through Cloudflare
 *   origin = the same URL with DNS pinned to the origin IP (Cloudflare bypassed)
 *
 *   edge OK                        -> healthy
 *   edge FAIL + origin OK          -> CLOUDFLARE-ORIGIN-LINK (hosting firewall
 *                                     blocking a Cloudflare edge IP, a web server
 *                                     restart mid-handshake, network blip).
 *                                     Actionable by the HOSTING provider.
 *   edge FAIL + origin TLS invalid -> ORIGIN-TLS-INVALID (expired/mismatched
 *                                     origin certificate — the single most common
 *                                     cause of 525 under Full (strict)).
 *                                     Actionable by US: renew AutoSSL.
 *   edge FAIL + origin FAIL        -> ORIGIN-DOWN (our server / app is the problem).
 *
 * The origin leg is probed WITH certificate verification first, precisely because
 * a bad certificate is what Full (strict) rejects; only if that fails is the probe
 * repeated without verification, to tell "certificate broken but server alive"
 * apart from "server unreachable". Skipping verification outright would report an
 * expired certificate as a healthy origin and send the owner chasing the hosting
 * provider's firewall for a problem on our own side.
 *
 * BLIND SPOT (by design): this watchdog runs ON the monitored server, so a total
 * server/network/cron failure silences it too. It cannot replace an external
 * monitor; what it does cover is the case that actually happened — origin alive,
 * Cloudflare link broken — plus every partial failure, with classification an
 * external ping cannot provide.
 *
 * Alerting rules (mirrors pos:agent-offline-alerts):
 *  - Alert only after FAILS_BEFORE_ALERT consecutive failures, so a single
 *    unlucky probe never wakes anybody.
 *  - ONE email per incident, plus ONE recovery email carrying the duration.
 *    A multi-hour outage can never turn into a mail storm.
 *  - Sent SYNCHRONOUSLY (no queue worker on cPanel), same as the other guards.
 *
 * Every probe pair is appended to storage/logs/uptime-watch.log so the incident
 * history survives independently of email, which is what makes a "was my shop
 * really down?" question answerable later.
 */
class WatchSiteUptime extends Command
{
    protected $signature = 'site:uptime-watch
        {--force : Run outside production (dev/manual checks)}
        {--no-mail : Probe and log, never email}';

    protected $description = 'Probe the live site through Cloudflare and directly at the origin; classify and alert on outages.';

    /** Consecutive failed runs before the first alert (2 runs x 2 min = ~4 min). */
    private const FAILS_BEFORE_ALERT = 2;

    /** If the alert mail could not be sent, re-attempt every Nth failed run. */
    private const ALERT_RETRY_EVERY = 15;

    private const STATE_FILE = 'uptime-watch.json';

    private const LOG_FILE = 'uptime-watch.log';

    /** Trim the log once it passes this size, keeping the newest lines. */
    private const LOG_MAX_BYTES = 2097152;

    private const LOG_KEEP_LINES = 3000;

    public function handle(): int
    {
        if (! app()->isProduction() && ! $this->option('force')) {
            $this->line('Skipped — not production (use --force to probe anyway).');

            return self::SUCCESS;
        }

        $url = trim((string) config('services.uptime_watch.url', ''));
        if ($url === '') {
            Log::warning('Uptime watch skipped: services.uptime_watch.url not configured');
            $this->warn('Skipped — no URL configured.');

            return self::FAILURE;
        }

        $edge = $this->probe($url, null);

        // The origin leg only matters when the edge failed; skipping it while
        // healthy halves the request volume of a job that runs every 2 minutes.
        $origin = $edge['ok'] ? null : $this->probeOrigin($url);

        $kind = $this->classify($edge, $origin);
        $this->appendLog($edge, $origin, $kind);

        if ($edge['ok']) {
            $this->recovered();
            $this->info("OK — {$edge['status']} in {$edge['ms']}ms.");

            return self::SUCCESS;
        }

        $this->failed($kind, $edge, $origin);
        $this->error("FAIL ({$kind}) — edge " . $this->describe($edge) . ', origin ' . $this->describe($origin) . '.');

        return self::FAILURE;
    }

    /**
     * One HTTP probe. $resolveIp pins the hostname to that IP (Cloudflare
     * bypass); null goes through normal DNS, i.e. through Cloudflare.
     *
     * Anything below 500 counts as reachable — a redirect or even a 404 still
     * proves the TLS handshake and the web server worked, which is what this
     * watchdog measures. 5xx (including Cloudflare's 52x family) and thrown
     * connection errors are failures.
     */
    private function probe(string $url, ?string $resolveIp, bool $verify = true): array
    {
        $started = microtime(true);

        try {
            $request = Http::connectTimeout(10)
                ->timeout(15)
                ->withoutRedirecting()
                ->withHeaders([
                    'User-Agent' => 'TaxNest-UptimeWatch/1.0',
                    'Cache-Control' => 'no-cache',
                ]);

            if ($resolveIp !== null) {
                $host = (string) parse_url($url, PHP_URL_HOST);
                $port = (int) (parse_url($url, PHP_URL_PORT) ?: 443);
                $request = $request->withOptions([
                    'curl' => [CURLOPT_RESOLVE => ["{$host}:{$port}:{$resolveIp}"]],
                ]);
            }

            if (! $verify) {
                $request = $request->withoutVerifying();
            }

            // Cache-busting param: a cached edge hit would hide an origin outage.
            $response = $request->get($url, ['_uw' => (string) time()]);

            return [
                'ok' => $response->status() < 500,
                'status' => $response->status(),
                'ms' => $this->elapsedMs($started),
                'error' => null,
                'tls_ok' => true,
                // Cloudflare stamps every response — including its own 52x error
                // pages — with cf-ray. The hosting provider cannot trace a
                // Cloudflare-side failure in their logs without it, so it is
                // captured here rather than reconstructed after the fact.
                'ray' => $response->header('cf-ray') ?: null,
            ];
        } catch (\Throwable $e) {
            return [
                'ok' => false,
                'status' => 0,
                'ms' => $this->elapsedMs($started),
                'error' => $e->getMessage(),
                'tls_ok' => $verify ? null : true,
                'ray' => null,
            ];
        }
    }

    /**
     * The Cloudflare-bypass leg. Verification stays ON for the first attempt: an
     * expired or mismatched origin certificate is exactly what Full (strict)
     * rejects with 525, so it must be detectable, not skipped. Only when the
     * verified attempt fails is the probe repeated without verification — if the
     * server answers then, the certificate is the fault rather than the server.
     */
    private function probeOrigin(string $url): array
    {
        $ip = $this->originIp($url);

        if ($ip === null) {
            return [
                'ok' => false, 'status' => 0, 'ms' => 0, 'tls_ok' => null,
                'error' => 'origin IP could not be determined (set UPTIME_WATCH_ORIGIN_IP)',
            ];
        }

        $verified = $this->probe($url, $ip, verify: true);
        if ($verified['ok']) {
            return $verified;
        }

        $insecure = $this->probe($url, $ip, verify: false);

        // Server answers only with verification off => the certificate is broken.
        if ($insecure['ok']) {
            $insecure['tls_ok'] = false;
            $insecure['error'] = 'certificate rejected: ' . (string) $verified['error'];
        }

        return $insecure;
    }

    /**
     * Origin IP for the Cloudflare-bypass probe. Configured value wins; the
     * fallback resolves the cPanel hostname, which is never proxied through
     * Cloudflare and therefore always points at the real server — so the
     * monitor keeps working after a hosting IP change with no redeploy.
     */
    private function originIp(string $url): ?string
    {
        $configured = trim((string) config('services.uptime_watch.origin_ip', ''));
        if ($configured !== '') {
            return $configured;
        }

        $host = (string) parse_url($url, PHP_URL_HOST);
        if ($host === '') {
            return null;
        }

        $cpanelHost = 'cpanel.' . preg_replace('/^www\./', '', $host);
        $ip = gethostbyname($cpanelHost);

        // gethostbyname returns the input unchanged when resolution fails.
        return filter_var($ip, FILTER_VALIDATE_IP) ? $ip : null;
    }

    private function classify(array $edge, ?array $origin): string
    {
        if ($edge['ok']) {
            return 'OK';
        }
        if ($origin === null || $origin['ok'] === false) {
            return 'ORIGIN-DOWN';
        }
        if (($origin['tls_ok'] ?? true) === false) {
            return 'ORIGIN-TLS-INVALID';
        }

        return 'CLOUDFLARE-ORIGIN-LINK';
    }

    /** Healthy run: close any open incident and email the all-clear once. */
    private function recovered(): void
    {
        $state = $this->readState();
        if (($state['fails'] ?? 0) === 0) {
            return;
        }

        $wasAlerted = (bool) ($state['alerted'] ?? false);
        $since = $state['since'] ?? null;
        $kind = (string) ($state['kind'] ?? 'UNKNOWN');

        $this->writeState(['fails' => 0, 'since' => null, 'alerted' => false, 'kind' => null]);

        if (! $wasAlerted) {
            return;   // Never alerted, so there is nothing to stand down from.
        }

        $minutes = $since ? max(1, (int) round((time() - strtotime($since)) / 60)) : 0;
        Log::info('Uptime watch: site recovered', ['kind' => $kind, 'down_minutes' => $minutes]);

        $this->mailAdmins(
            'RECOVERED: taxnest.com.pk is reachable again',
            "The site is answering normally again.\n\n"
            . "Outage type : {$kind}\n"
            . "Started     : " . $this->localTime($since) . "\n"
            . "Duration    : ~{$minutes} minute(s)\n\n"
            . "No action needed — this is the all-clear message.\n\n"
            . 'TaxNest uptime watch'
        );
    }

    /** Failed run: count it and send exactly one alert per incident. */
    private function failed(string $kind, array $edge, ?array $origin): void
    {
        $state = $this->readState();
        $fails = ((int) ($state['fails'] ?? 0)) + 1;
        $since = $state['since'] ?? now()->toIso8601String();
        $alerted = (bool) ($state['alerted'] ?? false);

        // Retry a failed send instead of going quiet: mail is only marked done
        // once it actually left. Attempts are spaced (2nd failure, then every
        // 15th ~= half-hourly) so a broken mailer can never turn into a storm.
        $shouldAlert = ! $alerted
            && $fails >= self::FAILS_BEFORE_ALERT
            && ($fails === self::FAILS_BEFORE_ALERT || $fails % self::ALERT_RETRY_EVERY === 0);

        Log::warning('Uptime watch: probe failed', [
            'kind' => $kind,
            'consecutive_fails' => $fails,
            'edge' => $this->describe($edge),
            'origin' => $this->describe($origin),
        ]);

        $delivered = false;

        if ($shouldAlert) {
            $explanation = match ($kind) {
                'CLOUDFLARE-ORIGIN-LINK' =>
                    "Cloudflare cannot reach the hosting server, but the server itself IS\n"
                    . "answering (with a valid certificate) when Cloudflare is bypassed.\n"
                    . "Visitors see Cloudflare's \"SSL handshake failed\" / 52x error page.\n\n"
                    . "This is a HOSTING-side problem, not an application bug. Usual causes:\n"
                    . "  - the server firewall (CSF/lfd) temporarily blocking a Cloudflare edge IP\n"
                    . "  - a web server restart (AutoSSL renewal / provider maintenance)\n"
                    . "  - a transient network fault between Cloudflare and the datacentre\n\n"
                    . "Action: send the hosting provider the UTC timestamp AND the Cloudflare Ray\n"
                    . "ID printed above — without the Ray ID they cannot trace the failed\n"
                    . "connection in their logs and the ticket will come back as \"nothing found\".",
                'ORIGIN-TLS-INVALID' =>
                    "The server is alive, but its SSL certificate is being REJECTED. Cloudflare\n"
                    . "runs in Full (strict) mode, so it refuses the origin and shows visitors\n"
                    . "\"SSL handshake failed\" (Error 525).\n\n"
                    . "This one is on OUR side and is fixable in cPanel:\n"
                    . "  - cPanel -> SSL/TLS Status -> Run AutoSSL (renew the certificate)\n"
                    . "  - or install a Cloudflare Origin Certificate (15-year, never expires\n"
                    . "    in practice) — see docs/cloudflare-setup-guide.md",
                default =>
                    "Neither Cloudflare nor a direct connection to the origin could get a\n"
                    . "healthy response — the server or the application itself is down.\n\n"
                    . "Action: check the server, then the application error log.",
            };

            $delivered = $this->mailAdmins(
                "DOWN: taxnest.com.pk unreachable ({$kind})",
                "The uptime watchdog failed {$fails} checks in a row.\n\n"
                . "Type          : {$kind}\n"
                . "First failure : " . $this->localTime($since) . "\n"
                . "Via Cloudflare: " . $this->describe($edge) . "\n"
                . "Direct origin : " . $this->describe($origin) . "\n\n"
                . $explanation . "\n\n"
                . "Shops running the NestPOS Desktop Agent with Offline Mode keep billing\n"
                . "through this; their bills sync once the site is reachable again.\n\n"
                . "You will get one more email when the site recovers.\n\n"
                . 'TaxNest uptime watch'
            );
        }

        $this->writeState([
            'fails' => $fails,
            'since' => $since,
            'alerted' => $alerted || $delivered,
            'kind' => $kind,
        ]);
    }

    /** @return bool true only when the mail actually left — the caller uses this
     *               to decide whether the incident counts as "alerted". */
    private function mailAdmins(string $subject, string $body): bool
    {
        if ($this->option('no-mail')) {
            return false;
        }

        try {
            $emails = \App\Models\AdminUser::whereNotNull('email')
                ->where('email', '!=', '')
                ->pluck('email')->unique()->values();

            if ($emails->isEmpty()) {
                Log::warning('Uptime watch alert: no admin emails configured');

                return false;
            }

            Mail::raw($body, function ($m) use ($emails, $subject) {
                $m->to($emails->all())->subject($subject);
            });

            \App\Services\MailHealth::recordSuccess();

            return true;
        } catch (\Throwable $e) {
            Log::warning('Uptime watch alert email failed', ['error' => $e->getMessage()]);
            \App\Services\MailHealth::recordFailure('Uptime watch alert', $e);

            return false;
        }
    }

    private function describe(?array $probe): string
    {
        if ($probe === null) {
            return 'not probed';
        }
        if (($probe['tls_ok'] ?? true) === false) {
            return "HTTP {$probe['status']} but INVALID TLS — "
                . \Illuminate\Support\Str::limit((string) $probe['error'], 160);
        }
        if ($probe['error'] !== null) {
            return 'connection error: ' . \Illuminate\Support\Str::limit($probe['error'], 160);
        }

        return "HTTP {$probe['status']} in {$probe['ms']}ms";
    }

    private function elapsedMs(float $started): int
    {
        return (int) round((microtime(true) - $started) * 1000);
    }

    private function localTime(?string $iso): string
    {
        if (! $iso) {
            return 'unknown';
        }

        return \Illuminate\Support\Carbon::parse($iso)
            ->timezone(config('app.timezone'))
            ->format('d M Y, h:i A');
    }

    // ------------------------------------------------------------------
    // State + log files (plain files: readable over SSH during an incident,
    // and independent of the cache store, which the outage may involve).
    // ------------------------------------------------------------------

    private function statePath(): string
    {
        return storage_path('app/' . self::STATE_FILE);
    }

    private function readState(): array
    {
        $path = $this->statePath();
        if (! is_file($path)) {
            return [];
        }
        $decoded = json_decode((string) @file_get_contents($path), true);

        return is_array($decoded) ? $decoded : [];
    }

    /**
     * Atomic write (temp file + rename): a truncate-in-place write that is
     * interrupted, or read by a concurrent run mid-write, would yield invalid
     * JSON — which readState() treats as "no incident", silently resetting the
     * failure counter and suppressing the alert for a real outage.
     */
    private function writeState(array $state): void
    {
        $path = $this->statePath();
        @mkdir(dirname($path), 0775, true);

        $tmp = $path . '.' . getmypid() . '.tmp';
        $written = @file_put_contents($tmp, json_encode($state, JSON_PRETTY_PRINT), LOCK_EX);

        if ($written === false || ! @rename($tmp, $path)) {
            @unlink($tmp);
            // Without durable state the counter restarts every run and no alert
            // would ever fire, so this must be loud rather than swallowed.
            Log::error('Uptime watch: could not persist state file', ['path' => $path]);
        }
    }

    private function appendLog(array $edge, ?array $origin, string $kind): void
    {
        $path = storage_path('logs/' . self::LOG_FILE);
        $line = sprintf(
            "%s | edge=%s%s | origin=%s | %s\n",
            now()->timezone(config('app.timezone'))->format('Y-m-d H:i:s'),
            $this->describe($edge),
            ($edge['ray'] ?? null) ? ' ray=' . $edge['ray'] : '',
            $origin === null ? '-' : $this->describe($origin),
            $kind
        );

        @mkdir(dirname($path), 0775, true);
        @file_put_contents($path, $line, FILE_APPEND | LOCK_EX);

        if (@filesize($path) > self::LOG_MAX_BYTES) {
            $lines = @file($path) ?: [];
            @file_put_contents($path, implode('', array_slice($lines, -self::LOG_KEEP_LINES)), LOCK_EX);
        }
    }
}

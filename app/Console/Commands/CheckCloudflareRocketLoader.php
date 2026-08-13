<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;

/**
 * Nightly guard: Cloudflare Rocket Loader must stay OFF.
 *
 * Rocket Loader rewrites/delays inline <script> tags, which kills the POS
 * universal sale screen's Alpine x-data boot (items stop registering). Any
 * Cloudflare dashboard admin could flip it ON by mistake, so this command
 * fetches the live homepage and looks for the Rocket Loader injection
 * marker (`/cdn-cgi/scripts/.../rocket-loader`). If found, it emails every
 * SaaS admin immediately.
 *
 * Runs SYNCHRONOUSLY from the scheduler (no queue worker required on
 * cPanel). Fetch failures are logged but do NOT alert — a transient network
 * blip is not a Rocket Loader incident; the next nightly run re-checks.
 */
class CheckCloudflareRocketLoader extends Command
{
    protected $signature = 'cloudflare:check-rocket-loader
        {--url= : Override the page to check (default: https://taxnest.com.pk/)}';

    protected $description = 'Alert admins if Cloudflare Rocket Loader is detected ON for the live site.';

    private const DEFAULT_URL = 'https://taxnest.com.pk/';

    public function handle(): int
    {
        $url = (string) ($this->option('url') ?: self::DEFAULT_URL);

        try {
            // Plain GET like a real browser hit; follow redirects so the
            // check works even if / redirects (www, https upgrades, etc.).
            $response = Http::withHeaders([
                'User-Agent' => 'TaxNest-RocketLoader-Check/1.0',
                'Accept' => 'text/html',
            ])->timeout(30)->get($url);
        } catch (\Throwable $e) {
            Log::warning('Rocket Loader check: fetch failed', [
                'url' => $url,
                'error' => $e->getMessage(),
            ]);
            $this->error("Fetch failed: {$e->getMessage()}");

            return self::FAILURE;
        }

        if (! $response->successful()) {
            Log::warning('Rocket Loader check: non-2xx response', [
                'url' => $url,
                'status' => $response->status(),
            ]);
            $this->error("Non-2xx response: {$response->status()}");

            return self::FAILURE;
        }

        $html = (string) $response->body();

        // Rocket Loader injects a script from /cdn-cgi/scripts/<hash>/cloudflare-static/rocket-loader.min.js
        // and rewrites script tags to type="<hash>-text/javascript" with data-cf-modified attrs.
        $found = stripos($html, 'rocket-loader') !== false
            && stripos($html, 'cdn-cgi/scripts') !== false;

        if (! $found) {
            $this->info('OK — Rocket Loader not detected.');

            return self::SUCCESS;
        }

        Log::error('Rocket Loader DETECTED on live site', ['url' => $url]);
        $this->error('Rocket Loader DETECTED — attempting auto-fix via Cloudflare API.');

        $autoFixed = $this->turnOffViaApi();
        $this->alertAdmins($url, $autoFixed);

        return self::FAILURE;
    }

    /**
     * PATCH zone settings/rocket_loader = off via the Cloudflare API.
     * Returns true only when Cloudflare confirms success. Any failure
     * (missing token/zone, HTTP error, API error) is logged and returns
     * false so the urgent manual-fix email still goes out unchanged.
     */
    private function turnOffViaApi(): bool
    {
        $token = (string) config('services.cloudflare.api_token', '');
        $zoneId = (string) config('services.cloudflare.zone_id', '');

        if ($token === '' || $zoneId === '') {
            Log::warning('Rocket Loader auto-fix skipped: CLOUDFLARE_API_TOKEN / CLOUDFLARE_ZONE_ID not configured');
            $this->warn('Auto-fix skipped — Cloudflare API token/zone not configured.');

            return false;
        }

        try {
            $response = Http::withToken($token)
                ->timeout(30)
                ->patch("https://api.cloudflare.com/client/v4/zones/{$zoneId}/settings/rocket_loader", [
                    'value' => 'off',
                ]);

            $json = $response->json();
            $ok = $response->successful() && (bool) ($json['success'] ?? false);

            if ($ok) {
                Log::warning('Rocket Loader auto-fixed: turned OFF via Cloudflare API');
                $this->info('Auto-fix OK — Rocket Loader turned OFF via Cloudflare API.');

                return true;
            }

            Log::error('Rocket Loader auto-fix FAILED: Cloudflare API rejected the request', [
                'status' => $response->status(),
                'errors' => $json['errors'] ?? null,
            ]);
            $this->error("Auto-fix FAILED — Cloudflare API status {$response->status()}.");

            return false;
        } catch (\Throwable $e) {
            Log::error('Rocket Loader auto-fix FAILED: API call threw', ['error' => $e->getMessage()]);
            $this->error("Auto-fix FAILED — {$e->getMessage()}");

            return false;
        }
    }

    /**
     * Email every admin account. Mirrors the payment-proof alert pattern:
     * synchronous send, failures logged + MailHealth-recorded.
     */
    private function alertAdmins(string $url, bool $autoFixed = false): void
    {
        try {
            $emails = \App\Models\AdminUser::whereNotNull('email')
                ->where('email', '!=', '')
                ->pluck('email')->unique()->values();
            if ($emails->isEmpty()) {
                Log::warning('Rocket Loader alert: no admin emails configured');

                return;
            }

            if ($autoFixed) {
                $subject = 'FIXED: Cloudflare Rocket Loader was ON — auto-turned OFF';
                $body = "Cloudflare Rocket Loader was detected ON for the live site and has been\n"
                    . "AUTOMATICALLY turned OFF via the Cloudflare API.\n\n"
                    . "Checked page: {$url}\n"
                    . "Marker found: /cdn-cgi/scripts/.../rocket-loader script injection\n"
                    . "Action taken: PATCH settings/rocket_loader = off (success)\n\n"
                    . "Someone flipped Rocket Loader ON in the Cloudflare dashboard — please find out\n"
                    . "who/why so it does not happen again. Rocket Loader BREAKS the POS sale screen.\n\n"
                    . "Verify: hard-refresh a POS sale screen and confirm items register again.\n"
                    . "(Cloudflare edge cache may take a few minutes to serve clean HTML.)\n\n"
                    . "(See docs/cloudflare-setup-guide.md — Rocket Loader must stay OFF.)\n\n"
                    . 'TaxNest automated check';
            } else {
                $subject = 'URGENT: Cloudflare Rocket Loader is ON — POS sale screen at risk';
                $body = "WARNING: Cloudflare Rocket Loader appears to be ON for the live site.\n\n"
                    . "Checked page: {$url}\n"
                    . "Marker found: /cdn-cgi/scripts/.../rocket-loader script injection\n\n"
                    . "Automatic fix via the Cloudflare API FAILED or is not configured\n"
                    . "(CLOUDFLARE_API_TOKEN / CLOUDFLARE_ZONE_ID) — manual fix needed NOW.\n\n"
                    . "Rocket Loader delays/rewrites inline scripts and BREAKS the POS sale screen\n"
                    . "(Alpine x-data stops booting — cashiers cannot add items to bills).\n\n"
                    . "Fix NOW:\n"
                    . "1. https://dash.cloudflare.com -> taxnest.com.pk -> Speed -> Optimization\n"
                    . "2. Turn Rocket Loader OFF\n"
                    . "3. Hard-refresh a POS sale screen to confirm items register again\n\n"
                    . "(See docs/cloudflare-setup-guide.md — Rocket Loader must stay OFF.)\n\n"
                    . 'TaxNest automated check';
            }

            Mail::raw($body, function ($m) use ($emails, $subject) {
                $m->to($emails->all())
                    ->subject($subject);
            });

            \App\Services\MailHealth::recordSuccess();
        } catch (\Throwable $e) {
            Log::warning('Rocket Loader alert email failed', ['error' => $e->getMessage()]);
            \App\Services\MailHealth::recordFailure('Rocket Loader alert', $e);
        }
    }
}

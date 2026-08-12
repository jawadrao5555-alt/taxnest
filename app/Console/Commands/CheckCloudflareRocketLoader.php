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
        $this->error('Rocket Loader DETECTED — alerting admins.');
        $this->alertAdmins($url);

        return self::FAILURE;
    }

    /**
     * Email every admin account. Mirrors the payment-proof alert pattern:
     * synchronous send, failures logged + MailHealth-recorded.
     */
    private function alertAdmins(string $url): void
    {
        try {
            $emails = \App\Models\AdminUser::whereNotNull('email')
                ->where('email', '!=', '')
                ->pluck('email')->unique()->values();
            if ($emails->isEmpty()) {
                Log::warning('Rocket Loader alert: no admin emails configured');

                return;
            }

            $body = "WARNING: Cloudflare Rocket Loader appears to be ON for the live site.\n\n"
                . "Checked page: {$url}\n"
                . "Marker found: /cdn-cgi/scripts/.../rocket-loader script injection\n\n"
                . "Rocket Loader delays/rewrites inline scripts and BREAKS the POS sale screen\n"
                . "(Alpine x-data stops booting — cashiers cannot add items to bills).\n\n"
                . "Fix NOW:\n"
                . "1. https://dash.cloudflare.com -> taxnest.com.pk -> Speed -> Optimization\n"
                . "2. Turn Rocket Loader OFF\n"
                . "3. Hard-refresh a POS sale screen to confirm items register again\n\n"
                . "(See docs/cloudflare-setup-guide.md — Rocket Loader must stay OFF.)\n\n"
                . 'TaxNest automated check';

            Mail::raw($body, function ($m) use ($emails) {
                $m->to($emails->all())
                    ->subject('URGENT: Cloudflare Rocket Loader is ON — POS sale screen at risk');
            });

            \App\Services\MailHealth::recordSuccess();
        } catch (\Throwable $e) {
            Log::warning('Rocket Loader alert email failed', ['error' => $e->getMessage()]);
            \App\Services\MailHealth::recordFailure('Rocket Loader alert', $e);
        }
    }
}

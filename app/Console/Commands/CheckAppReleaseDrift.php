<?php

namespace App\Console\Commands;

use App\Services\ApkManifestReader;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Http;

/**
 * Task 1412 — catch a phone app whose new versionName never reached shops.
 *
 * WHY: each of the six Android downloads (POS, FBR POS, Waiter, Rider, DI,
 * Caller ID) is released in THREE separate manual steps — build the APK,
 * upload it, flip the admin *_latest_version setting. Skip or reorder any one
 * and the release is silently invisible: the Caller ID app sat at 1.4.0 in the
 * repo for weeks while the website kept serving 1.1.0 and the admin setting
 * still said 1.1.0, so no shop ever saw the consent screen, the language switch
 * or the POS call back. Nothing flagged it.
 *
 * This command reconciles the three numbers PER APP, over HTTP so it needs no
 * SSH:
 *   1. BUILD    — versionName in <module>/app/build.gradle (source of truth)
 *   2. ADVERTISED — the live site's /api/app-version?app=<key> `latest`
 *      (what phones compare against for the update banner)
 *   3. HOSTED   — the versionName INSIDE the downloaded APK (what a phone
 *      actually installs)
 *
 * Exit non-zero if any app's three numbers disagree, so a release cannot be
 * called finished while shops still download the old file. Missing pieces
 * (empty setting, un-downloadable APK) are reported, never hidden.
 *
 * SDK-free: the hosted APK's versionName is read by ApkManifestReader, the same
 * binary-manifest parser scripts/apk-release-check.sh uses.
 */
class CheckAppReleaseDrift extends Command
{
    protected $signature = 'apps:check-release-drift
        {--base= : Site base URL (default: https://taxnest.pk)}
        {--app=* : Limit to these app keys (default: all)}';

    protected $description = 'Reconcile each Android app version: build.gradle vs live /api/app-version vs the hosted APK.';

    private const DEFAULT_BASE = 'https://taxnest.pk';

    /**
     * app key => [human name, build.gradle path, api key].
     * caller/caller_plus share one module (one build.gradle) on purpose — the
     * plus build carries the same versionName, only a different flavor.
     */
    private const APPS = [
        'pos'         => ['POS',              'pos-app/app/build.gradle',     'pos'],
        'fbrpos'      => ['FBR POS',          'fbr-pos-app/app/build.gradle', 'fbrpos'],
        'waiter'      => ['Waiter',           'waiter-app/app/build.gradle',  'waiter'],
        'rider'       => ['Rider',            'rider-app/app/build.gradle',   'rider'],
        'di'          => ['DI',               'di-app/app/build.gradle',      'di'],
        'caller'      => ['Caller ID (clean)', 'caller-app/app/build.gradle', 'caller'],
        'caller_plus' => ['Caller ID (plus)',  'caller-app/app/build.gradle', 'caller_plus'],
    ];

    public function handle(): int
    {
        $base = rtrim((string) ($this->option('base') ?: self::DEFAULT_BASE), '/');
        $only = (array) $this->option('app');

        $drift = 0;
        $this->line("Release drift check — base {$base}");
        $this->line(str_repeat('-', 72));

        foreach (self::APPS as $key => [$name, $gradle, $apiKey]) {
            if ($only && !in_array($key, $only, true)) {
                continue;
            }

            $build = $this->buildVersion(base_path($gradle));
            [$advertised, $apkUrl] = $this->advertisedVersion($base, $apiKey);
            $hosted = $apkUrl ? $this->hostedVersion($apkUrl) : null;

            $line = sprintf(
                '%-20s build=%-8s advertised=%-8s hosted=%-8s',
                $name,
                $build ?? '?',
                $advertised === null ? '?' : ($advertised === '' ? '(unset)' : $advertised),
                $hosted ?? '?'
            );

            // The three numbers we CAN read must all agree. A null (build.gradle
            // unreadable, site unreachable, APK not downloadable) is reported
            // but never counted as a silent pass — "cannot verify" is drift.
            $known = array_filter([$build, $advertised === '' ? null : $advertised, $hosted], fn ($v) => $v !== null);
            $agree = $build !== null && $advertised !== null && $advertised !== '' && $hosted !== null
                && $build === $advertised && $advertised === $hosted;

            if ($agree) {
                $this->info('  OK   ' . $line);
                continue;
            }

            $drift++;
            $this->error(' DRIFT ' . $line);
            if ($advertised === '') {
                $this->warn("        └─ {$name}: /api/app-version says (unset) — the admin *_latest_version is blank, so no phone is offered an update.");
            } elseif ($advertised !== null && $hosted !== null && $advertised !== $hosted) {
                $this->warn("        └─ {$name}: site advertises {$advertised} but the hosted APK is {$hosted} — phones would download the wrong file.");
            } elseif ($build !== null && $advertised !== null && $build !== $advertised) {
                $this->warn("        └─ {$name}: built {$build} but the site still advertises {$advertised} — new build never went live.");
            } elseif (count($known) < 3) {
                $this->warn("        └─ {$name}: could not read every source (see ? above) — treat as drift until verified.");
            }
        }

        $this->line(str_repeat('-', 72));
        if ($drift > 0) {
            $this->error("{$drift} app(s) out of sync — a release is not finished until build, site and APK all match.");
            return 1;
        }
        $this->info('All checked apps: build.gradle, site and hosted APK agree.');
        return 0;
    }

    /** versionName out of a module's app/build.gradle, or null. */
    private function buildVersion(string $path): ?string
    {
        if (!is_file($path)) {
            return null;
        }
        $src = (string) file_get_contents($path);
        if (preg_match('/^\s*versionName\s+["\']([^"\']+)["\']/m', $src, $m)) {
            return trim($m[1]);
        }
        return null;
    }

    /**
     * The live site's advertised version + apk_url for one app key.
     * Returns [null, null] on any fetch/JSON failure (reported as ?), or
     * ['', $url] when the setting is unset (reported as (unset)).
     *
     * @return array{0: ?string, 1: ?string}
     */
    private function advertisedVersion(string $base, string $apiKey): array
    {
        try {
            $res = Http::withHeaders(['Accept' => 'application/json'])
                ->timeout(30)
                ->get($base . '/api/app-version', ['app' => $apiKey]);
        } catch (\Throwable $e) {
            return [null, null];
        }
        if (!$res->ok() || ($res->json('ok') !== true)) {
            return [null, null];
        }
        return [(string) $res->json('latest', ''), (string) $res->json('apk_url', '')];
    }

    /**
     * Download the hosted APK to a temp file and read its versionName. Returns
     * null when the file is not downloadable or not a readable APK.
     */
    private function hostedVersion(string $url): ?string
    {
        try {
            $res = Http::timeout(60)->get($url);
        } catch (\Throwable $e) {
            return null;
        }
        if (!$res->ok()) {
            return null;
        }
        $tmp = tempnam(sys_get_temp_dir(), 'apk');
        if ($tmp === false) {
            return null;
        }
        try {
            file_put_contents($tmp, $res->body());
            return ApkManifestReader::versionName($tmp);
        } finally {
            @unlink($tmp);
        }
    }
}

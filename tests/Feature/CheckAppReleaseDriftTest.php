<?php

namespace Tests\Feature;

use Illuminate\Support\Facades\Http;
use Tests\Support\FakeApkBuilder;
use Tests\TestCase;

/**
 * Task 1412 — apps:check-release-drift reconciles, per Android app, the three
 * numbers a release keeps in sync by hand: the versionName in build.gradle, the
 * version the live site advertises (/api/app-version), and the versionName
 * inside the hosted APK. It exits non-zero the moment they disagree, so a
 * release cannot be called finished while shops still download the old file.
 *
 * All three sources are faked: the live API over Http::fake, the APK body as a
 * real binary-manifest zip so the SDK-free reader runs for real. build.gradle
 * is the repo's own (unfakeable), so the tests limit --app to caller and pin
 * the assertions to what the built file actually says.
 */
class CheckAppReleaseDriftTest extends TestCase
{
    private const BASE = 'https://taxnest.test';

    /** The caller-app build.gradle versionName the repo ships today. */
    private string $builtVersion;

    /** Temp APKs served to the command; removed afterwards. */
    private array $tmp = [];

    protected function setUp(): void
    {
        parent::setUp();
        $gradle = (string) file_get_contents(base_path('caller-app/app/build.gradle'));
        preg_match('/^\s*versionName\s+["\']([^"\']+)["\']/m', $gradle, $m);
        $this->builtVersion = $m[1] ?? '';
        $this->assertNotSame('', $this->builtVersion, 'could not read caller-app versionName');
    }

    protected function tearDown(): void
    {
        foreach ($this->tmp as $p) {
            @unlink($p);
        }
        parent::tearDown();
    }

    /** Build an APK body of the given version to serve as the hosted file. */
    private function apkBody(string $version): string
    {
        $path = tempnam(sys_get_temp_dir(), 'drift');
        $this->tmp[] = $path;
        FakeApkBuilder::write($path, $version);
        return (string) file_get_contents($path);
    }

    private function fakeSite(string $advertised, string $apkVersion): void
    {
        $apkUrl = self::BASE . '/downloads/taxnest-caller.apk';
        Http::fake([
            self::BASE . '/api/app-version*' => Http::response([
                'ok' => true, 'app' => 'caller', 'latest' => $advertised, 'apk_url' => $apkUrl,
            ]),
            $apkUrl => Http::response($this->apkBody($apkVersion), 200),
        ]);
    }

    public function test_all_three_in_sync_passes(): void
    {
        // Site + hosted APK both match what the repo built → green.
        $this->fakeSite($this->builtVersion, $this->builtVersion);

        $this->artisan('apps:check-release-drift', ['--base' => self::BASE, '--app' => ['caller']])
            ->assertExitCode(0);
    }

    public function test_site_advertises_a_version_the_apk_lacks_is_drift(): void
    {
        // The flip-before-upload trap: site says the built version, hosted APK
        // is still an old one.
        $this->fakeSite($this->builtVersion, '0.0.1');

        $this->artisan('apps:check-release-drift', ['--base' => self::BASE, '--app' => ['caller']])
            ->expectsOutputToContain('DRIFT')
            ->assertExitCode(1);
    }

    public function test_new_build_that_never_went_live_is_drift(): void
    {
        // Repo built a version the site still does not advertise (the Caller ID
        // 1.4.0-stuck-at-1.1.0 incident this task exists for).
        $this->fakeSite('0.0.1', '0.0.1');

        $this->artisan('apps:check-release-drift', ['--base' => self::BASE, '--app' => ['caller']])
            ->assertExitCode(1);
    }

    public function test_unset_setting_is_reported_as_drift(): void
    {
        // Empty latest = no phone is offered an update — a release that stopped
        // one step short.
        $this->fakeSite('', $this->builtVersion);

        $this->artisan('apps:check-release-drift', ['--base' => self::BASE, '--app' => ['caller']])
            ->expectsOutputToContain('unset')
            ->assertExitCode(1);
    }

    public function test_unreachable_site_is_drift_never_a_silent_pass(): void
    {
        Http::fake([self::BASE . '/api/app-version*' => Http::response('down', 503)]);

        $this->artisan('apps:check-release-drift', ['--base' => self::BASE, '--app' => ['caller']])
            ->assertExitCode(1);
    }
}

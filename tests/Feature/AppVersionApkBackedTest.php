<?php

namespace Tests\Feature;

use App\Models\SystemSetting;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\FakeApkBuilder;
use Tests\TestCase;

/**
 * Task 1413 — the in-app update banner must be backed by the hosted file.
 *
 * The phone offers a download purely from the admin *_latest_version setting.
 * If that setting is flipped before (or without) the APK upload, every phone is
 * told an update exists, downloads the OLD file, installs the same versionName
 * it already runs, and is prompted again next launch — a nag that never
 * applies, with no error to explain it. Both public update endpoints
 * (/api/app-version and the Caller ID /api/caller-app/v1/version) now refuse to
 * advertise a version the hosted APK does not contain.
 */
class AppVersionApkBackedTest extends TestCase
{
    use RefreshDatabase;

    /** APKs we dropped into public/downloads and must remove afterwards. */
    private array $planted = [];

    protected function tearDown(): void
    {
        foreach ($this->planted as $p) {
            @unlink($p);
        }
        parent::tearDown();
    }

    private function plantApk(string $relative, string $version): void
    {
        $path = public_path($relative);
        if (!is_dir(dirname($path))) {
            mkdir(dirname($path), 0777, true);
        }
        FakeApkBuilder::write($path, $version);
        $this->planted[] = $path;
    }

    public function test_matching_hosted_apk_is_advertised_as_today(): void
    {
        SystemSetting::set('caller_app_latest_version', '1.4.0');
        $this->plantApk('downloads/taxnest-caller.apk', '1.4.0');

        $res = $this->getJson('/api/app-version?app=caller')->assertOk()->json();
        $this->assertSame('1.4.0', $res['latest']);
    }

    public function test_setting_ahead_of_hosted_apk_falls_back_to_the_hosted_version(): void
    {
        // Flip-before-upload: setting says 1.4.0, the file on disk is still 1.1.0.
        SystemSetting::set('caller_app_latest_version', '1.4.0');
        $this->plantApk('downloads/taxnest-caller.apk', '1.1.0');

        $res = $this->getJson('/api/app-version?app=caller')->assertOk()->json();
        $this->assertSame('1.1.0', $res['latest'],
            'A phone must never be offered a version the hosted APK does not contain.');
    }

    public function test_missing_apk_fails_open_and_trusts_the_setting(): void
    {
        // No file on disk (dev/CI) — behave exactly as before the guard.
        SystemSetting::set('waiter_app_latest_version', '9.9.9');

        $res = $this->getJson('/api/app-version?app=waiter')->assertOk()->json();
        $this->assertSame('9.9.9', $res['latest']);
    }

    public function test_caller_app_version_endpoint_is_apk_backed(): void
    {
        SystemSetting::set('caller_app_latest_version', '1.4.0');
        SystemSetting::set('caller_app_plus_latest_version', '1.4.0');
        $this->plantApk('downloads/taxnest-caller.apk', '1.1.0');       // sim: stale
        $this->plantApk('downloads/taxnest-caller-plus.apk', '1.4.0');  // plus: correct

        $sim = $this->getJson('/api/caller-app/v1/version?build=sim')->assertOk()->json();
        $this->assertSame('1.1.0', $sim['latest'], 'sim build falls back to the stale hosted version');

        $plus = $this->getJson('/api/caller-app/v1/version?build=plus')->assertOk()->json();
        $this->assertSame('1.4.0', $plus['latest'], 'plus build advertises the matching hosted version');
    }
}

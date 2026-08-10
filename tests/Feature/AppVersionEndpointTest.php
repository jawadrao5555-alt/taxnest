<?php

namespace Tests\Feature;

use App\Models\SystemSetting;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Task #443 — public /api/app-version endpoint powering the Play-Store-style
 * in-app update check inside the Android shells (pos/fbrpos/waiter/rider/di).
 */
class AppVersionEndpointTest extends TestCase
{
    use RefreshDatabase;

    public function test_unknown_app_is_404(): void
    {
        $this->getJson('/api/app-version?app=bogus')->assertStatus(404)->assertJson(['ok' => false]);
        $this->getJson('/api/app-version')->assertStatus(404);
    }

    public function test_each_app_returns_latest_and_first_party_apk_url(): void
    {
        $expect = [
            'pos'    => ['pos_app_latest_version', 'downloads/taxnest-pos.apk'],
            'fbrpos' => ['fbrpos_app_latest_version', 'downloads/taxnest-fbr-pos.apk'],
            'waiter' => ['waiter_app_latest_version', 'downloads/taxnest-waiter.apk'],
            'rider'  => ['rider_app_latest_version', 'downloads/taxnest-rider.apk'],
            'di'     => ['di_app_latest_version', 'downloads/taxnest-di.apk'],
        ];
        foreach ($expect as $app => [$key, $path]) {
            SystemSetting::set($key, '9.9.9');
            $res = $this->getJson('/api/app-version?app=' . $app)->assertOk()->json();
            $this->assertTrue($res['ok']);
            $this->assertSame('9.9.9', $res['latest'], $app);
            $this->assertStringEndsWith($path, $res['apk_url'], $app);
        }
    }

    public function test_empty_setting_means_empty_latest_no_error(): void
    {
        $res = $this->getJson('/api/app-version?app=waiter')->assertOk()->json();
        $this->assertSame('', $res['latest']);
    }

    public function test_rider_app_v1_version_prefers_system_setting(): void
    {
        SystemSetting::set('rider_app_latest_version', '9.8.7');
        $this->getJson('/api/rider-app/v1/version')->assertOk()->assertJson(['ok' => true, 'latest' => '9.8.7']);
    }
}

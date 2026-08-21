<?php

namespace Tests\Feature;

use App\Http\Controllers\SaasAdmin\AdminSettingsController;
use Tests\Support\FakeApkBuilder;
use Tests\TestCase;

/**
 * Task 1413 — the admin App-versions save must NAME a version/APK mismatch
 * back to the admin (the cause), instead of it failing silently on shops'
 * phones. AdminSettingsController::apkVersionMismatches() produces one note per
 * app whose hosted APK does not carry the version the admin just set.
 */
class AdminAppVersionMismatchWarningTest extends TestCase
{
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

    /** Call the private mismatch helper the controller uses at save time. */
    private function mismatches(array $data): array
    {
        $m = new \ReflectionMethod(AdminSettingsController::class, 'apkVersionMismatches');
        $m->setAccessible(true);
        return $m->invoke(app(AdminSettingsController::class), $data);
    }

    public function test_mismatch_names_the_app_and_both_versions(): void
    {
        $this->plantApk('downloads/taxnest-caller.apk', '1.1.0');

        $notes = $this->mismatches(['caller_app_latest_version' => '1.4.0']);
        $this->assertCount(1, $notes);
        $this->assertStringContainsString('Caller ID (clean)', $notes[0]);
        $this->assertStringContainsString('1.4.0', $notes[0]);
        $this->assertStringContainsString('1.1.0', $notes[0]);
    }

    public function test_matching_hosted_apk_produces_no_note(): void
    {
        $this->plantApk('downloads/taxnest-pos.apk', '1.1.0');
        $this->assertSame([], $this->mismatches(['pos_app_latest_version' => '1.1.0']));
    }

    public function test_absent_apk_or_empty_setting_is_not_flagged(): void
    {
        // Empty setting → nothing to check. Absent file → fails open (no note),
        // matching the phone-facing /api/app-version behaviour.
        $this->assertSame([], $this->mismatches(['rider_app_latest_version' => '']));
        $this->assertSame([], $this->mismatches(['di_app_latest_version' => '2.0.0']));
    }
}

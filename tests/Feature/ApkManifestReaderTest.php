<?php

namespace Tests\Feature;

use App\Services\ApkManifestReader;
use Tests\Support\FakeApkBuilder;
use Tests\TestCase;

/**
 * Task 1413 — the SDK-free reader that pulls versionName out of a hosted APK's
 * binary AndroidManifest.xml, plus the advertisedVersion() guard that stops the
 * in-app update check advertising a version the file does not contain.
 */
class ApkManifestReaderTest extends TestCase
{
    private string $dir;

    protected function setUp(): void
    {
        parent::setUp();
        $this->dir = sys_get_temp_dir() . '/apk-reader-test-' . uniqid();
        mkdir($this->dir, 0777, true);
    }

    protected function tearDown(): void
    {
        array_map('unlink', glob($this->dir . '/*') ?: []);
        @rmdir($this->dir);
        parent::tearDown();
    }

    public function test_reads_version_out_of_a_real_binary_manifest(): void
    {
        $apk = FakeApkBuilder::write($this->dir . '/caller.apk', '1.4.0', 5);

        $info = ApkManifestReader::read($apk);
        $this->assertSame('pk.taxnest.callerid', $info['package']);
        $this->assertSame('1.4.0', $info['versionName']);
        $this->assertSame('5', $info['versionCode']);
        $this->assertSame('1.4.0', ApkManifestReader::versionName($apk));
    }

    public function test_missing_or_unreadable_file_returns_null(): void
    {
        $this->assertNull(ApkManifestReader::read($this->dir . '/nope.apk'));
        $this->assertNull(ApkManifestReader::versionName(''));

        // A non-APK (plain zip without a manifest) also returns null, not a crash.
        $zip = new \ZipArchive();
        $zip->open($this->dir . '/plain.zip', \ZipArchive::CREATE);
        $zip->addFromString('hello.txt', 'hi');
        $zip->close();
        $this->assertNull(ApkManifestReader::versionName($this->dir . '/plain.zip'));
    }

    public function test_advertised_version_matches_the_hosted_file(): void
    {
        $apk = FakeApkBuilder::write($this->dir . '/ok.apk', '1.4.0', 5);
        // Setting agrees with the file → advertise it unchanged.
        $this->assertSame('1.4.0', ApkManifestReader::advertisedVersion('1.4.0', $apk));
    }

    public function test_advertised_version_falls_back_to_the_hosted_version_on_mismatch(): void
    {
        // The flip-before-upload trap: setting says 1.4.0, file is still 1.1.0.
        $apk = FakeApkBuilder::write($this->dir . '/old.apk', '1.1.0', 2);
        $this->assertSame(
            '1.1.0',
            ApkManifestReader::advertisedVersion('1.4.0', $apk),
            'A version the hosted APK does not contain must never be advertised.'
        );
    }

    public function test_advertised_version_fails_open_when_the_apk_is_absent(): void
    {
        // Dev/CI without the APK on disk: trust the setting rather than hide a
        // legitimately hosted release.
        $this->assertSame('1.4.0', ApkManifestReader::advertisedVersion('1.4.0', $this->dir . '/absent.apk'));
    }

    public function test_empty_setting_stays_empty(): void
    {
        $apk = FakeApkBuilder::write($this->dir . '/any.apk', '1.4.0', 5);
        $this->assertSame('', ApkManifestReader::advertisedVersion('', $apk));
    }
}

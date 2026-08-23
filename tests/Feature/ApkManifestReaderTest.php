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

    // ---------------------------------------------------------------------
    // The no-ext-zip reader. It only runs where ZipArchive is missing (the
    // owner's PHP CLI), which is exactly where nobody would notice it going
    // wrong — so it is tested directly here, on a box that HAS the extension.
    // ---------------------------------------------------------------------

    /** Call the private fallback reader. */
    private function fallback(string $path, string $entry = 'AndroidManifest.xml'): ?string
    {
        $m = new \ReflectionMethod(ApkManifestReader::class, 'entryBytesWithoutZipExt');
        $m->setAccessible(true);

        return $m->invoke(null, $path, $entry);
    }

    public function test_fallback_reader_matches_ziparchive_byte_for_byte(): void
    {
        $apk = FakeApkBuilder::write($this->dir . '/fb.apk', '1.5.0', 6);

        $zip = new \ZipArchive();
        $zip->open($apk);
        $viaExt = $zip->getFromName('AndroidManifest.xml');
        $zip->close();

        $this->assertSame($viaExt, $this->fallback($apk));
        $this->assertNull($this->fallback($apk, 'NoSuchEntry.xml'));
    }

    public function test_fallback_reader_rejects_a_fake_eocd_and_trailing_garbage(): void
    {
        $apk = FakeApkBuilder::write($this->dir . '/tail.apk', '1.5.0', 6);
        $good = (string) file_get_contents($apk);

        // Bytes appended after the real record: the EOCD no longer ends at EOF.
        file_put_contents($this->dir . '/garbage.apk', $good . str_repeat("\x00", 64));
        $this->assertNull($this->fallback($this->dir . '/garbage.apk'));

        // A bare "PK\x05\x06" signature with nonsense offsets is not a directory.
        // (ext-zip repairs such a file and still finds the entry — that is fine
        // and is why only the fallback is asserted here.)
        file_put_contents($this->dir . '/fake-eocd.apk', $good . "PK\x05\x06" . str_repeat("\xFF", 18));
        $this->assertNull($this->fallback($this->dir . '/fake-eocd.apk'));
    }

    public function test_fallback_reader_refuses_an_oversized_central_directory(): void
    {
        $apk = FakeApkBuilder::write($this->dir . '/big-cd.apk', '1.5.0', 6);
        $raw = (string) file_get_contents($apk);
        $eocd = strrpos($raw, "PK\x05\x06");

        // Claim a 512 MB central directory — must be refused, not allocated.
        $patched = substr_replace($raw, pack('V', 512 * 1024 * 1024), $eocd + 12, 4);
        file_put_contents($this->dir . '/big-cd.apk', $patched);

        $this->assertNull($this->fallback($this->dir . '/big-cd.apk'));
    }

    public function test_fallback_reader_refuses_a_compression_bomb(): void
    {
        // 12 MB of zeros deflates to a few KB — the declared plain size is what
        // stops us, before any inflate happens.
        $bomb = $this->dir . '/bomb.apk';
        $zip = new \ZipArchive();
        $zip->open($bomb, \ZipArchive::CREATE | \ZipArchive::OVERWRITE);
        $zip->addFromString('AndroidManifest.xml', str_repeat("\x00", 12 * 1024 * 1024));
        $zip->close();

        $this->assertNull($this->fallback($bomb));
        $this->assertNull(ApkManifestReader::read($bomb));
    }

    public function test_fallback_reader_refuses_a_bomb_that_lies_about_its_size(): void
    {
        // The honest-size bomb is caught by the directory cap; this one forges a
        // tiny plain size in the central directory, so only a length-limited
        // inflate can stop it.
        $path = $this->dir . '/liar.apk';
        $zip = new \ZipArchive();
        $zip->open($path, \ZipArchive::CREATE | \ZipArchive::OVERWRITE);
        $zip->addFromString('AndroidManifest.xml', str_repeat("\x00", 12 * 1024 * 1024));
        $zip->close();

        $raw = (string) file_get_contents($path);
        $cd = strpos($raw, "PK\x01\x02");                 // first (and only) entry
        $this->assertNotFalse($cd);
        $raw = substr_replace($raw, pack('V', 8000), $cd + 24, 4);   // "it's only 8 KB"
        file_put_contents($path, $raw);

        $this->assertNull($this->fallback($path), 'a forged plain size must not buy an unbounded inflate');
    }

    public function test_string_pool_refuses_a_forged_count_and_repeated_offsets(): void
    {
        $pool = new \ReflectionMethod(ApkManifestReader::class, 'stringPool');
        $pool->setAccessible(true);

        // One 32 KB string that every offset points at: 200 entries would decode
        // to ~6 MB out of a 32 KB buffer. The byte budget must cut it off.
        $body = "\xFD\x00\xFD\x00" . str_repeat('A', 32000) . "\x00";
        $build = function (int $count) use ($body): string {
            $stringsStart = 28 + ($count * 4);
            $chunk = pack('v', 0x0001) . pack('v', 28) . pack('V', $stringsStart + strlen($body))
                . pack('V', $count) . pack('V', 0)
                . pack('V', 1 << 8)                        // UTF-8 flag
                . pack('V', $stringsStart) . pack('V', 0)
                . str_repeat(pack('V', 0), $count);        // every offset → the same string

            return $chunk . $body;
        };

        $this->assertCount(2, $pool->invoke(null, $build(2), 0), 'an honest little pool still reads');
        $this->assertSame([], $pool->invoke(null, $build(200), 0), 'repeated offsets must hit the byte budget');

        // A count that cannot possibly fit in the buffer is refused outright.
        $forged = substr_replace($build(2), pack('V', 1000000), 8, 4);
        $this->assertSame([], $pool->invoke(null, $forged, 0));
    }

    public function test_a_truncated_binary_manifest_returns_null_instead_of_throwing(): void
    {
        $apk = FakeApkBuilder::write($this->dir . '/trunc.apk', '1.5.0', 6);
        $zip = new \ZipArchive();
        $zip->open($apk);
        $manifest = (string) $zip->getFromName('AndroidManifest.xml');
        $zip->close();

        // Cut before the root element is complete → nothing to report.
        foreach ([4, 12, 40] as $i => $cut) {
            $path = $this->dir . "/trunc-{$i}.apk";
            $zip = new \ZipArchive();
            $zip->open($path, \ZipArchive::CREATE | \ZipArchive::OVERWRITE);
            $zip->addFromString('AndroidManifest.xml', substr($manifest, 0, $cut));
            $zip->close();

            $this->assertNull(ApkManifestReader::read($path), "cut at {$cut} must fail open");
            $this->assertNull(ApkManifestReader::versionName($path));
            $this->assertNotNull($this->fallback($path));   // the zip itself is still valid
        }

        // Cut anywhere at all: the answer may be null or a parsed root, but the
        // call must never throw — a corrupt hosted file cannot 500 /api/app-version.
        for ($cut = 1; $cut < strlen($manifest); $cut += 37) {
            $path = $this->dir . "/scan.apk";
            @unlink($path);
            $zip = new \ZipArchive();
            $zip->open($path, \ZipArchive::CREATE | \ZipArchive::OVERWRITE);
            $zip->addFromString('AndroidManifest.xml', substr($manifest, 0, $cut));
            $zip->close();

            $info = ApkManifestReader::read($path);
            $this->assertTrue($info === null || is_array($info), "cut at {$cut} threw instead of failing open");
        }

        // Pure garbage in the manifest slot behaves the same way.
        $path = $this->dir . '/garbage-manifest.apk';
        $zip = new \ZipArchive();
        $zip->open($path, \ZipArchive::CREATE | \ZipArchive::OVERWRITE);
        $zip->addFromString('AndroidManifest.xml', random_bytes(2048));
        $zip->close();
        $this->assertNull(ApkManifestReader::versionName($path));
    }
}

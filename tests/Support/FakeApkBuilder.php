<?php

namespace Tests\Support;

/**
 * Test-only builder for a minimal APK whose binary AndroidManifest.xml carries
 * a package/versionName/versionCode (Task 1412/1413). Mirrors the AXML layout
 * scripts/tests/play-build-check-test.sh builds in python, so the PHP
 * ApkManifestReader is exercised against real binary manifest bytes — no
 * Android SDK, no python3 dependency in the PHP suite.
 */
class FakeApkBuilder
{
    private const TYPE_STRING = 0x03;
    private const TYPE_INT_DEC = 0x10;

    /** Write an APK at $path with the given version. Returns $path. */
    public static function write(string $path, string $versionName, int $versionCode = 1, string $package = 'pk.taxnest.callerid'): string
    {
        $manifest = self::axml([
            ['manifest', [
                ['package', self::TYPE_STRING, $package],
                ['versionCode', self::TYPE_INT_DEC, $versionCode],
                ['versionName', self::TYPE_STRING, $versionName],
            ]],
            ['application', [['label', self::TYPE_STRING, 'TaxNest Caller ID']]],
        ]);

        $zip = new \ZipArchive();
        if ($zip->open($path, \ZipArchive::CREATE | \ZipArchive::OVERWRITE) !== true) {
            throw new \RuntimeException("cannot create APK at {$path}");
        }
        $zip->addFromString('AndroidManifest.xml', $manifest);
        $zip->addFromString('classes.dex', "dex\n035\x00");
        $zip->addFromString('resources.arsc', "\x00");
        $zip->close();

        return $path;
    }

    /**
     * Build a binary AXML document from [[tag, [[name, type, val], ...]], ...].
     */
    private static function axml(array $elements): string
    {
        $pool = [];
        $idx = function (string $s) use (&$pool): int {
            $i = array_search($s, $pool, true);
            if ($i === false) {
                $pool[] = $s;
                $i = count($pool) - 1;
            }
            return $i;
        };

        $encoded = [];
        foreach ($elements as [$tag, $attrs]) {
            $t = $idx($tag);
            $ea = [];
            foreach ($attrs as [$name, $dtype, $val]) {
                $n = $idx($name);
                $d = $dtype === self::TYPE_STRING ? $idx($val) : $val;
                $ea[] = [$n, $dtype, $d];
            }
            $encoded[] = [$t, $ea];
        }

        // String pool (UTF-16LE entries).
        $data = '';
        $offsets = [];
        foreach ($pool as $s) {
            $offsets[] = strlen($data);
            $u16 = mb_convert_encoding($s, 'UTF-16LE', 'UTF-8');
            $data .= pack('v', mb_strlen($s, 'UTF-8')) . $u16 . "\x00\x00";
        }
        while (strlen($data) % 4 !== 0) {
            $data .= "\x00";
        }
        $offs = '';
        foreach ($offsets as $o) {
            $offs .= pack('V', $o);
        }
        $stringsStart = 28 + strlen($offs);
        $poolChunk = pack('vvVVVVVV', 0x0001, 28, $stringsStart + strlen($data),
            count($pool), 0, 0, $stringsStart, 0) . $offs . $data;

        // Start-element chunks.
        $body = '';
        foreach ($encoded as [$tagI, $attrs]) {
            $ab = '';
            foreach ($attrs as [$nameI, $dtype, $d]) {
                $raw = $dtype === self::TYPE_STRING ? $d : 0xFFFFFFFF;
                $ab .= pack('VVVvCCV', 0xFFFFFFFF, $nameI, $raw, 8, 0, $dtype, $d);
            }
            $ext = pack('VVvvvvvv', 0xFFFFFFFF, $tagI, 20, 20, count($attrs), 0, 0, 0);
            $body .= pack('vvVVV', 0x0102, 16, 16 + strlen($ext) + strlen($ab), 0, 0xFFFFFFFF) . $ext . $ab;
        }

        $payload = $poolChunk . $body;
        return pack('vvV', 0x0003, 8, 8 + strlen($payload)) . $payload;
    }
}

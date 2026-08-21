<?php

namespace App\Services;

use Illuminate\Support\Facades\Cache;

/**
 * SDK-free reader for the version stamped inside an Android APK (Task 1413).
 *
 * WHY this exists: two release traps have bitten shops through the version
 * SYSTEM SETTINGS alone —
 *   - Task 1412: the setting sat three releases ahead of the file the website
 *     actually served, so shops never got the "new" app.
 *   - Task 1413: the setting was flipped BEFORE the APK was uploaded, so every
 *     phone was told an update exists, downloaded the OLD file, installed the
 *     same version, and got nagged again on the next launch.
 * Both are invisible until you look INSIDE the hosted file, so the server now
 * cross-checks the setting against the APK's own versionName before it
 * advertises an update. The parsing logic is deliberately the same shape as
 * scripts/apk-release-check.sh's python AXML walk — a binary AndroidManifest.xml
 * carries versionCode/versionName as attributes on the root <manifest>, and we
 * can read them straight out of the zip with no Android SDK.
 *
 * Fails OPEN by design: a missing/unparsable file returns null, and callers
 * treat "cannot read the APK" as "no cross-check available" rather than hiding
 * a legitimately hosted release. The result is cached so the launch-time
 * /api/app-version poll never re-parses a multi-MB file per request.
 */
class ApkManifestReader
{
    /** Cache the parsed manifest this long — keyed on the file's mtime+size. */
    private const CACHE_TTL_SECONDS = 300;

    /**
     * versionName stamped inside the APK at $path, or null if the file is
     * missing / not a readable APK / has no versionName. Cached.
     */
    public static function versionName(string $path): ?string
    {
        $info = self::read($path);
        return $info['versionName'] ?? null;
    }

    /**
     * Task 1413 — the version the in-app update check is ALLOWED to advertise,
     * given the admin setting ($latest) and the hosted file ($apkPath).
     *
     * If the file is readable and does NOT contain $latest, we advertise the
     * file's OWN versionName instead: phones then compare against what a
     * download would actually install, so they are never nagged into
     * re-installing the same bytes (the flip-before-upload trap). When the file
     * cannot be read (dev boxes, container without the APK) we fail OPEN and
     * return $latest unchanged — the on-save admin warning is the belt-and-
     * braces, this is only the phone-facing guard.
     */
    public static function advertisedVersion(string $latest, string $apkPath): string
    {
        $latest = trim($latest);
        if ($latest === '') {
            return '';
        }
        $hosted = self::versionName($apkPath);
        if ($hosted === null || $hosted === '') {
            return $latest;               // cannot read the file → trust the setting
        }
        return $hosted === $latest ? $latest : $hosted;
    }

    /**
     * Parsed {package, versionName, versionCode} for the APK, or null.
     * Cache key folds in mtime+size so a re-uploaded APK (same path, new bytes)
     * is re-read without waiting out the TTL.
     */
    public static function read(string $path): ?array
    {
        if ($path === '' || !is_file($path) || !is_readable($path)) {
            return null;
        }
        $stamp = @filemtime($path) . ':' . @filesize($path);
        $key = 'apk_manifest:' . md5($path) . ':' . $stamp;

        return Cache::remember($key, self::CACHE_TTL_SECONDS, function () use ($path) {
            return self::parse($path);
        });
    }

    /** Open the APK zip, pull AndroidManifest.xml, parse the root attributes. */
    private static function parse(string $path): ?array
    {
        if (!class_exists(\ZipArchive::class)) {
            return null;
        }
        $zip = new \ZipArchive();
        if ($zip->open($path) !== true) {
            return null;
        }
        $bytes = $zip->getFromName('AndroidManifest.xml');
        $zip->close();
        if ($bytes === false || $bytes === '') {
            return null;
        }

        $manifest = self::rootAttributes($bytes);
        if ($manifest === null) {
            return null;
        }

        return [
            'package'     => $manifest['package'] ?? null,
            'versionName' => $manifest['versionName'] ?? null,
            'versionCode' => isset($manifest['versionCode']) ? (string) $manifest['versionCode'] : null,
        ];
    }

    /**
     * Minimal binary-AXML walk: read the string pool, find the first
     * RES_XML_START_ELEMENT_TYPE (the <manifest> root) and return its
     * attributes as name => value. Same chunk layout aapt2's xmltree dumps;
     * we only need the root, so we stop there.
     *
     * @return array<string,string|int>|null
     */
    private static function rootAttributes(string $data): ?array
    {
        $len = strlen($data);
        if ($len < 8) {
            return null;
        }
        $headerSize = self::u16($data, 2);
        $pos = $headerSize;
        $strings = [];

        while ($pos + 8 <= $len) {
            $ctype = self::u16($data, $pos);
            $chunkHeader = self::u16($data, $pos + 2);
            $chunkSize = self::u32($data, $pos + 4);
            if ($chunkSize <= 0 || $pos + $chunkSize > $len) {
                break;
            }

            if ($ctype === 0x0001) { // RES_STRING_POOL_TYPE
                $strings = self::stringPool($data, $pos);
            } elseif ($ctype === 0x0102) { // RES_XML_START_ELEMENT_TYPE (first = root)
                return self::elementAttributes($data, $pos, $chunkHeader, $strings);
            }

            $pos += $chunkSize;
        }

        return null;
    }

    /** Read a RES_STRING_POOL chunk at $pos into a 0-indexed array. */
    private static function stringPool(string $data, int $pos): array
    {
        $count = self::u32($data, $pos + 8);
        $flags = self::u32($data, $pos + 16);
        $stringsStart = self::u32($data, $pos + 20);
        $utf8 = ($flags & (1 << 8)) !== 0;
        $out = [];
        $base = $pos + $stringsStart;
        $len = strlen($data);

        for ($i = 0; $i < $count; $i++) {
            $off = self::u32($data, $pos + 28 + ($i * 4));
            $p = $base + $off;
            if ($p >= $len) {
                $out[] = '';
                continue;
            }
            if ($utf8) {
                // Two length prefixes (char count, then byte count); high bit
                // marks a second length byte. We only need the byte length.
                $n = ord($data[$p]); $p++;
                if ($n & 0x80) { $p++; }
                $n = ord($data[$p]); $p++;
                if ($n & 0x80) {
                    $n = (($n & 0x7F) << 8) | ord($data[$p]); $p++;
                }
                $out[] = substr($data, $p, $n);
            } else {
                $n = self::u16($data, $p); $p += 2;
                if ($n & 0x8000) {
                    $n = (($n & 0x7FFF) << 16) | self::u16($data, $p); $p += 2;
                }
                $raw = substr($data, $p, $n * 2);
                $out[] = mb_convert_encoding($raw, 'UTF-8', 'UTF-16LE');
            }
        }

        return $out;
    }

    /**
     * Decode the attribute table of a start-element chunk.
     *
     * @return array<string,string|int>
     */
    private static function elementAttributes(string $data, int $pos, int $chunkHeader, array $strings): array
    {
        $p = $pos + $chunkHeader;
        $attrStart = self::u16($data, $p + 8);
        $attrCount = self::u16($data, $p + 12);

        $attrs = [];
        $ap = $p + $attrStart;
        for ($i = 0; $i < $attrCount; $i++) {
            $nameIdx = self::u32($data, $ap + 4);
            $dtype = ord($data[$ap + 15]);      // res_value.dataType
            $adata = self::u32($data, $ap + 16); // res_value.data
            $key = $strings[$nameIdx] ?? '';

            if ($dtype === 0x03) {              // TYPE_STRING
                $val = $strings[$adata] ?? '';
            } else {                            // ints, bools, hex, refs → the number
                $val = $adata;
            }
            if ($key !== '') {
                $attrs[$key] = $val;
            }
            $ap += 20;                          // sizeof ResXMLTree_attribute
        }

        return $attrs;
    }

    private static function u16(string $data, int $off): int
    {
        return unpack('v', substr($data, $off, 2))[1];
    }

    private static function u32(string $data, int $off): int
    {
        return unpack('V', substr($data, $off, 4))[1];
    }
}

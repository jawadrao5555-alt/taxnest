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

    /** The one zip entry we ever read out of the APK. */
    private const ENTRY = 'AndroidManifest.xml';

    /**
     * Hard ceilings for the no-ext-zip reader. A real APK's central directory
     * is a few hundred KB and its binary AndroidManifest.xml ~10 KB, so these
     * are wildly generous — they exist only so a corrupt (or swapped) file
     * cannot make us allocate the whole archive or inflate a zip bomb. PHP's
     * memory-exhaustion fatal is NOT catchable, so refusing early is the only
     * way this stays fail-open.
     */
    private const MAX_CENTRAL_DIRECTORY = 8388608;   // 8 MiB
    private const MAX_ENTRY_BYTES = 4194304;         // 4 MiB compressed or inflated

    /**
     * Open the APK zip, pull AndroidManifest.xml, parse the root attributes.
     *
     * EVERYTHING here is inside one fail-open boundary: a corrupt/truncated APK
     * must make the caller "unable to check", never a 500. The binary walks
     * below index into the buffer at offsets the file itself supplies, and on
     * PHP 8 a short `unpack()` throws — so the catch is load-bearing, not
     * decoration.
     */
    private static function parse(string $path): ?array
    {
        try {
            $bytes = self::entryBytes($path, self::ENTRY);
            if ($bytes === null || $bytes === '') {
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
        } catch (\Throwable $e) {
            return null;
        }
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

    /**
     * Raw bytes of one entry inside the APK.
     *
     * ZipArchive is used when it exists — but it does NOT exist everywhere this
     * runs: the owner's cPanel PHP is built without the zip extension, so on
     * PRODUCTION (the only place the guard matters) every read returned null
     * and advertisedVersion() quietly fell back to trusting the setting. That
     * is the exact blind spot this class was written to close, so when the
     * extension is missing we read the zip ourselves. It is a tiny read: the
     * end-of-central-directory record, the one central-directory entry we want,
     * then that entry's bytes — no temp files, no full-file slurp.
     */
    private static function entryBytes(string $path, string $entry): ?string
    {
        if (class_exists(\ZipArchive::class)) {
            $zip = new \ZipArchive();
            if ($zip->open($path) === true) {
                $bytes = $zip->getFromName($entry);
                $zip->close();
                if (is_string($bytes) && $bytes !== '') {
                    return $bytes;
                }
            }
        }

        return self::entryBytesWithoutZipExt($path, $entry);
    }

    /** Pure-PHP zip entry read (no ext-zip). Returns null on anything unexpected. */
    private static function entryBytesWithoutZipExt(string $path, string $entry): ?string
    {
        $fh = @fopen($path, 'rb');
        if ($fh === false) {
            return null;
        }

        try {
            $size = @filesize($path);
            if (!is_int($size) || $size < 22) {
                return null;
            }

            // --- End of central directory: last 22 bytes, plus up to 64 KB of
            //     trailing zip comment. APKs have no comment, but scan anyway.
            $tailLen = (int) min($size, 22 + 65535);
            fseek($fh, $size - $tailLen);
            $tail = (string) fread($fh, $tailLen);
            $eocd = strrpos($tail, "PK\x05\x06");
            if ($eocd === false || $eocd + 22 > strlen($tail)) {
                return null;
            }

            // The record must end EXACTLY at EOF once its comment length is
            // counted, and describe a single-disk archive. Without this a
            // "PK\x05\x06" that merely happens to sit inside a comment (or
            // inside compressed data) is accepted as the directory pointer and
            // sends the walk off into arbitrary bytes — a wrong answer, which
            // is worse than no answer for a version check.
            $commentLen = self::u16($tail, $eocd + 20);
            if ($eocd + 22 + $commentLen !== strlen($tail)) {
                return null;
            }
            if (self::u16($tail, $eocd + 4) !== 0 || self::u16($tail, $eocd + 6) !== 0) {
                return null;                      // multi-disk
            }
            if (self::u16($tail, $eocd + 8) !== self::u16($tail, $eocd + 10)) {
                return null;                      // split across disks
            }

            $cdSize = self::u32($tail, $eocd + 12);
            $cdOffset = self::u32($tail, $eocd + 16);
            // 0xFFFFFFFF = zip64. Our APKs are megabytes, not gigabytes; if one
            // ever gets there, fail open rather than mis-parse.
            if ($cdSize <= 0 || $cdSize > self::MAX_CENTRAL_DIRECTORY
                || $cdOffset <= 0 || $cdOffset === 0xFFFFFFFF || $cdOffset + $cdSize > $size) {
                return null;
            }

            // --- Central directory: find the entry, note where its local header sits.
            fseek($fh, $cdOffset);
            $cd = (string) fread($fh, $cdSize);
            $p = 0;
            $cdLen = strlen($cd);
            $method = null;
            $compressedSize = 0;
            $plainSize = 0;
            $localOffset = 0;
            while ($p + 46 <= $cdLen) {
                if (substr($cd, $p, 4) !== "PK\x01\x02") {
                    return null;
                }
                $nameLen = self::u16($cd, $p + 28);
                $extraLen = self::u16($cd, $p + 30);
                $entryComment = self::u16($cd, $p + 32);
                if (substr($cd, $p + 46, $nameLen) === $entry) {
                    $method = self::u16($cd, $p + 10);
                    $compressedSize = self::u32($cd, $p + 20);
                    $plainSize = self::u32($cd, $p + 24);
                    $localOffset = self::u32($cd, $p + 42);
                    break;
                }
                $p += 46 + $nameLen + $extraLen + $entryComment;
            }
            // Size ceilings: a real binary manifest is ~10 KB, so anything near
            // the cap is a corrupt or hostile file, not our APK.
            if ($method === null || $compressedSize <= 0
                || $compressedSize > self::MAX_ENTRY_BYTES
                || $plainSize > self::MAX_ENTRY_BYTES
                || $localOffset + 30 > $size) {
                return null;
            }

            // --- Local header: its name/extra lengths can differ from the central
            //     directory's, so the data offset must come from here.
            fseek($fh, $localOffset);
            $local = (string) fread($fh, 30);
            if (strlen($local) < 30 || substr($local, 0, 4) !== "PK\x03\x04") {
                return null;
            }
            $dataAt = $localOffset + 30 + self::u16($local, 26) + self::u16($local, 28);
            if ($dataAt + $compressedSize > $size) {
                return null;
            }

            fseek($fh, $dataAt);
            $raw = (string) fread($fh, $compressedSize);
            if (strlen($raw) !== $compressedSize) {
                return null;
            }

            if ($method === 0) {                    // stored
                return $raw;
            }
            if ($method === 8) {                    // deflate
                $out = @gzinflate($raw);
                return is_string($out) && $out !== '' ? $out : null;
            }

            return null;                            // some other method — not ours to guess
        } catch (\Throwable $e) {
            return null;
        } finally {
            @fclose($fh);
        }
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

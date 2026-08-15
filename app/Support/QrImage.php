<?php

namespace App\Support;

use chillerlan\QRCode\QRCode;
use chillerlan\QRCode\QROptions;
use chillerlan\QRCode\Common\EccLevel;
use chillerlan\QRCode\Output\QROutputInterface;

class QrImage
{
    /**
     * Test-only recording buffer.  Call QrImage::fake() at the start of a test
     * to intercept all ::dataUri() calls; inspect QrImage::recorded() afterwards
     * to assert exact payloads; call QrImage::resetFake() in tearDown.
     *
     * In fake mode ::dataUri() returns a predictable sentinel string so the
     * caller (blade / controller) still gets a non-empty "image" and code paths
     * that branch on empty-string are not perturbed.
     */
    private static ?array $_fakeRecorded = null;

    public static function fake(): void
    {
        self::$_fakeRecorded = [];
    }

    /** @return string[] ordered list of $data args passed to ::dataUri() since fake() */
    public static function recorded(): array
    {
        return self::$_fakeRecorded ?? [];
    }

    public static function resetFake(): void
    {
        self::$_fakeRecorded = null;
    }

    /**
     * Generate a self-contained QR code as a base64 data URI.
     *
     * Renders entirely server-side so it works in the browser, in DomPDF and
     * fully offline — no dependency on any external image API. Prefers a PNG
     * (best DomPDF / thermal-print fidelity) and falls back to an inline SVG,
     * returning an empty string only if QR generation is impossible.
     */
    /**
     * @param int|null $minVersion QR version floor (ZFC 13 Aug 2026): receipts
     *        print the PRA fiscal QR (short payload → v1-2, chunky modules) and
     *        the local invoice QR (longer payload → v5+, fine modules) at the
     *        SAME rendered size — customers read the density mismatch as two
     *        "different" QR types. A shared floor (v4) makes short payloads
     *        render on the same 33x33 grid; longer payloads still auto-grow.
     */
    public static function dataUri(string $data, int $scale = 5, ?int $minVersion = null): string
    {
        if (self::$_fakeRecorded !== null) {
            self::$_fakeRecorded[] = $data;
            // Return a minimal valid data-URI sentinel so callers that branch on
            // empty string (e.g. @if($qrUrl)) still follow the true path.
            return 'data:image/png;base64,FAKE';
        }

        $base = [
            'eccLevel'      => EccLevel::M,
            'scale'         => $scale,
            'quietzoneSize' => 2,
            'outputBase64'  => true,
        ];
        if ($minVersion !== null) {
            $base['versionMin'] = $minVersion;
        }
        try {
            return (new QRCode(new QROptions($base + [
                'outputType'       => QROutputInterface::GDIMAGE_PNG,
                'imageTransparent' => false,
            ])))->render($data);
        } catch (\Throwable $e) {
            try {
                return (new QRCode(new QROptions($base + [
                    'outputType' => QROutputInterface::MARKUP_SVG,
                ])))->render($data);
            } catch (\Throwable $e2) {
                return '';
            }
        }
    }
}

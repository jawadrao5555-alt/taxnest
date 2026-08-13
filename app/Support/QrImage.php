<?php

namespace App\Support;

use chillerlan\QRCode\QRCode;
use chillerlan\QRCode\QROptions;
use chillerlan\QRCode\Common\EccLevel;
use chillerlan\QRCode\Output\QROutputInterface;

class QrImage
{
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

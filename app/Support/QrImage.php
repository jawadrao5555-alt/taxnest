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
    public static function dataUri(string $data, int $scale = 5): string
    {
        try {
            return (new QRCode(new QROptions([
                'outputType'       => QROutputInterface::GDIMAGE_PNG,
                'outputBase64'     => true,
                'eccLevel'         => EccLevel::M,
                'scale'            => $scale,
                'quietzoneSize'    => 2,
                'imageTransparent' => false,
            ])))->render($data);
        } catch (\Throwable $e) {
            try {
                return (new QRCode(new QROptions([
                    'outputType'    => QROutputInterface::MARKUP_SVG,
                    'outputBase64'  => true,
                    'eccLevel'      => EccLevel::M,
                    'scale'         => $scale,
                    'quietzoneSize' => 2,
                ])))->render($data);
            } catch (\Throwable $e2) {
                return '';
            }
        }
    }
}

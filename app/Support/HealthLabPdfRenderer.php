<?php

namespace App\Support;

use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Log;
use Mpdf\Barcode;
use Throwable;

final class HealthLabPdfRenderer
{
    public static function report(array $data, string $orderNumber): Response
    {
        $view = 'health.lab.report';
        $filename = $orderNumber.'.pdf';

        try {
            return MpdfRenderer::render($view, $data, 'a4', $filename, true);
        } catch (Throwable $failure) {
            Log::warning('Lab report mPDF rendering failed.', ['exception_type' => $failure::class]);
        }

        $html = view($view, $data)->render();
        // DomPDF cannot shape Arabic script. The browser can still print the
        // original verified result, including free-text values in a Latin UI.
        if (app()->getLocale() !== PosLocale::URDU_SCRIPT && preg_match('/[\p{Arabic}\p{Hebrew}]/u', $html) === 0) {
            try {
                return Pdf::loadHTML($html)->setPaper('a4')->stream($filename);
            } catch (Throwable $failure) {
                Log::warning('Lab report fallback PDF rendering failed.', ['exception_type' => $failure::class]);
            }
        }

        return response()->view($view, $data + ['browserPrint' => true]);
    }

    public static function label(array $data, string $barcode): Response
    {
        $view = 'health.lab.label';
        $filename = $barcode.'.pdf';

        try {
            return MpdfRenderer::render($view, $data, [80, 35], $filename, true);
        } catch (Throwable $failure) {
            Log::warning('Lab label mPDF rendering failed.', ['exception_type' => $failure::class]);
        }

        $data['barcodeSvg'] = self::barcodeImage($barcode);

        try {
            $pointsPerMm = 72 / 25.4;

            return Pdf::loadView($view, $data)
                ->setPaper([0, 0, 80 * $pointsPerMm, 35 * $pointsPerMm])->stream($filename);
        } catch (Throwable $failure) {
            Log::warning('Lab label fallback PDF rendering failed.', ['exception_type' => $failure::class]);

            return response()->view($view, $data + ['browserPrint' => true]);
        }
    }

    /** Draw the encoder's Code 128-B modules and retain both quiet zones. */
    private static function barcodeImage(string $barcode): string
    {
        $encoded = (new Barcode)->getBarcodeArray($barcode, 'C128B');
        $offset = (int) $encoded['lightmL'];
        $rectangles = '';

        foreach ($encoded['bcode'] as $bar) {
            $width = (int) $bar['w'];
            if ($bar['t'] && $width > 0) {
                $rectangles .= sprintf('<rect x="%d" y="0" width="%d" height="48"/>', $offset, $width);
            }
            $offset += $width;
        }

        $width = $offset + (int) $encoded['lightmR'];
        $svg = sprintf(
            '<svg xmlns="http://www.w3.org/2000/svg" width="%d" height="48" viewBox="0 0 %d 48" preserveAspectRatio="none"><rect width="%d" height="48" fill="white"/><g fill="black">%s</g></svg>',
            $width, $width, $width, $rectangles,
        );

        return 'data:image/svg+xml;base64,'.base64_encode($svg);
    }
}

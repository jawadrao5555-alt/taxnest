<?php

namespace App\Support;

use Illuminate\Http\Response;
use Throwable;

/**
 * mPDF renderer for Urdu-script (locale 'ur') PDFs.
 *
 * DomPDF cannot shape Arabic/Urdu text — it renders each glyph in isolation,
 * left-to-right, making Urdu unreadable (Task 240 fallback: applyPdfSafeLocale
 * drops 'ur' → 'rur' before every DomPDF render). This class uses mPDF v8,
 * which ships its own Arabic/Urdu OTL shaping engine, to produce a properly
 * shaped, RTL Urdu PDF.
 *
 * Font: XB Riyaz — a Naskh-style Arabic/Urdu font bundled with mPDF v8,
 * already registered in mPDF's FontVariables with useOTL=0xFF (full
 * OpenType Layout) and useKashida=75. Naskh reads well at thermal receipt
 * sizes; the alternative bundled font (Lateef/Nastaliq) is too tall/thin
 * for small point sizes on cheap thermal heads.
 *
 * Used only when locale === PosLocale::URDU_SCRIPT. en/rur continue to use
 * DomPDF unchanged. If mPDF is missing or throws, the caller catches and
 * falls back to the DomPDF+rur path — never a 500.
 *
 * Paper argument:
 *   'a4'                    → A4 portrait with standard margins
 *   [float $w, float $h]   → custom page in mm (thermal receipts)
 */
final class MpdfRenderer
{
    /**
     * mPDF font key for XB Riyaz. mPDF normalises CSS font-family names to
     * lowercase with spaces stripped, so CSS `font-family: 'XB Riyaz'`
     * resolves to the key 'xbriyaz' in its FontVariables registry.
     */
    private const FONT_KEY = 'xbriyaz';

    /**
     * Render a Blade view to a PDF response via mPDF.
     *
     * @param  string        $view        Blade view name
     * @param  array         $data        View data
     * @param  string|array  $paper       'a4' | 'a4-report' | [widthMm, heightMm]
     * @param  string        $filename    PDF filename (used in Content-Disposition)
     * @param  bool          $stream      true = inline preview, false = download
     * @param  string        $orientation 'portrait' | 'landscape' (A4 paper types only)
     * @return Response
     */
    public static function render(
        string $view,
        array $data,
        string|array $paper,
        string $filename,
        bool $stream,
        string $orientation = 'portrait'
    ): Response {
        $mpdf = static::makeMpdf($paper, $orientation);

        $html = static::prepareHtml(view($view, $data)->render(), $paper);
        $mpdf->WriteHTML($html);

        $pdfBytes = $mpdf->Output($filename, 'S');

        $disposition = $stream ? 'inline' : 'attachment';

        return response($pdfBytes, 200, [
            'Content-Type'        => 'application/pdf',
            'Content-Disposition' => $disposition . '; filename="' . $filename . '"',
            'Content-Length'      => strlen($pdfBytes),
        ]);
    }

    // -------------------------------------------------------------------------

    /**
     * Strip CSS @page { size/margin } rules from the HTML before handing to
     * mPDF so that the mPDF config (format + margin_*) controls page geometry.
     *
     * The Blade templates contain:
     *   @page { size: 80mm auto; margin: 0; }   ← thermal receipts
     *   @page { size: A4 portrait; margin: … }  ← FBR invoice
     *
     * When mPDF processes these it OVERRIDES the format/margin values we set
     * in the Mpdf constructor config, producing wildly wrong page counts (76
     * pages for an 80mm thermal slip, 822 for a single-page A4 invoice).
     * Stripping the rule lets our constructor config win.
     *
     * We also strip <script> blocks — mPDF ignores JS execution but the raw
     * script text can bloat the parsed DOM and trigger unexpected line-breaks
     * in some mPDF versions.
     */
    private static function prepareHtml(string $html, string|array $paper): string
    {
        // Remove all @page { … } CSS blocks (handles nested braces by being
        // greedy past the first closing brace that ends the rule).
        $html = preg_replace('/@page\s*\{[^}]*\}/s', '', $html);

        // Strip <script>…</script> blocks entirely — mPDF doesn't need them.
        $html = preg_replace('/<script\b[^>]*>.*?<\/script>/is', '', $html);

        return $html;
    }

    private static function makeMpdf(string|array $paper, string $orientation = 'portrait'): \Mpdf\Mpdf
    {
        // mPDF needs a writable temp dir. storage/app/mpdf is safe on both
        // dev and cPanel (storage/ is always writable in a Laravel app).
        $tempDir = storage_path('app/mpdf');
        if (!is_dir($tempDir)) {
            mkdir($tempDir, 0775, true);
        }

        $config = [
            'tempDir'      => $tempDir,
            // XB Riyaz is already registered in mPDF's bundled FontVariables.php
            // with useOTL=0xFF + useKashida=75 — no custom fontDir/fontData needed.
            // Setting it as default_font ensures any element without an explicit
            // font-family (e.g. fallback text) still uses a shaped Arabic font.
            'default_font' => self::FONT_KEY,
        ];

        if ($paper === 'a4') {
            // A4 portrait. Margins match the @page CSS rule in fbr-pos/invoice-pdf:
            // 15mm top/left/right, 18mm bottom.
            $config['format']        = $orientation === 'landscape' ? 'A4-L' : 'A4';
            $config['margin_left']   = 15;
            $config['margin_right']  = 15;
            $config['margin_top']    = 15;
            $config['margin_bottom'] = 18;
        } elseif ($paper === 'a4-report') {
            // A4 report pages. Margins match @page { margin: 10mm 15mm } that the
            // report templates declare and that prepareHtml() strips before mPDF
            // sees the HTML — so these values must be the effective page margins.
            $config['format']        = $orientation === 'landscape' ? 'A4-L' : 'A4';
            $config['margin_left']   = 15;
            $config['margin_right']  = 15;
            $config['margin_top']    = 10;
            $config['margin_bottom'] = 10;
        } else {
            // Thermal custom size [widthMm, heightMm].
            // Margins 0 — the Blade template's body padding (3mm) handles all
            // internal spacing; the DomPDF-override CSS block does the same for
            // the PDF render path. Thermal height is content-sized (over-estimated)
            // so long receipts (15+ items) never clip at the bottom.
            [$widthMm, $heightMm]    = $paper;
            $config['format']        = [$widthMm, $heightMm];
            $config['margin_left']   = 0;
            $config['margin_right']  = 0;
            $config['margin_top']    = 0;
            $config['margin_bottom'] = 0;
        }

        return new \Mpdf\Mpdf($config);
    }
}

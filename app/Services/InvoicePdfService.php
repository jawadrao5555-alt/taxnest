<?php

namespace App\Services;

use App\Models\Invoice;

/**
 * Single source of truth for the buyer-facing B/W invoice PDF (invoice.pdf-bw).
 *
 * The same build logic was previously duplicated in InvoiceController
 * (pdf / pdfBwPreview / download / bulk ZIP) and ShareController (public
 * share PDF); both now delegate here, and the email-send path
 * (InvoiceShareMail) reuses it for the attachment.
 *
 * Owner rule: everything on the PDF stays ENGLISH.
 */
class InvoicePdfService
{
    /**
     * Have we already reported that the QR cannot be drawn in this process?
     *
     * A QR failure is practically never about one invoice — it is the whole
     * environment (a PHP build without GD, most often), so the background
     * warmer would otherwise log the identical line for every invoice it
     * touches. Once is enough to diagnose it.
     */
    private static bool $qrFailureLogged = false;

    /**
     * View data for invoice.pdf-bw.
     *
     * @param float|null $fallbackWhtRate used only when the invoice has no
     *        stored wht_rate AND it is not locked+production (the controller
     *        pdf routes pass the ?wht_rate= query param through here; share
     *        links and emails pass null).
     */
    public static function buildData(Invoice $invoice, ?float $fallbackWhtRate = null): array
    {
        // 'branch' is eager-loaded because production runs with lazy loading
        // disabled and the template prints the branch trading name.
        $invoice->loadMissing('items', 'company', 'branch');

        $showWatermark = false;
        $isDraft = $invoice->status === 'draft';

        $company = $invoice->company ?? \App\Models\Company::find($invoice->company_id);
        if ($company && ($company->force_watermark ?? false)) {
            $showWatermark = true;
        }

        $subtotal = $invoice->items->sum(fn($item) => $item->price * $item->quantity);
        $totalTax = $invoice->items->sum('tax');

        if ($invoice->status === 'locked' && $invoice->fbr_status === 'production') {
            $whtRate = $invoice->wht_rate ?? 0;
            $whtAmount = $invoice->wht_amount ?? 0;
            $netReceivable = $invoice->net_receivable ?? $invoice->total_amount;
        } else {
            $whtRate = floatval($invoice->wht_rate ?? $fallbackWhtRate ?? 0);
            $whtAmount = round($subtotal * ($whtRate / 100), 2);
            $netReceivable = round(($subtotal + $totalTax) + $whtAmount, 2);
        }

        $qrBase64 = '';
        $fbrLogoBase64 = '';
        if ($invoice->fbr_invoice_number) {
            // The QR must carry the FBR invoice number and NOTHING else.
            //
            // FBR's own instruction to buyers is "enter the FBR invoice no. OR
            // scan the QR code" — i.e. the scan is a shortcut for typing that
            // number, and Tax Asaan reads the scanned text as the number it
            // looks up. We used to encode a JSON object (NTN, number, date,
            // total); a generic scanner showed the JSON, but Tax Asaan could
            // not pull an invoice number out of it, so a buyer scanning a
            // filed invoice got nothing. FBR POS receipts already encode the
            // bare number — DI was the odd one out.
            //
            // A QR failure (e.g. GD missing on a CLI binary) must not take the
            // whole invoice down: QrImage returns an empty string and the
            // template falls back to printing the FBR number on its own, which
            // is what a buyer actually needs.
            $qrBase64 = \App\Support\QrImage::dataUri(trim((string) $invoice->fbr_invoice_number), 8);

            if ($qrBase64 === '' && !self::$qrFailureLogged) {
                // Once per process, not once per invoice. When the cause is
                // environmental every invoice fails identically, and the
                // background warmer walks thousands of them: this line alone
                // once contributed 7,603 copies of itself to a 105 MB log.
                self::$qrFailureLogged = true;
                \Illuminate\Support\Facades\Log::warning('Invoice PDF: QR render failed', [
                    'first_invoice_id' => $invoice->id,
                    // Almost always the answer, and the one thing the message
                    // never used to say.
                    'gd_loaded' => extension_loaded('gd'),
                    'php_binary' => PHP_BINARY,
                ]);
            }

            // The full-size logo is a 42 KB screen asset, and it was being
            // embedded whole into EVERY filed invoice — three quarters of the
            // file, and ~250 MB of dead weight in a 6,000-invoice ZIP. The
            // print copy is the same mark at the resolution the PDF actually
            // prints it (240px for a ~16mm box ≈ 380 dpi), so it looks
            // identical on paper for a fifth of the bytes.
            $logoPath = public_path('images/fbr-digital-invoice-logo-print.png');
            if (!file_exists($logoPath)) {
                $logoPath = public_path('images/fbr-digital-invoice-logo.png');
            }
            // DomPDF cannot place an image of ANY kind without GD — its PNG
            // and SVG paths both end in Cpdf::addPngFromFile(), which throws
            // on its first line when the extension is missing. So dropping
            // only the QR above would not save the document: this logo would
            // kill it three lines later, in exactly the same way.
            //
            // An invoice without the FBR mark still shows the FBR number and
            // is still a valid document. An invoice that will not render is
            // nothing at all.
            if (file_exists($logoPath) && function_exists('imagecreatefrompng')) {
                $fbrLogoBase64 = 'data:image/png;base64,' . base64_encode(file_get_contents($logoPath));
            }
        }

        return [
            'invoice' => $invoice,
            'showWatermark' => $showWatermark,
            'isDraft' => $isDraft,
            'subtotal' => $subtotal,
            'totalTax' => $totalTax,
            'wht_rate' => $whtRate,
            'wht_amount' => $whtAmount,
            'net_receivable' => $netReceivable,
            'qrBase64' => $qrBase64,
            'fbrLogoBase64' => $fbrLogoBase64,
        ];
    }

    /**
     * Render the A4 B/W PDF (DomPDF instance — call ->stream/->download/->output).
     */
    /**
     * Every invoice PDF is built here so they all get font subsetting.
     *
     * Without it DomPDF embeds BOTH complete font files into EVERY invoice:
     * 734 KB of an 881 KB file. One shop's 5,961-invoice archive came to
     * 4.9 GB, which no shop can download and which blew past the export size
     * cap. With subsetting the same invoice is 28 KB, byte-identical in text
     * and pixel-identical on the page.
     *
     * Deliberately set here rather than globally in config: POS thermal
     * receipts embed a Nastaleeq Urdu font through the same library, and its
     * ligature coverage under subsetting has not been verified.
     */
    public static function make(string $view, array $data): \Barryvdh\DomPDF\PDF
    {
        return \Barryvdh\DomPDF\Facade\Pdf::setOption('enable_font_subsetting', true)
            ->loadView($view, $data);
    }

    public static function renderBw(Invoice $invoice, ?float $fallbackWhtRate = null): \Barryvdh\DomPDF\PDF
    {
        $pdf = self::make('invoice.pdf-bw', self::buildData($invoice, $fallbackWhtRate));
        $pdf->setPaper('A4', 'portrait');
        return $pdf;
    }

    /**
     * "14750.50" -> "Rupees Fourteen Thousand Seven Hundred Fifty and Fifty Paisa Only".
     *
     * Buyers routinely check the words line against the figure, so it is
     * printed under the totals. International scale (thousand / million) is
     * used rather than lakh / crore: the PDF is English-only by owner rule and
     * distributor invoices are read outside Pakistan too.
     */
    public static function amountInWords(float $amount): string
    {
        // A credit note prints a negative figure; the words must not read as a
        // positive amount next to it.
        $prefix = $amount < 0 ? 'Minus ' : '';
        $amount = round(abs($amount), 2);
        $rupees = (int) floor($amount);
        $paisa = (int) round(($amount - $rupees) * 100);

        $words = $prefix . 'Rupees ' . self::wordsForInt($rupees);
        if ($paisa > 0) {
            $words .= ' and ' . self::wordsForInt($paisa) . ' Paisa';
        }

        return $words . ' Only';
    }

    /** @internal English words for a non-negative integer. */
    protected static function wordsForInt(int $n): string
    {
        static $ones = ['Zero', 'One', 'Two', 'Three', 'Four', 'Five', 'Six', 'Seven', 'Eight', 'Nine', 'Ten',
            'Eleven', 'Twelve', 'Thirteen', 'Fourteen', 'Fifteen', 'Sixteen', 'Seventeen', 'Eighteen', 'Nineteen'];
        static $tens = [2 => 'Twenty', 'Thirty', 'Forty', 'Fifty', 'Sixty', 'Seventy', 'Eighty', 'Ninety'];

        if ($n < 20) {
            return $ones[$n];
        }
        if ($n < 100) {
            return $tens[intdiv($n, 10)] . ($n % 10 ? ' ' . $ones[$n % 10] : '');
        }
        if ($n < 1000) {
            return $ones[intdiv($n, 100)] . ' Hundred' . ($n % 100 ? ' ' . self::wordsForInt($n % 100) : '');
        }

        foreach ([1000000000000 => 'Trillion', 1000000000 => 'Billion', 1000000 => 'Million', 1000 => 'Thousand'] as $unit => $name) {
            if ($n >= $unit) {
                return self::wordsForInt(intdiv($n, $unit)) . ' ' . $name . ($n % $unit ? ' ' . self::wordsForInt($n % $unit) : '');
            }
        }

        return (string) $n; // unreachable for sane invoice values
    }

    public static function filename(Invoice $invoice): string
    {
        return 'invoice-' . ($invoice->fbr_invoice_number ?? $invoice->internal_invoice_number ?? $invoice->invoice_number ?? $invoice->id) . '.pdf';
    }
}

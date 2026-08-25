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
            $qrData = json_encode([
                'sellerNTNCNIC' => preg_replace('/[^0-9]/', '', $invoice->company->fbr_registration_no ?: ($invoice->company->ntn ?? '')),
                'fbr_invoice_number' => $invoice->fbr_invoice_number,
                'invoiceDate' => $invoice->invoice_date ?? $invoice->created_at->format('Y-m-d'),
                'totalValues' => $invoice->total_amount,
            ]);
            // NB: chillerlan v5 picks the renderer by outputType STRING/const —
            // GDIMAGE_PNG here; do not switch to outputInterface (silently SVG).
            $qrOptions = new \chillerlan\QRCode\QROptions([
                'outputType' => \chillerlan\QRCode\Output\QROutputInterface::GDIMAGE_PNG,
                'scale' => 10,
            ]);
            $qrBase64 = (new \chillerlan\QRCode\QRCode($qrOptions))->render($qrData);

            $logoPath = public_path('images/fbr-digital-invoice-logo.png');
            if (file_exists($logoPath)) {
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
        $amount = round(abs($amount), 2);
        $rupees = (int) floor($amount);
        $paisa = (int) round(($amount - $rupees) * 100);

        $words = 'Rupees ' . self::wordsForInt($rupees);
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

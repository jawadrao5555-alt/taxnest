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
        $invoice->loadMissing('items', 'company');

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
    public static function renderBw(Invoice $invoice, ?float $fallbackWhtRate = null): \Barryvdh\DomPDF\PDF
    {
        $pdf = \Barryvdh\DomPDF\Facade\Pdf::loadView('invoice.pdf-bw', self::buildData($invoice, $fallbackWhtRate));
        $pdf->setPaper('A4', 'portrait');
        return $pdf;
    }

    public static function filename(Invoice $invoice): string
    {
        return 'invoice-' . ($invoice->fbr_invoice_number ?? $invoice->internal_invoice_number ?? $invoice->invoice_number ?? $invoice->id) . '.pdf';
    }
}

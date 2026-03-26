<?php

namespace App\Http\Controllers;

use App\Models\Invoice;

class ShareController extends Controller
{
    public function show(string $uuid)
    {
        $invoice = Invoice::where('share_uuid', $uuid)
            ->with('items', 'company')
            ->firstOrFail();

        return view('share.invoice', compact('invoice'));
    }

    public function pdf(string $uuid)
    {
        $invoice = Invoice::where('share_uuid', $uuid)
            ->with('items', 'company')
            ->firstOrFail();

        $invoice->load('items', 'company');

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
            $whtRate = floatval($invoice->wht_rate ?? 0);
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

        $data = [
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

        $pdf = \Barryvdh\DomPDF\Facade\Pdf::loadView('invoice.pdf-professional', $data);
        $pdf->setPaper('A4', 'portrait');
        $filename = 'invoice-' . ($invoice->fbr_invoice_number ?? $invoice->internal_invoice_number ?? $invoice->invoice_number ?? $invoice->id) . '.pdf';

        return $pdf->stream($filename);
    }
}

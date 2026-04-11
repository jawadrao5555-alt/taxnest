<?php

require __DIR__ . '/vendor/autoload.php';
$app = require_once __DIR__ . '/bootstrap/app.php';
$kernel = $app->make(\Illuminate\Contracts\Console\Kernel::class);
$kernel->bootstrap();

$invoice = \App\Models\Invoice::with(['items', 'company'])->find(592);

if (!$invoice) {
    echo "Invoice 592 not found!\n";
    exit(1);
}

$company = $invoice->company;
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
        'sellerNTNCNIC' => preg_replace('/[^0-9]/', '', $company->fbr_registration_no ?: ($company->ntn ?? '')),
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
    'showWatermark' => false,
    'isDraft' => $invoice->status === 'draft',
    'subtotal' => $subtotal,
    'totalTax' => $totalTax,
    'wht_rate' => $whtRate,
    'wht_amount' => $whtAmount,
    'net_receivable' => $netReceivable,
    'qrBase64' => $qrBase64,
    'fbrLogoBase64' => $fbrLogoBase64,
];

$outputDir = storage_path('app/annex-invoices');

$pdf1 = \Barryvdh\DomPDF\Facade\Pdf::loadView('invoice.pdf-modern', $data);
$pdf1->setPaper('A4', 'portrait');
$pdf1->save($outputDir . '/REAL-MODERN-FORMAT.pdf');
echo "Generated: REAL-MODERN-FORMAT.pdf (Modern Design)\n";

$pdf2 = \Barryvdh\DomPDF\Facade\Pdf::loadView('invoice.pdf-professional', $data);
$pdf2->setPaper('A4', 'portrait');
$pdf2->save($outputDir . '/REAL-CURRENT-FORMAT.pdf');
echo "Generated: REAL-CURRENT-FORMAT.pdf (Current Design)\n";

echo "\nInvoice #592 - {$company->name}\n";
echo "FBR#: {$invoice->fbr_invoice_number}\n";
echo "Buyer: {$invoice->buyer_name}\n";
echo "Subtotal: " . number_format($subtotal) . "\n";
echo "Tax: " . number_format($totalTax) . "\n";
echo "Total: " . number_format($invoice->total_amount) . "\n";
echo "Items: " . $invoice->items->count() . "\n";

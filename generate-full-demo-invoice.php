<?php

require __DIR__ . '/vendor/autoload.php';
$app = require_once __DIR__ . '/bootstrap/app.php';
$kernel = $app->make(\Illuminate\Contracts\Console\Kernel::class);
$kernel->bootstrap();

$invoice = \App\Models\Invoice::with(['items', 'company'])->find(592);
if (!$invoice) { echo "Invoice 592 not found!\n"; exit(1); }

$company = $invoice->company;

$fakeInvoice = clone $invoice;
$fakeInvoice->buyer_name = 'AHMAD BROTHERS TRADERS';
$fakeInvoice->buyer_ntn = '1234567-8';
$fakeInvoice->buyer_cnic = '36201-1234567-9';
$fakeInvoice->buyer_registration_type = 'Registered';
$fakeInvoice->buyer_address = 'SHOP NO. 45, MAIN BAZAAR, MULTAN ROAD';
$fakeInvoice->destination_province = 'Punjab';
$fakeInvoice->supplier_province = 'Punjab';
$fakeInvoice->document_type = 'Sale Invoice';
$fakeInvoice->status = 'locked';
$fakeInvoice->fbr_status = 'production';

$fakeItems = collect([
    (object)[
        'hs_code' => '3808.9210',
        'description' => 'Insecticides, fungicides, herbicides',
        'default_uom' => 'Kilograms',
        'quantity' => 150,
        'price' => 2500,
        'tax' => 67500,
        'tax_rate' => 18,
        'further_tax' => 27000,
        'schedule_type' => 'third_schedule',
        'sro_schedule_no' => '6th Schd Table I',
        'serial_no' => '133',
    ],
    (object)[
        'hs_code' => '2201.1010',
        'description' => 'Aerated Water / Mineral Water',
        'default_uom' => 'Liters',
        'quantity' => 500,
        'price' => 120,
        'tax' => 10800,
        'tax_rate' => 18,
        'further_tax' => 4320,
        'schedule_type' => 'third_schedule',
        'sro_schedule_no' => '6th Schd Table I',
        'serial_no' => '45',
    ],
    (object)[
        'hs_code' => '8418.1000',
        'description' => 'Combined refrigerator-freezers',
        'default_uom' => 'Pieces',
        'quantity' => 5,
        'price' => 85000,
        'tax' => 76500,
        'tax_rate' => 18,
        'further_tax' => 0,
        'schedule_type' => 'exempt',
        'sro_schedule_no' => '',
        'serial_no' => '',
    ],
]);

$fakeInvoice->setRelation('items', $fakeItems);
$fakeInvoice->setRelation('company', $company);

$subtotal = $fakeItems->sum(fn($item) => $item->price * $item->quantity);
$totalTax = $fakeItems->sum('tax');
$totalFurtherTax = $fakeItems->sum('further_tax');
$whtRate = 4;
$whtAmount = round($subtotal * ($whtRate / 100), 2);
$totalAmount = $subtotal + $totalTax + $totalFurtherTax;
$netReceivable = $totalAmount - $whtAmount;

$fakeInvoice->total_amount = $totalAmount;
$fakeInvoice->wht_rate = $whtRate;
$fakeInvoice->wht_amount = $whtAmount;
$fakeInvoice->net_receivable = $netReceivable;

$qrData = json_encode([
    'sellerNTNCNIC' => '3620291786117',
    'fbr_invoice_number' => $fakeInvoice->fbr_invoice_number,
    'invoiceDate' => $fakeInvoice->created_at->format('Y-m-d'),
    'totalValues' => $totalAmount,
]);
$qrOptions = new \chillerlan\QRCode\QROptions([
    'outputType' => \chillerlan\QRCode\Output\QROutputInterface::GDIMAGE_PNG,
    'scale' => 10,
]);
$qrBase64 = (new \chillerlan\QRCode\QRCode($qrOptions))->render($qrData);

$fbrLogoBase64 = '';
$logoPath = public_path('images/fbr-digital-invoice-logo.png');
if (file_exists($logoPath)) {
    $fbrLogoBase64 = 'data:image/png;base64,' . base64_encode(file_get_contents($logoPath));
}

$data = [
    'invoice' => $fakeInvoice,
    'showWatermark' => false,
    'isDraft' => false,
    'subtotal' => $subtotal,
    'totalTax' => $totalTax,
    'wht_rate' => $whtRate,
    'wht_amount' => $whtAmount,
    'net_receivable' => $netReceivable,
    'qrBase64' => $qrBase64,
    'fbrLogoBase64' => $fbrLogoBase64,
];

$outputDir = storage_path('app/annex-invoices');

$pdf = \Barryvdh\DomPDF\Facade\Pdf::loadView('invoice.pdf-modern-demo', $data);
$pdf->setPaper('A4', 'portrait');
$pdf->save($outputDir . '/FULL-DEMO-INVOICE.pdf');
echo "Generated: FULL-DEMO-INVOICE.pdf\n";

echo "\n=== FULL DEMO INVOICE ===\n";
echo "Company: {$company->name}\n";
echo "Buyer: {$fakeInvoice->buyer_name}\n";
echo "Items: 3\n";
echo "  1. Insecticides - 150 x 2,500 = 375,000 + Tax: 67,500 + FT: 27,000\n";
echo "  2. Aerated Water - 500 x 120 = 60,000 + Tax: 10,800 + FT: 4,320\n";
echo "  3. Refrigerators - 5 x 85,000 = 425,000 + Tax: 76,500 + FT: 0\n";
echo "Sub Total: " . number_format($subtotal) . "\n";
echo "Total Tax (GST): " . number_format($totalTax) . "\n";
echo "Further Tax (4%): " . number_format($totalFurtherTax) . "\n";
echo "WHT (4%): " . number_format($whtAmount) . "\n";
echo "Grand Total: " . number_format($totalAmount) . "\n";
echo "Net Receivable: " . number_format($netReceivable) . "\n";

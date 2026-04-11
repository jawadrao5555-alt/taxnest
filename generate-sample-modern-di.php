<?php

require __DIR__ . '/vendor/autoload.php';
$app = require_once __DIR__ . '/bootstrap/app.php';
$kernel = $app->make(\Illuminate\Contracts\Console\Kernel::class);
$kernel->bootstrap();

use SimpleSoftwareIO\QrCode\Facades\QrCode;

function generateQrBase64($text) {
    $svg = QrCode::format('svg')
        ->size(200)
        ->margin(1)
        ->errorCorrection('M')
        ->generate($text);
    return 'data:image/svg+xml;base64,' . base64_encode($svg);
}

$fakeCompany = new \stdClass();
$fakeCompany->name = 'ZIA CORPORATION';
$fakeCompany->ntn = '3620291786117';
$fakeCompany->address = 'Main Market, Commercial Area';
$fakeCompany->city = 'Lahore';
$fakeCompany->cnic = null;
$fakeCompany->registration_no = '3620291786117';
$fakeCompany->phone = '042-35761234';
$fakeCompany->mobile = '0300-1234567';
$fakeCompany->email = 'info@ziacorp.pk';

$items = [];

$item1 = new \stdClass();
$item1->hs_code = '8471.3010';
$item1->description = 'Laptop Computer (Dell Latitude 5540)';
$item1->default_uom = 'PCS';
$item1->quantity = 5;
$item1->price = 185000;
$item1->tax_rate = 18;
$item1->tax = 166500;
$item1->further_tax = 37000;
$item1->schedule_type = 'third_schedule';
$item1->sro_schedule_no = 'SRO 1125(I)/2011';
$item1->serial_no = '49';
$items[] = $item1;

$item2 = new \stdClass();
$item2->hs_code = '8443.3210';
$item2->description = 'Laser Printer (HP LaserJet Pro)';
$item2->default_uom = 'PCS';
$item2->quantity = 10;
$item2->price = 45000;
$item2->tax_rate = 18;
$item2->tax = 81000;
$item2->further_tax = 18000;
$item2->schedule_type = 'third_schedule';
$item2->sro_schedule_no = 'SRO 1125(I)/2011';
$item2->serial_no = '52';
$items[] = $item2;

$item3 = new \stdClass();
$item3->hs_code = '8544.4210';
$item3->description = 'Network Cable CAT-6 (per meter)';
$item3->default_uom = 'MTR';
$item3->quantity = 500;
$item3->price = 85;
$item3->tax_rate = 18;
$item3->tax = 7650;
$item3->further_tax = 0;
$item3->schedule_type = null;
$item3->sro_schedule_no = null;
$item3->serial_no = null;
$items[] = $item3;

$subtotal = 0;
$totalTax = 0;
foreach ($items as $it) {
    $subtotal += $it->price * $it->quantity;
    $totalTax += $it->tax;
}
$totalFurtherTax = array_sum(array_map(fn($i) => $i->further_tax, $items));
$grandTotal = $subtotal + $totalTax + $totalFurtherTax;

$fbrNumber = 'ZC20260000145';
$qrContent = "NTN:{$fakeCompany->ntn}|INV:DI-2026-0145|DATE:15-Mar-2026|TOTAL:{$grandTotal}|FBR:{$fbrNumber}";
$qrBase64 = generateQrBase64($qrContent);

$fakeInvoice = new \stdClass();
$fakeInvoice->id = 145;
$fakeInvoice->invoice_number = 'DI-2026-0145';
$fakeInvoice->internal_invoice_number = 'DI-2026-0145';
$fakeInvoice->fbr_invoice_number = $fbrNumber;
$fakeInvoice->status = 'locked';
$fakeInvoice->document_type = 'Sale Invoice';
$fakeInvoice->buyer_name = 'PAKISTAN ENGINEERING CO.';
$fakeInvoice->buyer_ntn = '1234567-8';
$fakeInvoice->buyer_cnic = '35202-1234567-1';
$fakeInvoice->buyer_address = 'Plot 45, Industrial Estate, Multan Road';
$fakeInvoice->buyer_registration_type = 'Registered';
$fakeInvoice->destination_province = 'Punjab';
$fakeInvoice->supplier_province = 'Punjab';
$fakeInvoice->reference_invoice_number = null;
$fakeInvoice->total_amount = $grandTotal;
$fakeInvoice->wht_rate = 0;
$fakeInvoice->wht_amount = 0;
$fakeInvoice->net_receivable = $grandTotal;
$fakeInvoice->created_at = \Carbon\Carbon::createFromFormat('d-M-Y h:i A', '15-Mar-2026 02:30 PM');
$fakeInvoice->company = $fakeCompany;
$fakeInvoice->items = collect($items);

$data = [
    'invoice' => $fakeInvoice,
    'showWatermark' => false,
    'isDraft' => false,
    'subtotal' => $subtotal,
    'totalTax' => $totalTax,
    'wht_rate' => 0,
    'wht_amount' => 0,
    'net_receivable' => $grandTotal,
    'qrBase64' => $qrBase64,
];

$pdf = \Barryvdh\DomPDF\Facade\Pdf::loadView('invoice.pdf-modern', $data);
$pdf->setPaper('A4', 'portrait');

$outputDir = storage_path('app/annex-invoices');
$pdf->save($outputDir . '/SAMPLE-MODERN-DI.pdf');

echo "Generated: SAMPLE-MODERN-DI.pdf\n";
echo "Subtotal: " . number_format($subtotal) . "\n";
echo "Tax: " . number_format($totalTax) . "\n";
echo "Further Tax: " . number_format($totalFurtherTax) . "\n";
echo "Grand Total: " . number_format($grandTotal) . "\n";

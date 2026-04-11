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

$invoices = [
    [
        'serial' => 22,
        'invoice_number' => 'INV-RAM01',
        'date' => '01-Jan-2026',
        'time' => '10:15 AM',
        'buyer_name' => 'pizza arena',
        'buyer_ntn' => '3620345387411',
        'buyer_status' => 'Unregistered',
        'quantity' => 583.95,
        'value' => 200000,
        'tax' => 36000,
        'total' => 236000,
    ],
    [
        'serial' => 21,
        'invoice_number' => 'INV-RAM02',
        'date' => '02-Jan-2026',
        'time' => '11:30 AM',
        'buyer_name' => 'pizza arena',
        'buyer_ntn' => '3620345387411',
        'buyer_status' => 'Unregistered',
        'quantity' => 583.95,
        'value' => 200000,
        'tax' => 36000,
        'total' => 236000,
    ],
    [
        'serial' => 20,
        'invoice_number' => 'INV-RAM03',
        'date' => '03-Jan-2026',
        'time' => '02:45 PM',
        'buyer_name' => 'pizza arena',
        'buyer_ntn' => '3620345387411',
        'buyer_status' => 'Unregistered',
        'quantity' => 583.95,
        'value' => 200000,
        'tax' => 36000,
        'total' => 236000,
    ],
    [
        'serial' => 19,
        'invoice_number' => 'INV-RAM04',
        'date' => '04-Jan-2026',
        'time' => '04:10 PM',
        'buyer_name' => 'pizza arena',
        'buyer_ntn' => '3620345387411',
        'buyer_status' => 'Unregistered',
        'quantity' => 583.95,
        'value' => 200000,
        'tax' => 36000,
        'total' => 236000,
    ],
];

$seller = [
    'name' => 'CHOUDHARY TRADERS',
    'ntn' => '0807585-9',
    'registration_no' => '3620317950351',
    'address' => 'NEAR LARI ADDA, STREET SUMBAL BAKERS WALI',
    'city' => 'Lodhran',
    'tax_period' => 'Jan 2026',
];

$commonItem = [
    'description' => 'Oil and Ghee',
    'hs_code' => '1516.2020',
    'uom' => 'KG',
    'tax_rate' => 18,
    'sale_type' => 'Goods at standard rate',
    'origin_province' => 'Punjab',
    'destination_province' => 'Punjab',
];

$outputDir = storage_path('app/annex-invoices');

foreach ($invoices as $inv) {
    $unitPrice = $inv['quantity'] > 0 ? round($inv['value'] / $inv['quantity'], 2) : 0;

    $fakeCompany = new \stdClass();
    $fakeCompany->name = $seller['name'];
    $fakeCompany->ntn = $seller['ntn'];
    $fakeCompany->address = $seller['address'];
    $fakeCompany->city = $seller['city'];
    $fakeCompany->cnic = null;
    $fakeCompany->registration_no = $seller['registration_no'];
    $fakeCompany->phone = null;
    $fakeCompany->mobile = null;
    $fakeCompany->email = null;
    $fakeCompany->fbr_registration_no = $seller['ntn'];

    $fakeItem = new \stdClass();
    $fakeItem->hs_code = $commonItem['hs_code'];
    $fakeItem->description = $commonItem['description'];
    $fakeItem->default_uom = $commonItem['uom'];
    $fakeItem->quantity = $inv['quantity'];
    $fakeItem->price = $unitPrice;
    $fakeItem->tax_rate = $commonItem['tax_rate'];
    $fakeItem->tax = $inv['tax'];
    $fakeItem->further_tax = 0;
    $fakeItem->schedule_type = null;
    $fakeItem->sro_schedule_no = null;
    $fakeItem->serial_no = null;

    $dummyFbrNumber = 'CT' . date('Y', strtotime($inv['date'])) . str_pad($inv['serial'], 6, '0', STR_PAD_LEFT);

    $qrContent = "NTN:{$seller['ntn']}|INV:{$inv['invoice_number']}|DATE:{$inv['date']}|TOTAL:{$inv['total']}|TAX:{$inv['tax']}|BUYER:{$inv['buyer_ntn']}";
    $qrBase64 = generateQrBase64($qrContent);

    $fakeInvoice = new \stdClass();
    $fakeInvoice->id = $inv['serial'];
    $fakeInvoice->invoice_number = $inv['invoice_number'];
    $fakeInvoice->internal_invoice_number = $inv['invoice_number'];
    $fakeInvoice->fbr_invoice_number = $dummyFbrNumber;
    $fakeInvoice->status = 'locked';
    $fakeInvoice->fbr_status = null;
    $fakeInvoice->document_type = 'Sale Invoice';
    $fakeInvoice->buyer_name = strtoupper($inv['buyer_name']);
    $fakeInvoice->buyer_ntn = $inv['buyer_ntn'];
    $fakeInvoice->buyer_cnic = $inv['buyer_ntn'];
    $fakeInvoice->buyer_address = null;
    $fakeInvoice->buyer_registration_type = $inv['buyer_status'];
    $fakeInvoice->destination_province = $commonItem['destination_province'];
    $fakeInvoice->supplier_province = $commonItem['origin_province'];
    $fakeInvoice->reference_invoice_number = null;
    $fakeInvoice->total_amount = $inv['total'];
    $fakeInvoice->wht_rate = 0;
    $fakeInvoice->wht_amount = 0;
    $fakeInvoice->net_receivable = $inv['total'];
    $fakeInvoice->invoice_date = $inv['date'];
    $fakeInvoice->created_at = \Carbon\Carbon::createFromFormat('d-M-Y h:i A', $inv['date'] . ' ' . $inv['time']);
    $fakeInvoice->company = $fakeCompany;
    $fakeInvoice->items = collect([$fakeItem]);

    $data = [
        'invoice' => $fakeInvoice,
        'showWatermark' => false,
        'isDraft' => false,
        'subtotal' => $inv['value'],
        'totalTax' => $inv['tax'],
        'wht_rate' => 0,
        'wht_amount' => 0,
        'net_receivable' => $inv['total'],
        'qrBase64' => $qrBase64,
        'fbrLogoBase64' => 'HIDE',
        'hideFbrBadge' => true,
    ];

    $pdf = \Barryvdh\DomPDF\Facade\Pdf::loadView('invoice.pdf-annex', $data);
    $pdf->setPaper('A4', 'portrait');

    $filename = $inv['invoice_number'] . '.pdf';
    $pdf->save($outputDir . '/' . $filename);

    echo "Generated: {$filename}\n";
}

echo "\nAll 4 invoices generated in: {$outputDir}\n";

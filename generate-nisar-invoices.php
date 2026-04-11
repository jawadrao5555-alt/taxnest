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
        'serial' => 23,
        'invoice_number' => 'INV-NISR23',
        'date' => '23-Jan-2026',
        'time' => '09:40 AM',
        'buyer_name' => 'pizza arena',
        'buyer_ntn' => '3620345387411',
        'buyer_status' => 'Unregistered',
        'quantity' => 2978.77,
        'value' => 182923,
        'tax' => 32926,
        'total' => 215849,
    ],
    [
        'serial' => 10,
        'invoice_number' => 'INV-NISR10',
        'date' => '10-Jan-2026',
        'time' => '03:20 PM',
        'buyer_name' => 'pizza arena',
        'buyer_ntn' => '3620345387411',
        'buyer_status' => 'Unregistered',
        'quantity' => 2978.77,
        'value' => 182923,
        'tax' => 32926,
        'total' => 215849,
    ],
];

$seller = [
    'name' => 'NISAR TRADERS',
    'ntn' => '0974562-9',
    'registration_no' => '3620318197247',
    'address' => 'SUPER CHOWK, OPPOSITE AL BADAR HOSPITAL',
    'city' => 'LODHRAN',
];

$commonItem = [
    'description' => 'Aerated Water (3rd Schedule Goods)',
    'hs_code' => '2201.1010',
    'uom' => 'Liter',
    'tax_rate' => 18,
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

    $dummyFbrNumber = 'NT' . date('Y', strtotime($inv['date'])) . str_pad($inv['serial'], 6, '0', STR_PAD_LEFT);

    $qrContent = "NTN:{$seller['ntn']}|INV:{$inv['invoice_number']}|DATE:{$inv['date']}|TOTAL:{$inv['total']}|TAX:{$inv['tax']}|BUYER:{$inv['buyer_ntn']}";
    $qrBase64 = generateQrBase64($qrContent);

    $fakeInvoice = new \stdClass();
    $fakeInvoice->id = $inv['serial'];
    $fakeInvoice->invoice_number = $inv['invoice_number'];
    $fakeInvoice->internal_invoice_number = $inv['invoice_number'];
    $fakeInvoice->fbr_invoice_number = $dummyFbrNumber;
    $fakeInvoice->status = 'locked';
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
    ];

    $pdf = \Barryvdh\DomPDF\Facade\Pdf::loadView('invoice.pdf-modern', $data);
    $pdf->setPaper('A4', 'portrait');

    $filename = $inv['invoice_number'] . '.pdf';
    $pdf->save($outputDir . '/' . $filename);

    echo "Generated: {$filename} (FBR#: {$dummyFbrNumber})\n";
}

echo "\nAll 2 invoices generated in: {$outputDir}\n";

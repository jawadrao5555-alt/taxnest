<?php

require __DIR__ . '/vendor/autoload.php';
$app = require_once __DIR__ . '/bootstrap/app.php';
$kernel = $app->make(\Illuminate\Contracts\Console\Kernel::class);
$kernel->bootstrap();

function generateDummyQrBase64($text) {
    $size = 200;
    $img = imagecreatetruecolor($size, $size);
    $white = imagecolorallocate($img, 255, 255, 255);
    $black = imagecolorallocate($img, 0, 0, 0);
    $green = imagecolorallocate($img, 22, 101, 52);
    $gray = imagecolorallocate($img, 200, 200, 200);

    imagefill($img, 0, 0, $white);

    imagerectangle($img, 0, 0, $size-1, $size-1, $gray);

    $moduleSize = 6;
    $modules = 25;
    $offset = ($size - ($modules * $moduleSize)) / 2;

    for ($i = 0; $i < 7; $i++) {
        for ($j = 0; $j < 7; $j++) {
            if ($i == 0 || $i == 6 || $j == 0 || $j == 6 || ($i >= 2 && $i <= 4 && $j >= 2 && $j <= 4)) {
                imagefilledrectangle($img,
                    $offset + $j * $moduleSize, $offset + $i * $moduleSize,
                    $offset + ($j+1) * $moduleSize - 1, $offset + ($i+1) * $moduleSize - 1,
                    $black);
            }
        }
    }

    $tlX = $modules - 7;
    for ($i = 0; $i < 7; $i++) {
        for ($j = 0; $j < 7; $j++) {
            if ($i == 0 || $i == 6 || $j == 0 || $j == 6 || ($i >= 2 && $i <= 4 && $j >= 2 && $j <= 4)) {
                imagefilledrectangle($img,
                    $offset + ($tlX + $j) * $moduleSize, $offset + $i * $moduleSize,
                    $offset + ($tlX + $j + 1) * $moduleSize - 1, $offset + ($i+1) * $moduleSize - 1,
                    $black);
            }
        }
    }

    $blY = $modules - 7;
    for ($i = 0; $i < 7; $i++) {
        for ($j = 0; $j < 7; $j++) {
            if ($i == 0 || $i == 6 || $j == 0 || $j == 6 || ($i >= 2 && $i <= 4 && $j >= 2 && $j <= 4)) {
                imagefilledrectangle($img,
                    $offset + $j * $moduleSize, $offset + ($blY + $i) * $moduleSize,
                    $offset + ($j+1) * $moduleSize - 1, $offset + ($blY + $i + 1) * $moduleSize - 1,
                    $black);
            }
        }
    }

    srand(crc32($text));
    for ($i = 8; $i < $modules - 1; $i++) {
        for ($j = 8; $j < $modules - 1; $j++) {
            if ($i < 7 && $j >= $modules - 7) continue;
            if ($i >= $modules - 7 && $j < 7) continue;
            if (rand(0, 100) > 55) {
                imagefilledrectangle($img,
                    $offset + $j * $moduleSize, $offset + $i * $moduleSize,
                    $offset + ($j+1) * $moduleSize - 1, $offset + ($i+1) * $moduleSize - 1,
                    $black);
            }
        }
    }

    ob_start();
    imagepng($img);
    $pngData = ob_get_clean();
    imagedestroy($img);

    return 'data:image/png;base64,' . base64_encode($pngData);
}

$invoices = [
    [
        'serial' => 22,
        'invoice_number' => 'INV-RAM01',
        'date' => '01-Jan-2026',
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

    $qrBase64 = generateDummyQrBase64($seller['ntn'] . '-' . $inv['invoice_number'] . '-' . $inv['total']);

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
    $fakeInvoice->created_at = \Carbon\Carbon::createFromFormat('d-M-Y', $inv['date']);
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
        'fbrLogoBase64' => '',
    ];

    $pdf = \Barryvdh\DomPDF\Facade\Pdf::loadView('invoice.pdf-professional', $data);
    $pdf->setPaper('A4', 'portrait');

    $filename = $inv['invoice_number'] . '.pdf';
    $pdf->save($outputDir . '/' . $filename);

    echo "Generated: {$filename} (FBR#: {$dummyFbrNumber})\n";
}

echo "\nAll 4 invoices generated in: {$outputDir}\n";

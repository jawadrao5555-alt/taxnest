<?php
require __DIR__ . '/../vendor/autoload.php';
$app = require __DIR__ . '/../bootstrap/app.php';
$app->make(\Illuminate\Contracts\Console\Kernel::class)->bootstrap();

$token = 'e4a65142-08e3-3c92-9772-dd14a32fbf23';
$url = 'https://gw.fbr.gov.pk/di_data/v1/di/postinvoicedata_sb';

$variants = ['B282410-8', 'B2824108', '2824108', '02824108', '28241080', '3120180085013'];

foreach ($variants as $ntn) {
    $payload = [
        "items" => [[
            "uoM" => "Numbers, pieces, units",
            "rate" => "18%",
            "hsCode" => "2402.2000",
            "discount" => 0.0,
            "extraTax" => 0.0,
            "quantity" => 1.0,
            "saleType" => "Goods at standard rate (default)",
            "fedPayable" => 0.0,
            "furtherTax" => 0.0,
            "totalValues" => 1.18,
            "productDescription" => "Test",
            "salesTaxApplicable" => 0.18,
            "valueSalesExcludingST" => 1.0,
            "salesTaxWithheldAtSource" => 0.0,
            "fixedNotifiedValueOrRetailPrice" => 1.0,
        ]],
        "invoiceDate" => date('Y-m-d'),
        "invoiceType" => "Sale Invoice",
        "documentTypeId" => 1,
        "buyerAddress" => "Lodhran",
        "invoiceRefNo" => "TEST-" . time(),
        "buyerProvince" => "Punjab",
        "sellerAddress" => "Bahawalpur",
        "sellerNTNCNIC" => $ntn,
        "sellerProvince" => "Punjab",
        "buyerBusinessName" => "RAO BROTHERS",
        "sellerBusinessName" => "AL REHMAN TRADERS ONE",
        "buyerRegistrationType" => "Registered",
        "buyerNTNCNIC" => "3620344337269",
        "scenarioId" => "SN001",
    ];

    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true, CURLOPT_POST => true,
        CURLOPT_POSTFIELDS => json_encode($payload),
        CURLOPT_HTTPHEADER => ['Authorization: Bearer ' . $token, 'Content-Type: application/json'],
        CURLOPT_TIMEOUT => 30, CURLOPT_SSL_VERIFYPEER => false,
    ]);
    $body = curl_exec($ch);
    $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    $j = json_decode($body, true);
    $vr = $j['validationResponse'] ?? [];
    $fbrNo = $j['invoiceNumber'] ?? null;
    $status = $vr['status'] ?? '?';
    $errCode = $vr['errorCode'] ?? '';
    $err = $vr['error'] ?? '';
    $icon = ($status === 'Valid' || $fbrNo) ? '✅' : '❌';
    printf("%s NTN=%-15s HTTP %d  status=%-7s  err=%s %s\n", $icon, $ntn, $code, $status, $errCode, substr($err, 0, 80));
    sleep(1);
}

<?php
require __DIR__ . '/../vendor/autoload.php';
$app = require __DIR__ . '/../bootstrap/app.php';
$app->make(\Illuminate\Contracts\Console\Kernel::class)->bootstrap();

$token = 'e4a65142-08e3-3c92-9772-dd14a32fbf23';
$url = 'https://gw.fbr.gov.pk/di_data/v1/di/postinvoicedata_sb';

$saleTypes = [
    'Goods at standard rate (default)',
    'Goods at standard rate (retail)',
    'Goods at standard rate (FMCG)',
    'Goods at standard rate (wholesale)',
    'Goods at Reduced Rate',
    '3rd Schedule Goods',
    'Cement /Concrete Block',
    'Mobile Phones',
    'Potassium Chlorate',
    'CNG Sales',
    'Steel melting and re-rolling',
    'Ship breaking',
    'Cotton Ginners',
    'Telecommunication services',
    'Toll Manufacturing',
    'Petroleum Products',
    'Electricity Supply to Retailers',
    'Gas to CNG stations',
    'Processing/ Conversion of Goods',
    'Goods (FED in ST Mode)',
    'Services (FED in ST Mode)',
    'Electric Vehicle',
    'Goods as per SRO.297(|)/2023',
    'Non-Adjustable Supplies',
    'Services',
    'Export',
    'Zero Rated',
    'Exempt',
];

$scenarios = ['SN026', 'SN027', 'SN028'];

function test($url, $token, $scenarioId, $saleType, $rate = '18%') {
    $taxRate = floatval(rtrim($rate, '%'));
    $tax = round(1.0 * ($taxRate / 100), 2);
    $payload = [
        "items" => [[
            "uoM" => "Numbers, pieces, units", "rate" => $rate, "hsCode" => "2402.2000",
            "discount" => 0.0, "extraTax" => 0.0, "quantity" => 1.0,
            "saleType" => $saleType, "fedPayable" => 0.0, "furtherTax" => 0.0,
            "totalValues" => 1.0 + $tax, "productDescription" => "Test",
            "salesTaxApplicable" => $tax, "valueSalesExcludingST" => 1.0,
            "salesTaxWithheldAtSource" => 0.0, "fixedNotifiedValueOrRetailPrice" => 1.0,
        ]],
        "invoiceDate" => date('Y-m-d'), "invoiceType" => "Sale Invoice",
        "documentTypeId" => 1, "buyerAddress" => "Bahawalpur",
        "invoiceRefNo" => "T-" . substr(md5($scenarioId.$saleType.microtime()),0,8),
        "buyerProvince" => "Punjab", "sellerAddress" => "Bahawalpur",
        "sellerNTNCNIC" => "3120180085013", "sellerProvince" => "Punjab",
        "buyerBusinessName" => "End Consumer", "sellerBusinessName" => "AL REHMAN TRADERS ONE",
        "buyerRegistrationType" => "Unregistered", "buyerNTNCNIC" => "3520112345678",
        "scenarioId" => $scenarioId,
    ];
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true, CURLOPT_POST => true,
        CURLOPT_POSTFIELDS => json_encode($payload),
        CURLOPT_HTTPHEADER => ['Authorization: Bearer ' . $token, 'Content-Type: application/json'],
        CURLOPT_TIMEOUT => 30, CURLOPT_SSL_VERIFYPEER => false,
    ]);
    $body = curl_exec($ch);
    curl_close($ch);
    $j = json_decode($body, true);
    return [
        'fbrNo' => $j['invoiceNumber'] ?? null,
        'status' => $j['validationResponse']['status'] ?? '?',
        'errCode' => $j['validationResponse']['errorCode'] ?? '',
        'err' => $j['validationResponse']['error'] ?? '',
    ];
}

foreach ($scenarios as $sc) {
    echo "\n========== $sc ==========\n";
    foreach ($saleTypes as $st) {
        $r = test($url, $token, $sc, $st);
        $icon = ($r['status'] === 'Valid' || $r['fbrNo']) ? '✅' : ($r['errCode'] === '0204' ? '·' : '⚠');
        printf("%s [%-5s] %-40s → %s %s\n", $icon, $r['errCode'], substr($st, 0, 40), $r['status'], substr($r['err'], 0, 60));
        usleep(300000);
    }
}

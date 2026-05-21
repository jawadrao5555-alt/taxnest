<?php
require __DIR__ . '/../vendor/autoload.php';
$app = require __DIR__ . '/../bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

use App\Models\Company;
use Illuminate\Support\Facades\Crypt;

$company = Company::find(18);
$token = null;
try { $token = Crypt::decryptString($company->fbr_sandbox_token); }
catch (\Throwable $e) { $token = $company->fbr_sandbox_token; }

$seller = [
    'sellerNTNCNIC'      => $company->ntn ?: $company->fbr_registration_no,
    'sellerBusinessName' => $company->name ?: ($company->fbr_business_name ?: 'AL REHMAN TRADERS ONE'),
    'sellerProvince'     => $company->province ?: 'Punjab',
    'sellerAddress'      => $company->address ?: 'Lodhran',
];

$registeredBuyer = [
    'buyerNTNCNIC'         => '7962754',
    'buyerBusinessName'    => 'RAO BROTHER',
    'buyerProvince'        => 'Punjab',
    'buyerAddress'         => 'Old Ghalla Mandi Lodhran',
    'buyerRegistrationType'=> 'Registered',
];
$walkInBuyer = [
    'buyerNTNCNIC'         => '',
    'buyerBusinessName'    => 'Walk-in Customer',
    'buyerProvince'        => 'Punjab',
    'buyerAddress'         => '',
    'buyerRegistrationType'=> 'Unregistered',
];

// Per-scenario item template (FBR Digital Invoicing v1 fields)
function item($overrides = []) {
    return array_merge([
        'hsCode'                          => '0101.2100',
        'productDescription'              => 'Test Product',
        'rate'                            => '18%',
        'uoM'                             => 'Numbers, pieces, units',
        'quantity'                        => 1,
        'totalValues'                     => 0,
        'valueSalesExcludingST'           => 100.00,
        'fixedNotifiedValueOrRetailPrice' => 0,
        'salesTaxApplicable'              => 18.00,
        'salesTaxWithheldAtSource'        => 0,
        'extraTax'                        => '',
        'furtherTax'                      => 0,
        'sroScheduleNo'                   => '',
        'fedPayable'                      => 0,
        'discount'                        => 0,
        'saleType'                        => 'Goods at standard rate (default)',
        'sroItemSerialNo'                 => '',
    ], $overrides);
}

$scenarios = [
    // SN001 — Goods at standard rate, registered B2B
    'SN001' => ['buyer' => $registeredBuyer, 'item' => item([
        'productDescription' => 'Standard Rate Goods (SN001)',
    ])],
    // SN002 — Goods at standard rate, unregistered walk-in
    'SN002' => ['buyer' => $walkInBuyer, 'item' => item([
        'productDescription' => 'Standard Rate Goods Walk-in (SN002)',
    ])],
    // SN008 — 3rd Schedule Goods / Registered (real product: PHILIP MORRIS cigarettes 2402.2000)
    'SN008' => ['buyer' => $registeredBuyer, 'item' => item([
        'hsCode'                          => '2402.2000',
        'productDescription'              => 'Cigarettes 3rd Schedule Registered (SN008)',
        'saleType'                        => '3rd Schedule Goods',
        'fixedNotifiedValueOrRetailPrice' => 100.00,
        'uoM'                             => 'Thousand Unit',
    ])],
    // SN026 — Standard Rate / End Consumer (walk-in)
    'SN026' => ['buyer' => $walkInBuyer, 'item' => item([
        'productDescription' => 'Standard Rate End Consumer (SN026)',
    ])],
    // SN027 — 3rd Schedule Goods / End Consumer (real product: cigarettes 2402.2000)
    'SN027' => ['buyer' => $walkInBuyer, 'item' => item([
        'hsCode'                          => '2402.2000',
        'productDescription'              => 'Cigarettes 3rd Schedule Walk-in (SN027)',
        'saleType'                        => '3rd Schedule Goods',
        'fixedNotifiedValueOrRetailPrice' => 100.00,
        'uoM'                             => 'Thousand Unit',
    ])],
    // SN028 — Goods at Reduced Rate (8th Schedule), walk-in
    'SN028' => ['buyer' => $walkInBuyer, 'item' => item([
        'hsCode'             => '3923.2100',
        'productDescription' => 'Plastic Bags Reduced Rate (SN028)',
        'saleType'           => 'Goods at Reduced Rate',
        'rate'               => '10%',
        'salesTaxApplicable' => 10.00,
        'uoM'                => 'KG',
        'sroScheduleNo'      => 'EIGHTH SCHEDULE Table 1',
        'sroItemSerialNo'    => '1',
    ])],
];

function fbrCall($url, $token, $payload) {
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT        => 30,
        CURLOPT_HTTPHEADER     => ['Content-Type: application/json', 'Authorization: Bearer ' . $token],
        CURLOPT_POST           => true,
        CURLOPT_POSTFIELDS     => json_encode($payload),
    ]);
    $body = curl_exec($ch);
    $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    return ['code' => $code, 'body' => $body, 'json' => json_decode($body ?? '', true)];
}

$validateUrl = 'https://gw.fbr.gov.pk/di_data/v1/di/validateinvoicedata_sb';
$postUrl     = 'https://gw.fbr.gov.pk/di_data/v1/di/postinvoicedata_sb';

$summary = [];
foreach ($scenarios as $sid => $cfg) {
    $payload = array_merge([
        'invoiceType'   => 'Sale Invoice',
        'invoiceDate'   => date('Y-m-d'),
        'invoiceRefNo'  => '',
        'scenarioId'    => $sid,
        'items'         => [$cfg['item']],
    ], $seller, $cfg['buyer']);

    echo "═══════ {$sid} (".$cfg['buyer']['buyerRegistrationType'].") ═══════\n";
    $v = fbrCall($validateUrl, $token, $payload);
    $vr = $v['json']['validationResponse'] ?? [];
    echo "VALIDATE → HTTP {$v['code']} | status: ".($vr['status'] ?? '?')." | code: ".($vr['errorCode'] ?? '?')."\n";
    if (!empty($vr['error'])) echo "  error: {$vr['error']}\n";
    if (($vr['status'] ?? '') !== 'Valid') {
        echo "  raw: ".substr($v['body'], 0, 800)."\n";
        if (!empty($vr['invoiceStatuses'])) {
            foreach ($vr['invoiceStatuses'] as $is) {
                echo "  item-error[".($is['itemSNo']??'?')."]: code=".($is['errorCode']??'?')." → ".($is['error']??'?')."\n";
            }
        }
    }

    $passedValidate = ($vr['status'] ?? '') === 'Valid';

    if ($passedValidate) {
        $p = fbrCall($postUrl, $token, $payload);
        $pr = $p['json']['validationResponse'] ?? [];
        $fbrInv = $p['json']['invoiceNumber'] ?? null;
        echo "POST     → HTTP {$p['code']} | status: ".($pr['status'] ?? '?')." | FBR Inv#: ".($fbrInv ?: '-')."\n";
        if (!empty($pr['error'])) echo "  error: {$pr['error']}\n";
        $summary[$sid] = ($pr['status'] ?? '') === 'Valid' ? "PASS (Inv# {$fbrInv})" : "FAIL @POST: ".($pr['error'] ?? '?');
    } else {
        $summary[$sid] = "FAIL @VALIDATE: ".($vr['error'] ?? '?');
    }
    echo "\n";
}

echo "═══════════ FINAL SUMMARY ═══════════\n";
foreach ($summary as $sid => $result) {
    $icon = str_starts_with($result, 'PASS') ? '✅' : '❌';
    echo "  {$icon} {$sid}: {$result}\n";
}

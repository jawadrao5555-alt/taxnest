<?php
require __DIR__ . '/../vendor/autoload.php';
$app = require __DIR__ . '/../bootstrap/app.php';
$app->make(\Illuminate\Contracts\Console\Kernel::class)->bootstrap();

use App\Models\Company;
use App\Models\Invoice;
use App\Models\InvoiceItem;
use App\Services\FbrService;
use Illuminate\Support\Facades\DB;

$COMPANY_ID = 18;
$company = Company::find($COMPANY_ID);
if (!$company) { fwrite(STDERR, "Company $COMPANY_ID not found\n"); exit(1); }

$fbr = new FbrService();

$scenarios = [
    ['code' => 'SN001', 'desc' => 'Goods Std Rate → Registered',
     'sale_type' => 'Goods at standard rate (default)', 'schedule_type' => 'standard',
     'buyer_name' => 'RAO BROTHERS', 'buyer_ntn' => '3620344337269', 'buyer_cnic' => null,
     'buyer_addr' => 'Old Ghalla Mandi, Lodhran', 'buyer_prov' => 'Punjab',
     'buyer_reg' => 'Registered', 'tax_rate' => 18.00],

    ['code' => 'SN002', 'desc' => 'Goods Std Rate → Unregistered',
     'sale_type' => 'Goods at standard rate (default)', 'schedule_type' => 'standard',
     'buyer_name' => 'Walk-in Customer', 'buyer_ntn' => null, 'buyer_cnic' => '3520112345671',
     'buyer_addr' => 'Bahawalpur', 'buyer_prov' => 'Punjab',
     'buyer_reg' => 'Unregistered', 'tax_rate' => 18.00],

    ['code' => 'SN008', 'desc' => '3rd Schedule Goods (Cigarettes)',
     'sale_type' => '3rd Schedule Goods', 'schedule_type' => 'third_schedule',
     'buyer_name' => 'RAO BROTHERS', 'buyer_ntn' => '3620344337269', 'buyer_cnic' => null,
     'buyer_addr' => 'Old Ghalla Mandi, Lodhran', 'buyer_prov' => 'Punjab',
     'buyer_reg' => 'Registered', 'tax_rate' => 18.00],

    ['code' => 'SN026', 'desc' => 'Retail Sale to End Consumer',
     'sale_type' => 'Goods at standard rate (retail)', 'schedule_type' => 'standard',
     'buyer_name' => 'End Consumer', 'buyer_ntn' => null, 'buyer_cnic' => '3520112345672',
     'buyer_addr' => 'Bahawalpur', 'buyer_prov' => 'Punjab',
     'buyer_reg' => 'Unregistered', 'tax_rate' => 18.00],

    ['code' => 'SN027', 'desc' => 'Retail Sale to End Consumer',
     'sale_type' => 'Goods at standard rate (retail)', 'schedule_type' => 'standard',
     'buyer_name' => 'End Consumer', 'buyer_ntn' => null, 'buyer_cnic' => '3520112345673',
     'buyer_addr' => 'Bahawalpur', 'buyer_prov' => 'Punjab',
     'buyer_reg' => 'Unregistered', 'tax_rate' => 18.00],

    ['code' => 'SN028', 'desc' => 'Retail Sale to End Consumer',
     'sale_type' => 'Goods at standard rate (retail)', 'schedule_type' => 'standard',
     'buyer_name' => 'End Consumer', 'buyer_ntn' => null, 'buyer_cnic' => '3520112345674',
     'buyer_addr' => 'Bahawalpur', 'buyer_prov' => 'Punjab',
     'buyer_reg' => 'Unregistered', 'tax_rate' => 18.00],
];

$token = trim($company->fbr_sandbox_token);
$url = 'https://gw.fbr.gov.pk/di_data/v1/di/postinvoicedata_sb';

$results = [];
foreach ($scenarios as $sc) {
    echo str_repeat('=', 70) . "\n[{$sc['code']}] {$sc['desc']}\n" . str_repeat('=', 70) . "\n";

    DB::beginTransaction();
    try {
        $today = now()->toDateString();
        $internalNo = 'AL-' . $sc['code'] . '-' . now()->format('YmdHis');

        $value = 1.00;
        $tax = round($value * ($sc['tax_rate'] / 100), 2);
        $total = $value + $tax;

        $invoice = Invoice::create([
            'company_id' => $COMPANY_ID,
            'invoice_number' => $internalNo,
            'internal_invoice_number' => $internalNo,
            'invoice_date' => $today,
            'status' => 'draft',
            'buyer_name' => $sc['buyer_name'],
            'buyer_ntn' => $sc['buyer_ntn'],
            'buyer_cnic' => $sc['buyer_cnic'],
            'buyer_address' => $sc['buyer_addr'],
            'buyer_registration_type' => $sc['buyer_reg'],
            'supplier_province' => 'Punjab',
            'destination_province' => $sc['buyer_prov'],
            'document_type' => 'Sale Invoice',
            'total_value_excluding_st' => $value,
            'total_sales_tax' => $tax,
            'total_amount' => $total,
            'wht_rate' => 0,
            'wht_amount' => 0,
            'net_receivable' => $total,
        ]);

        InvoiceItem::create([
            'invoice_id' => $invoice->id,
            'hs_code' => '2402.2000',
            'schedule_type' => $sc['schedule_type'],
            'tax_rate' => $sc['tax_rate'],
            'default_uom' => 'Numbers, pieces, units',
            'sale_type' => $sc['sale_type'],
            'st_withheld_at_source' => 0,
            'description' => 'Cigarettes - HS 2402.2000',
            'quantity' => 1,
            'price' => $value,
            'tax' => $tax,
        ]);

        $invoice = $invoice->fresh(['items', 'company']);
        $payload = $fbr->buildPayload($invoice);
        $payload['scenarioId'] = $sc['code'];

        $jsonBody = json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRESERVE_ZERO_FRACTION);
        echo "Payload (scenarioId={$sc['code']}):\n" . substr($jsonBody, 0, 500) . "...\n\n";

        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => $jsonBody,
            CURLOPT_HTTPHEADER => [
                'Authorization: Bearer ' . $token,
                'Content-Type: application/json',
                'Accept: application/json',
            ],
            CURLOPT_TIMEOUT => 60,
            CURLOPT_SSL_VERIFYPEER => false,
            CURLOPT_SSL_VERIFYHOST => false,
        ]);
        $start = microtime(true);
        $body = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $err = curl_error($ch);
        $ms = (int)((microtime(true) - $start) * 1000);
        curl_close($ch);

        echo "HTTP $httpCode  ({$ms}ms)\n";
        echo "Response: " . substr($body ?: $err, 0, 600) . "\n\n";

        $resp = json_decode($body, true);
        $fbrNo = $resp['invoiceNumber'] ?? ($resp['fbr_invoice_number'] ?? null);
        $valResp = $resp['validationResponse'] ?? [];
        $statusCode = $valResp['statusCode'] ?? null;
        $statusMsg = $valResp['status'] ?? null;
        $errorMsg = $valResp['error'] ?? ($valResp['errorCode'] ?? null);

        if ($fbrNo && (!$statusCode || $statusCode === '00')) {
            $invoice->fbr_invoice_number = $fbrNo;
            $invoice->fbr_status = 'submitted';
            $invoice->fbr_submission_date = now();
            $invoice->status = 'locked';
            $invoice->save();
            DB::commit();
            $results[] = ['code' => $sc['code'], 'pass' => true, 'fbr_no' => $fbrNo, 'http' => $httpCode];
            echo "✅ PASS — FBR# $fbrNo\n";
        } else {
            $invoice->fbr_status = 'failed';
            $invoice->save();
            DB::commit();
            $results[] = ['code' => $sc['code'], 'pass' => false, 'http' => $httpCode, 'err' => $errorMsg ?: substr($body, 0, 200)];
            echo "❌ FAIL — " . ($errorMsg ?: 'unknown') . "\n";
        }
    } catch (\Throwable $e) {
        DB::rollBack();
        $results[] = ['code' => $sc['code'], 'pass' => false, 'err' => $e->getMessage()];
        echo "❌ EXCEPTION — " . $e->getMessage() . "\n";
    }
    echo "\n";
    sleep(1);
}

echo "\n" . str_repeat('=', 70) . "\nFINAL REPORT\n" . str_repeat('=', 70) . "\n";
foreach ($results as $r) {
    $icon = $r['pass'] ? '✅' : '❌';
    $detail = $r['pass'] ? "FBR# {$r['fbr_no']}" : "ERR: " . ($r['err'] ?? '');
    echo sprintf("%s  %s   %s\n", $icon, $r['code'], $detail);
}

<?php
require __DIR__ . '/vendor/autoload.php';
$app = require_once __DIR__ . '/bootstrap/app.php';
$kernel = $app->make(Illuminate\Contracts\Console\Kernel::class);
$kernel->bootstrap();

$logFile = __DIR__ . '/fbr_retry_log.txt';
$maxAttempts = 4;
$waitMinutes = 30;

function logMsg($file, $msg) {
    $timestamp = date('Y-m-d H:i:s');
    $line = "[$timestamp] $msg\n";
    file_put_contents($file, $line, FILE_APPEND);
    echo $line;
}

file_put_contents($logFile, '');

logMsg($logFile, "============================================");
logMsg($logFile, "=== FBR SMART AUTO-RETRY STARTED ===");
logMsg($logFile, "Strategy: Reference APIs check + Validate + Submit");
logMsg($logFile, "Interval: {$waitMinutes} minutes | Max attempts: {$maxAttempts}");
logMsg($logFile, "============================================");

$invoice = App\Models\Invoice::with(['company', 'items'])->find(9);
if (!$invoice) {
    logMsg($logFile, "FATAL: Invoice #9 not found!");
    exit(1);
}

$fbrService = new App\Services\FbrService();
$payload = $fbrService->buildPayload($invoice);
logMsg($logFile, "Current payload:");
logMsg($logFile, json_encode($payload, JSON_PRETTY_PRINT));

$company = $invoice->company;
$token = '';
$env = $company->fbr_environment ?? 'sandbox';
$encryptedToken = ($env === 'production') ? ($company->fbr_production_token ?? '') : ($company->fbr_sandbox_token ?? '');
if (!empty($encryptedToken)) {
    try { $token = Illuminate\Support\Facades\Crypt::decryptString($encryptedToken); } catch (\Exception $e) { $token = $encryptedToken; }
}

logMsg($logFile, "");
logMsg($logFile, "=== STEP 1: Checking FBR Reference APIs ===");

$refApis = [
    'provinces' => 'https://gw.fbr.gov.pk/pdi/v1/provinces',
    'uom' => 'https://gw.fbr.gov.pk/pdi/v1/uom',
    'doctypecode' => 'https://gw.fbr.gov.pk/pdi/v1/doctypecode',
];

foreach ($refApis as $name => $url) {
    try {
        $resp = Illuminate\Support\Facades\Http::timeout(15)->withToken($token)->get($url);
        if ($resp->successful() && strlen($resp->body()) > 5) {
            $data = $resp->json();
            if (is_array($data) && count($data) > 0) {
                logMsg($logFile, "REF API [{$name}]: OK - " . count($data) . " items");
                if ($name === 'provinces') {
                    $provNames = array_column($data, 'stateProvinceDesc');
                    logMsg($logFile, "  Valid provinces: " . implode(', ', $provNames));
                    $match = in_array($payload['sellerProvince'], $provNames);
                    logMsg($logFile, "  Our sellerProvince '{$payload['sellerProvince']}' match: " . ($match ? 'YES' : 'NO!'));
                    $match2 = in_array($payload['buyerProvince'], $provNames);
                    logMsg($logFile, "  Our buyerProvince '{$payload['buyerProvince']}' match: " . ($match2 ? 'YES' : 'NO!'));
                }
                if ($name === 'uom') {
                    $uomNames = array_column($data, 'description');
                    logMsg($logFile, "  All valid UoMs: " . implode(', ', $uomNames));
                    $ourUom = $payload['items'][0]['uoM'];
                    $match = in_array($ourUom, $uomNames);
                    logMsg($logFile, "  Our UoM '{$ourUom}' match: " . ($match ? 'YES' : 'NO!'));
                }
                if ($name === 'doctypecode') {
                    $docTypes = array_column($data, 'docDescription');
                    logMsg($logFile, "  Valid doc types: " . implode(', ', $docTypes));
                }
            } else {
                logMsg($logFile, "REF API [{$name}]: Empty/invalid data (possible rate limit)");
            }
        } else {
            logMsg($logFile, "REF API [{$name}]: HTTP " . $resp->status() . " body_len=" . strlen($resp->body()));
        }
    } catch (\Exception $e) {
        logMsg($logFile, "REF API [{$name}]: ERROR - " . $e->getMessage());
    }
    sleep(3);
}

logMsg($logFile, "");
logMsg($logFile, "=== STEP 2: Starting retry loop ===");

for ($attempt = 1; $attempt <= $maxAttempts; $attempt++) {
    if ($attempt > 1) {
        logMsg($logFile, "");
        logMsg($logFile, ">>> Waiting {$waitMinutes} minutes...");
        logMsg($logFile, ">>> Next attempt at: " . date('Y-m-d H:i:s', time() + $waitMinutes * 60));
        sleep($waitMinutes * 60);
    }

    $invoice->refresh();
    $invoice->load('company', 'items');

    if ($invoice->status === 'locked' && $invoice->fbr_status === 'accepted') {
        logMsg($logFile, "Invoice already accepted! Stopping.");
        break;
    }

    logMsg($logFile, "");
    logMsg($logFile, "============================================");
    logMsg($logFile, "=== ATTEMPT {$attempt}/{$maxAttempts} - " . date('H:i:s') . " ===");
    logMsg($logFile, "============================================");

    logMsg($logFile, "--- Phase A: Validate-Only Endpoint ---");
    try {
        $validateResult = $fbrService->validateOnly($invoice);
        $vStatus = $validateResult['status'] ?? 'unknown';
        logMsg($logFile, "Validate status: {$vStatus}");
        logMsg($logFile, "Validate response: " . json_encode($validateResult, JSON_PRETTY_PRINT));

        if ($vStatus === 'valid') {
            logMsg($logFile, "VALIDATION PASSED! Payload accepted by FBR validate endpoint.");
        } elseif ($vStatus === 'invalid') {
            logMsg($logFile, "VALIDATION FAILED: " . json_encode($validateResult['errors'] ?? []));
        } elseif ($vStatus === 'rate_limited' || (isset($validateResult['failure_type']) && $validateResult['failure_type'] === 'rate_limited')) {
            logMsg($logFile, "Validate endpoint also rate limited. Continuing to submit anyway...");
        }
    } catch (\Exception $e) {
        logMsg($logFile, "Validate error: " . $e->getMessage());
    }

    sleep(5);

    logMsg($logFile, "--- Phase B: Production Submit ---");
    try {
        $submitResult = $fbrService->submitInvoice($invoice, $attempt);
        $sStatus = $submitResult['status'] ?? 'unknown';
        logMsg($logFile, "Submit result: " . json_encode($submitResult, JSON_PRETTY_PRINT));

        if ($sStatus === 'success') {
            logMsg($logFile, "");
            logMsg($logFile, "!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!");
            logMsg($logFile, "=== FBR ACCEPTED SUCCESSFULLY! ===");
            logMsg($logFile, "FBR Invoice Number: " . ($submitResult['fbr_invoice_number'] ?? 'N/A'));
            logMsg($logFile, "!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!");

            $invoice->status = 'locked';
            $invoice->fbr_status = 'accepted';
            $invoice->fbr_invoice_number = $submitResult['fbr_invoice_number'] ?? null;
            $invoice->fbr_response = json_encode($submitResult['fbr_response'] ?? $submitResult);
            $invoice->submitted_at = now();
            $invoice->save();

            logMsg($logFile, "Invoice #9 updated: status=locked, fbr_status=accepted");
            logMsg($logFile, "=== MISSION COMPLETE ===");
            break;
        }

        $failureType = $submitResult['failure_type'] ?? 'unknown';
        $errors = $submitResult['errors'] ?? [];

        logMsg($logFile, "Submit FAILED: type={$failureType}");

        if ($failureType === 'rate_limited') {
            logMsg($logFile, "FBR still rate limiting. Will wait {$waitMinutes} minutes and retry.");
        } elseif ($failureType === 'validation_error') {
            logMsg($logFile, "FBR VALIDATION ERROR - details:");
            foreach ($errors as $err) {
                logMsg($logFile, "  >> {$err}");
            }
            logMsg($logFile, "Will still retry in case it's a temporary issue.");
        } elseif ($failureType === 'server_error') {
            logMsg($logFile, "FBR server error (500) - temporary issue, will retry.");
        } else {
            logMsg($logFile, "Error type: {$failureType} - will retry.");
        }

    } catch (\Exception $e) {
        logMsg($logFile, "Submit EXCEPTION: " . $e->getMessage());
    }
}

logMsg($logFile, "");
logMsg($logFile, "============================================");
logMsg($logFile, "=== FBR AUTO-RETRY FINISHED ===");
logMsg($logFile, "Time: " . date('Y-m-d H:i:s'));
$finalInvoice = App\Models\Invoice::find(9);
logMsg($logFile, "Final status: " . $finalInvoice->status);
logMsg($logFile, "Final fbr_status: " . ($finalInvoice->fbr_status ?? 'null'));
logMsg($logFile, "FBR Invoice Number: " . ($finalInvoice->fbr_invoice_number ?? 'none'));
logMsg($logFile, "============================================");

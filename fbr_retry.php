<?php
require __DIR__ . '/vendor/autoload.php';
$app = require_once __DIR__ . '/bootstrap/app.php';
$kernel = $app->make(Illuminate\Contracts\Console\Kernel::class);
$kernel->bootstrap();

$logFile = __DIR__ . '/fbr_retry_log.txt';
$maxAttempts = 8;
$waitMinutes = 15;

function logMsg($file, $msg) {
    $timestamp = date('Y-m-d H:i:s');
    $line = "[$timestamp] $msg\n";
    file_put_contents($file, $line, FILE_APPEND);
    echo $line;
}

logMsg($logFile, "=== FBR Auto-Retry Started ===");
logMsg($logFile, "Will try every {$waitMinutes} minutes, max {$maxAttempts} attempts");

for ($attempt = 1; $attempt <= $maxAttempts; $attempt++) {
    if ($attempt > 1) {
        logMsg($logFile, "Waiting {$waitMinutes} minutes before attempt {$attempt}...");
        sleep($waitMinutes * 60);
    }

    logMsg($logFile, "--- Attempt {$attempt}/{$maxAttempts} ---");

    try {
        $invoice = App\Models\Invoice::with(['company', 'items'])->find(9);
        if (!$invoice) {
            logMsg($logFile, "ERROR: Invoice #9 not found!");
            break;
        }

        logMsg($logFile, "Invoice #9 status: {$invoice->status}");

        if ($invoice->status === 'locked' && $invoice->fbr_status === 'accepted') {
            logMsg($logFile, "SUCCESS: Invoice already accepted by FBR! No retry needed.");
            break;
        }

        $fbrService = new App\Services\FbrService();
        $result = $fbrService->submitInvoice($invoice, 0);

        logMsg($logFile, "FBR Response: " . json_encode($result));

        if (($result['status'] ?? '') === 'success') {
            logMsg($logFile, "=== FBR ACCEPTED! ===");
            logMsg($logFile, "FBR Invoice Number: " . ($result['fbr_invoice_number'] ?? 'N/A'));

            $invoice->status = 'locked';
            $invoice->fbr_status = 'accepted';
            $invoice->fbr_invoice_number = $result['fbr_invoice_number'] ?? null;
            $invoice->fbr_response = json_encode($result);
            $invoice->submitted_at = now();
            $invoice->save();

            logMsg($logFile, "Invoice #9 status updated to: locked (FBR accepted)");
            logMsg($logFile, "=== DONE - SUCCESS ===");
            break;
        }

        $failureType = $result['failure_type'] ?? 'unknown';
        if ($failureType === 'rate_limited') {
            logMsg($logFile, "Still rate limited. Will retry...");
        } elseif ($failureType === 'validation_error') {
            logMsg($logFile, "FBR validation error - stopping retries");
            logMsg($logFile, "Errors: " . json_encode($result['errors'] ?? []));
            break;
        } else {
            logMsg($logFile, "FBR error type: {$failureType}");
            if ($attempt >= $maxAttempts) {
                logMsg($logFile, "Max attempts reached. Stopping.");
            }
        }

    } catch (\Exception $e) {
        logMsg($logFile, "EXCEPTION: " . $e->getMessage());
    }
}

logMsg($logFile, "=== FBR Auto-Retry Finished ===");

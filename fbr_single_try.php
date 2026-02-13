<?php
require __DIR__ . '/vendor/autoload.php';
$app = require_once __DIR__ . '/bootstrap/app.php';
$kernel = $app->make(Illuminate\Contracts\Console\Kernel::class);
$kernel->bootstrap();

$logFile = __DIR__ . '/fbr_retry_log.txt';
$attempt = $argv[1] ?? 1;

function logMsg($file, $msg) {
    $timestamp = date('Y-m-d H:i:s');
    $line = "[$timestamp] $msg\n";
    file_put_contents($file, $line, FILE_APPEND);
    echo $line;
}

$invoice = App\Models\Invoice::with(['company', 'items'])->find(9);
if (!$invoice) { logMsg($logFile, "FATAL: Invoice #9 not found!"); exit(1); }
if ($invoice->status === 'locked' && $invoice->fbr_status === 'accepted') {
    logMsg($logFile, "Invoice already FBR accepted! Done.");
    exit(0);
}

$fbrService = new App\Services\FbrService();

logMsg($logFile, "=== ATTEMPT {$attempt} at " . date('H:i:s') . " ===");

logMsg($logFile, "Phase A: Validate-Only...");
$vr = $fbrService->validateOnly($invoice);
logMsg($logFile, "Validate: " . json_encode($vr));

sleep(3);

logMsg($logFile, "Phase B: Production Submit...");
$sr = $fbrService->submitInvoice($invoice, intval($attempt));
logMsg($logFile, "Submit: " . json_encode($sr));

if (($sr['status'] ?? '') === 'success') {
    logMsg($logFile, "!!! FBR ACCEPTED !!! Number: " . ($sr['fbr_invoice_number'] ?? 'N/A'));
    $invoice->status = 'locked';
    $invoice->fbr_status = 'accepted';
    $invoice->fbr_invoice_number = $sr['fbr_invoice_number'] ?? null;
    $invoice->fbr_response = json_encode($sr['fbr_response'] ?? $sr);
    $invoice->submitted_at = now();
    $invoice->save();
    logMsg($logFile, "Invoice locked. MISSION COMPLETE!");
    exit(0);
}

$ft = $sr['failure_type'] ?? 'unknown';
logMsg($logFile, "Failed: {$ft}");
exit(($ft === 'validation_error') ? 2 : 1);

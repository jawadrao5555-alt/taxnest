<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use App\Models\Invoice;
use App\Services\FbrService;

class FbrRetrySubmit extends Command
{
    protected $signature = 'fbr:retry {invoice_id} {--attempts=4} {--interval=30}';
    protected $description = 'Retry FBR submission for a specific invoice with interval-based retries';

    public function handle()
    {
        $invoiceId = $this->argument('invoice_id');
        $maxAttempts = (int) $this->option('attempts');
        $intervalMinutes = (int) $this->option('interval');

        $this->info("FBR Retry: Invoice #{$invoiceId}, {$maxAttempts} attempts, {$intervalMinutes} min interval");

        $fbrService = new FbrService();

        for ($attempt = 1; $attempt <= $maxAttempts; $attempt++) {
            if ($attempt > 1) {
                $this->info("Waiting {$intervalMinutes} minutes before attempt {$attempt}...");
                $this->info("Next attempt at: " . now()->addMinutes($intervalMinutes)->format('H:i:s'));
                sleep($intervalMinutes * 60);
            }

            $invoice = Invoice::with(['company', 'items'])->find($invoiceId);
            if (!$invoice) {
                $this->error("Invoice #{$invoiceId} not found!");
                return 1;
            }

            if ($invoice->status === 'locked' && $invoice->fbr_status === 'accepted') {
                $this->info("Invoice already accepted by FBR!");
                return 0;
            }

            $this->info("=== Attempt {$attempt}/{$maxAttempts} at " . now()->format('H:i:s') . " ===");

            $this->info("Phase A: Validate-Only...");
            try {
                $vResult = $fbrService->validateOnly($invoice);
                $vStatus = $vResult['status'] ?? 'unknown';
                $this->info("Validate: {$vStatus}");
                if ($vStatus === 'valid') {
                    $this->info("Payload VALID per FBR!");
                } elseif ($vStatus === 'invalid') {
                    $errors = $vResult['errors'] ?? [];
                    $nonEmpty = array_filter($errors, fn($e) => !empty(trim($e)));
                    if (!empty($nonEmpty)) {
                        $this->warn("Validate errors: " . implode(', ', $nonEmpty));
                    } else {
                        $this->info("Empty response (rate limited)");
                    }
                }
            } catch (\Exception $e) {
                $this->warn("Validate error: " . $e->getMessage());
            }

            sleep(5);

            $this->info("Phase B: Production Submit...");
            try {
                $sResult = $fbrService->submitInvoice($invoice, $attempt);
                $sStatus = $sResult['status'] ?? 'unknown';

                if ($sStatus === 'success') {
                    $fbrNum = $sResult['fbr_invoice_number'] ?? 'N/A';
                    $this->newLine();
                    $this->info("!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!");
                    $this->info("FBR ACCEPTED! Number: {$fbrNum}");
                    $this->info("!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!");

                    $invoice->status = 'locked';
                    $invoice->fbr_status = 'accepted';
                    $invoice->fbr_invoice_number = $sResult['fbr_invoice_number'] ?? null;
                    $invoice->fbr_response = json_encode($sResult['fbr_response'] ?? $sResult);
                    $invoice->submitted_at = now();
                    $invoice->save();

                    $this->info("Invoice #{$invoiceId} locked. DONE!");
                    return 0;
                }

                $failureType = $sResult['failure_type'] ?? 'unknown';
                $errors = $sResult['errors'] ?? [];
                $this->warn("Failed: {$failureType}");
                foreach ($errors as $err) {
                    $this->warn("  > {$err}");
                }

                if ($failureType === 'rate_limited') {
                    $this->info("Rate limited - will retry after wait.");
                }

            } catch (\Exception $e) {
                $this->error("Submit error: " . $e->getMessage());
            }
        }

        $this->error("All {$maxAttempts} attempts exhausted. Please retry later.");
        return 1;
    }
}

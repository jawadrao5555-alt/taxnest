<?php

namespace App\Console\Commands;

use App\Http\Controllers\InvoiceController;
use App\Models\Invoice;
use App\Services\DiFiscalSubmissionState;
use Illuminate\Console\Command;

class FbrRetrySubmit extends Command
{
    protected $signature = 'fbr:retry {invoice_id} {--attempts=4} {--interval=30}';
    protected $description = 'Retry a DI invoice through the canonical fiscal submission flow';

    public function handle(): int
    {
        $attempts = (int) $this->option('attempts');
        $interval = (int) $this->option('interval');
        if ($attempts < 1 || $attempts > 10 || $interval < 0 || $interval > 1440) {
            $this->error('Use 1–10 attempts and an interval of 0–1440 minutes.');
            return self::FAILURE;
        }

        for ($attempt = 1; $attempt <= $attempts; $attempt++) {
            $invoice = Invoice::withoutGlobalScopes()->with('company')->find($this->argument('invoice_id'));
            if (!$invoice || !$invoice->company) {
                $this->error('Invoice or company not found.');
                return self::FAILURE;
            }
            if ($invoice->status === 'locked' && !empty($invoice->fbr_invoice_number)) {
                $this->info('Invoice already has a regulator reference; no submission was made.');
                return self::SUCCESS;
            }
            $environment = in_array($invoice->company->fbr_environment, ['sandbox', 'production'], true)
                ? $invoice->company->fbr_environment : 'sandbox';
            $claimed = DiFiscalSubmissionState::reserve($invoice->id, 'cli_retry', $environment);
            if (!$claimed) {
                $this->error('Invoice is accepted, processing, or requires verification. Reconcile it before retry.');
                return self::FAILURE;
            }
            // This path owns acknowledgement, ledger, integrity and audit updates.
            // Never maintain a second CLI-only accepted-state implementation.
            try {
                $result = app(InvoiceController::class)->submitToFbrSync($claimed, $environment);
            } catch (\Throwable $e) {
                $fresh = $claimed->fresh();
                if ($fresh && !$fresh->fbr_invoice_number && $fresh->is_fbr_processing) {
                    DiFiscalSubmissionState::verificationRequired($fresh, $environment, 'cli_callback_loss');
                    $fresh->save();
                }
                $this->error('Submission ended without a confirmed outcome. Reconcile the invoice before retry.');
                return self::FAILURE;
            }
            $status = $result['status'] ?? 'pending_verification';
            if ($status === 'success') {
                $this->info('Canonical submission completed with a regulator acknowledgement.');
                return self::SUCCESS;
            }
            if ($status !== 'failed') {
                $this->warn('No authoritative acceptance or rejection; review the invoice before another attempt.');
                return self::FAILURE;
            }
            // Permanent data/configuration errors need correction, not repeated POSTs.
            if (($result['failure_type'] ?? '') !== 'rate_limited' || $attempt === $attempts) {
                $this->warn('Submission failed. Review fiscal diagnostics and correct the invoice or configuration.');
                return self::FAILURE;
            }
            if ($interval > 0) sleep($interval * 60);
        }
        return self::FAILURE;
    }
}

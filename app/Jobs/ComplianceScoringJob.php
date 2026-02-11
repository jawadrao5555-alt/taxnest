<?php

namespace App\Jobs;

use App\Models\Invoice;
use App\Services\HybridComplianceScorer;
use App\Services\VendorRiskEngine;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;

class ComplianceScoringJob implements ShouldQueue
{
    use Queueable;

    public $tries = 2;
    public $backoff = [10, 30];

    public function __construct(public int $invoiceId)
    {
    }

    public function handle(): void
    {
        $invoice = Invoice::with(['company', 'items'])->find($this->invoiceId);

        if (!$invoice) {
            Log::warning("ComplianceScoringJob: Invoice #{$this->invoiceId} not found");
            return;
        }

        $result = HybridComplianceScorer::score($invoice);

        Log::info("ComplianceScoringJob: Invoice #{$this->invoiceId} scored {$result['final_score']} ({$result['risk_level']})");

        if ($invoice->buyer_ntn) {
            $vendorResult = VendorRiskEngine::calculateVendorScore($invoice->company_id, $invoice->buyer_ntn);
            VendorRiskEngine::persistVendorProfile($invoice->company_id, $invoice->buyer_ntn, $invoice->buyer_name, $vendorResult);
        }
    }

    public function failed(\Throwable $exception): void
    {
        Log::error("ComplianceScoringJob failed for invoice #{$this->invoiceId}: " . $exception->getMessage());
    }
}

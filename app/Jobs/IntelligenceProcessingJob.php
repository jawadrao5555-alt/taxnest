<?php

namespace App\Jobs;

use App\Models\Invoice;
use App\Models\Company;
use App\Services\RiskIntelligenceEngine;
use App\Services\ComplianceScoreService;
use App\Services\VendorRiskEngine;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;

class IntelligenceProcessingJob implements ShouldQueue
{
    use Queueable;

    public $tries = 2;
    public $backoff = [10, 30];

    public function __construct(
        public int $invoiceId,
        public bool $fullAnalysis = false
    ) {}

    public function handle(): void
    {
        $invoice = Invoice::with(['company', 'items'])->find($this->invoiceId);

        if (!$invoice) {
            Log::warning("IntelligenceProcessingJob: Invoice #{$this->invoiceId} not found");
            return;
        }

        $riskResult = RiskIntelligenceEngine::analyzeInvoice($invoice, true);

        Log::info("IntelligenceProcessingJob: Invoice #{$this->invoiceId} risk score: {$riskResult['risk_score']} ({$riskResult['risk_level']}), {$riskResult['risk_count']} risks detected");

        ComplianceScoreService::recalculate($invoice->company_id);

        if ($invoice->buyer_ntn) {
            $vendorResult = VendorRiskEngine::calculateVendorScore($invoice->company_id, $invoice->buyer_ntn);
            VendorRiskEngine::persistVendorProfile($invoice->company_id, $invoice->buyer_ntn, $invoice->buyer_name, $vendorResult);
        }

        if ($this->fullAnalysis) {
            VendorRiskEngine::refreshVendorProfiles($invoice->company_id);
        }
    }

    public function failed(\Throwable $exception): void
    {
        Log::error("IntelligenceProcessingJob failed for invoice #{$this->invoiceId}: " . $exception->getMessage());
    }
}

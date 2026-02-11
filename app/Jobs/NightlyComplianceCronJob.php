<?php

namespace App\Jobs;

use App\Models\Company;
use App\Services\ComplianceRiskService;
use App\Services\AnomalyDetectionService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;

class NightlyComplianceCronJob implements ShouldQueue
{
    use Queueable;

    public function handle(): void
    {
        $companies = Company::all();

        foreach ($companies as $company) {
            try {
                ComplianceRiskService::recalculateAndStore($company->id);
                AnomalyDetectionService::runAllDetections($company->id);
                Log::info("Nightly compliance recalculated for company #{$company->id}");
            } catch (\Exception $e) {
                Log::error("Compliance cron failed for company #{$company->id}: " . $e->getMessage());
            }
        }
    }
}

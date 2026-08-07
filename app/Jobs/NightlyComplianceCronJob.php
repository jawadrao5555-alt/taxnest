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
        // Fix A: exclude internal/demo accounts (is_internal_account=true) and the
        // 4,500 @scaletest.pk load-test companies (is_internal_account=false but fake).
        // Scale-test companies are NOT flagged by the boolean — they are identified by
        // their email domain, so we filter both dimensions.
        // Fix B: chunk(20) instead of all() to avoid a 4500-row memory spike, and
        // sleep 200 ms between batches so MySQL isn't monopolised continuously.
        Company::where('is_internal_account', false)
            ->where('email', 'not like', '%@scaletest.pk')
            ->chunk(20, function ($companies) {
                foreach ($companies as $company) {
                    try {
                        ComplianceRiskService::recalculateAndStore($company->id);
                        AnomalyDetectionService::runAllDetections($company->id);
                        Log::info("Nightly compliance recalculated for company #{$company->id}");
                    } catch (\Exception $e) {
                        Log::error("Compliance cron failed for company #{$company->id}: " . $e->getMessage());
                    }
                }
                usleep(200_000); // 200 ms yield between batches — keeps MySQL breathing
            });
    }
}

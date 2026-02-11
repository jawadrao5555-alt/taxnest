<?php

namespace App\Services;

use App\Models\Invoice;
use App\Models\FbrLog;
use App\Models\Company;

class SmartInsightsService
{
    public static function getInsights(int $companyId): array
    {
        $insights = [];

        $oldDrafts = Invoice::where('company_id', $companyId)
            ->where('status', 'draft')
            ->where('created_at', '<', now()->subDays(7))
            ->count();

        if ($oldDrafts > 0) {
            $insights[] = [
                'type' => 'warning',
                'icon' => 'clock',
                'title' => 'High Draft Aging',
                'message' => "You have {$oldDrafts} draft invoice(s) older than 7 days. Submit them to maintain your compliance score.",
                'priority' => 'high',
            ];
        }

        $invoiceIds = Invoice::where('company_id', $companyId)->pluck('id');
        $totalLogs = FbrLog::whereIn('invoice_id', $invoiceIds)->count();
        $retryLogs = FbrLog::whereIn('invoice_id', $invoiceIds)->where('retry_count', '>', 0)->count();

        if ($totalLogs > 0 && ($retryLogs / $totalLogs) > 0.3) {
            $retryPercent = round(($retryLogs / $totalLogs) * 100);
            $insights[] = [
                'type' => 'warning',
                'icon' => 'refresh',
                'title' => 'High Retry Rate',
                'message' => "{$retryPercent}% of your FBR submissions required retries. Check your FBR token and invoice data quality.",
                'priority' => 'medium',
            ];
        }

        $successLogs = FbrLog::whereIn('invoice_id', $invoiceIds)->where('status', 'success')->count();
        if ($totalLogs > 0) {
            $successRate = ($successLogs / $totalLogs) * 100;
            if ($successRate < 70) {
                $insights[] = [
                    'type' => 'danger',
                    'icon' => 'alert',
                    'title' => 'Low FBR Success Rate',
                    'message' => "Your FBR success rate is " . round($successRate) . "%. Review failed submissions and correct data issues.",
                    'priority' => 'high',
                ];
            }
        }

        $company = Company::find($companyId);
        if ($company && $company->compliance_score < 50) {
            $insights[] = [
                'type' => 'danger',
                'icon' => 'shield',
                'title' => 'Compliance Score At Risk',
                'message' => "Your compliance score is {$company->compliance_score}/100. Take immediate action to improve it.",
                'priority' => 'critical',
            ];
        }

        usort($insights, function ($a, $b) {
            $priorityOrder = ['critical' => 0, 'high' => 1, 'medium' => 2, 'low' => 3];
            return ($priorityOrder[$a['priority']] ?? 4) <=> ($priorityOrder[$b['priority']] ?? 4);
        });

        return $insights;
    }
}

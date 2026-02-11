<?php

namespace App\Services;

use App\Models\Company;
use App\Models\ComplianceScore;
use Carbon\Carbon;

class ComplianceCertificateService
{
    public static function generateHtml(int $companyId, ?string $month = null): string
    {
        $company = Company::findOrFail($companyId);
        $monthDate = $month ? Carbon::parse($month) : now()->subMonth();
        $monthLabel = $monthDate->format('F Y');

        $scoreRecord = ComplianceScore::where('company_id', $companyId)
            ->whereMonth('calculated_date', $monthDate->month)
            ->whereYear('calculated_date', $monthDate->year)
            ->orderBy('calculated_date', 'desc')
            ->first();

        $score = $scoreRecord ? $scoreRecord->score : ($company->compliance_score ?? 100);
        $category = $scoreRecord ? $scoreRecord->category : ComplianceRiskService::categorize($score);

        $badgeColor = match ($category) {
            'SAFE' => '#10b981',
            'MODERATE' => '#f59e0b',
            default => '#ef4444',
        };

        return view('certificates.compliance', compact(
            'company', 'monthLabel', 'score', 'category', 'badgeColor'
        ))->render();
    }
}

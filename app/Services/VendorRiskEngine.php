<?php

namespace App\Services;

use App\Models\Invoice;
use App\Models\FbrLog;
use App\Models\ComplianceReport;
use App\Models\VendorRiskProfile;
use Illuminate\Support\Facades\DB;

class VendorRiskEngine
{
    public static function calculateVendorScore(int $companyId, string $vendorNtn): array
    {
        $invoices = Invoice::where('company_id', $companyId)
            ->where('buyer_ntn', $vendorNtn)
            ->get();

        $totalInvoices = $invoices->count();
        if ($totalInvoices === 0) {
            return ['score' => 100, 'total_invoices' => 0, 'rejected' => 0, 'tax_mismatches' => 0, 'anomalies' => 0];
        }

        $invoiceIds = $invoices->pluck('id');

        $rejectedCount = FbrLog::whereIn('invoice_id', $invoiceIds)
            ->where('status', 'failed')
            ->distinct('invoice_id')
            ->count('invoice_id');

        $taxMismatches = 0;
        foreach ($invoices as $invoice) {
            $invoice->load('items');
            $result = ComplianceEngine::validate($invoice);
            if ($result['flags']['RATE_MISMATCH']) {
                $taxMismatches++;
            }
        }

        $anomalyCount = ComplianceReport::whereIn('invoice_id', $invoiceIds)
            ->where('risk_level', '!=', 'LOW')
            ->count();

        $score = 100;
        $rejectionPenalty = $totalInvoices > 0 ? ($rejectedCount / $totalInvoices) * 40 : 0;
        $mismatchPenalty = $totalInvoices > 0 ? ($taxMismatches / $totalInvoices) * 30 : 0;
        $anomalyPenalty = $totalInvoices > 0 ? ($anomalyCount / $totalInvoices) * 30 : 0;

        $score = (int) round(max(0, $score - $rejectionPenalty - $mismatchPenalty - $anomalyPenalty));

        return [
            'score' => $score,
            'total_invoices' => $totalInvoices,
            'rejected' => $rejectedCount,
            'tax_mismatches' => $taxMismatches,
            'anomalies' => $anomalyCount,
        ];
    }

    public static function persistVendorProfile(int $companyId, string $vendorNtn, ?string $vendorName, array $result): VendorRiskProfile
    {
        return VendorRiskProfile::updateOrCreate(
            ['company_id' => $companyId, 'vendor_ntn' => $vendorNtn],
            [
                'vendor_name' => $vendorName ?? 'Unknown',
                'vendor_score' => $result['score'],
                'total_invoices' => $result['total_invoices'],
                'rejected_invoices' => $result['rejected'],
                'tax_mismatches' => $result['tax_mismatches'],
                'anomaly_count' => $result['anomalies'],
                'last_flagged_at' => $result['score'] < 70 ? now() : null,
            ]
        );
    }

    public static function refreshVendorProfiles(int $companyId): array
    {
        $vendors = Invoice::where('company_id', $companyId)
            ->whereNotNull('buyer_ntn')
            ->where('buyer_ntn', '!=', '')
            ->select('buyer_ntn', DB::raw('MAX(buyer_name) as vendor_name'))
            ->groupBy('buyer_ntn')
            ->get();

        $profiles = [];
        foreach ($vendors as $vendor) {
            $result = self::calculateVendorScore($companyId, $vendor->buyer_ntn);

            $profile = self::persistVendorProfile($companyId, $vendor->buyer_ntn, $vendor->vendor_name, $result);
            $profiles[] = $profile;
        }

        return $profiles;
    }

    public static function getTopRiskyVendors(int $companyId, int $limit = 5): array
    {
        return VendorRiskProfile::where('company_id', $companyId)
            ->orderBy('vendor_score', 'asc')
            ->take($limit)
            ->get()
            ->toArray();
    }
}

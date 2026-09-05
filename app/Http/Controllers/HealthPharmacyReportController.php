<?php

namespace App\Http\Controllers;

use App\Http\Controllers\Concerns\HealthPharmacyContext;
use App\Services\BranchStockService;
use App\Services\HealthPharmacyReportService;
use Illuminate\Http\Request;

/**
 * Pharmacy reporting (Task 1549).
 *
 * Every figure comes from HealthPharmacyReportService — the same source the hub
 * tiles read — so a report can never disagree with the alert that sent the
 * pharmacist to it.
 */
class HealthPharmacyReportController extends Controller
{
    use HealthPharmacyContext;

    public function index(Request $request)
    {
        $companyId = $this->companyId();
        $branchId = $this->viewBranchId();
        $allBranches = BranchStockService::viewingAllBranches($companyId);

        $settings = $this->settings();
        $report = $request->query('report', 'low_stock');
        [$from, $to] = HealthPharmacyReportService::range($request->query('from'), $request->query('to'));

        $data = match ($report) {
            'near_expiry' => [
                'rows' => HealthPharmacyReportService::nearExpiryQuery($companyId, $branchId, $allBranches, (int) $settings->near_expiry_days)->get(),
            ],
            'expired' => [
                'rows' => HealthPharmacyReportService::expiredQuery($companyId, $branchId, $allBranches)->get(),
            ],
            'valuation' => [
                'rows' => HealthPharmacyReportService::valuationRows($companyId, $branchId, $allBranches),
                'totals' => HealthPharmacyReportService::valuation($companyId, $branchId, $allBranches),
            ],
            'margin' => [
                'rows' => HealthPharmacyReportService::margin($companyId, $branchId, $allBranches, $from, $to),
            ],
            'purchases' => [
                'rows' => HealthPharmacyReportService::purchases($companyId, $branchId, $allBranches, $from, $to),
            ],
            'suppliers' => [
                'rows' => HealthPharmacyReportService::supplierBalances($companyId),
            ],
            default => [
                'rows' => collect(HealthPharmacyReportService::lowStock($companyId, $branchId, $allBranches)),
            ],
        };

        return view('health.pharmacy.reports', array_merge($data, [
            'report' => in_array($report, ['low_stock', 'near_expiry', 'expired', 'valuation', 'margin', 'purchases', 'suppliers'], true)
                ? $report
                : 'low_stock',
            'settings' => $settings,
            'from' => $from,
            'to' => $to,
            'summary' => HealthPharmacyReportService::summary($companyId, $branchId, $allBranches),
        ]));
    }
}

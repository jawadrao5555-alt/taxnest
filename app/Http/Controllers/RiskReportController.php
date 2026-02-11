<?php

namespace App\Http\Controllers;

use App\Services\AuditDefenseService;
use Illuminate\Http\Request;

class RiskReportController extends Controller
{
    public function show(Request $request)
    {
        $companyId = app('currentCompanyId');
        $month = $request->get('month');

        $report = AuditDefenseService::generateRiskReport($companyId, $month);

        return view('reports.risk-report', compact('report'));
    }
}

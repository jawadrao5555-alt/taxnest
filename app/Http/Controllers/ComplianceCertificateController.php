<?php

namespace App\Http\Controllers;

use App\Services\ComplianceCertificateService;
use Illuminate\Http\Request;

class ComplianceCertificateController extends Controller
{
    public function generate(Request $request)
    {
        $companyId = app('currentCompanyId');
        $month = $request->query('month');
        $html = ComplianceCertificateService::generateHtml($companyId, $month);

        return response($html)->header('Content-Type', 'text/html');
    }
}

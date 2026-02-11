<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;

use App\Models\Invoice;
use Illuminate\Support\Facades\DB;

class DashboardController extends Controller
{
    public function index()
    {
        $companyId = app('currentCompanyId');

        $invoices = Invoice::where('company_id', $companyId)->get();

        // PostgreSQL specific date extraction for monthly trend
        $monthlyData = Invoice::where('company_id', $companyId)
            ->selectRaw('COUNT(*) as count, TO_CHAR(created_at, \'Month\') as month_name, EXTRACT(MONTH FROM created_at) as month_num')
            ->groupBy('month_name', 'month_num')
            ->orderBy('month_num')
            ->get()
            ->pluck('count', 'month_name');

        return view('dashboard', compact('invoices', 'monthlyData'));
    }
}

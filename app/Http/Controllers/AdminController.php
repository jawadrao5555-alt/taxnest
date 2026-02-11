<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;

use App\Models\Company;
use App\Models\Invoice;
use App\Models\FbrLog;
use App\Models\User;

class AdminController extends Controller
{
    public function dashboard()
    {
        $totalCompanies = Company::count();
        $totalUsers = User::count();
        $totalInvoices = Invoice::count();
        $lockedInvoices = Invoice::where('status', 'locked')->count();
        $failedLogs = FbrLog::where('status', 'failed')->count();

        return view('admin.dashboard', compact(
            'totalCompanies',
            'totalUsers',
            'totalInvoices',
            'lockedInvoices',
            'failedLogs'
        ));
    }
}

<?php

namespace App\Http\Controllers\SaasAdmin;

use App\Http\Controllers\Controller;
use App\Models\Company;
use App\Models\User;
use App\Models\Subscription;
use App\Models\Franchise;
use App\Models\PosTransaction;
use App\Models\AdminAuditLog;
use App\Models\SystemControl;
use Illuminate\Support\Facades\DB;

class AdminDashboardController extends Controller
{
    public function index()
    {
        $stats = [
            'total_companies' => Company::count(),
            'pending_companies' => Company::where('status', 'pending')->count(),
            'active_subscriptions' => Subscription::where('active', true)->count(),
            'total_users' => User::count(),
            'total_franchises' => Franchise::count(),
            'total_pos_transactions' => PosTransaction::where('status', 'completed')->count(),
            'total_pos_revenue' => PosTransaction::where('status', 'completed')->sum('total_amount'),
            'today_pos_transactions' => PosTransaction::where('status', 'completed')
                ->whereDate('created_at', today())->count(),
        ];

        $recentCompanies = Company::orderBy('created_at', 'desc')->take(5)->get();
        $recentAuditLogs = AdminAuditLog::with('admin')->orderBy('created_at', 'desc')->take(10)->get();
        $systemControls = SystemControl::all();

        $monthlyRevenue = PosTransaction::where('status', 'completed')
            ->where('created_at', '>=', now()->subMonths(6))
            ->selectRaw("TO_CHAR(created_at, 'YYYY-MM') as month, SUM(total_amount) as revenue, COUNT(*) as count")
            ->groupByRaw("TO_CHAR(created_at, 'YYYY-MM')")
            ->orderBy('month')
            ->get();

        return view('saas-admin.dashboard', compact('stats', 'recentCompanies', 'recentAuditLogs', 'systemControls', 'monthlyRevenue'));
    }
}

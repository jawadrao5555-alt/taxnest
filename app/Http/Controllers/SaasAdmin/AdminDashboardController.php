<?php

namespace App\Http\Controllers\SaasAdmin;

use App\Http\Controllers\Controller;
use App\Models\Company;
use App\Models\User;
use App\Models\Invoice;
use App\Models\Subscription;
use App\Models\Franchise;
use App\Models\PosTransaction;
use App\Models\FbrPosTransaction;
use App\Models\AdminAuditLog;
use App\Models\SystemControl;
use Illuminate\Support\Facades\DB;

class AdminDashboardController extends Controller
{
    public function index()
    {
        $diCompanies = Company::where('product_type', 'di')->get();
        $posCompanies = Company::where('product_type', 'pos')->get();
        $fbrposCompanies = Company::where('product_type', 'fbrpos')->get();

        $stats = [
            'total_companies' => Company::count(),
            'di_companies' => $diCompanies->count(),
            'pos_companies' => $posCompanies->count(),
            'fbrpos_companies' => $fbrposCompanies->count(),
            'pending_companies' => Company::where('status', 'pending')->count(),
            'suspended_companies' => Company::where('status', 'suspended')->count(),
            'binned_companies' => Company::onlyTrashed()->count(),
            'active_subscriptions' => Subscription::where('active', true)->count(),
            'total_users' => User::count(),
            'total_franchises' => Franchise::count(),

            'di_invoices' => Invoice::count(),
            'di_revenue' => Invoice::where('fbr_status', 'locked')->sum('total_amount'),

            'pos_transactions' => PosTransaction::where('status', 'completed')->count(),
            'pos_revenue' => PosTransaction::where('status', 'completed')->sum('total_amount'),
            'today_pos_transactions' => PosTransaction::where('status', 'completed')
                ->whereDate('created_at', today())->count(),

            'fbrpos_transactions' => FbrPosTransaction::count(),
            'fbrpos_revenue' => FbrPosTransaction::sum('total_amount'),
            'today_fbrpos_transactions' => FbrPosTransaction::whereDate('created_at', today())->count(),
        ];

        $diCompaniesList = Company::where('product_type', 'di')
            ->with(['activeSubscription', 'franchise'])
            ->withCount(['users', 'invoices'])
            ->orderBy('created_at', 'desc')
            ->get();

        $posCompaniesList = Company::where('product_type', 'pos')
            ->with(['activeSubscription', 'franchise'])
            ->withCount('users')
            ->orderBy('created_at', 'desc')
            ->get();

        $fbrposCompaniesList = Company::where('product_type', 'fbrpos')
            ->with(['activeSubscription', 'franchise'])
            ->withCount('users')
            ->orderBy('created_at', 'desc')
            ->get();

        foreach ($posCompaniesList as $pc) {
            $pc->pos_transaction_count = PosTransaction::where('company_id', $pc->id)
                ->where('status', 'completed')->count();
            $pc->pos_revenue = PosTransaction::where('company_id', $pc->id)
                ->where('status', 'completed')->sum('total_amount');
        }

        foreach ($fbrposCompaniesList as $fc) {
            $fc->fbrpos_transaction_count = FbrPosTransaction::where('company_id', $fc->id)->count();
            $fc->fbrpos_revenue = FbrPosTransaction::where('company_id', $fc->id)->sum('total_amount');
        }

        foreach ($diCompaniesList as $dc) {
            $dc->di_revenue = Invoice::where('company_id', $dc->id)
                ->where('fbr_status', 'locked')->sum('total_amount');
        }

        $recentAuditLogs = AdminAuditLog::with('admin')->orderBy('created_at', 'desc')->take(10)->get();
        $systemControls = SystemControl::all();

        return view('saas-admin.dashboard', compact(
            'stats', 'diCompaniesList', 'posCompaniesList', 'fbrposCompaniesList',
            'recentAuditLogs', 'systemControls'
        ));
    }
}

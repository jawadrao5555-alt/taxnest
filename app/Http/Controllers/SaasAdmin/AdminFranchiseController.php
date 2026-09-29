<?php

namespace App\Http\Controllers\SaasAdmin;

use App\Http\Controllers\Controller;
use App\Models\Franchise;
use App\Models\FranchiseCommission;
use App\Models\AdminAuditLog;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Schema;

class AdminFranchiseController extends Controller
{
    public function index()
    {
        $franchises = Franchise::withCount('companies')->orderBy('created_at', 'desc')->get();
        $balances = Schema::hasTable('franchise_commissions')
            ? FranchiseCommission::where('status', 'pending')
                ->selectRaw('franchise_id, SUM(amount) as balance')
                ->groupBy('franchise_id')->pluck('balance', 'franchise_id')
            : collect();
        $approvals = Schema::hasTable('franchise_company_approvals')
            ? DB::table('franchise_company_approvals as a')
            ->join('companies as c', 'c.id', '=', 'a.company_id')
            ->join('franchises as f', 'f.id', '=', 'a.franchise_id')
            ->where('c.status', 'pending')
            ->select('c.id', 'c.name', 'f.name as franchise_name', 'a.approved_at')
            ->orderByDesc('a.approved_at')->limit(20)->get()
            : collect();
        return view('saas-admin.franchises', compact('franchises', 'balances', 'approvals'));
    }

    public function statement(int $id)
    {
        $franchise = Franchise::findOrFail($id);
        $lines = FranchiseCommission::where('franchise_id', $id)
            ->orderByDesc('earned_at')->orderByDesc('id')->paginate(30);
        $totals = [
            'pending' => FranchiseCommission::where('franchise_id', $id)->where('status', 'pending')->sum('amount'),
            'paid' => FranchiseCommission::where('franchise_id', $id)->where('status', 'paid')->sum('amount'),
        ];
        return view('saas-admin.franchise-statement', compact('franchise', 'lines', 'totals'));
    }

    public function reconcile(int $id)
    {
        abort_unless(auth('admin')->user()?->isSuperAdmin(), 403);
        Franchise::findOrFail($id);
        $count = \App\Services\FranchiseCommissionService::reconcile($id);
        AdminAuditLog::log(auth('admin')->id(), 'Franchise verified receipts reconciled', 'Franchise', $id,
            ['new_decisions' => $count]);
        return back()->with('success', "Verified receipts checked; {$count} missing decision(s) recovered.");
    }

    public function markPaid(Request $request, int $id)
    {
        abort_unless(auth('admin')->user()?->isSuperAdmin(), 403);
        $request->validate(['reference' => 'required|string|max:255']);
        $payout = DB::transaction(function () use ($id, $request) {
            Franchise::whereKey($id)->lockForUpdate()->firstOrFail();
            $lines = FranchiseCommission::where('franchise_id', $id)->where('status', 'pending')
                ->lockForUpdate()->get();
            $net = round((float) $lines->sum('amount'), 2);
            if ($net <= 0) return null;
            $payoutId = DB::table('franchise_payouts')->insertGetId([
                'franchise_id' => $id, 'amount' => $net, 'reference' => $request->reference,
                'paid_by_admin_id' => auth('admin')->id(), 'created_at' => now(), 'updated_at' => now(),
            ]);
            FranchiseCommission::whereIn('id', $lines->pluck('id'))->update([
                'status' => 'paid', 'paid_at' => now(), 'paid_by_admin_id' => auth('admin')->id(),
                'payout_reference' => $request->reference, 'payout_id' => $payoutId,
            ]);
            return ['id' => $payoutId, 'amount' => $net];
        }, 3);
        if (!$payout) return back()->with('error', 'No positive payable balance.');
        AdminAuditLog::log(auth('admin')->id(), 'Franchise payout recorded', 'FranchisePayout', $payout['id'],
            ['franchise_id' => $id, 'amount' => $payout['amount'], 'reference' => $request->reference]);
        return back()->with('success', 'Net payout of PKR '.number_format($payout['amount'], 2).' recorded.');
    }

    public function adjust(Request $request, int $id, int $lineId)
    {
        abort_unless(auth('admin')->user()?->isSuperAdmin(), 403);
        $data = $request->validate([
            'amount' => 'required|numeric|min:0.01|max:100000000',
            'reason' => 'required|string|max:255',
        ]);
        $adjustment = DB::transaction(function () use ($id, $lineId, $data) {
            $original = FranchiseCommission::whereKey($lineId)->where('franchise_id', $id)
                ->where('type', 'earned')->where('amount', '>', 0)->lockForUpdate()->firstOrFail();
            $already = FranchiseCommission::where('source_commission_id', $original->id)->sum('amount');
            if ((float) $data['amount'] > round((float) $original->amount + (float) $already, 2)) return null;
            return FranchiseCommission::create([
                'franchise_id' => $id, 'company_id' => $original->company_id,
                'company_name' => $original->company_name, 'source_commission_id' => $original->id,
                'type' => 'adjustment', 'base_amount' => $original->base_amount,
                'rate_percent' => $original->rate_percent, 'amount' => -round((float) $data['amount'], 2),
                'status' => 'pending', 'earned_at' => now(), 'adjustment_reason' => $data['reason'],
            ]);
        }, 3);
        if (!$adjustment) return back()->with('error', 'Adjustment exceeds the remaining commission.');
        AdminAuditLog::log(auth('admin')->id(), 'Franchise commission adjusted', 'FranchiseCommission', $adjustment->id,
            ['source_commission_id' => $lineId, 'amount' => $adjustment->amount, 'reason' => $data['reason']]);
        return back()->with('success', 'Refund adjustment recorded.');
    }

    public function store(Request $request)
    {
        abort_unless(auth('admin')->user()?->isSuperAdmin(), 403);
        $request->validate([
            'name' => 'required|string|max:255',
            'email' => 'required|email|unique:franchises,email',
            'phone' => 'nullable|string|max:30',
            'commission_rate' => 'required|numeric|min:0|max:100',
            'password' => ['required', 'string', \Illuminate\Validation\Rules\Password::defaults()],
        ]);

        $franchise = Franchise::create([
            'name' => $request->name,
            'email' => $request->email,
            'phone' => $request->phone,
            'commission_rate' => $request->commission_rate,
            'password' => $request->password,
            'status' => 'active',
        ]);

        AdminAuditLog::log(auth('admin')->id(), 'Franchise created', 'Franchise', $franchise->id, ['name' => $franchise->name]);
        return back()->with('success', "Franchise '{$franchise->name}' created.");
    }

    public function update(Request $request, $id)
    {
        abort_unless(auth('admin')->user()?->isSuperAdmin(), 403);
        $franchise = Franchise::findOrFail($id);

        $request->validate([
            'name' => 'required|string|max:255',
            'email' => "required|email|unique:franchises,email,{$id}",
            'phone' => 'nullable|string|max:30',
            'commission_rate' => 'required|numeric|min:0|max:100',
        ]);

        $franchise->update($request->only(['name', 'email', 'phone', 'commission_rate']));

        if ($request->filled('password')) {
            $franchise->update(['password' => $request->password]);
        }

        AdminAuditLog::log(auth('admin')->id(), 'Franchise updated', 'Franchise', $franchise->id, ['name' => $franchise->name]);
        return back()->with('success', "Franchise '{$franchise->name}' updated.");
    }

    public function toggleStatus($id)
    {
        abort_unless(auth('admin')->user()?->isSuperAdmin(), 403);
        $franchise = Franchise::findOrFail($id);
        $newStatus = $franchise->status === 'active' ? 'suspended' : 'active';
        $franchise->update(['status' => $newStatus]);

        AdminAuditLog::log(auth('admin')->id(), "Franchise {$newStatus}", 'Franchise', $franchise->id);
        return back()->with('success', "Franchise '{$franchise->name}' is now {$newStatus}.");
    }
}

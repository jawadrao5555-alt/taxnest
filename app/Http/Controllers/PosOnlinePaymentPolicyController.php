<?php

namespace App\Http\Controllers;

use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class PosOnlinePaymentPolicyController extends Controller
{
    private function companyId(): int
    {
        $companyId = (int) app('currentCompanyId');
        $actor = auth('pos')->user();
        $current = $actor ? User::where('company_id', $companyId)->find($actor->id) : null;
        abort_unless($current && $current->is_active && $current->role === 'company_admin', 403);
        return $companyId;
    }

    public function index()
    {
        $companyId = $this->companyId();
        $cashiers = User::where('company_id', $companyId)->where('pos_role', 'pos_cashier')->get(['id', 'name']);
        $enabled = DB::table('pos_online_payment_policies')->where('company_id', $companyId)->where('requires_approval', true)->pluck('user_id')->all();
        return view('pos.online-payment-policy', compact('cashiers', 'enabled'));
    }

    public function update(Request $request, int $id)
    {
        $companyId = $this->companyId();
        User::where('company_id', $companyId)->where('pos_role', 'pos_cashier')->findOrFail($id);
        $request->validate(['requires_approval' => 'required|boolean']);
        DB::table('pos_online_payment_policies')->updateOrInsert(['company_id' => $companyId, 'user_id' => $id], [
            'requires_approval' => $request->boolean('requires_approval'), 'updated_by' => auth('pos')->id(),
            'created_at' => now(), 'updated_at' => now(),
        ]);
        return back()->with('success', __('online_payment_policy.saved'));
    }
}

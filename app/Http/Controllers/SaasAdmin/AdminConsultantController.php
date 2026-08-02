<?php

namespace App\Http\Controllers\SaasAdmin;

use App\Http\Controllers\Controller;
use App\Models\AdminAuditLog;
use App\Models\ConsultantClientLink;
use App\Models\ConsultantCommission;
use App\Models\ConsultantProfile;
use App\Services\ConsultantService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * SaaS-admin oversight of the consultant program: consultants, links,
 * commission ledger and payout marking. Payouts stay manual (owner pays via
 * bank/JazzCash and records it here) — no automated money movement.
 */
class AdminConsultantController extends Controller
{
    public function index(Request $request)
    {
        $profiles = ConsultantProfile::with('user')
            ->orderByDesc('id')
            ->get();

        // Per-consultant aggregates in three grouped queries (no N+1).
        $activeLinks = ConsultantClientLink::where('status', 'active')
            ->selectRaw('consultant_user_id, COUNT(*) as c')
            ->groupBy('consultant_user_id')
            ->pluck('c', 'consultant_user_id');

        $sums = ConsultantCommission::selectRaw("consultant_user_id, status, SUM(amount) as total")
            ->groupBy('consultant_user_id', 'status')
            ->get()
            ->groupBy('consultant_user_id');

        $referred = DB::table('companies')
            ->whereNotNull('referred_by_user_id')
            ->whereNull('deleted_at')
            ->selectRaw('referred_by_user_id, COUNT(*) as c')
            ->groupBy('referred_by_user_id')
            ->pluck('c', 'referred_by_user_id');

        $pendingCommissions = ConsultantCommission::with('consultant')
            ->where('status', 'pending')
            ->orderByDesc('id')
            ->get();

        $paidCommissions = ConsultantCommission::with('consultant')
            ->where('status', 'paid')
            ->orderByDesc('paid_at')
            ->limit(30)
            ->get();

        $links = ConsultantClientLink::with(['consultant', 'company'])
            ->orderByDesc('id')
            ->limit(50)
            ->get();

        return view('saas-admin.consultants', [
            'profiles' => $profiles,
            'activeLinks' => $activeLinks,
            'sums' => $sums,
            'referred' => $referred,
            'pendingCommissions' => $pendingCommissions,
            'paidCommissions' => $paidCommissions,
            'links' => $links,
        ]);
    }

    /** Enable/disable a consultant. Disabled: no console, no switches, no NEW commissions. */
    public function toggle($id)
    {
        $profile = ConsultantProfile::with('user')->findOrFail($id);
        $newStatus = $profile->status === 'active' ? 'disabled' : 'active';
        $profile->update(['status' => $newStatus]);

        AdminAuditLog::log(auth('admin')->id(), "Consultant {$newStatus}", 'ConsultantProfile', $profile->id, [
            'user' => $profile->user->email ?? $profile->user_id,
        ]);

        return back()->with('success', "Consultant {$newStatus}.");
    }

    /** Update a consultant's commission rate (applies to FUTURE commissions only). */
    public function updateRate(Request $request, $id)
    {
        $request->validate(['commission_rate' => 'required|numeric|min:0|max:100']);

        $profile = ConsultantProfile::findOrFail($id);
        $old = (float) $profile->commission_rate;
        $profile->update(['commission_rate' => $request->commission_rate]);

        AdminAuditLog::log(auth('admin')->id(), 'Consultant rate updated', 'ConsultantProfile', $profile->id, [
            'old' => $old,
            'new' => (float) $request->commission_rate,
        ]);

        return back()->with('success', 'Commission rate updated (future commissions).');
    }

    /** Admin-side revoke of any active/pending link. */
    public function revokeLink($id)
    {
        $link = ConsultantClientLink::findOrFail($id);

        if ($link->status === 'revoked') {
            return back()->with('error', 'Link is already revoked.');
        }

        ConsultantService::revokeLink($link, 'admin');

        AdminAuditLog::log(auth('admin')->id(), 'Consultant link revoked', 'ConsultantClientLink', $link->id, [
            'consultant_user_id' => $link->consultant_user_id,
            'company_id' => $link->company_id,
        ]);

        return back()->with('success', 'Link revoked.');
    }

    /** Mark a pending commission as paid (manual payout already made outside). */
    public function markPaid(Request $request, $id)
    {
        $request->validate(['payout_reference' => 'nullable|string|max:255']);

        $commission = ConsultantCommission::findOrFail($id);

        if ($commission->status !== 'pending') {
            return back()->with('error', 'This commission is not pending.');
        }

        $commission->update([
            'status' => 'paid',
            'paid_at' => now(),
            'paid_by_admin_id' => auth('admin')->id(),
            'payout_reference' => $request->payout_reference,
        ]);

        AdminAuditLog::log(auth('admin')->id(), 'Consultant commission paid', 'ConsultantCommission', $commission->id, [
            'amount' => (float) $commission->amount,
            'consultant_user_id' => $commission->consultant_user_id,
            'reference' => $request->payout_reference,
        ]);

        return back()->with('success', 'Commission marked as paid.');
    }
}

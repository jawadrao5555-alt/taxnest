<?php

namespace App\Http\Controllers;

use App\Models\ConsultantClientLink;
use App\Models\ConsultantInvite;
use App\Services\AuditLogService;
use App\Services\ConsultantService;
use Illuminate\Http\Request;

/**
 * Client-side consent management (company_admin only, DI web guard).
 * The client is ALWAYS in control: they generate invite codes, approve or
 * reject pending consultant requests, and can revoke access at any time.
 */
class CompanyConsultantController extends Controller
{
    public function index()
    {
        $company = auth()->user()->company;
        abort_unless($company && $company->product_type === 'di', 403, 'Digital Invoice companies only.');
        $companyId = $company->id;

        $links = ConsultantClientLink::with('consultant')
            ->where('company_id', $companyId)
            ->whereIn('status', ['pending', 'active'])
            ->orderByRaw("CASE WHEN status = 'pending' THEN 0 ELSE 1 END")
            ->orderByDesc('id')
            ->get();

        $invites = ConsultantInvite::where('company_id', $companyId)
            ->whereNull('used_at')
            ->whereNull('revoked_at')
            ->where('expires_at', '>', now())
            ->orderByDesc('id')
            ->get();

        return view('company.consultants', [
            'links' => $links,
            'invites' => $invites,
        ]);
    }

    /** Generate a single-use invite code (7-day expiry) — THE client-consent artifact. */
    public function createInvite(Request $request)
    {
        $user = auth()->user();

        $invite = ConsultantInvite::create([
            'company_id' => $user->company_id,
            'code' => ConsultantService::generateInviteCode(),
            'created_by_user_id' => $user->id,
            'expires_at' => now()->addDays(7),
        ]);

        AuditLogService::log('consultant_invite_created', 'ConsultantInvite', $invite->id, null, [
            'expires_at' => $invite->expires_at->toDateTimeString(),
        ], $user->company_id, $user->id);

        return redirect('/company/consultants')->with('success', "Invite code {$invite->code} created — share it with your tax consultant. Valid for 7 days, single use.");
    }

    /** Revoke an unused invite code. */
    public function revokeInvite(Request $request, ConsultantInvite $invite)
    {
        if ($invite->company_id !== auth()->user()->company_id) {
            abort(403);
        }

        if ($invite->used_at === null && $invite->revoked_at === null) {
            $invite->update(['revoked_at' => now()]);
            AuditLogService::log('consultant_invite_revoked', 'ConsultantInvite', $invite->id, null, null, $invite->company_id, auth()->id());
        }

        return redirect('/company/consultants')->with('success', 'Invite code revoked.');
    }

    /** Approve a pending consultant request — the explicit consent action. */
    public function approve(Request $request, ConsultantClientLink $link)
    {
        if ($link->company_id !== auth()->user()->company_id) {
            abort(403);
        }

        if (!ConsultantService::approveLink($link, auth()->user())) {
            return redirect('/company/consultants')->with('error', 'This request can no longer be approved.');
        }

        return redirect('/company/consultants')->with('success', 'Consultant access approved. You can revoke it at any time.');
    }

    /** Reject a pending consultant request. */
    public function reject(Request $request, ConsultantClientLink $link)
    {
        if ($link->company_id !== auth()->user()->company_id || $link->status !== 'pending') {
            abort(403);
        }

        ConsultantService::revokeLink($link, 'client', auth()->id());

        return redirect('/company/consultants')->with('success', 'Request rejected.');
    }

    /** Revoke an active consultant's access — takes effect within one request. */
    public function revokeLink(Request $request, ConsultantClientLink $link)
    {
        if ($link->company_id !== auth()->user()->company_id || $link->status !== 'active') {
            abort(403);
        }

        ConsultantService::revokeLink($link, 'client', auth()->id());

        return redirect('/company/consultants')->with('success', 'Consultant access revoked.');
    }
}

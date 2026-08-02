<?php

namespace App\Http\Controllers;

use App\Models\Company;
use App\Models\ConsultantClientLink;
use App\Models\ConsultantCommission;
use App\Models\User;
use App\Services\AuditLogService;
use App\Services\ConsultantService;
use Illuminate\Http\Request;

/**
 * Consultant Console — DI web guard only.
 *
 * One login, many clients: a consultant sees health signals for every
 * consented (active-link) client company and can switch into one with a
 * single click. The switch logs the client's admin user into the web guard
 * (the proven impersonation mechanics, adapted to a single guard) and is
 * watched request-by-request by ConsultantSwitchGuard.
 */
class ConsultantConsoleController extends Controller
{
    /** Console dashboard: clients + health chips, pending requests, add-client forms. */
    public function index()
    {
        $user = auth()->user();
        $profile = ConsultantService::profileFor($user->id);

        $clients = [];
        $pending = collect();
        $earnings = ['pending' => 0, 'paid' => 0];

        if ($profile) {
            $clients = ConsultantService::clientsWithHealth($user->id);

            $pending = ConsultantClientLink::with('company')
                ->where('consultant_user_id', $user->id)
                ->where('status', 'pending')
                ->orderByDesc('id')
                ->get()
                ->filter(fn ($l) => $l->company !== null);

            $earnings = [
                'pending' => (float) ConsultantCommission::where('consultant_user_id', $user->id)->where('status', 'pending')->sum('amount'),
                'paid' => (float) ConsultantCommission::where('consultant_user_id', $user->id)->where('status', 'paid')->sum('amount'),
            ];
        }

        return view('consultant.console', [
            'profile' => $profile,
            'clients' => $clients,
            'pendingLinks' => $pending,
            'earnings' => $earnings,
            'switched' => is_array(session(ConsultantService::SESSION_KEY)),
        ]);
    }

    /** Opt in as a consultant (any DI web user). */
    public function join(Request $request)
    {
        $profile = ConsultantService::activateProfile($request->user());

        if (!$profile->isActive()) {
            return redirect('/consultant')->with('error', 'Your consultant account is disabled. Please contact support.');
        }

        return redirect('/consultant')->with('success', 'You are now a consultant! Share your referral code to start earning.');
    }

    /** Redeem a client-generated invite code → active link (client consent already given). */
    public function redeem(Request $request)
    {
        $request->validate(['invite_code' => 'required|string|max:30']);

        $user = $request->user();
        if (!ConsultantService::activeProfileFor($user->id)) {
            return redirect('/consultant')->with('error', 'Activate your consultant profile first.');
        }

        $company = ConsultantService::redeemInvite($user, $request->invite_code);

        if (!$company) {
            return redirect('/consultant')->with('error', 'Invalid or expired invite code.');
        }

        return redirect('/consultant')->with('success', "Linked with {$company->name} — the client authorised this invite.");
    }

    /** Ask a client (by NTN) for access — creates a PENDING request only. */
    public function requestLink(Request $request)
    {
        $request->validate(['ntn' => 'required|string|max:50']);

        $user = $request->user();
        if (!ConsultantService::activeProfileFor($user->id)) {
            return redirect('/consultant')->with('error', 'Activate your consultant profile first.');
        }

        ConsultantService::requestLink($user, $request->ntn);

        // Deliberately generic — never confirms whether an NTN exists.
        return redirect('/consultant')->with('success', 'Request submitted. If the company exists on TaxNest, its admin will see your request for approval.');
    }

    /** Consultant cancels their own pending request. */
    public function cancel(Request $request, ConsultantClientLink $link)
    {
        if ($link->consultant_user_id !== auth()->id() || $link->status !== 'pending') {
            abort(403);
        }

        ConsultantService::revokeLink($link, 'consultant', auth()->id());

        return redirect('/consultant')->with('success', 'Request cancelled.');
    }

    /** Consultant-side revoke of an active link. */
    public function revoke(Request $request, ConsultantClientLink $link)
    {
        if ($link->consultant_user_id !== auth()->id() || $link->status !== 'active') {
            abort(403);
        }

        ConsultantService::revokeLink($link, 'consultant', auth()->id());

        return redirect('/consultant')->with('success', 'Client unlinked.');
    }

    /**
     * One-click switch into a linked client. Mirrors admin impersonation:
     * gate exactly what the panel middleware will enforce, log the client's
     * admin user into the web guard, rotate the session id, then set the flag
     * (the only memory of who the consultant is). Every switch is audited.
     */
    public function switchIn(Request $request, $companyId)
    {
        $user = auth()->user();

        if (session()->has(ConsultantService::SESSION_KEY)) {
            return redirect('/dashboard')->with('error', 'Already inside a client session — exit it first.');
        }

        $profile = ConsultantService::activeProfileFor($user->id);
        if (!$profile) {
            abort(403);
        }

        $link = ConsultantService::activeLink($user->id, (int) $companyId);
        if (!$link) {
            abort(403, 'No active link with this company.');
        }

        $company = Company::find((int) $companyId);

        // DI web guard only — and CompanyIsolation force-logs-out non-active
        // company_status, which would instantly bounce the session.
        if (!$company || $company->product_type !== 'di') {
            return redirect('/consultant')->with('error', 'Only Digital Invoice clients can be opened from the console.');
        }
        if ($company->company_status !== 'active') {
            return redirect('/consultant')->with('error', 'This client company is not active right now.');
        }

        $clientUser = User::where('company_id', $company->id)
            ->where('role', 'company_admin')
            ->where('is_active', true)
            ->orderBy('id')
            ->first();

        if (!$clientUser) {
            return redirect('/consultant')->with('error', 'This client has no active admin user to open a session with.');
        }

        // Audit BEFORE the identity changes — attributed to the consultant.
        AuditLogService::log('consultant_switch_in', 'Company', $company->id, null, [
            'consultant_user_id' => $user->id,
            'consultant_name' => $user->name,
            'client_user_id' => $clientUser->id,
        ], $company->id, $user->id);

        auth('web')->login($clientUser);
        $request->session()->regenerate(); // anti-fixation; keeps session data

        session([ConsultantService::SESSION_KEY => [
            'consultant_user_id' => $user->id,
            'consultant_name' => $user->name,
            'consultant_company_id' => $user->company_id,
            'client_user_id' => $clientUser->id,
            'client_company_id' => $company->id,
            'client_company_name' => $company->name,
        ]]);

        return redirect('/dashboard')->with('success', "You are now working inside {$company->name}.");
    }

    /**
     * Exit the client session and restore the consultant's own login.
     * Registered with 'auth' middleware ONLY (no company/approval gates) so
     * the exit always works — even if the client company was demoted while
     * the consultant was inside.
     */
    public function exitSwitch(Request $request)
    {
        $flag = $request->session()->get(ConsultantService::SESSION_KEY);

        if (!is_array($flag)) {
            return redirect('/dashboard');
        }

        $current = auth('web')->user();

        // The flag only means something while the client user is logged in.
        if (!$current || (int) $current->id !== (int) ($flag['client_user_id'] ?? 0)) {
            $request->session()->forget(ConsultantService::SESSION_KEY);
            return redirect('/dashboard');
        }

        $companyId = (int) ($flag['client_company_id'] ?? 0);
        $consultantId = (int) ($flag['consultant_user_id'] ?? 0);

        AuditLogService::log('consultant_switch_out', 'Company', $companyId, null, [
            'consultant_user_id' => $consultantId,
        ], $companyId, $consultantId);

        auth('web')->logout();
        $request->session()->forget(ConsultantService::SESSION_KEY);

        $consultant = User::find($consultantId);
        if ($consultant && $consultant->is_active) {
            auth('web')->login($consultant);
            $request->session()->regenerate();
            return redirect('/consultant')->with('success', 'Back in your own account.');
        }

        // Consultant account died mid-session — fail safe to a clean logout.
        $request->session()->invalidate();
        $request->session()->regenerateToken();

        return redirect('/login')->with('error', 'Consultant session ended.');
    }

    /** Earnings: commission ledger + totals + referral link. */
    public function earnings()
    {
        $user = auth()->user();
        $profile = ConsultantService::profileFor($user->id);

        if (!$profile) {
            return redirect('/consultant')->with('error', 'Activate your consultant profile first.');
        }

        $commissions = ConsultantCommission::where('consultant_user_id', $user->id)
            ->orderByDesc('id')
            ->paginate(25);

        $totals = [
            'pending' => (float) ConsultantCommission::where('consultant_user_id', $user->id)->where('status', 'pending')->sum('amount'),
            'paid' => (float) ConsultantCommission::where('consultant_user_id', $user->id)->where('status', 'paid')->sum('amount'),
            'referred' => Company::where('referred_by_user_id', $user->id)->count(),
            'clients' => ConsultantClientLink::where('consultant_user_id', $user->id)->where('status', 'active')->count(),
        ];

        return view('consultant.earnings', [
            'profile' => $profile,
            'commissions' => $commissions,
            'totals' => $totals,
        ]);
    }
}

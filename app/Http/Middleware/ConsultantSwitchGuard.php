<?php

namespace App\Http\Middleware;

use App\Models\Company;
use App\Models\ConsultantClientLink;
use App\Models\ConsultantProfile;
use App\Models\User;
use App\Services\AuditLogService;
use App\Services\ConsultantService;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Enforces consent for the consultant "switch into client" session on EVERY
 * request while the session flag is set (web group — runs after StartSession,
 * before route middleware like CompanyIsolation).
 *
 * Unlike admin impersonation (two guards, two providers), the consultant and
 * the client user share the ONE web guard: switching logs the client's admin
 * user in, and the flag is the only memory of who the consultant was. So:
 *
 *  - Flag valid ONLY while the logged-in web user IS the flag's client user.
 *    Any legitimate identity change (fresh login, logout) silently clears the
 *    flag — nothing can be misattributed to a switch that no longer exists.
 *  - Link revoked / consultant disabled / client company no longer active
 *    → forced exit within one request: restore the consultant's own login
 *    (or log out fully if the consultant account died), audit, redirect.
 */
class ConsultantSwitchGuard
{
    public function handle(Request $request, Closure $next): Response
    {
        $flag = $request->session()->get(ConsultantService::SESSION_KEY);
        if (!is_array($flag)) {
            return $next($request);
        }

        $webUser = auth('web')->user();

        // Identity changed (logout / different login) — the switch is over.
        if (!$webUser || (int) $webUser->id !== (int) ($flag['client_user_id'] ?? 0)) {
            $request->session()->forget(ConsultantService::SESSION_KEY);
            return $next($request);
        }

        // Consent still valid? Link active + consultant profile active + client company active.
        $consultantId = (int) ($flag['consultant_user_id'] ?? 0);
        $companyId = (int) ($flag['client_company_id'] ?? 0);

        $stillOk = ConsultantClientLink::where('consultant_user_id', $consultantId)
                ->where('company_id', $companyId)
                ->where('status', 'active')
                ->exists()
            && ConsultantProfile::where('user_id', $consultantId)
                ->where('status', 'active')
                ->exists()
            && Company::where('id', $companyId)
                ->where('company_status', 'active')
                ->exists();

        if ($stillOk) {
            return $next($request);
        }

        // Forced exit: consent is gone — end the client session NOW.
        auth('web')->logout();
        $request->session()->forget(ConsultantService::SESSION_KEY);

        AuditLogService::log('consultant_switch_out', 'Company', $companyId, null, [
            'forced' => true,
            'reason' => 'link_revoked_or_inactive',
            'consultant_user_id' => $consultantId,
        ], $companyId, $consultantId);

        $consultant = User::find($consultantId);
        if ($consultant && $consultant->is_active) {
            auth('web')->login($consultant);
            $request->session()->regenerate();
            return redirect('/consultant')
                ->with('error', 'Client access was revoked — you have been returned to your own account.');
        }

        $request->session()->invalidate();
        $request->session()->regenerateToken();

        return redirect('/login')->with('error', 'Consultant session ended.');
    }
}

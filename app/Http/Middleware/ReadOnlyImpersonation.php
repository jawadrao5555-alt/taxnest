<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * "View as Company" impersonation guard.
 *
 * Two impersonation modes share one session flag ('impersonation'):
 *   - VIEW-ONLY  (readonly === true):  every state-changing request inside the
 *     company panels (web / pos / fbrpos / health) is blocked.
 *   - FULL-ACCESS (readonly falsey):   writes pass through (and are audited by
 *     LogImpersonatedWrites); only identity-swaps are still blocked.
 *
 * In BOTH modes the demo-login GET authenticator is blocked so an impersonating
 * admin can never hop to a different company mid-session (tenant-isolation break).
 *
 * The admin panel (admin/*) is always allowed so the admin can keep working and,
 * crucially, so the exit / lock routes (admin/impersonation/*) stay reachable.
 *
 * Runs on the `web` middleware group AFTER StartSession — do NOT register it as a
 * global middleware (the global stack runs before sessions boot, so session() would
 * be empty and this guard would silently no-op).
 */
class ReadOnlyImpersonation
{
    public function handle(Request $request, Closure $next): Response
    {
        $imp = $request->session()->get('impersonation');

        // Not impersonating → behave normally.
        if (!is_array($imp)) {
            return $next($request);
        }

        // ORPHANED FLAG (owner report, 5 Sep 2026): the flag can outlive the admin
        // session that created it — that session expired, was cleared, or the browser
        // simply kept a stale cookie. Company access is only ever granted BY a live
        // admin session, so once that session is gone the impersonation is over:
        // close the panel login it opened (the panel's own auth middleware then sends
        // the visitor to its login page) and drop the flag.
        //
        // Leaving the flag in place was what locked the owner out: with no admin
        // behind it, the refusal below still bounced every login POST back to the
        // very page it came from, and no banner existed anywhere to press "Exit" on —
        // so signing in looked like it did nothing at all.
        if (!auth('admin')->check()) {
            $guard = $imp['guard'] ?? null;
            if ($guard && auth($guard)->check()) {
                auth($guard)->logout();
            }
            $request->session()->forget('impersonation');

            return $next($request);
        }

        $path = ltrim($request->path(), '/');

        // Admin panel is always allowed (admin guard actions + exit/lock routes).
        if ($path === 'admin' || str_starts_with($path, 'admin/')) {
            return $next($request);
        }

        $isWrite = !in_array($request->method(), ['GET', 'HEAD', 'OPTIONS'], true);

        // Identity / session paths blocked in ANY mode (view AND full-access) so an
        // impersonating admin can only ever leave via the banner's "Exit" button:
        //   - demo-login: a GET authenticator that would swap to another tenant.
        //   - panel login POSTs: would authenticate a DIFFERENT company into the
        //     panel while the impersonation flag still points at the ORIGINAL
        //     company_id — every later audit row would be misattributed.
        //   - panel logout POSTs: call session()->invalidate(), which would nuke the
        //     admin's own session mid-impersonation AND let the write escape the
        //     LogImpersonatedWrites audit trail.
        $isDemoLogin = $path === 'demo-login' || str_starts_with($path, 'demo-login/');
        $identityPaths = [
            'login', 'logout',
            'pos/login', 'pos/logout',
            'fbr-pos/login', 'fbr-pos/logout',
            // Healthcare ERP (Task 1547) — same rule, its own paths.
            'health/login', 'health/logout',
        ];
        $isIdentitySwap = $isDemoLogin || ($isWrite && in_array($path, $identityPaths, true));

        // View-only mode additionally blocks anything that can change state.
        $blockedWrite = !empty($imp['readonly']) && $isWrite;

        if ($isIdentitySwap || $blockedWrite) {
            $message = $isIdentitySwap
                ? 'You cannot switch or sign out of this account while acting as a company — use "Exit" to leave.'
                : 'View-only mode — you are viewing this company as admin. Changes are disabled.';

            if ($request->expectsJson() || $request->ajax()) {
                return response()->json(['error' => $message, 'view_only' => !empty($imp['readonly'])], 403);
            }

            // A refused identity swap means the visitor is sitting on a LOGIN page,
            // and back() would drop them on that same page — the very "nothing
            // happens, it just returns here" loop the owner hit. Send them to the
            // admin panel instead: that is the one surface that carries the banner
            // with the "Exit" button, i.e. the only legitimate way out.
            if ($isIdentitySwap) {
                return redirect('/admin/dashboard')->with('error', $message);
            }

            return redirect()->back()->with('error', $message);
        }

        return $next($request);
    }
}

<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

class PosAuth
{
    public function handle(Request $request, Closure $next)
    {
        if (!Auth::guard('pos')->check()) {
            return redirect('/pos/login');
        }

        $user = Auth::guard('pos')->user();

        if (!$user->is_active) {
            Auth::guard('pos')->logout();
            return redirect('/pos/login')->with('error', 'Your account has been deactivated.');
        }

        if (!$user->company_id) {
            Auth::guard('pos')->logout();
            return redirect('/pos/login')->with('error', 'No company associated with your account.');
        }

        $company = \App\Models\Company::find($user->company_id);
        if (!$company) {
            Auth::guard('pos')->logout();
            return redirect('/pos/login')->with('error', 'Company not found. Please contact admin.');
        }

        if ($company->product_type !== 'pos') {
            Auth::guard('pos')->logout();
            if ($company->product_type === 'fbrpos') {
                return redirect('/fbr-pos/login')->with('error', 'This is an FBR POS account. Please login from the FBR POS portal.');
            }
            return redirect('/login')->with('error', 'This account is not registered for NestPOS.');
        }

        app()->instance('currentCompanyId', $user->company_id);

        // ═══ Archive Viewer isolation ═══
        // Users with pos_role='archive_viewer' are confined to /pos/archive/* and
        // /pos/logout. They never see normal POS pages — any other /pos/* URL
        // is redirected back to the archive portal. POS admin/cashier panels never
        // expose this role (Team page filters it out).
        if (($user->pos_role ?? null) === 'archive_viewer') {
            $path = ltrim($request->path(), '/');
            $allowed = str_starts_with($path, 'pos/archive')
                // Tutorial videos (owner, 2 Aug 2026) — every role may learn.
                || str_starts_with($path, 'pos/tutorials')
                || $path === 'pos/logout'
                || $path === 'pos/login';
            if (!$allowed) {
                return $this->toPortal('/pos/archive');
            }
        } else {
            // Conversely, non-archive users cannot access archive routes.
            if (str_starts_with(ltrim($request->path(), '/'), 'pos/archive')) {
                abort(404);
            }
        }

        // ═══ Local Bills Viewer isolation ═══
        // Users with pos_role='local_viewer' are confined to /pos/local-bills/* and
        // /pos/logout — the surface where local (non-PRA) bills are visible.
        // POS ADMINS may also VIEW the portal (owner request Jul 2026) — the company
        // admin of a local-billing company must always see its local bills.
        // Cashiers and every other pos_role still get a 404 on these routes.
        if (($user->pos_role ?? null) === 'local_viewer') {
            $path = ltrim($request->path(), '/');
            $allowed = str_starts_with($path, 'pos/local-bills')
                // Tutorial videos (owner, 2 Aug 2026) — every role may learn.
                || str_starts_with($path, 'pos/tutorials')
                || $path === 'pos/logout'
                || $path === 'pos/login';
            if (!$allowed) {
                return $this->toPortal('/pos/local-bills');
            }
        } else {
            if (str_starts_with(ltrim($request->path(), '/'), 'pos/local-bills') && !$user->isPosAdmin()) {
                abort(404);
            }
        }

        // ═══ Kitchen account isolation (P5, F4) ═══
        // Users with pos_role='pos_kitchen' are confined to the Kitchen Display —
        // /pos/restaurant/kds* (board, kitchen-status, scan) + live-orders polling
        // + logout. Any other /pos/* URL redirects back to the KDS. They are
        // limit-EXEMPT team accounts created from the Team page.
        if (($user->pos_role ?? null) === 'pos_kitchen') {
            $path = ltrim($request->path(), '/');
            $allowed = str_starts_with($path, 'pos/restaurant/kds')
                || $path === 'pos/restaurant/api/live-orders'
                // KOT ticket view (GET) — needed by the KDS auto-print iframe (P6).
                || preg_match('#^pos/restaurant/orders/\d+/kitchen-ticket$#', $path)
                // Silent printing — KDS enqueues KOT jobs for the Desktop Agent.
                || $path === 'pos/api/print-jobs'
                // Tutorial videos (owner, 2 Aug 2026) — every role may learn.
                || str_starts_with($path, 'pos/tutorials')
                || $path === 'pos/logout'
                || $path === 'pos/login';
            if (!$allowed) {
                return $this->toPortal('/pos/restaurant/kds');
            }
        }

        // Delivery Rider accounts (Jul 2026) are confined to the Rider portal —
        // today's own deliveries + mark-delivered only. Limit-EXEMPT accounts
        // created from the Riders page. NOTE: exact-prefix match — /pos/riders
        // (admin CRUD) must NOT fall inside 'pos/rider' with a bare prefix test.
        if (($user->pos_role ?? null) === 'pos_rider') {
            $path = ltrim($request->path(), '/');
            $allowed = $path === 'pos/rider'
                || str_starts_with($path, 'pos/rider/')
                // Tutorial videos (owner, 2 Aug 2026) — every role may learn.
                || str_starts_with($path, 'pos/tutorials')
                || $path === 'pos/logout'
                || $path === 'pos/login';
            if (!$allowed) {
                return $this->toPortal('/pos/rider');
            }
        }

        // Delivery Manager accounts (owner, 20 Jul 2026) are confined to the
        // Deliveries board — rider assignment, delivery status, bulk actions
        // (/pos/deliveries*) + rider cash settlement (/pos/riders/{id}/settle).
        // Limit-EXEMPT team accounts created from the Team page. NOTE: settle
        // lives under /pos/riders/ — allow ONLY the settle POST, never the
        // rider CRUD pages (those stay admin/manager-only via PosAdminOnly).
        if (($user->pos_role ?? null) === 'pos_delivery') {
            $path = ltrim($request->path(), '/');
            $allowed = $path === 'pos/deliveries'
                || str_starts_with($path, 'pos/deliveries/')
                || preg_match('#^pos/riders/\d+/settle$#', $path)
                // Tutorial videos (owner, 2 Aug 2026) — every role may learn.
                || str_starts_with($path, 'pos/tutorials')
                || $path === 'pos/logout'
                || $path === 'pos/login';
            if (!$allowed) {
                return $this->toPortal('/pos/deliveries');
            }
        }

        // Waiter accounts (P7, F6) are confined to the Waiter Tablet — order
        // composing + send-to-cashier only. Limit-EXEMPT team accounts.
        if (($user->pos_role ?? null) === 'pos_waiter') {
            $path = ltrim($request->path(), '/');
            $allowed = str_starts_with($path, 'pos/waiter')
                // Per-user grid visibility prefs (owner, 25 Jul 2026) — waiters
                // may hide/show items on their OWN tablet grid.
                || str_starts_with($path, 'pos/grid-prefs')
                // Tutorial videos (owner, 2 Aug 2026) — every role may learn.
                // Exact match on purpose: future pos/tutorials/* sub-routes must
                // NOT be silently admitted into waiter confinement.
                || $path === 'pos/tutorials'
                || $path === 'pos/logout'
                || $path === 'pos/login';
            if (!$allowed) {
                return $this->toPortal('/pos/waiter');
            }
        }

        // ═══ POS Team Custom Access (Task #111, owner-approved 2 Aug 2026) ═══
        // Optional per-member feature grants set on /pos/team. Applies ONLY to
        // pos_cashier / pos_manager — the confined roles above keep their
        // path-prefix confinement (it SUPERSEDES custom grants), and the owner
        // (company_admin) can never be restricted. Paths that map to no feature
        // key (sale screen, invoice APIs, prefs, logout) are always reachable.
        $customAccess = \App\Services\PosAccessService::customSet($user);
        if ($customAccess !== null) {
            $path = ltrim($request->path(), '/');
            $feature = \App\Services\PosAccessService::featureForPath($path);
            if ($feature !== null && !in_array($feature, $customAccess, true)) {
                if ($request->expectsJson()) {
                    abort(403, __('pos.custom_access_denied'));
                }
                // Dashboard blocked too → land on the sale screen (unmapped path,
                // always allowed) so the redirect can never loop.
                $home = in_array('dashboard', $customAccess, true) ? '/pos/dashboard' : '/pos/invoice/create';
                return $this->toPortal($home)->with('error', __('pos.custom_access_denied'));
            }
        }

        // ═══ Staff Hazri heartbeat (owner batch, 26 Jul 2026) ═══
        // Throttled "last seen" stamp on the user's latest OPEN session row —
        // max ONE UPDATE per user per 5 minutes (cache flag), kabhi request
        // block nahi karta. Browser band / bijli gayi = logout_at NULL rehta
        // hai aur report last_activity_at ko hi aakhri waqt dikhati hai.
        try {
            // Task 705: khufia identity-switch ke dauran physically-present
            // staff = ASAL local cashier (session mein yaad). Heartbeat USI ki
            // open row par jaye — kabhi PRA counterpart ki row par nahi (woh
            // doosre PC par apni asli hazri row rakhta hai).
            // Task 1157: pos_hazri_user_id (set at real login, never at a switch
            // login) is the authoritative owner of this station's hazri row.
            // It is FIRST priority so that forward-after-reverse (local→PRA after
            // a PRA→local reverse switch) cannot override it via
            // pos_identity_original_id = local->id (local has no hazri row).
            // pos_identity_original_id stays as a legacy fallback for sessions
            // created before this key was introduced.
            $hazriUserId = (int) (session('pos_hazri_user_id') ?: session('pos_identity_original_id') ?: $user->id);
            $beatKey = 'pos_hazri_beat_' . $hazriUserId;
            if (!cache()->has($beatKey)) {
                cache()->put($beatKey, 1, 300);
                \Illuminate\Support\Facades\DB::table('pos_user_sessions')
                    ->where('user_id', $hazriUserId)
                    ->whereNull('logout_at')
                    ->orderByDesc('id')
                    ->limit(1)
                    ->update(['last_activity_at' => now(), 'updated_at' => now()]);
            }
        } catch (\Throwable $e) {
            // Table not migrated yet — ignore.
        }

        // Resolve & bind active branch (returns null if no branches exist yet).
        // NOTE: use bind() not instance() — instance(name, null) is treated as "not bound" by Laravel.
        $branchId = app(\App\Services\BranchContextService::class)->getActiveBranchId();
        app()->bind('currentBranchId', fn() => $branchId);
        view()->share('currentBranchId', $branchId);
        view()->share('currentBranch', $branchId ? \App\Models\Branch::find($branchId) : null);

        return $next($request);
    }

    /**
     * Send a signed-in staff member back to its own portal with a PATH-RELATIVE
     * redirect. The development preview reaches Laravel through a local HTTP
     * bridge while production forces HTTPS URLs; an absolute forced-HTTPS
     * Location would point the browser at TLS on the local PHP server port and
     * block a confined role right after a valid sign-in. Relative Location
     * headers are resolved against the current request by every browser, so
     * production behaviour is unchanged.
     */
    private function toPortal(string $path): \Illuminate\Http\RedirectResponse
    {
        return redirect()->away($path);
    }
}

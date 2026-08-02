<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;

class PosAdminOnly
{
    public function handle(Request $request, Closure $next)
    {
        $user = auth('pos')->user();
        if (!$user) {
            return redirect('/pos/login');
        }

        // POS Team Custom Access: for members WITH a custom set (cashier or
        // manager), this admin group is DENY-BY-DEFAULT — the path's feature
        // must be mapped AND ticked. Grants therefore EXPAND a cashier's reach
        // (Customize ticked → settings open) and RESTRICT a manager's (untick
        // Customize → even unmapped admin endpoints in this group are blocked).
        $customSet = \App\Services\PosAccessService::customSet($user);
        if ($customSet !== null) {
            $feature = \App\Services\PosAccessService::featureForPath($request->path());
            if ($feature === null || !in_array($feature, $customSet, true)) {
                if ($request->expectsJson()) {
                    abort(403, __('pos.custom_access_denied'));
                }
                return redirect()->route('pos.dashboard')->with('error', __('pos.custom_access_denied'));
            }
        } elseif ($user->isPosCashier()) {
            // No custom set → unchanged historical behavior.
            return redirect()->route('pos.dashboard')->with('error', 'Access denied. Admin privileges required.');
        }

        // Special isolated accounts can never reach POS admin pages.
        if (in_array($user->pos_role ?? null, ['archive_viewer', 'local_viewer'], true)) {
            return redirect($user->pos_role === 'local_viewer' ? '/pos/local-bills' : '/pos/archive');
        }

        return $next($request);
    }
}

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

        if ($user->isPosCashier()) {
            return redirect()->route('pos.dashboard')->with('error', 'Access denied. Admin privileges required.');
        }

        // Special isolated accounts can never reach POS admin pages.
        if (in_array($user->pos_role ?? null, ['archive_viewer', 'local_viewer'], true)) {
            return redirect($user->pos_role === 'local_viewer' ? '/pos/local-bills' : '/pos/archive');
        }

        return $next($request);
    }
}

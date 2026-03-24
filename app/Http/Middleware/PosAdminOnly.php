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

        return $next($request);
    }
}

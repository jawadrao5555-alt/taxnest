<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;

class FranchiseAuth
{
    public function handle(Request $request, Closure $next)
    {
        if (!auth('franchise')->check()) {
            return redirect('/franchise/login');
        }

        $franchise = auth('franchise')->user();
        if ($franchise->status !== 'active') {
            auth('franchise')->logout();
            return redirect('/franchise/login')->with('error', 'Your franchise account is suspended.');
        }

        return $next($request);
    }
}

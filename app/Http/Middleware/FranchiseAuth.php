<?php

namespace App\Http\Middleware;

use App\Services\FranchiseSession;
use App\Services\TwoStepLogin;
use Closure;
use Illuminate\Http\Request;

class FranchiseAuth
{
    public function handle(Request $request, Closure $next)
    {
        if (!auth('franchise')->check()) {
            return redirect('/franchise/login');
        }

        $franchise = auth('franchise')->user()->fresh();
        if (!$franchise || !$franchise->isActive()) {
            FranchiseSession::revokeCurrent($request);
            return redirect('/franchise/login')->with('error', 'Your franchise account is suspended.');
        }
        if (!FranchiseSession::matches($request, $franchise)) {
            FranchiseSession::revokeCurrent($request);
            return redirect('/franchise/login');
        }

        return TwoStepLogin::challenge($request, 'franchise') ?? $next($request);
    }
}

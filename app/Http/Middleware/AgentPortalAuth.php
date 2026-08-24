<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;

class AgentPortalAuth
{
    public function handle(Request $request, Closure $next)
    {
        $agent = auth('agent')->user();
        if (!$agent) {
            return redirect('/agent/login');
        }
        if (!$agent->is_active || !$agent->isActive()) {
            auth('agent')->logout();
            return redirect('/agent/login')->with('error', 'Your agent account is inactive.');
        }

        return $next($request);
    }
}
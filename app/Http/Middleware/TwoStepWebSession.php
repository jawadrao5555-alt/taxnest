<?php

namespace App\Http\Middleware;

use App\Services\TwoStepLogin;
use Closure;
use Illuminate\Http\Request;

/** Run after persisted session/delegation revocation, before protected handlers. */
final class TwoStepWebSession
{
    public function handle(Request $request, Closure $next)
    {
        if ($request->routeIs('consultant.exit')) {
            return $next($request);
        }
        foreach ($request->route()?->gatherMiddleware() ?? [] as $middleware) {
            if (is_string($middleware) && ($middleware === 'auth' || str_starts_with($middleware, 'auth:'))) {
                return TwoStepLogin::challenge($request, auth()->getDefaultDriver()) ?? $next($request);
            }
        }

        return $next($request);
    }
}

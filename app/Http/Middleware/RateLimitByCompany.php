<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Symfony\Component\HttpFoundation\Response;

class RateLimitByCompany
{
    public function handle(Request $request, Closure $next): Response
    {
        if (!auth()->check()) {
            return $next($request);
        }

        $user = auth()->user();

        if ($user->role === 'super_admin') {
            return $next($request);
        }

        $companyId = $user->company_id;
        if (!$companyId) {
            return $next($request);
        }

        $key = "rate_limit:company:{$companyId}";
        $maxRequests = 200;
        $decayMinutes = 1;

        $current = Cache::get($key, 0);

        if ($current >= $maxRequests) {
            return response()->json([
                'error' => 'Rate limit exceeded. Maximum ' . $maxRequests . ' requests per minute.',
            ], 429);
        }

        Cache::put($key, $current + 1, now()->addMinutes($decayMinutes));

        $response = $next($request);
        $response->headers->set('X-RateLimit-Limit', $maxRequests);
        $response->headers->set('X-RateLimit-Remaining', max(0, $maxRequests - $current - 1));

        return $response;
    }
}

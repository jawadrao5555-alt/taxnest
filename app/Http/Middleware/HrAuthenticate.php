<?php

namespace App\Http\Middleware;

use App\Services\TwoStepLogin;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

final class HrAuthenticate
{
    public function handle(Request $request, Closure $next): Response
    {
        if (!auth('hr')->user()?->fresh()?->is_active) {
            if ($request->expectsJson()) {
                abort(401);
            }

            return redirect()->route('hr.login');
        }

        $response = TwoStepLogin::challenge($request, 'hr') ?? $next($request);
        $response->headers->set('Cache-Control', 'private, no-store');

        return $response;
    }
}

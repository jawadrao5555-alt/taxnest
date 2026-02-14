<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class ForceHttps
{
    public function handle(Request $request, Closure $next): Response
    {
        if (app()->environment('production') && !$request->secure() && $request->header('x-forwarded-proto') !== 'https') {
            return redirect()->secure($request->getRequestUri());
        }
        return $next($request);
    }
}

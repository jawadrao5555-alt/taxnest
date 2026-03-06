<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class ForceHttps
{
    public function handle(Request $request, Closure $next): Response
    {
        $isLocal = in_array($request->getHost(), ['localhost', '127.0.0.1', '0.0.0.0']);
        if (!$isLocal && !$request->secure() && $request->header('x-forwarded-proto') !== 'https') {
            return redirect()->secure($request->getRequestUri());
        }
        return $next($request);
    }
}

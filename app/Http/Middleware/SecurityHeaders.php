<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class SecurityHeaders
{
    public function handle(Request $request, Closure $next): Response
    {
        $response = $next($request);

        $response->headers->set('X-Content-Type-Options', 'nosniff');
        $response->headers->set('X-XSS-Protection', '1; mode=block');
        $response->headers->set('Referrer-Policy', 'strict-origin-when-cross-origin');

        $contentType = $response->headers->get('Content-Type', '');
        if (str_contains($contentType, 'text/html')) {
            $response->headers->set('Cache-Control', 'no-cache, no-store, must-revalidate');
            $response->headers->set('Pragma', 'no-cache');
            $response->headers->set('Expires', '0');
        }

        if (app()->environment('production')) {
            $response->headers->set('Permissions-Policy', 'camera=(), microphone=(), geolocation=()');
            $response->headers->set('Content-Security-Policy',
                "default-src 'self' https:; " .
                "script-src 'self' 'unsafe-inline' 'unsafe-eval' https:; " .
                "style-src 'self' 'unsafe-inline' https:; " .
                "font-src 'self' https: data:; " .
                "img-src 'self' data: blob: https:; " .
                "connect-src 'self' https: wss:; " .
                "frame-ancestors *"
            );
        }

        return $response;
    }
}

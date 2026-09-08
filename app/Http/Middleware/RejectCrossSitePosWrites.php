<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Defence-in-depth for the cookie-session POS panel.
 *
 * `pos/*` is CSRF-exempt because the Android/WebView shells post with the
 * session cookie and cannot reliably attach X-CSRF-TOKEN. CSRF is therefore
 * not the control for this surface. This middleware rejects writes that a
 * modern browser has already labelled as cross-site (Sec-Fetch-Site) or that
 * carry an Origin host different from the application host.
 *
 * Requests without either header (Kotlin WebView, older WebViews, Desktop
 * Agent is NOT this surface) are allowed through — PosAuth + company scope
 * remain the authentication/tenant controls.
 */
class RejectCrossSitePosWrites
{
    public function handle(Request $request, Closure $next): Response
    {
        if (!$request->is('pos') && !$request->is('pos/*')) {
            return $next($request);
        }

        if (in_array($request->method(), ['GET', 'HEAD', 'OPTIONS'], true)) {
            return $next($request);
        }

        $site = strtolower((string) $request->header('Sec-Fetch-Site', ''));
        if ($site === 'cross-site') {
            abort(403, 'Cross-site POS write rejected.');
        }

        $origin = (string) $request->header('Origin', '');
        if ($origin !== '') {
            $originHost = parse_url($origin, PHP_URL_HOST);
            if (is_string($originHost) && $originHost !== '' && strcasecmp($originHost, $request->getHost()) !== 0) {
                abort(403, 'Cross-origin POS write rejected.');
            }
        }

        return $next($request);
    }
}

<?php

namespace App\Http\Middleware;

use App\Services\RestoreSessionEpoch;
use App\Support\NestErps;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/** Refuse resurrected browser sessions without touching stateless Agent/API pairing. */
final class RestoreSessionBoundary
{
    public function handle(Request $request, Closure $next): Response
    {
        if (! $request->hasSession()) {
            return $next($request); // Stateless reporting/Agent tokens have independent quarantine.
        }
        $authenticated = [];
        foreach (config('auth.guards', []) as $name => $definition) {
            if (($definition['driver'] ?? null) === 'session' && auth($name)->check()) {
                $authenticated[] = $name;
            }
        }
        if (! $authenticated) {
            return $next($request); // Public/guest paths need no restore-ledger query.
        }
        $current = RestoreSessionEpoch::current();
        $issued = $request->session()->get(RestoreSessionEpoch::KEY, 'legacy');
        if (is_string($issued) && hash_equals($current, $issued)) {
            return $next($request);
        }
        foreach ($authenticated as $name) {
            auth($name)->logoutCurrentDevice();
        }
        // A restore is deliberately global, unlike ordinary per-product resets.
        $request->session()->invalidate();
        $request->session()->regenerateToken();
        if ($request->expectsJson()) {
            return response()->json(['error' => 'Please sign in again after system recovery.'], 401);
        }
        $path = ltrim($request->path(), '/');
        $login = '/login';
        foreach (['admin' => '/admin/login', 'franchise' => '/franchise/login', 'pos' => '/pos/login', 'fbr-pos' => '/fbr-pos/login'] as $prefix => $destination) {
            if ($path === $prefix || str_starts_with($path, $prefix.'/')) {
                $login = $destination;
            }
        }
        foreach (NestErps::VERTICALS as $vertical) {
            if (str_starts_with($path, $vertical['path_prefix'].'/')) {
                $login = $vertical['login_path'];
            }
        }

        return redirect($login);
    }
}

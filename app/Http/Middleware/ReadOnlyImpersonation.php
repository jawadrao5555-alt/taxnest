<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * VIEW-ONLY "View as Company" enforcement.
 *
 * When a super-admin is impersonating a company in read-only mode
 * (session('impersonation.readonly') === true), every state-changing request
 * inside the company panels (web / pos / fbrpos) is blocked. The admin panel
 * itself (admin/*) is always allowed so the admin can keep working and, crucially,
 * so the "Exit view-only" route (admin/impersonation/stop) is reachable.
 *
 * Runs on the `web` middleware group AFTER StartSession — do NOT register it as a
 * global middleware (the global stack runs before sessions boot, so session() would
 * be empty and this guard would silently no-op).
 */
class ReadOnlyImpersonation
{
    public function handle(Request $request, Closure $next): Response
    {
        $imp = $request->session()->get('impersonation');

        // No active read-only impersonation → behave normally.
        if (!is_array($imp) || empty($imp['readonly'])) {
            return $next($request);
        }

        $path = ltrim($request->path(), '/');

        // Admin panel is always allowed (admin guard actions + the exit route).
        if ($path === 'admin' || str_starts_with($path, 'admin/')) {
            return $next($request);
        }

        // Reads are fine; anything that can change state is blocked.
        $isWrite = !in_array($request->method(), ['GET', 'HEAD', 'OPTIONS'], true);

        // demo-login is a GET that authenticates a user — treat it as a write so
        // an impersonating admin can never swap identity mid-session.
        $isDemoLogin = $path === 'demo-login' || str_starts_with($path, 'demo-login/');

        if ($isWrite || $isDemoLogin) {
            $message = 'View-only mode — you are viewing this company as admin. Changes are disabled.';

            if ($request->expectsJson() || $request->ajax()) {
                return response()->json(['error' => $message, 'view_only' => true], 403);
            }

            return redirect()->back()->with('error', $message);
        }

        return $next($request);
    }
}

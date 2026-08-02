<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\App;

/**
 * Sets the app locale for POS / FBR POS panel users.
 * Guard is chosen by the ACTIVE panel (URL prefix) — never a blind pos??fbrpos
 * fallback, because both guards can be authenticated in one browser session.
 * Priority: user's own choice (users.language) → company default → 'ur'.
 * 'ur' = Roman Urdu, 'en' = pure English. No-ops for guests / other panels.
 * Appended to the web group (must run AFTER StartSession, like ReadOnlyImpersonation).
 */
class SetPosLocale
{
    public function handle(Request $request, Closure $next)
    {
        try {
            $guard = null;
            if ($request->is('fbr-pos') || $request->is('fbr-pos/*')) {
                $guard = 'fbrpos';
            } elseif ($request->is('pos') || $request->is('pos/*')) {
                $guard = 'pos';
            }
            if ($guard) {
                $user = auth()->guard($guard)->user();
                if ($user) {
                    // ?? null guards: on owner's cPanel PROD, columns can lag a deploy
                    // (schema-drift history) — missing attribute must not 500 the panel.
                    $lang = $user->language ?? null;
                    if (!$lang) {
                        $lang = $user->company->default_language ?? null;
                    }
                    $lang = in_array($lang, ['ur', 'en'], true) ? $lang : 'ur';
                    App::setLocale($lang);
                    // Remember the resolved language so guest pages (login /
                    // register after logout) keep following the user's choice.
                    if ($request->hasSession()) {
                        $request->session()->put('pos_locale', $lang);
                    }
                } else {
                    // Guest (login / register / forgot password): no user pref
                    // yet — use last-known session language, else browser hint,
                    // else Roman Urdu default (same default as logged-in users).
                    $lang = $request->hasSession() ? $request->session()->get('pos_locale') : null;
                    if (!in_array($lang, ['ur', 'en'], true)) {
                        $lang = $request->getPreferredLanguage(['ur', 'en']) ?: 'ur';
                    }
                    App::setLocale(in_array($lang, ['ur', 'en'], true) ? $lang : 'ur');
                }
            }
        } catch (\Throwable $e) {
            // Never let locale resolution break a request.
        }
        return $next($request);
    }
}

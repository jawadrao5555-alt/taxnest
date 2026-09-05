<?php

namespace App\Http\Middleware;

use App\Support\PosLocale;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\App;

/**
 * Sets the app locale for POS / FBR POS panel users.
 * Guard is chosen by the ACTIVE panel (URL prefix) — never a blind pos??fbrpos
 * fallback, because both guards can be authenticated in one browser session.
 * Priority: user's own choice (users.language) → company default → 'rur'.
 * Three locales (Aug 2026): 'en' English, 'rur' Roman Urdu (default),
 * 'ur' real Urdu script. No-ops for guests / other panels.
 * Appended to the web group (must run AFTER StartSession, like ReadOnlyImpersonation).
 */
class SetPosLocale
{
    public function handle(Request $request, Closure $next)
    {
        try {
            // Forgot/reset-password flow lives at root paths (no /pos prefix)
            // and is shared with DI users. Follow the last-known POS/FBR POS
            // session language ONLY when one exists — never browser-hint or
            // default to 'rur' here, or DI (English) visitors would flip.
            if ($request->is('forgot-password') || $request->is('verify-otp')
                || $request->is('reset-password') || $request->is('reset-password-link')) {
                $lang = $request->hasSession() ? $request->session()->get(PosLocale::SESSION_KEY) : null;
                if (PosLocale::isValid($lang)) {
                    App::setLocale($lang);
                }
                return $next($request);
            }

            $guard = null;
            if ($request->is('fbr-pos') || $request->is('fbr-pos/*')) {
                $guard = 'fbrpos';
            } elseif ($request->is('pos') || $request->is('pos/*')) {
                $guard = 'pos';
            } elseif ($erpsVertical = \App\Support\NestErps::verticalForPath($request->path())) {
                // Nest ERPS panels (Task 1568) — same three locales, same
                // guard-by-URL-prefix rule: never a blind fallback, because a
                // browser can hold a POS and a Nest ERPS session at once.
                // Derived from the vertical registry, so a future vertical is
                // covered the day it registers its prefix and guard.
                $guard = \App\Support\NestErps::guardFor($erpsVertical);
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
                    $lang = PosLocale::normalize($lang);
                    App::setLocale($lang);
                    // Remember the resolved language so guest pages (login /
                    // register after logout) keep following the user's choice.
                    if ($request->hasSession()) {
                        $request->session()->put(PosLocale::SESSION_KEY, $lang);
                    }
                } else {
                    // Guest (login / register / forgot password): no user pref
                    // yet — use last-known session language, else browser hint,
                    // else Roman Urdu default (same default as logged-in users).
                    $lang = $request->hasSession() ? $request->session()->get(PosLocale::SESSION_KEY) : null;
                    if (!PosLocale::isValid($lang)) {
                        // Browser 'ur' hint = an Urdu speaker with no saved
                        // choice — give them Roman Urdu (the product default),
                        // never script uninvited.
                        $hint = $request->getPreferredLanguage(['en', 'ur']);
                        $lang = $hint === 'en' ? 'en' : PosLocale::DEFAULT;
                    }
                    $lang = PosLocale::normalize($lang);
                    App::setLocale($lang);
                    // Persist so the root-level forgot/reset-password pages
                    // (linked from the login screen) follow the same language.
                    if ($request->hasSession()) {
                        $request->session()->put(PosLocale::SESSION_KEY, $lang);
                    }
                }
            }
        } catch (\Throwable $e) {
            // Never let locale resolution break a request.
        }
        return $next($request);
    }
}

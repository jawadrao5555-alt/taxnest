<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class AgentPortalAuthController extends Controller
{
    /** Commission money sits behind this form, so treat it like any other login. */
    private const MAX_ATTEMPTS = 5;
    private const DECAY_SECONDS = 60;

    public function showLogin()
    {
        return response('Not Found', 404);
    }

    public function login(Request $request)
    {
        // Distributors are referral-only records. Historical credentials remain
        // in storage for audit/backward compatibility, but can never establish
        // a session or expose company/commission data.
        return response('Not Found', 404);

        $credentials = $request->validate([
            'email' => ['required', 'email'],
            'password' => ['required', 'string'],
        ]);

        $key = $this->throttleKey($request);

        if (RateLimiter::tooManyAttempts($key, self::MAX_ATTEMPTS)) {
            $seconds = RateLimiter::availableIn($key);
            throw ValidationException::withMessages([
                'email' => "Too many login attempts. Please try again in {$seconds} seconds.",
            ]);
        }

        $credentials['is_active'] = true;
        $credentials['status'] = 'active';
        if (Auth::guard('agent')->attempt($credentials, $request->boolean('remember'))) {
            RateLimiter::clear($key);
            $request->session()->regenerate();
            return redirect()->intended(route('agent.dashboard'));
        }

        RateLimiter::hit($key, self::DECAY_SECONDS);

        return back()->withErrors(['email' => 'Invalid credentials or inactive account.'])->onlyInput('email');
    }

    private function throttleKey(Request $request): string
    {
        return 'agent-login|' . Str::lower((string) $request->input('email')) . '|' . $request->ip();
    }

    public function logout(Request $request)
    {
        return response('Not Found', 404);
    }
}
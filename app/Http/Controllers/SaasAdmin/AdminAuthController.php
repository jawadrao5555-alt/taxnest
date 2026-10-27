<?php

namespace App\Http\Controllers\SaasAdmin;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Str;

class AdminAuthController extends Controller
{
    public function showLogin()
    {
        if (auth('admin')->check()) {
            return redirect('/admin/dashboard');
        }
        return view('admin.login');
    }

    public function login(Request $request)
    {
        $request->validate([
            'email' => 'required|email',
            'password' => 'required',
        ]);

        // Hash identities in cache keys; casing must not provide a new budget.
        // The second budget also limits attempts spread across many identities.
        $identityKey = 'admin-login:identity:'.hash('sha256', Str::lower(trim($request->string('email')->toString())).'|'.$request->ip());
        $ipKey = 'admin-login:ip:'.hash('sha256', (string) $request->ip());
        $wait = 0;
        foreach ([$identityKey => 5, $ipKey => 20] as $key => $limit) {
            if (RateLimiter::tooManyAttempts($key, $limit)) {
                $wait = max($wait, RateLimiter::availableIn($key));
            }
        }
        if ($wait > 0) {
            return back()->withErrors(['email' => "Too many login attempts. Please try again in {$wait} seconds."])->onlyInput('email');
        }

        if (Auth::guard('admin')->attempt($request->only('email', 'password'), $request->boolean('remember'))) {
            RateLimiter::clear($identityKey);
            $request->session()->regenerate();
            return redirect()->intended('/admin/dashboard');
        }

        RateLimiter::hit($identityKey, 60);
        RateLimiter::hit($ipKey, 60);
        return back()->withErrors(['email' => 'Invalid credentials.'])->onlyInput('email');
    }

    public function logout(Request $request)
    {
        Auth::guard('admin')->logout();
        $request->session()->invalidate();
        $request->session()->regenerateToken();
        return redirect('/');
    }
}

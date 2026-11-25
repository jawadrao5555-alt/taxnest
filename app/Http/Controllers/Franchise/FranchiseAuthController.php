<?php

namespace App\Http\Controllers\Franchise;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Str;

class FranchiseAuthController extends Controller
{
    public function showLogin()
    {
        if (auth('franchise')->check()) {
            return redirect('/franchise/dashboard');
        }
        return view('franchise.login');
    }

    public function login(Request $request)
    {
        $request->validate([
            'email' => 'required|email',
            'password' => 'required',
        ]);

        $email = Str::lower(trim($request->string('email')->toString()));
        $ip = (string) $request->ip();
        $identityKey = 'franchise-login:identity:'.hash('sha256', $email.'|'.$ip);
        $ipKey = 'franchise-login:ip:'.hash('sha256', $ip);
        $wait = 0;
        foreach ([$identityKey => 5, $ipKey => 20] as $key => $limit) {
            if (RateLimiter::tooManyAttempts($key, $limit)) {
                $wait = max($wait, RateLimiter::availableIn($key));
            }
        }
        if ($wait > 0) {
            return back()->withErrors(['email' => "Too many login attempts. Please try again in {$wait} seconds."])->onlyInput('email');
        }

        if (Auth::guard('franchise')->attempt($request->only('email', 'password'), $request->boolean('remember'))) {
            RateLimiter::clear($identityKey);
            $request->session()->regenerate();
            return redirect('/franchise/dashboard');
        }

        RateLimiter::hit($identityKey, 60);
        RateLimiter::hit($ipKey, 60);
        return back()->withErrors(['email' => 'Invalid credentials.'])->onlyInput('email');
    }

    public function logout(Request $request)
    {
        Auth::guard('franchise')->logout();
        $request->session()->invalidate();
        $request->session()->regenerateToken();
        return redirect('/franchise/login');
    }
}

<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Http\Requests\Auth\LoginRequest;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\View\View;

class AuthenticatedSessionController extends Controller
{
    /**
     * Display the login view.
     */
    public function create(): View
    {
        return view('auth.login');
    }

    /**
     * Handle an incoming authentication request.
     */
    public function store(LoginRequest $request): RedirectResponse
    {
        $request->authenticate();

        $request->session()->regenerate();

        return redirect()->intended(route('dashboard', absolute: false));
    }

    public function demoLogin(Request $request, string $role): RedirectResponse
    {
        $credentials = match($role) {
            'super_admin' => ['email' => 'admin@test.com', 'password' => 'admin123'],
            'company_admin' => ['email' => 'company_admin@test.com', 'password' => 'admin123'],
            'demo' => ['email' => 'demo@taxnest.pk', 'password' => 'admin123'],
            default => null,
        };

        if (!$credentials || !Auth::attempt($credentials)) {
            return redirect('/login')->with('error', 'Demo login failed.');
        }

        $request->session()->regenerate();
        return redirect()->intended(route('dashboard', absolute: false));
    }

    /**
     * Destroy an authenticated session.
     */
    public function destroy(Request $request): RedirectResponse
    {
        Auth::guard('web')->logout();

        $request->session()->invalidate();

        $request->session()->regenerateToken();

        return redirect('/');
    }
}

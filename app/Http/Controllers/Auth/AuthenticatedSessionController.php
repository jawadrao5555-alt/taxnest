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
    public function create(): View|RedirectResponse
    {
        return view('landing', ['showLogin' => true]);
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
        $demoUsers = [
            'super_admin' => 'admin@test.com',
            'company_admin' => 'company_admin@test.com',
            'demo' => 'demo@taxnest.pk',
        ];

        $email = $demoUsers[$role] ?? null;
        if (!$email) {
            return redirect('/login')->with('error', 'Invalid demo role.');
        }

        $user = \App\Models\User::where('email', $email)->first();
        if (!$user) {
            return redirect('/login')->with('error', 'Demo user not found. Please run database seeder.');
        }

        Auth::login($user);
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

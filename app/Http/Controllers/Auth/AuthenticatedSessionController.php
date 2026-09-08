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
        // Same public-only package list the landing route uses.
        $plans = \App\Services\DiPlanComparisonService::plans();
        return view('di-landing', ['showLogin' => true, 'plans' => $plans]);
    }

    /**
     * Handle an incoming authentication request.
     */
    public function store(LoginRequest $request): RedirectResponse
    {
        $request->authenticate();

        // PHASE 3 — Admin universal redirect (admin succeeded inside LoginRequest::authenticate)
        if (session()->pull('admin_login_redirect')) {
            $request->session()->regenerate();
            return redirect('/admin/dashboard');
        }

        // STRICT ISOLATION: LoginRequest now refuses non-DI users with generic
        // "Invalid credentials". No cross-product redirects here — clean DI flow only.
        $request->session()->regenerate();

        return redirect()->intended(route('dashboard', absolute: false));
    }

    /**
     * Local-development shortcut into a seeded demo tenant.
     *
     * Fails closed: 404 unless the app runs in the `local` environment AND
     * DEMO_LOGIN_ENABLED=true. Platform-level roles (users.role = super_admin)
     * are never offered, and a seeded account that carries such a role is
     * refused even when the switch is on, so this path can never grant a
     * CompanyScope / RoleMiddleware bypass.
     */
    public const DEMO_ROLES = [
        'company_admin' => 'company_admin@test.com',
        'demo' => 'demo@taxnest.pk',
    ];

    public static function demoLoginEnabled(): bool
    {
        return app()->environment('local') && (bool) config('app.demo_login_enabled', false);
    }

    public function demoLogin(Request $request, string $role): RedirectResponse
    {
        abort_unless(self::demoLoginEnabled(), 404);

        $email = self::DEMO_ROLES[$role] ?? null;
        if (!$email) {
            abort(404);
        }

        $user = \App\Models\User::where('email', $email)->first();
        if (!$user) {
            return redirect('/login')->with('error', 'Demo user not found. Please run database seeder.');
        }

        if ($user->role === 'super_admin' || !$user->company_id) {
            abort(404);
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

<?php

namespace App\Http\Controllers;

use App\Models\User;
use App\Models\Company;
use App\Models\AdminUser;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Str;
use Illuminate\Validation\Rules;

class FbrPosAuthController extends Controller
{
    public function showLogin()
    {
        if (Auth::guard('fbrpos')->check()) {
            return redirect('/fbr-pos/dashboard');
        }
        return view('fbr-pos.auth.login');
    }

    public function login(Request $request)
    {
        $request->validate([
            'login' => 'required|string',
            'password' => 'required|string',
        ]);

        $throttleKey = Str::transliterate(Str::lower($request->input('login')) . '|fbrpos|' . $request->ip());
        if (RateLimiter::tooManyAttempts($throttleKey, 5)) {
            $seconds = RateLimiter::availableIn($throttleKey);
            return back()->withErrors([
                'login' => "Too many login attempts. Please try again in {$seconds} seconds.",
            ])->withInput($request->only('login'));
        }

        $login = trim($request->login);
        $user = null;

        if (filter_var($login, FILTER_VALIDATE_EMAIL)) {
            $user = User::where('email', $login)->first();
        } elseif (preg_match('/^\d{10,13}$/', preg_replace('/\D/', '', $login))) {
            $phone = preg_replace('/\D/', '', $login);
            $user = User::where('phone', $phone)->first();
            if (!$user) {
                $company = Company::where('ntn', $login)->orWhere('cnic', $login)->first();
                if ($company) {
                    $user = User::where('company_id', $company->id)->where('role', 'company_admin')->orderBy('id')->first();
                }
            }
        } else {
            $user = User::where('username', $login)->first();
        }

        if ($user && Hash::check($request->password, $user->password)) {
            $company = Company::find($user->company_id);

            if ($company && $company->product_type === 'di') {
                RateLimiter::hit($throttleKey);
                return back()->withErrors([
                    'login' => 'This is a Digital Invoice account. Please login from the Digital Invoice portal.',
                ])->withInput($request->only('login'));
            }

            if ($company && $company->product_type === 'pos') {
                RateLimiter::hit($throttleKey);
                return back()->withErrors([
                    'login' => 'This is a PRA POS account. Please login from the NestPOS portal.',
                ])->withInput($request->only('login'));
            }

            if (!$company || !$company->fbr_pos_enabled || $company->product_type !== 'fbrpos') {
                RateLimiter::hit($throttleKey);
                return back()->withErrors([
                    'login' => 'FBR POS is not enabled for your company. Please contact admin.',
                ])->withInput($request->only('login'));
            }

            RateLimiter::clear($throttleKey);
            Auth::guard('fbrpos')->login($user, $request->boolean('remember'));
            $request->session()->regenerate();
            $request->session()->forget('url.intended');
            return redirect('/fbr-pos/dashboard');
        }

        if (filter_var($login, FILTER_VALIDATE_EMAIL)) {
            $admin = AdminUser::where('email', $login)->first();
            if ($admin && Hash::check($request->password, $admin->password)) {
                RateLimiter::clear($throttleKey);
                Auth::guard('admin')->login($admin, $request->boolean('remember'));
                $request->session()->regenerate();
                return redirect('/admin/dashboard');
            }
        }

        RateLimiter::hit($throttleKey);

        return back()->withErrors([
            'login' => 'These credentials do not match our records.',
        ])->withInput($request->only('login'));
    }

    public function showRegister()
    {
        if (Auth::guard('fbrpos')->check()) {
            return redirect('/fbr-pos/dashboard');
        }
        return view('fbr-pos.auth.register');
    }

    public function register(Request $request)
    {
        $request->validate([
            'company_name' => 'required|string|max:255',
            'company_ntn' => 'required|string|max:50|unique:companies,ntn',
            'name' => 'required|string|max:255',
            'email' => 'required|string|email|max:255|unique:users',
            'phone' => 'nullable|string|max:20',
            'password' => ['required', 'confirmed', Rules\Password::defaults()],
        ]);

        $company = Company::create([
            'name' => $request->company_name,
            'ntn' => $request->company_ntn,
            'email' => $request->email,
            'phone' => $request->phone,
            'company_status' => 'pending',
            'product_type' => 'fbrpos',
            'fbr_pos_enabled' => true,
            'fbr_pos_environment' => 'sandbox',
            'fbr_reporting_enabled' => true,
        ]);

        $user = User::create([
            'name' => $request->name,
            'email' => $request->email,
            'phone' => $request->phone,
            'password' => Hash::make($request->password),
            'company_id' => $company->id,
            'role' => 'company_admin',
            'is_active' => true,
        ]);

        Auth::guard('fbrpos')->login($user);

        return redirect('/fbr-pos/dashboard');
    }

    public function logout(Request $request)
    {
        Auth::guard('fbrpos')->logout();
        $request->session()->invalidate();
        $request->session()->regenerateToken();
        return redirect('/fbr-pos-landing');
    }
}

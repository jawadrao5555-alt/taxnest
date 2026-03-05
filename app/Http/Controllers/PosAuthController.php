<?php

namespace App\Http\Controllers;

use App\Models\User;
use App\Models\Company;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\Rules;

class PosAuthController extends Controller
{
    public function showLogin()
    {
        if (Auth::guard('pos')->check()) {
            return redirect('/pos/dashboard');
        }
        return view('pos.auth.login');
    }

    public function login(Request $request)
    {
        $request->validate([
            'login' => 'required|string',
            'password' => 'required|string',
        ]);

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
            Auth::guard('pos')->login($user, $request->boolean('remember'));
            $request->session()->regenerate();
            return redirect()->intended('/pos/dashboard');
        }

        return back()->withErrors([
            'login' => 'These credentials do not match our records.',
        ])->withInput($request->only('login'));
    }

    public function showRegister()
    {
        if (Auth::guard('pos')->check()) {
            return redirect('/pos/dashboard');
        }
        return view('pos.auth.register');
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
            'pra_reporting_enabled' => false,
            'pra_environment' => 'sandbox',
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

        Auth::guard('pos')->login($user);

        return redirect('/pos/dashboard');
    }

    public function logout(Request $request)
    {
        Auth::guard('pos')->logout();
        $request->session()->invalidate();
        $request->session()->regenerateToken();
        return redirect('/pos');
    }
}

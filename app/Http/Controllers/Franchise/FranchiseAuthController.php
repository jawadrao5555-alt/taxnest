<?php

namespace App\Http\Controllers\Franchise;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

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

        if (Auth::guard('franchise')->attempt($request->only('email', 'password'), $request->boolean('remember'))) {
            $request->session()->regenerate();
            return redirect('/franchise/dashboard');
        }

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

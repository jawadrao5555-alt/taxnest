<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Auth\Events\PasswordReset;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Illuminate\Validation\Rules;
use Illuminate\View\View;

class NewPasswordController extends Controller
{
    public function create(Request $request): View|RedirectResponse
    {
        $token = $request->query('token');
        $email = $request->query('email');

        if (!$token || !$email || session('password_reset_token') !== $token || session('password_reset_email') !== $email) {
            return redirect()->route('password.request')->withErrors(['email' => 'Invalid or expired reset session. Please start again.']);
        }

        return view('auth.reset-password', ['email' => $email, 'token' => $token]);
    }

    public function store(Request $request): RedirectResponse
    {
        $request->validate([
            'token' => ['required'],
            'email' => ['required', 'email'],
            'password' => ['required', 'confirmed', Rules\Password::defaults()],
        ]);

        if (session('password_reset_token') !== $request->token || session('password_reset_email') !== $request->email) {
            return redirect()->route('password.request')->withErrors(['email' => 'Invalid or expired reset session. Please start again.']);
        }

        $user = User::where('email', $request->email)->first();
        if (!$user) {
            return back()->withErrors(['email' => 'User not found.']);
        }

        $fill = [
            'password' => Hash::make($request->password),
            'remember_token' => Str::random(60),
        ];
        // POS team roles keep the admin-viewable encrypted copy in sync
        // (owner, Jul 2026) so /pos/team never shows a stale password after a
        // forgot-password reset. Drift-safe: only when the column exists.
        if (in_array($user->pos_role, ['pos_cashier', 'pos_manager', 'pos_kitchen', 'pos_waiter'], true)
            && \Illuminate\Support\Facades\Schema::hasColumn('users', 'pos_team_password_enc')) {
            $fill['pos_team_password_enc'] = \Illuminate\Support\Facades\Crypt::encryptString($request->password);
        }
        $user->forceFill($fill)->save();

        event(new PasswordReset($user));

        session()->forget(['password_reset_token', 'password_reset_email']);

        return redirect()->route('login')->with('status', 'Your password has been reset successfully. Please login with your new password.');
    }
}

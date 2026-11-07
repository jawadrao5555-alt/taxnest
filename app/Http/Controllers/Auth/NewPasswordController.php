<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Auth\Events\PasswordReset;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\DB;
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
            return redirect()->route('password.request')->withErrors(['email' => __('pos.auth_session_invalid')]);
        }

        return view('auth.reset-password', ['email' => $email, 'token' => $token]);
    }

    public function store(Request $request): RedirectResponse
    {
        $request->validate([
            'token' => ['required'],
            'email' => ['required', 'email'],
            'password' => ['required', 'confirmed', Rules\Password::defaults()],
        ], [
            'email.required' => __('pos.auth_val_email_required'),
            'email.email' => __('pos.auth_val_email_email'),
            'password.required' => __('pos.auth_val_password_required'),
            'password.confirmed' => __('pos.auth_val_password_confirmed'),
        ]);

        if (session('password_reset_token') !== $request->token || session('password_reset_email') !== $request->email) {
            return redirect()->route('password.request')->withErrors(['email' => __('pos.auth_session_invalid')]);
        }

        // The reset belongs to ONE product line (5 Sep 2026). The same email
        // may hold a PRA POS, an FBR POS and a Digital Invoice account, and
        // the two the visitor did not ask for must stay untouched.
        $product = session('password_reset_product');
        $user = DB::transaction(function () use ($product, $request) {
            $grant = DB::table('password_reset_otps')->where('id', session('password_reset_record'))
                ->where('email', $request->email)->where('used', true)
                ->whereNull('reset_completed_at')->where('expires_at', '>', now())
                ->lockForUpdate()->first();
            if (!$grant || !$grant->reset_grant_hash
                || !hash_equals($grant->reset_grant_hash, hash('sha256', $request->token))
                || (($grant->product_type ?? null) !== null && $grant->product_type !== $product)) {
                return null;
            }
            $user = $product === null
                ? User::where('email', $request->email)->first()
                : \App\Support\IdentityScope::findUserByEmail($request->email, $product);
            if (!$user) return null;
            $user = User::whereKey($user->id)->lockForUpdate()->firstOrFail();

            $fill = ['password' => Hash::make($request->password), 'remember_token' => Str::random(60)];
            // Preserve the existing owner-approved viewable-password policy.
            if (\App\Services\ViewablePasswordService::supports($user->pos_role)) {
                $fill = \App\Services\ViewablePasswordService::apply($fill, $request->password);
            }
            $user->forceFill($fill)->save();
            DB::table('password_reset_otps')->where('id', $grant->id)
                ->update(['reset_completed_at' => now(), 'reset_grant_hash' => null, 'updated_at' => now()]);
            return $user;
        });

        session()->forget(['password_reset_token', 'password_reset_email', 'password_reset_product', 'password_reset_record']);
        if (!$user) {
            return redirect()->route('password.request')->withErrors(['email' => __('pos.auth_session_invalid')]);
        }

        event(new PasswordReset($user));

        return redirect()->route('login')->with('status', __('pos.auth_password_reset_success'));
    }
}

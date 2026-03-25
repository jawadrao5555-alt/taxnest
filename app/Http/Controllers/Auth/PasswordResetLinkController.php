<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Illuminate\View\View;
use App\Models\User;

class PasswordResetLinkController extends Controller
{
    public function create(): View
    {
        return view('auth.forgot-password');
    }

    public function store(Request $request): RedirectResponse
    {
        $request->validate([
            'email' => ['required', 'email'],
        ]);

        $user = User::where('email', $request->email)->first();
        if (!$user) {
            return back()->withErrors(['email' => 'No account found with this email address.']);
        }

        DB::table('password_reset_otps')
            ->where('email', $request->email)
            ->where('used', false)
            ->update(['used' => true]);

        $otp = str_pad(random_int(0, 999999), 6, '0', STR_PAD_LEFT);

        DB::table('password_reset_otps')->insert([
            'email' => $request->email,
            'otp' => $otp,
            'expires_at' => now()->addMinutes(15),
            'used' => false,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        try {
            Mail::raw(
                "Your TaxNest password reset code is: {$otp}\n\nThis code expires in 15 minutes.\n\nIf you did not request this, please ignore this email.\n\n— TaxNest Team",
                function ($message) use ($request) {
                    $message->to($request->email)
                        ->subject('TaxNest - Password Reset Code');
                }
            );
        } catch (\Exception $e) {
            \Log::error('Mail send failed: ' . $e->getMessage());
            return back()->withErrors(['email' => 'Failed to send email. Please try again later.']);
        }

        return redirect()->route('password.verify.otp', ['email' => $request->email])
            ->with('status', 'A 6-digit code has been sent to your email.');
    }

    public function showOtpForm(Request $request): View
    {
        return view('auth.verify-otp', ['email' => $request->query('email', '')]);
    }

    public function verifyOtp(Request $request): RedirectResponse
    {
        $request->validate([
            'email' => ['required', 'email'],
            'otp' => ['required', 'string', 'size:6'],
        ]);

        $record = DB::table('password_reset_otps')
            ->where('email', $request->email)
            ->where('otp', $request->otp)
            ->where('used', false)
            ->where('expires_at', '>', now())
            ->first();

        if (!$record) {
            return back()->withInput()->withErrors(['otp' => 'Invalid or expired code. Please try again.']);
        }

        $tempToken = bin2hex(random_bytes(32));

        DB::table('password_reset_otps')
            ->where('id', $record->id)
            ->update(['used' => true, 'updated_at' => now()]);

        session(['password_reset_token' => $tempToken, 'password_reset_email' => $request->email]);

        return redirect()->route('password.reset.form', ['token' => $tempToken, 'email' => $request->email]);
    }
}

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
        $token = bin2hex(random_bytes(32));

        DB::table('password_reset_otps')->insert([
            'email' => $request->email,
            'otp' => $otp,
            'token' => $token,
            'expires_at' => now()->addMinutes(15),
            'used' => false,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $resetLink = url("/reset-password-link?token={$token}&email=" . urlencode($request->email));

        try {
            Mail::send([], [], function ($message) use ($request, $otp, $resetLink) {
                $message->to($request->email)
                    ->subject('TaxNest - Password Reset')
                    ->html($this->buildEmailHtml($otp, $resetLink, $request->email));
            });
        } catch (\Exception $e) {
            \Log::error('Mail send failed: ' . $e->getMessage());
            return back()->withErrors(['email' => 'Failed to send email. Please try again later.']);
        }

        return redirect()->route('password.verify.otp', ['email' => $request->email])
            ->with('status', 'A 6-digit code and reset link have been sent to your email.');
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

    public function resetViaLink(Request $request): View|RedirectResponse
    {
        $token = $request->query('token');
        $email = $request->query('email');

        if (!$token || !$email) {
            return redirect()->route('password.request')->withErrors(['email' => 'Invalid reset link.']);
        }

        $record = DB::table('password_reset_otps')
            ->where('email', $email)
            ->where('token', $token)
            ->where('used', false)
            ->where('expires_at', '>', now())
            ->first();

        if (!$record) {
            return redirect()->route('password.request')->withErrors(['email' => 'This reset link has expired or already been used. Please request a new one.']);
        }

        DB::table('password_reset_otps')
            ->where('id', $record->id)
            ->update(['used' => true, 'updated_at' => now()]);

        $sessionToken = bin2hex(random_bytes(32));
        session(['password_reset_token' => $sessionToken, 'password_reset_email' => $email]);

        return redirect()->route('password.reset.form', ['token' => $sessionToken, 'email' => $email]);
    }

    private function buildEmailHtml(string $otp, string $resetLink, string $email): string
    {
        return '
<!DOCTYPE html>
<html>
<head><meta charset="UTF-8"><meta name="viewport" content="width=device-width, initial-scale=1.0"></head>
<body style="margin:0; padding:0; background-color:#f0fdf4; font-family:Arial,Helvetica,sans-serif;">
<table width="100%" cellpadding="0" cellspacing="0" style="background-color:#f0fdf4; padding:40px 20px;">
<tr><td align="center">
<table width="520" cellpadding="0" cellspacing="0" style="background-color:#ffffff; border-radius:16px; box-shadow:0 4px 24px rgba(0,0,0,0.08); overflow:hidden;">

<!-- Header -->
<tr><td style="background:linear-gradient(135deg,#064e3b,#047857); padding:32px; text-align:center;">
<table cellpadding="0" cellspacing="0" style="margin:0 auto;"><tr>
<td style="background:linear-gradient(135deg,#059669,#14b8a6); width:40px; height:40px; border-radius:12px; text-align:center; vertical-align:middle; color:#fff; font-size:20px;">&#128737;</td>
<td style="padding-left:12px; color:#ffffff; font-size:22px; font-weight:bold;">TaxNest</td>
</tr></table>
<p style="color:#a7f3d0; margin:12px 0 0; font-size:14px;">Password Reset Request</p>
</td></tr>

<!-- Body -->
<tr><td style="padding:32px;">
<p style="color:#374151; font-size:15px; margin:0 0 8px;">Hello,</p>
<p style="color:#6b7280; font-size:14px; line-height:1.6; margin:0 0 24px;">You requested a password reset for your TaxNest account (<strong>' . htmlspecialchars($email) . '</strong>). You can reset your password using either method below:</p>

<!-- OTP Box -->
<div style="background:#f0fdf4; border:2px solid #bbf7d0; border-radius:12px; padding:24px; text-align:center; margin-bottom:24px;">
<p style="color:#6b7280; font-size:13px; margin:0 0 8px; text-transform:uppercase; letter-spacing:1px;">Method 1 — Enter Code on Website</p>
<div style="font-size:36px; font-weight:bold; color:#059669; letter-spacing:12px; font-family:monospace; padding:8px 0;">' . $otp . '</div>
</div>

<!-- OR Divider -->
<table width="100%" cellpadding="0" cellspacing="0" style="margin-bottom:24px;"><tr>
<td style="border-bottom:1px solid #e5e7eb; width:45%;"></td>
<td style="text-align:center; color:#9ca3af; font-size:13px; padding:0 12px; white-space:nowrap;">OR</td>
<td style="border-bottom:1px solid #e5e7eb; width:45%;"></td>
</tr></table>

<!-- Link Button -->
<div style="text-align:center; margin-bottom:24px;">
<p style="color:#6b7280; font-size:13px; margin:0 0 12px; text-transform:uppercase; letter-spacing:1px;">Method 2 — Click the Link</p>
<a href="' . $resetLink . '" style="display:inline-block; background:linear-gradient(135deg,#059669,#14b8a6); color:#ffffff; text-decoration:none; padding:14px 40px; border-radius:10px; font-weight:bold; font-size:15px;">Reset Password</a>
</div>

<div style="background:#fffbeb; border:1px solid #fde68a; border-radius:8px; padding:12px 16px; margin-bottom:16px;">
<p style="color:#92400e; font-size:13px; margin:0;">&#9201; This code and link expire in <strong>15 minutes</strong>.</p>
</div>

<p style="color:#9ca3af; font-size:13px; line-height:1.5; margin:0;">If you did not request this, please ignore this email. Your password will remain unchanged.</p>
</td></tr>

<!-- Footer -->
<tr><td style="background:#f9fafb; border-top:1px solid #f3f4f6; padding:20px 32px; text-align:center;">
<p style="color:#9ca3af; font-size:12px; margin:0;">&copy; ' . date('Y') . ' TaxNest. All rights reserved.</p>
<p style="color:#d1d5db; font-size:11px; margin:8px 0 0;">FBR Compliant Tax & Invoice Management</p>
</td></tr>

</table>
</td></tr>
</table>
</body>
</html>';
    }
}

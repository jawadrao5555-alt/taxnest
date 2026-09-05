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
    /**
     * Which product line this reset belongs to. Since 5 Sep 2026 the same
     * email can hold a PRA POS, an FBR POS and a Digital Invoice account, so
     * "reset my password" is ambiguous until we know the panel. Every login
     * page passes ?panel=…; when it is missing and the email really does exist
     * on more than one product, we ASK instead of guessing.
     */
    private function panel(Request $request): string
    {
        return \App\Support\IdentityScope::normalize($request->input('panel'));
    }

    /** PROD schema-drift guard for the new password_reset_otps column. */
    private function otpScoped(): bool
    {
        static $has = null;
        if ($has === null) {
            $has = \Illuminate\Support\Facades\Schema::hasColumn('password_reset_otps', 'product_type');
        }

        return $has;
    }

    public function create(Request $request): View
    {
        return view('auth.forgot-password', ['panel' => $this->panel($request)]);
    }

    public function store(Request $request): RedirectResponse
    {
        $request->validate([
            'email' => ['required', 'email'],
        ], [
            'email.required' => __('pos.auth_val_email_required'),
            'email.email' => __('pos.auth_val_email_email'),
        ]);

        $neutralStatus = __('pos.auth_fp_status_sent');

        $panel = $this->panel($request);
        $accounts = User::where('email', $request->email)->orderBy('id')->get()
            ->groupBy(fn ($candidate) => \App\Support\IdentityScope::ofCompanyId($candidate->company_id));

        if ($panel !== '') {
            $user = $accounts->get($panel)?->first();
        } elseif ($accounts->count() === 1) {
            $panel = (string) $accounts->keys()->first();
            $user = $accounts->first()->first();
        } elseif ($accounts->count() > 1) {
            // One email, several products. Never reset a password the visitor
            // did not mean — let them pick the account.
            return back()->withInput()->with('panelChoices', $accounts->keys()->all());
        } else {
            $user = null;
        }

        if (!$user) {
            // Neutral response: do not reveal whether the email is registered.
            // An empty panel is left OUT of the URL entirely — a trailing
            // ?panel= would make the unknown-email reply look different from
            // the panel-less one it is supposed to be indistinguishable from.
            return redirect()->route('password.verify.otp', array_filter([
                'email' => $request->email,
                'panel' => $panel,
            ], fn ($v) => $v !== '' && $v !== null))->with('status', $neutralStatus);
        }

        $scoped = $this->otpScoped();

        DB::table('password_reset_otps')
            ->where('email', $request->email)
            ->when($scoped, fn ($q) => $q->where(function ($inner) use ($panel) {
                $inner->where('product_type', $panel)->orWhereNull('product_type');
            }))
            ->where('used', false)
            ->update(['used' => true]);

        $otp = str_pad(random_int(0, 999999), 6, '0', STR_PAD_LEFT);
        $token = bin2hex(random_bytes(32));

        $row = [
            'email' => $request->email,
            'otp' => $otp,
            'token' => $token,
            'expires_at' => now()->addMinutes(15),
            'used' => false,
            'created_at' => now(),
            'updated_at' => now(),
        ];
        if ($scoped) {
            $row['product_type'] = $panel;
        }
        DB::table('password_reset_otps')->insert($row);

        $resetLink = url("/reset-password-link?token={$token}&email=" . urlencode($request->email)
            . ($panel !== '' ? "&panel=" . urlencode($panel) : ''));

        // Render the email in the user's chosen language (session pos_locale, fallback en)
        $sessionLocale = $request->hasSession() ? $request->session()->get(\App\Support\PosLocale::SESSION_KEY) : null;
        $mailLocale = in_array($sessionLocale, \App\Support\PosLocale::ALL, true) ? $sessionLocale : 'en';
        $previousLocale = app()->getLocale();
        app()->setLocale($mailLocale);

        try {
            $subject = __('pos.auth_mail_subject');
            $html = $this->buildEmailHtml($otp, $resetLink, $request->email);
        } finally {
            app()->setLocale($previousLocale);
        }

        try {
            Mail::send([], [], function ($message) use ($request, $subject, $html) {
                $message->to($request->email)
                    ->subject($subject)
                    ->html($html);
            });

            \App\Services\MailHealth::recordSuccess();
        } catch (\Exception $e) {
            \Log::error('Mail send failed: ' . $e->getMessage());
            \App\Services\MailHealth::recordFailure('Password reset email', $e);
            return back()->withErrors(['email' => __('pos.auth_fp_send_failed')]);
        }

        return redirect()->route('password.verify.otp', ['email' => $request->email])
            ->with('status', $neutralStatus);
    }

    public function showOtpForm(Request $request): View
    {
        return view('auth.verify-otp', [
            'email' => $request->query('email', ''),
            'panel' => $this->panel($request),
        ]);
    }

    public function verifyOtp(Request $request): RedirectResponse
    {
        $request->validate([
            'email' => ['required', 'email'],
            'otp' => ['required', 'string', 'size:6'],
        ], [
            'email.required' => __('pos.auth_val_email_required'),
            'email.email' => __('pos.auth_val_email_email'),
            'otp.required' => __('pos.auth_val_otp_required'),
            'otp.size' => __('pos.auth_val_otp_size'),
        ]);

        $panel = $this->panel($request);

        $record = DB::table('password_reset_otps')
            ->where('email', $request->email)
            ->where('otp', $request->otp)
            ->when($this->otpScoped(), fn ($q) => $q->where(function ($inner) use ($panel) {
                $inner->where('product_type', $panel)->orWhereNull('product_type');
            }))
            ->where('used', false)
            ->where('expires_at', '>', now())
            ->first();

        if (!$record) {
            return back()->withInput()->withErrors(['otp' => __('pos.auth_otp_invalid')]);
        }

        $tempToken = bin2hex(random_bytes(32));

        DB::table('password_reset_otps')
            ->where('id', $record->id)
            ->update(['used' => true, 'updated_at' => now()]);

        // The product travels with the reset, so the new password lands on the
        // account the OTP was actually issued for.
        session([
            'password_reset_token'   => $tempToken,
            'password_reset_email'   => $request->email,
            'password_reset_product' => $record->product_type ?? $panel,
        ]);

        return redirect()->route('password.reset.form', ['token' => $tempToken, 'email' => $request->email]);
    }

    public function resetViaLink(Request $request): View|RedirectResponse
    {
        $token = $request->query('token');
        $email = $request->query('email');

        if (!$token || !$email) {
            return redirect()->route('password.request')->withErrors(['email' => __('pos.auth_link_invalid')]);
        }

        $record = DB::table('password_reset_otps')
            ->where('email', $email)
            ->where('token', $token)
            ->where('used', false)
            ->where('expires_at', '>', now())
            ->first();

        if (!$record) {
            return redirect()->route('password.request')->withErrors(['email' => __('pos.auth_link_expired')]);
        }

        DB::table('password_reset_otps')
            ->where('id', $record->id)
            ->update(['used' => true, 'updated_at' => now()]);

        $sessionToken = bin2hex(random_bytes(32));
        session([
            'password_reset_token'   => $sessionToken,
            'password_reset_email'   => $email,
            // The link carries its own product; the row is the safer source.
            'password_reset_product' => $record->product_type ?? $this->panel($request),
        ]);

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
<p style="color:#a7f3d0; margin:12px 0 0; font-size:14px;">' . e(__('pos.auth_mail_header_sub')) . '</p>
</td></tr>

<!-- Body -->
<tr><td style="padding:32px;">
<p style="color:#374151; font-size:15px; margin:0 0 8px;">' . e(__('pos.auth_mail_greeting')) . '</p>
<p style="color:#6b7280; font-size:14px; line-height:1.6; margin:0 0 24px;">' . e(__('pos.auth_mail_intro', ['email' => $email])) . '</p>

<!-- OTP Box -->
<div style="background:#f0fdf4; border:2px solid #bbf7d0; border-radius:12px; padding:24px; text-align:center; margin-bottom:24px;">
<p style="color:#6b7280; font-size:13px; margin:0 0 8px; text-transform:uppercase; letter-spacing:1px;">' . e(__('pos.auth_mail_method1')) . '</p>
<div style="font-size:36px; font-weight:bold; color:#059669; letter-spacing:12px; font-family:monospace; padding:8px 0;">' . $otp . '</div>
</div>

<!-- OR Divider -->
<table width="100%" cellpadding="0" cellspacing="0" style="margin-bottom:24px;"><tr>
<td style="border-bottom:1px solid #e5e7eb; width:45%;"></td>
<td style="text-align:center; color:#9ca3af; font-size:13px; padding:0 12px; white-space:nowrap;">' . e(__('pos.auth_mail_or')) . '</td>
<td style="border-bottom:1px solid #e5e7eb; width:45%;"></td>
</tr></table>

<!-- Link Button -->
<div style="text-align:center; margin-bottom:24px;">
<p style="color:#6b7280; font-size:13px; margin:0 0 12px; text-transform:uppercase; letter-spacing:1px;">' . e(__('pos.auth_mail_method2')) . '</p>
<a href="' . $resetLink . '" style="display:inline-block; background:linear-gradient(135deg,#059669,#14b8a6); color:#ffffff; text-decoration:none; padding:14px 40px; border-radius:10px; font-weight:bold; font-size:15px;">' . e(__('pos.auth_mail_reset_button')) . '</a>
</div>

<div style="background:#fffbeb; border:1px solid #fde68a; border-radius:8px; padding:12px 16px; margin-bottom:16px;">
<p style="color:#92400e; font-size:13px; margin:0;">&#9201; ' . e(__('pos.auth_mail_expiry', ['minutes' => 15])) . '</p>
</div>

<p style="color:#9ca3af; font-size:13px; line-height:1.5; margin:0;">' . e(__('pos.auth_mail_ignore')) . '</p>
</td></tr>

<!-- Footer -->
<tr><td style="background:#f9fafb; border-top:1px solid #f3f4f6; padding:20px 32px; text-align:center;">
<p style="color:#9ca3af; font-size:12px; margin:0;">&copy; ' . date('Y') . ' ' . e(__('pos.auth_mail_rights')) . '</p>
<p style="color:#d1d5db; font-size:11px; margin:8px 0 0;">' . e(__('pos.auth_mail_tagline')) . '</p>
</td></tr>

</table>
</td></tr>
</table>
</body>
</html>';
    }
}

<?php

namespace App\Http\Controllers\SaasAdmin;

use App\Http\Controllers\Controller;
use App\Models\SystemSetting;
use App\Models\AdminAuditLog;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;

class AdminSettingsController extends Controller
{
    /**
     * Keys managed by this page (support contact + manual-payment bank details).
     */
    private array $keys = [
        'support_whatsapp_number',
        'company_legal_name',
        'contact_email',
        'contact_phone',
        'contact_address',
        'support_hours',
        'payment_bank_name',
        'payment_account_title',
        'payment_account_number',
        'payment_iban',
        'payment_instructions',
    ];

    public function index()
    {
        $settings = [];
        foreach ($this->keys as $key) {
            $settings[$key] = SystemSetting::get($key, '');
        }

        return view('saas-admin.settings', compact('settings'));
    }

    public function update(Request $request)
    {
        $data = $request->validate([
            'support_whatsapp_number' => ['nullable', 'string', 'max:25'],
            'company_legal_name' => ['nullable', 'string', 'max:150'],
            'contact_email' => ['nullable', 'email', 'max:150'],
            'contact_phone' => ['nullable', 'string', 'max:60'],
            'contact_address' => ['nullable', 'string', 'max:300'],
            'support_hours' => ['nullable', 'string', 'max:150'],
            'payment_bank_name' => ['nullable', 'string', 'max:120'],
            'payment_account_title' => ['nullable', 'string', 'max:120'],
            'payment_account_number' => ['nullable', 'string', 'max:60'],
            'payment_iban' => ['nullable', 'string', 'max:60'],
            'payment_instructions' => ['nullable', 'string', 'max:1000'],
        ]);

        // Normalise the WhatsApp number to digits only (country code + number, no +).
        if (isset($data['support_whatsapp_number'])) {
            $data['support_whatsapp_number'] = preg_replace('/\D/', '', $data['support_whatsapp_number']);
        }

        foreach ($this->keys as $key) {
            SystemSetting::set($key, $data[$key] ?? '', 'Trial / payment / support configuration');
        }

        AdminAuditLog::log(auth('admin')->id(), 'Support & payment settings updated', 'SystemSetting', null, [
            'whatsapp' => $data['support_whatsapp_number'] ?? '',
            'bank' => $data['payment_bank_name'] ?? '',
        ]);

        return back()->with('success', 'Settings saved successfully.');
    }

    /**
     * One-click SMTP health check: emails the logged-in admin's own address.
     *
     * Features like payment-proof alerts and trial reminders send mail
     * synchronously and fail SILENTLY (log line only) when the live server's
     * SMTP settings are wrong — this button surfaces the ACTUAL transport
     * error in the flash banner so a bad config is diagnosable in one click.
     */
    public function sendTestEmail()
    {
        $admin = auth('admin')->user();
        $email = trim((string) ($admin->email ?? ''));

        if ($email === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
            return back()->with('error', 'Your admin account has no valid email address to send the test to.');
        }

        $mailer = (string) config('mail.default');
        $host = (string) config('mail.mailers.smtp.host');
        $port = (string) config('mail.mailers.smtp.port');

        $body = "This is a TaxNest email delivery test.\n\n"
            . 'Triggered by: ' . ($admin->name ?? 'Admin') . " ({$email})\n"
            . 'Server time: ' . now()->format('d M Y, h:i A T') . "\n"
            . "Mailer: {$mailer}" . ($mailer === 'smtp' ? " ({$host}:{$port})" : '') . "\n\n"
            . 'If you are reading this, outgoing email from the server is working.';

        try {
            Mail::raw($body, function ($m) use ($email) {
                $m->to($email)->subject('TaxNest test email — delivery OK');
            });
        } catch (\Throwable $e) {
            Log::error('Admin test email failed', ['to' => $email, 'error' => $e->getMessage()]);

            return back()->with('error', 'Test email FAILED — ' . $e->getMessage());
        }

        AdminAuditLog::log(auth('admin')->id(), 'Test email sent', 'SystemSetting', null, [
            'to' => $email,
            'mailer' => $mailer,
        ]);

        $note = $mailer === 'smtp'
            ? "Test email sent to {$email} — check your inbox (and spam folder) to confirm delivery."
            : "Test email accepted by the '{$mailer}' mailer (no real SMTP send on this environment). On the live server this button verifies the actual SMTP delivery.";

        return back()->with('success', $note);
    }
}

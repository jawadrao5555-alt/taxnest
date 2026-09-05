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
        'pos_app_latest_version',
        'di_app_latest_version',
        'fbrpos_app_latest_version',
        'waiter_app_latest_version',
        'rider_app_latest_version',
        'caller_app_latest_version',
        'caller_app_plus_latest_version',
        'ai_reader_model',
        'ai_reader_model_strong',
        'distributor_year1', 'distributor_year2', 'distributor_year3',
        'distributor_max_discount', 'distributor_hold_days', 'distributor_tiers',
    ];

    public function index()
    {
        $settings = [];
        foreach ($this->keys as $key) {
            $settings[$key] = SystemSetting::get($key, '');
        }

        $smtpRaw = \App\Services\SmtpRuntimeConfig::settings() ?? [];
        $smtp = [
            'enabled' => (bool) ($smtpRaw['enabled'] ?? false),
            'host' => (string) ($smtpRaw['host'] ?? ''),
            'port' => (string) ($smtpRaw['port'] ?? ''),
            'encryption' => (string) ($smtpRaw['encryption'] ?? 'ssl'),
            'username' => (string) ($smtpRaw['username'] ?? ''),
            'from_address' => (string) ($smtpRaw['from_address'] ?? ''),
            'from_name' => (string) ($smtpRaw['from_name'] ?? ''),
            'has_password' => \App\Services\SmtpRuntimeConfig::hasPassword(),
        ];

        $wa = [
            'enabled' => \App\Models\SystemSetting::get(\App\Services\WhatsAppBusinessApi::CENTRAL_ENABLED_KEY, '0') === '1',
            'phone_number_id' => (string) \App\Models\SystemSetting::get(\App\Services\WhatsAppBusinessApi::CENTRAL_PHONE_ID_KEY, ''),
            'template' => (string) \App\Models\SystemSetting::get(\App\Services\WhatsAppBusinessApi::CENTRAL_OFFLINE_TEMPLATE_KEY, ''),
            'lang' => (string) \App\Models\SystemSetting::get(\App\Services\WhatsAppBusinessApi::CENTRAL_LANG_KEY, ''),
            'has_token' => \App\Services\WhatsAppBusinessApi::centralHasToken(),
        ];

        return view('saas-admin.settings', compact('settings', 'smtp', 'wa'));
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
            // Latest released Android POS APK version (e.g. 1.0.1). Old app
            // installs (UA "TaxNestPOSApp/<ver>") see an update banner when
            // their version is lower. Digits and dots only; empty = banner off.
            'pos_app_latest_version' => ['nullable', 'string', 'max:20', 'regex:/^\d+(\.\d+)*$/'],
            // Latest released Android DI APK version (e.g. 1.0.0). Old app
            // installs (UA "TaxNestDIApp/<ver>") see an update banner when
            // their version is lower. Also gates the downloads-page DI card and
            // in-panel nudge — leave empty until owner has phone-tested the APK.
            'di_app_latest_version'  => ['nullable', 'string', 'max:20', 'regex:/^\d+(\.\d+)*$/'],
            // Latest released FBR POS / Waiter / Rider APK versions (Task #443).
            // Feed the /api/app-version in-app update check: apps on an older
            // versionName show the "Update" dialog. Empty = check disabled.
            'fbrpos_app_latest_version' => ['nullable', 'string', 'max:20', 'regex:/^\d+(\.\d+)*$/'],
            'waiter_app_latest_version' => ['nullable', 'string', 'max:20', 'regex:/^\d+(\.\d+)*$/'],
            'rider_app_latest_version'  => ['nullable', 'string', 'max:20', 'regex:/^\d+(\.\d+)*$/'],
            // Caller ID app (Task 1333). Same beta-safe gate as DI/Waiter:
            // ALSO controls the downloads-page card and the POS → Customize
            // download button — empty keeps everything hidden.
            'caller_app_latest_version' => ['nullable', 'string', 'max:20', 'regex:/^\d+(\.\d+)*$/'],
            // Caller ID ki WhatsApp wali ("plus") build — Task 1345. Alag
            // version record: yeh khali ho to /download par WhatsApp wala
            // hissa aur plus phones ka update prompt dono band rehte hain.
            'caller_app_plus_latest_version' => ['nullable', 'string', 'max:20', 'regex:/^\d+(\.\d+)*$/'],
            // AI Reader (invoice photo/PDF OCR) model overrides. Empty primary
            // model = built-in default; empty strong model = auto-retry
            // escalation disabled. Model ids only (letters/digits . _ -).
            'ai_reader_model'        => ['nullable', 'string', 'max:100', 'regex:/^[A-Za-z0-9._\-]+$/'],
            'ai_reader_model_strong' => ['nullable', 'string', 'max:100', 'regex:/^[A-Za-z0-9._\-]+$/'],
            'distributor_year1' => ['required','numeric','min:0','max:20'],
            'distributor_year2' => ['required','numeric','min:0','max:20'],
            'distributor_year3' => ['required','numeric','min:0','max:20'],
            'distributor_max_discount' => ['required','numeric','min:0','max:10'],
            'distributor_hold_days' => ['required','integer','min:0','max:365'],
            'distributor_tiers' => ['required','string','max:500'],
        ]);
        $tiers = json_decode($data['distributor_tiers'], true);
        if (!is_array($tiers) || array_filter($tiers, fn($t) => !is_array($t) || !isset($t['companies'],$t['rate']) || $t['companies'] < 1 || $t['rate'] < 0 || $t['rate'] > 20)) {
            return back()->withErrors(['distributor_tiers' => 'Tiers must be JSON rows with companies and rate.']);
        }
        foreach ($tiers as $tier) {
            if ($data['distributor_year1'] + $tier['rate'] > 20) return back()->withErrors(['distributor_tiers' => 'Year 1 commission plus any incentive may not exceed 20%.']);
        }

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
            'distributor_policy' => array_intersect_key($data, array_flip(['distributor_year1','distributor_year2','distributor_year3','distributor_max_discount','distributor_hold_days','distributor_tiers'])),
        ]);

        // Task 1413 — surface a version/APK mismatch HERE, where it is caused,
        // not silently on shops' phones. Flipping a *_latest_version before (or
        // without) uploading the matching APK makes every phone download the
        // old file, install the same version, and get nagged again next launch.
        // /api/app-version now refuses to advertise a version the hosted file
        // does not contain; this note tells the admin why their number looks
        // ignored. Fails open when the APK is not on disk (no note).
        $mismatch = $this->apkVersionMismatches($data);
        if ($mismatch !== []) {
            return back()->with('success', 'Settings saved successfully. NOTE: ' . implode(' ', $mismatch));
        }

        return back()->with('success', 'Settings saved successfully.');
    }

    /**
     * Task 1413 — App-version setting => hosted APK, so a mismatch can be named
     * back to the admin at save time. Same canonical download paths the public
     * /api/app-version map (routes/web.php) serves.
     */
    private const APK_VERSION_MAP = [
        'pos_app_latest_version'            => ['POS', 'downloads/taxnest-pos.apk'],
        'fbrpos_app_latest_version'         => ['FBR POS', 'downloads/taxnest-fbr-pos.apk'],
        'waiter_app_latest_version'         => ['Waiter', 'downloads/taxnest-waiter.apk'],
        'rider_app_latest_version'          => ['Rider', 'downloads/taxnest-rider.apk'],
        'di_app_latest_version'             => ['DI', 'downloads/taxnest-di.apk'],
        'caller_app_latest_version'         => ['Caller ID (clean)', 'downloads/taxnest-caller.apk'],
        'caller_app_plus_latest_version'    => ['Caller ID (plus)', 'downloads/taxnest-caller-plus.apk'],
    ];

    /**
     * For every app-version field the admin just set, compare the value against
     * the versionName stamped inside the hosted APK. Returns a human note per
     * app whose file does not carry that version (empty setting or unreadable
     * file are skipped — the check fails open, matching /api/app-version).
     *
     * @param  array<string,mixed>  $data  validated request input
     * @return string[]
     */
    private function apkVersionMismatches(array $data): array
    {
        $notes = [];
        foreach (self::APK_VERSION_MAP as $key => [$name, $apk]) {
            $want = trim((string) ($data[$key] ?? ''));
            if ($want === '') {
                continue;
            }
            $hosted = \App\Services\ApkManifestReader::versionName(public_path($apk));
            if ($hosted === null || $hosted === '') {
                continue; // file not on disk yet — nothing to compare against
            }
            if ($hosted !== $want) {
                $notes[] = "{$name}: you set {$want} but the hosted APK is {$hosted} — upload the {$want} build, or phones will download the old file.";
            }
        }
        return $notes;
    }

    /**
     * Save the admin-managed SMTP (outgoing email) settings.
     *
     * Stored as one encrypted-password JSON SystemSetting and applied at
     * runtime by SmtpRuntimeConfig::apply() — .env stays the fallback when
     * disabled/incomplete. Leaving the password blank keeps the saved one.
     */
    public function updateSmtp(Request $request)
    {
        $enabled = $request->boolean('smtp_enabled');

        $data = $request->validate([
            'smtp_host' => [$enabled ? 'required' : 'nullable', 'string', 'max:190'],
            'smtp_port' => [$enabled ? 'required' : 'nullable', 'integer', 'between:1,65535'],
            'smtp_encryption' => ['nullable', 'in:ssl,tls'],
            'smtp_username' => [$enabled ? 'required' : 'nullable', 'string', 'max:190'],
            'smtp_password' => ['nullable', 'string', 'max:190'],
            'smtp_from_address' => ['nullable', 'email', 'max:190'],
            'smtp_from_name' => ['nullable', 'string', 'max:120'],
        ], [], [
            'smtp_host' => 'SMTP host',
            'smtp_port' => 'SMTP port',
            'smtp_username' => 'SMTP username',
            'smtp_password' => 'SMTP password',
            'smtp_from_address' => 'From email',
        ]);

        $newPassword = (string) ($data['smtp_password'] ?? '');

        if ($enabled && $newPassword === '' && !\App\Services\SmtpRuntimeConfig::hasPassword()) {
            return back()->withInput()->withErrors(['smtp_password' => 'Enter the mailbox password (none saved yet).']);
        }

        \App\Services\SmtpRuntimeConfig::save([
            'enabled' => $enabled,
            'host' => $data['smtp_host'] ?? '',
            'port' => $data['smtp_port'] ?? 465,
            'encryption' => $data['smtp_encryption'] ?? 'ssl',
            'username' => $data['smtp_username'] ?? '',
            'from_address' => $data['smtp_from_address'] ?? '',
            'from_name' => $data['smtp_from_name'] ?? '',
        ], $newPassword);

        AdminAuditLog::log(auth('admin')->id(), 'SMTP settings updated', 'SystemSetting', null, [
            'enabled' => $enabled,
            'host' => $data['smtp_host'] ?? '',
            'username' => $data['smtp_username'] ?? '',
            'password_changed' => $newPassword !== '',
        ]);

        return back()->with('success', $enabled
            ? 'Email (SMTP) settings saved and ACTIVE — use "Send Test Email" below to confirm delivery.'
            : 'Email (SMTP) settings saved but DISABLED — the server\'s .env settings will be used.');
    }

    /**
     * TaxNest-central WhatsApp Business number (Task 634) — owner alerts like
     * agent-offline are sent as a Meta-approved UTILITY template from this
     * number. Separate from per-company buyer-invoice credentials. Token is
     * stored ENCRYPTED; leave the token field blank to keep the saved one.
     */
    public function updateWhatsApp(Request $request)
    {
        $enabled = $request->boolean('wa_enabled');

        $data = $request->validate([
            'wa_phone_number_id' => [$enabled ? 'required' : 'nullable', 'string', 'max:60'],
            'wa_token' => ['nullable', 'string', 'max:500'],
            'wa_template' => ['nullable', 'string', 'max:120'],
            'wa_lang' => ['nullable', 'string', 'max:10'],
        ], [], [
            'wa_phone_number_id' => 'Phone Number ID',
            'wa_token' => 'API token',
        ]);

        $newToken = trim((string) ($data['wa_token'] ?? ''));

        if ($enabled && $newToken === '' && !\App\Services\WhatsAppBusinessApi::centralHasToken()) {
            return back()->withInput()->withErrors(['wa_token' => 'Enter the Meta API token (none saved yet).']);
        }

        \App\Models\SystemSetting::set(\App\Services\WhatsAppBusinessApi::CENTRAL_ENABLED_KEY, $enabled ? '1' : '0', 'Central WhatsApp owner alerts on/off');
        \App\Models\SystemSetting::set(\App\Services\WhatsAppBusinessApi::CENTRAL_PHONE_ID_KEY, trim((string) ($data['wa_phone_number_id'] ?? '')), 'Central WhatsApp phone number ID');
        \App\Models\SystemSetting::set(\App\Services\WhatsAppBusinessApi::CENTRAL_OFFLINE_TEMPLATE_KEY, trim((string) ($data['wa_template'] ?? '')), 'Agent-offline template name');
        \App\Models\SystemSetting::set(\App\Services\WhatsAppBusinessApi::CENTRAL_LANG_KEY, trim((string) ($data['wa_lang'] ?? '')), 'Central WhatsApp template language code');
        if ($newToken !== '') {
            \App\Models\SystemSetting::set(
                \App\Services\WhatsAppBusinessApi::CENTRAL_TOKEN_KEY,
                \Illuminate\Support\Facades\Crypt::encryptString($newToken),
                'Central WhatsApp API token (encrypted)'
            );
        }

        AdminAuditLog::log(auth('admin')->id(), 'Central WhatsApp settings updated', 'SystemSetting', null, [
            'enabled' => $enabled,
            'phone_number_id' => trim((string) ($data['wa_phone_number_id'] ?? '')),
            'token_changed' => $newToken !== '',
        ]);

        return back()->with('success', $enabled
            ? 'WhatsApp alert settings saved and ACTIVE — agent-offline alerts will try WhatsApp first (email stays the fallback).'
            : 'WhatsApp alert settings saved but DISABLED — agent-offline alerts will go by email only.');
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

            \App\Services\MailHealth::recordFailure('Admin test email', $e);

            return back()->with('error', 'Test email FAILED — ' . $e->getMessage());
        }

        \App\Services\MailHealth::recordSuccess();

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

<x-admin-layout>
<div class="p-4 sm:p-6 max-w-3xl mx-auto">
    <h1 class="text-2xl font-bold text-white mb-1">Support &amp; Payment Settings</h1>
    <p class="text-xs text-gray-500 dark:text-gray-400 mb-6">Configure the global WhatsApp support number and the bank / payment account details shown to companies whose free trial has ended.</p>

    <form method="POST" action="{{ route('saas.admin.settings.update') }}" class="space-y-6">
        @csrf

        <div class="bg-gray-900 border border-gray-800 rounded-xl p-5">
            <h2 class="text-sm font-semibold text-white mb-4 flex items-center gap-2">
                <svg class="w-5 h-5 text-emerald-400" fill="currentColor" viewBox="0 0 24 24"><path d="M.057 24l1.687-6.163a11.867 11.867 0 01-1.587-5.945C.16 5.335 5.495 0 12.05 0a11.817 11.817 0 018.413 3.488 11.824 11.824 0 013.48 8.414c-.003 6.557-5.338 11.892-11.893 11.892a11.9 11.9 0 01-5.688-1.448L.057 24zm6.597-3.807c1.676.995 3.276 1.591 5.392 1.592 5.448 0 9.886-4.434 9.889-9.885.002-5.462-4.415-9.89-9.881-9.892-5.452 0-9.887 4.434-9.889 9.884a9.86 9.86 0 001.515 5.26l-.999 3.648 3.973-1.715z"/></svg>
                Support Contact
            </h2>
            <label class="block text-xs font-medium text-gray-400 mb-1">WhatsApp Support Number</label>
            <input type="text" name="support_whatsapp_number" value="{{ old('support_whatsapp_number', $settings['support_whatsapp_number']) }}"
                   placeholder="e.g. 923001234567 (country code, no + or spaces)"
                   class="w-full rounded-lg bg-gray-800 border border-gray-700 text-white text-sm px-3 py-2 focus:ring-emerald-500 focus:border-emerald-500" />
            <p class="text-[11px] text-gray-500 mt-1">Used by the floating WhatsApp button on every screen and the "Send payment proof" button. Enter the full international number with country code, digits only.</p>
            @error('support_whatsapp_number') <p class="text-xs text-red-400 mt-1">{{ $message }}</p> @enderror
        </div>

        <div class="bg-gray-900 border border-gray-800 rounded-xl p-5">
            <h2 class="text-sm font-semibold text-white mb-4 flex items-center gap-2">
                <svg class="w-5 h-5 text-amber-400" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.8" d="M12 18h.01M8 21h8a2 2 0 002-2V5a2 2 0 00-2-2H8a2 2 0 00-2 2v14a2 2 0 002 2z"/></svg>
                Mobile App (APK)
            </h2>
            <div class="mb-4">
                <label class="block text-xs font-medium text-gray-400 mb-1">Latest POS Android App Version</label>
                <input type="text" name="pos_app_latest_version" value="{{ old('pos_app_latest_version', $settings['pos_app_latest_version']) }}"
                       placeholder="e.g. 1.0.1"
                       class="w-full rounded-lg bg-gray-800 border border-gray-700 text-white text-sm px-3 py-2 focus:ring-emerald-500 focus:border-emerald-500" />
                <p class="text-[11px] text-gray-500 mt-1">Set this to the versionName of the newest released POS APK (TaxNest POS). Users on an older app version see a dismissible "new app available" banner. Leave empty to disable. Digits and dots only (e.g. 1.0.2).</p>
                @error('pos_app_latest_version') <p class="text-xs text-red-400 mt-1">{{ $message }}</p> @enderror
            </div>
            <div>
                <label class="block text-xs font-medium text-gray-400 mb-1">Latest DI Android App Version</label>
                <input type="text" name="di_app_latest_version" value="{{ old('di_app_latest_version', $settings['di_app_latest_version']) }}"
                       placeholder="e.g. 1.0.0"
                       class="w-full rounded-lg bg-gray-800 border border-gray-700 text-white text-sm px-3 py-2 focus:ring-emerald-500 focus:border-emerald-500" />
                <p class="text-[11px] text-gray-500 mt-1">Set this to the versionName of the newest released DI APK (TaxNest DI). Also controls the downloads-page DI card and in-panel download nudge — <strong class="text-amber-400">leave empty until you have phone-tested the APK</strong>; setting it makes the card and nudge go live automatically. Digits and dots only (e.g. 1.0.0).</p>
                @error('di_app_latest_version') <p class="text-xs text-red-400 mt-1">{{ $message }}</p> @enderror
            </div>
            <div class="mt-4">
                <label class="block text-xs font-medium text-gray-400 mb-1">Latest FBR POS Android App Version</label>
                <input type="text" name="fbrpos_app_latest_version" value="{{ old('fbrpos_app_latest_version', $settings['fbrpos_app_latest_version']) }}"
                       placeholder="e.g. 1.0.2"
                       class="w-full rounded-lg bg-gray-800 border border-gray-700 text-white text-sm px-3 py-2 focus:ring-emerald-500 focus:border-emerald-500" />
                <p class="text-[11px] text-gray-500 mt-1">versionName of the newest released FBR POS APK. Apps with in-app update support (v1.0.2+) show an "Update" dialog when their version is lower. Leave empty to disable. Digits and dots only.</p>
                @error('fbrpos_app_latest_version') <p class="text-xs text-red-400 mt-1">{{ $message }}</p> @enderror
            </div>
            <div class="mt-4">
                <label class="block text-xs font-medium text-gray-400 mb-1">Latest Waiter Android App Version</label>
                <input type="text" name="waiter_app_latest_version" value="{{ old('waiter_app_latest_version', $settings['waiter_app_latest_version']) }}"
                       placeholder="e.g. 1.0.2"
                       class="w-full rounded-lg bg-gray-800 border border-gray-700 text-white text-sm px-3 py-2 focus:ring-emerald-500 focus:border-emerald-500" />
                <p class="text-[11px] text-gray-500 mt-1">versionName of the newest released Waiter APK. Same in-app update rule as above. Leave empty to disable.</p>
                @error('waiter_app_latest_version') <p class="text-xs text-red-400 mt-1">{{ $message }}</p> @enderror
            </div>
            <div class="mt-4">
                <label class="block text-xs font-medium text-gray-400 mb-1">Latest Rider Android App Version</label>
                <input type="text" name="rider_app_latest_version" value="{{ old('rider_app_latest_version', $settings['rider_app_latest_version']) }}"
                       placeholder="e.g. 1.4.1"
                       class="w-full rounded-lg bg-gray-800 border border-gray-700 text-white text-sm px-3 py-2 focus:ring-emerald-500 focus:border-emerald-500" />
                <p class="text-[11px] text-gray-500 mt-1">versionName of the newest released Rider APK. Same in-app update rule as above. Leave empty to disable.</p>
                @error('rider_app_latest_version') <p class="text-xs text-red-400 mt-1">{{ $message }}</p> @enderror
            </div>
        </div>

        <div class="bg-gray-900 border border-gray-800 rounded-xl p-5">
            <h2 class="text-sm font-semibold text-white mb-4 flex items-center gap-2">
                <svg class="w-5 h-5 text-purple-400" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.8" d="M9.75 3v2.25M14.25 3v2.25M9.75 18.75V21M14.25 18.75V21M3 9.75h2.25M3 14.25h2.25M18.75 9.75H21M18.75 14.25H21M7.5 6h9A1.5 1.5 0 0118 7.5v9a1.5 1.5 0 01-1.5 1.5h-9A1.5 1.5 0 016 16.5v-9A1.5 1.5 0 017.5 6z"/></svg>
                AI Reader Models
            </h2>
            <div class="mb-4">
                <label class="block text-xs font-medium text-gray-400 mb-1">AI Reader Model</label>
                <input type="text" name="ai_reader_model" value="{{ old('ai_reader_model', $settings['ai_reader_model']) }}"
                       placeholder="e.g. gpt-4o-mini (empty = built-in default)"
                       class="w-full rounded-lg bg-gray-800 border border-gray-700 text-white text-sm px-3 py-2 focus:ring-emerald-500 focus:border-emerald-500" />
                <p class="text-[11px] text-gray-500 mt-1">Vision model used to read invoice photos / scanned PDFs. Leave empty to use the built-in default (gpt-4o-mini). Model id only — letters, digits, dots, dashes.</p>
                @error('ai_reader_model') <p class="text-xs text-red-400 mt-1">{{ $message }}</p> @enderror
            </div>
            <div>
                <label class="block text-xs font-medium text-gray-400 mb-1">Strong Retry Model</label>
                <input type="text" name="ai_reader_model_strong" value="{{ old('ai_reader_model_strong', $settings['ai_reader_model_strong']) }}"
                       placeholder="e.g. gpt-4o (empty = retry escalation OFF)"
                       class="w-full rounded-lg bg-gray-800 border border-gray-700 text-white text-sm px-3 py-2 focus:ring-emerald-500 focus:border-emerald-500" />
                <p class="text-[11px] text-gray-500 mt-1">Stronger (costlier) model used for ONE automatic retry when a blurry photo/scan reads with low confidence. <strong class="text-amber-400">Leave empty to switch the strong retry off</strong> — set a model id to switch it on.</p>
                @error('ai_reader_model_strong') <p class="text-xs text-red-400 mt-1">{{ $message }}</p> @enderror
            </div>
        </div>

        <div class="bg-gray-900 border border-gray-800 rounded-xl p-5 space-y-4">
            <h2 class="text-sm font-semibold text-white flex items-center gap-2">
                <svg class="w-5 h-5 text-teal-400" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.8" d="M3 8l7.89 5.26a2 2 0 002.22 0L21 8M5 19h14a2 2 0 002-2V7a2 2 0 00-2-2H5a2 2 0 00-2 2v10a2 2 0 002 2z"/></svg>
                Contact Details
            </h2>
            <p class="text-[11px] text-gray-500 -mt-2">Shown publicly on the Contact page and in the site footer. Update here and it changes everywhere automatically.</p>
            <div>
                <label class="block text-xs font-medium text-gray-400 mb-1">Company Legal Name</label>
                <input type="text" name="company_legal_name" value="{{ old('company_legal_name', $settings['company_legal_name']) }}"
                       placeholder="e.g. TaxNest Technologies (Pvt) Ltd"
                       class="w-full rounded-lg bg-gray-800 border border-gray-700 text-white text-sm px-3 py-2 focus:ring-emerald-500 focus:border-emerald-500" />
                @error('company_legal_name') <p class="text-xs text-red-400 mt-1">{{ $message }}</p> @enderror
            </div>
            <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
                <div>
                    <label class="block text-xs font-medium text-gray-400 mb-1">Support Email</label>
                    <input type="email" name="contact_email" value="{{ old('contact_email', $settings['contact_email']) }}"
                           placeholder="support@taxnest.com"
                           class="w-full rounded-lg bg-gray-800 border border-gray-700 text-white text-sm px-3 py-2 focus:ring-emerald-500 focus:border-emerald-500" />
                    @error('contact_email') <p class="text-xs text-red-400 mt-1">{{ $message }}</p> @enderror
                </div>
                <div>
                    <label class="block text-xs font-medium text-gray-400 mb-1">Phone</label>
                    <input type="text" name="contact_phone" value="{{ old('contact_phone', $settings['contact_phone']) }}"
                           placeholder="e.g. +92 42 1234567"
                           class="w-full rounded-lg bg-gray-800 border border-gray-700 text-white text-sm px-3 py-2 focus:ring-emerald-500 focus:border-emerald-500" />
                </div>
            </div>
            <div>
                <label class="block text-xs font-medium text-gray-400 mb-1">Office Address</label>
                <input type="text" name="contact_address" value="{{ old('contact_address', $settings['contact_address']) }}"
                       placeholder="e.g. Office 12, Gulberg III, Lahore, Pakistan"
                       class="w-full rounded-lg bg-gray-800 border border-gray-700 text-white text-sm px-3 py-2 focus:ring-emerald-500 focus:border-emerald-500" />
            </div>
            <div>
                <label class="block text-xs font-medium text-gray-400 mb-1">Support Hours</label>
                <input type="text" name="support_hours" value="{{ old('support_hours', $settings['support_hours']) }}"
                       placeholder="e.g. Mon\u2013Sat, 9:00 AM \u2013 8:00 PM (PKT)"
                       class="w-full rounded-lg bg-gray-800 border border-gray-700 text-white text-sm px-3 py-2 focus:ring-emerald-500 focus:border-emerald-500" />
            </div>
        </div>

        <div class="bg-gray-900 border border-gray-800 rounded-xl p-5 space-y-4">
            <h2 class="text-sm font-semibold text-white flex items-center gap-2">
                <svg class="w-5 h-5 text-indigo-400" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.8" d="M3 10h18M7 15h1m4 0h1m-7 4h12a3 3 0 003-3V8a3 3 0 00-3-3H6a3 3 0 00-3 3v8a3 3 0 003 3z"/></svg>
                Bank / Payment Account Details
            </h2>
            <p class="text-[11px] text-gray-500 -mt-2">These details are shown in the trial-expired popup so companies can transfer payment manually, then send proof on WhatsApp.</p>

            <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
                <div>
                    <label class="block text-xs font-medium text-gray-400 mb-1">Bank Name</label>
                    <input type="text" name="payment_bank_name" value="{{ old('payment_bank_name', $settings['payment_bank_name']) }}"
                           class="w-full rounded-lg bg-gray-800 border border-gray-700 text-white text-sm px-3 py-2 focus:ring-emerald-500 focus:border-emerald-500" />
                </div>
                <div>
                    <label class="block text-xs font-medium text-gray-400 mb-1">Account Title</label>
                    <input type="text" name="payment_account_title" value="{{ old('payment_account_title', $settings['payment_account_title']) }}"
                           class="w-full rounded-lg bg-gray-800 border border-gray-700 text-white text-sm px-3 py-2 focus:ring-emerald-500 focus:border-emerald-500" />
                </div>
                <div>
                    <label class="block text-xs font-medium text-gray-400 mb-1">Account Number</label>
                    <input type="text" name="payment_account_number" value="{{ old('payment_account_number', $settings['payment_account_number']) }}"
                           class="w-full rounded-lg bg-gray-800 border border-gray-700 text-white text-sm px-3 py-2 focus:ring-emerald-500 focus:border-emerald-500" />
                </div>
                <div>
                    <label class="block text-xs font-medium text-gray-400 mb-1">IBAN</label>
                    <input type="text" name="payment_iban" value="{{ old('payment_iban', $settings['payment_iban']) }}"
                           class="w-full rounded-lg bg-gray-800 border border-gray-700 text-white text-sm px-3 py-2 focus:ring-emerald-500 focus:border-emerald-500" />
                </div>
            </div>

            <div>
                <label class="block text-xs font-medium text-gray-400 mb-1">Payment Instructions (optional)</label>
                <textarea name="payment_instructions" rows="3"
                          placeholder="e.g. After transfer, send the receipt screenshot on WhatsApp with your company name and NTN."
                          class="w-full rounded-lg bg-gray-800 border border-gray-700 text-white text-sm px-3 py-2 focus:ring-emerald-500 focus:border-emerald-500">{{ old('payment_instructions', $settings['payment_instructions']) }}</textarea>
            </div>
        </div>

        <div class="flex justify-end">
            <button type="submit" class="px-5 py-2.5 rounded-lg text-sm font-semibold bg-emerald-600 text-white hover:bg-emerald-500 transition">
                Save Settings
            </button>
        </div>
    </form>

    <div class="bg-gray-900 border border-gray-800 rounded-xl p-5 mt-6">
        <h2 class="text-sm font-semibold text-white mb-1 flex items-center gap-2">
            <svg class="w-5 h-5 text-sky-400" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.8" d="M21.75 6.75v10.5a2.25 2.25 0 01-2.25 2.25h-15a2.25 2.25 0 01-2.25-2.25V6.75m19.5 0A2.25 2.25 0 0019.5 4.5h-15a2.25 2.25 0 00-2.25 2.25m19.5 0v.243a2.25 2.25 0 01-1.07 1.916l-7.5 4.615a2.25 2.25 0 01-2.36 0L3.32 8.91a2.25 2.25 0 01-1.07-1.916V6.75"/></svg>
            Email (SMTP) Settings
        </h2>
        <p class="text-[11px] text-gray-500 mb-4">
            The mailbox all system emails are sent from (password resets, payment-proof alerts, approval emails, trial reminders).
            When enabled, these settings are used instead of the server's <span class="font-mono">.env</span> file — no server access needed to change them.
            When disabled or incomplete, the server's own settings keep working as the fallback.
        </p>

        <form method="POST" action="{{ route('saas.admin.settings.smtp') }}" class="space-y-4">
            @csrf

            <label class="flex items-center gap-3 cursor-pointer select-none">
                <input type="checkbox" name="smtp_enabled" value="1" {{ old('smtp_enabled', $smtp['enabled'] ? '1' : '') ? 'checked' : '' }}
                       class="rounded bg-gray-800 border-gray-600 text-emerald-600 focus:ring-emerald-500 w-4 h-4" />
                <span class="text-sm text-white font-medium">Use these settings for outgoing email</span>
            </label>

            <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
                <div>
                    <label class="block text-xs font-medium text-gray-400 mb-1">SMTP Host</label>
                    <input type="text" name="smtp_host" value="{{ old('smtp_host', $smtp['host']) }}"
                           placeholder="e.g. mail.taxnest.com.pk"
                           class="w-full rounded-lg bg-gray-800 border border-gray-700 text-white text-sm px-3 py-2 focus:ring-emerald-500 focus:border-emerald-500" />
                    @error('smtp_host') <p class="text-xs text-red-400 mt-1">{{ $message }}</p> @enderror
                </div>
                <div class="grid grid-cols-2 gap-3">
                    <div>
                        <label class="block text-xs font-medium text-gray-400 mb-1">Port</label>
                        <input type="number" name="smtp_port" value="{{ old('smtp_port', $smtp['port'] ?: '465') }}"
                               class="w-full rounded-lg bg-gray-800 border border-gray-700 text-white text-sm px-3 py-2 focus:ring-emerald-500 focus:border-emerald-500" />
                        @error('smtp_port') <p class="text-xs text-red-400 mt-1">{{ $message }}</p> @enderror
                    </div>
                    <div>
                        <label class="block text-xs font-medium text-gray-400 mb-1">Security</label>
                        <select name="smtp_encryption"
                                class="w-full rounded-lg bg-gray-800 border border-gray-700 text-white text-sm px-3 py-2 focus:ring-emerald-500 focus:border-emerald-500">
                            <option value="ssl" {{ old('smtp_encryption', $smtp['encryption']) === 'ssl' ? 'selected' : '' }}>SSL (port 465)</option>
                            <option value="tls" {{ old('smtp_encryption', $smtp['encryption']) === 'tls' ? 'selected' : '' }}>TLS (port 587)</option>
                        </select>
                    </div>
                </div>
                <div>
                    <label class="block text-xs font-medium text-gray-400 mb-1">Username (email account)</label>
                    <input type="text" name="smtp_username" value="{{ old('smtp_username', $smtp['username']) }}"
                           placeholder="e.g. noreply@taxnest.com.pk" autocomplete="off"
                           class="w-full rounded-lg bg-gray-800 border border-gray-700 text-white text-sm px-3 py-2 focus:ring-emerald-500 focus:border-emerald-500" />
                    @error('smtp_username') <p class="text-xs text-red-400 mt-1">{{ $message }}</p> @enderror
                </div>
                <div>
                    <label class="block text-xs font-medium text-gray-400 mb-1">
                        Mailbox Password
                        @if($smtp['has_password'])<span class="text-emerald-400 font-semibold">(saved — leave blank to keep)</span>@endif
                    </label>
                    <input type="password" name="smtp_password" value=""
                           placeholder="{{ $smtp['has_password'] ? '••••••••••••' : 'Mailbox password' }}" autocomplete="new-password"
                           class="w-full rounded-lg bg-gray-800 border border-gray-700 text-white text-sm px-3 py-2 focus:ring-emerald-500 focus:border-emerald-500" />
                    @error('smtp_password') <p class="text-xs text-red-400 mt-1">{{ $message }}</p> @enderror
                </div>
                <div>
                    <label class="block text-xs font-medium text-gray-400 mb-1">From Email (optional)</label>
                    <input type="text" name="smtp_from_address" value="{{ old('smtp_from_address', $smtp['from_address']) }}"
                           placeholder="Defaults to the username above"
                           class="w-full rounded-lg bg-gray-800 border border-gray-700 text-white text-sm px-3 py-2 focus:ring-emerald-500 focus:border-emerald-500" />
                    @error('smtp_from_address') <p class="text-xs text-red-400 mt-1">{{ $message }}</p> @enderror
                </div>
                <div>
                    <label class="block text-xs font-medium text-gray-400 mb-1">From Name (optional)</label>
                    <input type="text" name="smtp_from_name" value="{{ old('smtp_from_name', $smtp['from_name']) }}"
                           placeholder="e.g. TaxNest"
                           class="w-full rounded-lg bg-gray-800 border border-gray-700 text-white text-sm px-3 py-2 focus:ring-emerald-500 focus:border-emerald-500" />
                </div>
            </div>

            <div class="flex items-center justify-between gap-3">
                <p class="text-[11px] text-gray-500">The password is stored encrypted. After saving, click "Send Test Email" below to confirm delivery.</p>
                <button type="submit" class="px-5 py-2.5 rounded-lg text-sm font-semibold bg-emerald-600 text-white hover:bg-emerald-500 transition shrink-0">
                    Save Email Settings
                </button>
            </div>
        </form>
    </div>

    <div id="whatsapp-alerts" class="bg-gray-900 border border-gray-800 rounded-xl p-5 mt-6">
        <h2 class="text-sm font-semibold text-white mb-1 flex items-center gap-2">
            <svg class="w-5 h-5 text-green-400" fill="currentColor" viewBox="0 0 24 24"><path d="M12.04 2c-5.46 0-9.91 4.45-9.91 9.91 0 1.75.46 3.45 1.32 4.95L2.05 22l5.25-1.38c1.45.79 3.08 1.21 4.74 1.21 5.46 0 9.91-4.45 9.91-9.91S17.5 2 12.04 2zm0 18.03c-1.48 0-2.93-.4-4.2-1.15l-.3-.18-3.12.82.83-3.04-.2-.31a8.26 8.26 0 01-1.26-4.38c0-4.54 3.7-8.24 8.25-8.24 4.54 0 8.24 3.7 8.24 8.24s-3.7 8.24-8.24 8.24z"/></svg>
            WhatsApp Alerts (TaxNest Central Number)
        </h2>
        <p class="text-[11px] text-gray-500 mb-4">
            Owner alerts (jaise Desktop Agent offline) TaxNest ke APNE WhatsApp Business number se bheje jate hain — companies ke buyer-invoice WhatsApp credentials se bilkul alag.
            Requires a Meta Business account with an approved UTILITY template. Default template <span class="font-mono">agent_offline_alert</span> body:
            <span class="font-mono text-gray-400">"@{{1}} — aap ka NestPOS Desktop Agent @{{2}} ghante se offline hai (aakhri raabta: @{{3}}). PC/agent chalu karein warna bills silent-print nahi hon ge."</span>
            When disabled or unconfigured, alerts go by email only (current behaviour).
        </p>

        <form method="POST" action="{{ route('saas.admin.settings.whatsapp') }}" class="space-y-4">
            @csrf

            <label class="flex items-center gap-3 cursor-pointer select-none">
                <input type="checkbox" name="wa_enabled" value="1" {{ old('wa_enabled', $wa['enabled'] ? '1' : '') ? 'checked' : '' }}
                       class="rounded bg-gray-800 border-gray-600 text-emerald-600 focus:ring-emerald-500 w-4 h-4" />
                <span class="text-sm text-white font-medium">Send owner alerts on WhatsApp (email stays the fallback)</span>
            </label>

            <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
                <div>
                    <label class="block text-xs font-medium text-gray-400 mb-1">Phone Number ID (Meta)</label>
                    <input type="text" name="wa_phone_number_id" value="{{ old('wa_phone_number_id', $wa['phone_number_id']) }}"
                           placeholder="e.g. 123456789012345" autocomplete="off"
                           class="w-full rounded-lg bg-gray-800 border border-gray-700 text-white text-sm px-3 py-2 focus:ring-emerald-500 focus:border-emerald-500" />
                    @error('wa_phone_number_id') <p class="text-xs text-red-400 mt-1">{{ $message }}</p> @enderror
                </div>
                <div>
                    <label class="block text-xs font-medium text-gray-400 mb-1">
                        Meta API Token
                        @if($wa['has_token'])<span class="text-emerald-400 font-semibold">(saved — leave blank to keep)</span>@endif
                    </label>
                    <input type="password" name="wa_token" value=""
                           placeholder="{{ $wa['has_token'] ? '••••••••••••' : 'Permanent system-user access token' }}" autocomplete="new-password"
                           class="w-full rounded-lg bg-gray-800 border border-gray-700 text-white text-sm px-3 py-2 focus:ring-emerald-500 focus:border-emerald-500" />
                    @error('wa_token') <p class="text-xs text-red-400 mt-1">{{ $message }}</p> @enderror
                </div>
                <div>
                    <label class="block text-xs font-medium text-gray-400 mb-1">Agent-Offline Template Name (optional)</label>
                    <input type="text" name="wa_template" value="{{ old('wa_template', $wa['template']) }}"
                           placeholder="Defaults to agent_offline_alert"
                           class="w-full rounded-lg bg-gray-800 border border-gray-700 text-white text-sm px-3 py-2 focus:ring-emerald-500 focus:border-emerald-500" />
                </div>
                <div>
                    <label class="block text-xs font-medium text-gray-400 mb-1">Template Language Code (optional)</label>
                    <input type="text" name="wa_lang" value="{{ old('wa_lang', $wa['lang']) }}"
                           placeholder="Defaults to en"
                           class="w-full rounded-lg bg-gray-800 border border-gray-700 text-white text-sm px-3 py-2 focus:ring-emerald-500 focus:border-emerald-500" />
                </div>
            </div>

            <div class="flex items-center justify-between gap-3">
                <p class="text-[11px] text-gray-500">The token is stored encrypted. Alerts try WhatsApp first; on any failure the email goes out as before.</p>
                <button type="submit" class="px-5 py-2.5 rounded-lg text-sm font-semibold bg-emerald-600 text-white hover:bg-emerald-500 transition shrink-0">
                    Save WhatsApp Settings
                </button>
            </div>
        </form>
    </div>

    <div id="email-test" class="bg-gray-900 border border-gray-800 rounded-xl p-5 mt-6">
        <h2 class="text-sm font-semibold text-white mb-1 flex items-center gap-2">
            <svg class="w-5 h-5 text-amber-400" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.8" d="M12 19l9 2-9-18-9 18 9-2zm0 0v-8"/></svg>
            Email Delivery Test
        </h2>
        <p class="text-[11px] text-gray-500 mb-4">Payment-proof alerts and trial reminder emails depend on the server's SMTP settings. Click below to send a test email to your own address ({{ auth('admin')->user()->email }}) — if SMTP is misconfigured, the exact error appears here instead of failing silently.</p>
        <form method="POST" action="{{ route('saas.admin.settings.test-email') }}">
            @csrf
            <button type="submit" class="px-5 py-2.5 rounded-lg text-sm font-semibold bg-amber-600 text-white hover:bg-amber-500 transition">
                Send Test Email
            </button>
        </form>
        @php($tnMailLastOk = \App\Services\MailHealth::lastSuccessAgo())
        @if($tnMailLastOk)
        <p class="text-[11px] text-gray-500 mt-3">Last successful send: {{ $tnMailLastOk }}.</p>
        @endif
    </div>
</div>
</x-admin-layout>

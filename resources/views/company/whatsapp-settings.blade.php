<x-app-layout>
    <div class="py-8">
        <div class="max-w-4xl mx-auto px-4 sm:px-6 lg:px-8">
            <div class="mb-6">
                <h2 class="font-bold text-xl text-gray-800 dark:text-gray-100 leading-tight">WhatsApp Business API Settings</h2>
                <p class="text-sm text-gray-500 dark:text-gray-400 mt-1">Invoice buyer ko seedha WhatsApp par bhejein — bina apna WhatsApp khole (Meta Business API).</p>
            </div>

            @if(session('success'))
                <div class="mb-4 p-4 rounded-lg text-sm bg-emerald-50 border border-emerald-200 text-emerald-700">{{ session('success') }}</div>
            @endif
            @if(session('error'))
                <div class="mb-4 p-4 rounded-lg text-sm bg-red-50 border border-red-200 text-red-700">{{ session('error') }}</div>
            @endif

            <form method="POST" action="/company/whatsapp-settings" class="space-y-6">
                @csrf
                @method('PUT')

                <div class="bg-white dark:bg-gray-900 rounded-xl shadow-sm border border-gray-200 dark:border-gray-800 p-6">
                    <div class="flex items-start justify-between gap-4">
                        <div>
                            <h3 class="text-lg font-semibold text-gray-800 dark:text-gray-100">Direct Send (seedha bhejein)</h3>
                            <p class="text-sm text-gray-500 dark:text-gray-400 mt-1">
                                On hone par send modal mein "Seedha bhejein" ka option milega — server khud message + PDF bhejta hai.
                                Off ho to purana tareeqa (WhatsApp app khul kar) chalta rahega.
                            </p>
                        </div>
                        <label class="inline-flex items-center cursor-pointer flex-shrink-0 mt-1">
                            <input type="hidden" name="wa_api_enabled" value="0">
                            <input type="checkbox" name="wa_api_enabled" value="1" class="sr-only peer" {{ old('wa_api_enabled', $company->wa_api_enabled) ? 'checked' : '' }}>
                            <div class="relative w-11 h-6 bg-gray-200 peer-focus:outline-none peer-focus:ring-4 peer-focus:ring-emerald-300 rounded-full peer dark:bg-gray-600 peer-checked:after:translate-x-full peer-checked:after:border-white after:content-[''] after:absolute after:top-[2px] after:left-[2px] after:bg-white after:border-gray-300 after:border after:rounded-full after:h-5 after:w-5 after:transition-all peer-checked:bg-emerald-600"></div>
                        </label>
                    </div>
                </div>

                <div class="bg-white dark:bg-gray-900 rounded-xl shadow-sm border border-gray-200 dark:border-gray-800 p-6">
                    <h3 class="text-lg font-semibold text-gray-800 dark:text-gray-100 mb-1">Meta Cloud API Credentials</h3>
                    <p class="text-sm text-gray-500 dark:text-gray-400 mb-4">
                        Ye Meta Business Manager → WhatsApp → API Setup se milte hain. Number verify hona zaroori hai aur per-message billing Meta ke saath hoti hai.
                    </p>
                    <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                        <div>
                            <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-1">Phone Number ID</label>
                            <input type="text" name="wa_phone_number_id" value="{{ old('wa_phone_number_id', $company->wa_phone_number_id) }}" placeholder="e.g. 123456789012345"
                                   autocomplete="off" data-lpignore="true" data-form-type="other" data-1p-ignore
                                   class="w-full rounded-lg border-gray-300 dark:border-gray-700 dark:bg-gray-800 dark:text-white shadow-sm focus:ring-emerald-500 focus:border-emerald-500">
                        </div>
                        <div>
                            <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-1">Permanent Access Token</label>
                            <input type="password" name="wa_api_token" value="" placeholder="{{ $hasToken ? '•••••••• (saved — blank chhoren to purana rahega)' : 'EAAG...' }}"
                                   autocomplete="new-password" data-lpignore="true" data-form-type="other" data-1p-ignore
                                   class="w-full rounded-lg border-gray-300 dark:border-gray-700 dark:bg-gray-800 dark:text-white shadow-sm focus:ring-emerald-500 focus:border-emerald-500">
                            <p class="text-xs text-gray-400 mt-1">Encrypted store hota hai. Blank chhorne par purana token barqarar rehta hai.</p>
                        </div>
                        <div>
                            <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-1">Template Name</label>
                            <input type="text" name="wa_template_name" value="{{ old('wa_template_name', $company->wa_template_name) }}" placeholder="{{ $defaultTemplate }}"
                                   class="w-full rounded-lg border-gray-300 dark:border-gray-700 dark:bg-gray-800 dark:text-white shadow-sm focus:ring-emerald-500 focus:border-emerald-500">
                            <p class="text-xs text-gray-400 mt-1">Meta se approved ENGLISH template (default: <code>{{ $defaultTemplate }}</code>).</p>
                        </div>
                        <div class="flex items-center gap-2 pt-6">
                            <input type="hidden" name="wa_attach_pdf" value="0">
                            <input type="checkbox" id="waAttachPdf" name="wa_attach_pdf" value="1" {{ old('wa_attach_pdf', $company->wa_attach_pdf ?? true) ? 'checked' : '' }}
                                   class="rounded border-gray-300 text-emerald-600 focus:ring-emerald-500">
                            <label for="waAttachPdf" class="text-sm text-gray-700 dark:text-gray-300">Invoice PDF bhi attach karein (template mein DOCUMENT header zaroori)</label>
                        </div>
                    </div>
                </div>

                <div class="bg-white dark:bg-gray-900 rounded-xl shadow-sm border border-gray-200 dark:border-gray-800 p-6">
                    <h3 class="text-lg font-semibold text-gray-800 dark:text-gray-100 mb-1">Delivery Status Webhook</h3>
                    <p class="text-sm text-gray-500 dark:text-gray-400 mb-4">
                        Sent / Delivered / Read / Failed status invoice ki Delivery History mein update ho — is ke liye Meta App ke webhook mein ye URL aur Verify Token lagayen:
                    </p>
                    <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                        <div>
                            <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-1">Callback URL (Meta App mein paste karein)</label>
                            <input type="text" readonly value="{{ $webhookUrl }}" onclick="this.select()"
                                   class="w-full rounded-lg border-gray-300 dark:border-gray-700 bg-gray-50 dark:bg-gray-800 dark:text-white shadow-sm text-sm">
                        </div>
                        <div>
                            <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-1">Verify Token (khud choose karein)</label>
                            <input type="text" name="wa_webhook_verify_token" value="{{ old('wa_webhook_verify_token', $company->wa_webhook_verify_token) }}" placeholder="koi bhi secret string"
                                   autocomplete="off" data-lpignore="true" data-form-type="other" data-1p-ignore
                                   class="w-full rounded-lg border-gray-300 dark:border-gray-700 dark:bg-gray-800 dark:text-white shadow-sm focus:ring-emerald-500 focus:border-emerald-500">
                            <p class="text-xs text-gray-400 mt-1">Yehi token Meta App ke webhook setup mein "Verify token" field mein likhein. Subscribe field: <code>messages</code>.</p>
                        </div>
                    </div>
                </div>

                <div class="bg-amber-50 dark:bg-amber-900/20 border border-amber-200 dark:border-amber-800 rounded-xl p-4 text-sm text-amber-800 dark:text-amber-200">
                    <p class="font-semibold mb-1">Template requirement (English — Meta approval zaroori)</p>
                    <p>Body: <code>Invoice @{{1}} from @{{2}}. Amount: PKR @{{3}}. View or download: @{{4}}</code></p>
                    <p class="mt-1">Agar "PDF attach" on hai to template mein <strong>DOCUMENT header</strong> bhi hona chahiye. Template approve hone tak direct send fail hoga (error Delivery History mein nazar aayega).</p>
                </div>

                <div class="flex justify-end">
                    <button type="submit" class="px-5 py-2.5 bg-emerald-600 text-white rounded-lg text-sm font-bold hover:bg-emerald-700 transition">Save karein</button>
                </div>
            </form>
        </div>
    </div>
</x-app-layout>

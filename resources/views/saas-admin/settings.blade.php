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
</div>
</x-admin-layout>

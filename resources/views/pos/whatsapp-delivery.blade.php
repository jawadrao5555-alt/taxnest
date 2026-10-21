<x-pos-layout>
<div class="max-w-4xl mx-auto p-4 sm:p-6 space-y-5">
    <h1 class="text-xl font-bold">WhatsApp auto-send</h1>
    <p class="text-sm">Final bill customer ko WhatsApp Business API se bhejein. Yeh optional hai aur default OFF hai. Manual WhatsApp button aur auto-open ki settings Customize mein barqarar hain.</p>
    @if(session('success'))<p role="status" class="p-3 rounded bg-emerald-100 text-emerald-900">{{ session('success') }}</p>@endif
    @if($errors->any())<div role="alert" class="p-3 rounded bg-red-100 text-red-900">{{ $errors->first() }}</div>@endif
    @unless($allowed)<p class="p-3 rounded bg-amber-100 text-amber-900">{{ __('pos.wa_bill_plan_locked_api') }} Auto-send OFF karna hamesha mumkin hai.</p>@endunless
    <section class="border rounded-xl p-4 space-y-3" data-whatsapp-readiness>
        <h2 class="font-bold">Saved setup checklist</h2>
        <ul class="space-y-1">@foreach($readiness['checks'] as $label => $passed)
            <li>{{ $passed ? '✓' : '✗' }} {{ $label }} — {{ $passed ? 'Ready' : 'Action needed' }}</li>
        @endforeach</ul>
        <p class="text-sm">Yeh sirf saved configuration ka check hai. Meta number/token ki validity, approved template aur webhook delivery abhi is se verify nahi hoti.</p>
        @unless($readiness['webhook_verify_token_saved'])<p class="text-sm">Webhook Verify Token save karein aur Meta mein callback verification complete karein.</p>@endunless
        @if($confirmedDelivery)
            <p class="text-sm" data-confirmed-delivery>Is company ki latest confirmed callback: Bill #{{ $confirmedDelivery->transaction_id }}, {{ $confirmedDelivery->status }}, {{ $confirmedDelivery->updated_at }} ({{ config('app.timezone') }}). Yeh us bill ki evidence hai, har naye bill ki delivery guarantee nahi.</p>
        @else
            <p class="text-sm" data-confirmed-delivery>Abhi is company ke kisi auto-send bill ka delivered/read callback record nahi mila.</p>
        @endif
        <details><summary class="cursor-pointer font-semibold">Real bill verification ke steps</summary>
            <ol class="list-decimal pl-5 text-sm space-y-2 mt-2">
                <li>Meta mein isi number ka English four-parameter template approve karein; PDF ON ho to DOCUMENT header bhi chahiye.</li>
                <li>Apne authorised test customer number ke saath ek naya final bill banayein. Auto-send ON aur package eligibility confirm karein.</li>
                <li>Recent status mein sent sirf provider acceptance hai. Customer phone aur signed delivered/read callback se delivery confirm karein.</li>
                <li>Auto-send OFF karke agla bill verify karein. Manual WhatsApp sharing alag rehti hai. Unknown status ko bina provider history dekhe dobara send na karein.</li>
            </ol>
        </details>
    </section>
    <form method="POST" action="{{ route('pos.whatsapp-delivery.save') }}" class="space-y-4 p-4 border rounded-xl bg-white dark:bg-gray-900">
        @csrf
        <input type="hidden" name="auto_send" value="0">
        <label class="flex items-center gap-3 font-bold"><input type="checkbox" name="auto_send" value="1" @checked($company->pos_whatsapp_auto_send) class="rounded"> Final bills khud bhejein (ON/OFF)</label>
        <p class="text-sm">Sirf naye final bills aur finalize hone wale provisional bills. Purane bills automatically dobara nahi bheje jate. Customer ka valid WhatsApp number zaroori hai.</p>
        <input type="hidden" name="wa_api_enabled" value="0">
        <label class="flex items-center gap-2"><input type="checkbox" name="wa_api_enabled" value="1" @checked($company->wa_api_enabled) class="rounded"> WhatsApp Business API enabled</label>
        <div class="grid sm:grid-cols-2 gap-4">
            <label class="block">Phone Number ID<input name="wa_phone_number_id" value="{{ $company->wa_phone_number_id }}" inputmode="numeric" autocomplete="off" class="block w-full rounded dark:bg-gray-800"></label>
            <label class="block">Access Token<input type="password" name="wa_api_token" value="" autocomplete="new-password" placeholder="{{ $configured ? 'Saved — blank = keep' : 'Meta access token' }}" class="block w-full rounded dark:bg-gray-800"></label>
            <label class="block">Approved English template<input name="wa_template_name" value="{{ $company->wa_template_name ?: 'invoice_notification' }}" class="block w-full rounded dark:bg-gray-800"></label>
            <label class="block">Meta App Secret<input type="password" name="wa_app_secret" value="" autocomplete="new-password" placeholder="Blank = keep saved secret" class="block w-full rounded dark:bg-gray-800"></label>
            <label class="block">Webhook Verify Token<input name="wa_webhook_verify_token" value="{{ $company->wa_webhook_verify_token }}" autocomplete="off" class="block w-full rounded dark:bg-gray-800"></label>
        </div>
        <input type="hidden" name="wa_attach_pdf" value="0">
        <label class="flex items-center gap-2"><input type="checkbox" name="wa_attach_pdf" value="1" @checked($company->wa_attach_pdf) class="rounded"> PDF document header attach karein</label>
        <p class="text-sm">Meta-approved template body: invoice number, company, PKR amount, receipt URL (isi order mein). PDF ON ho to DOCUMENT header bhi approved hona chahiye. Meta account ki messaging charges apply hoti hain.</p>
        <p class="text-sm break-all">Webhook: {{ url('/webhooks/whatsapp/'.$company->id) }} — signed status callbacks aur Verify Token Meta mein configure karein.</p>
        <button class="px-4 py-2 rounded bg-emerald-700 text-white font-bold">Save settings</button>
    </form>
    <div class="overflow-x-auto border rounded-xl p-3">
        <h2 class="font-bold mb-2">Recent auto-send status</h2>
        <p class="text-sm mb-3">Pending = queue mein; sent = provider ne accept kiya; delivered/read = signed webhook se tasdeeq. Unknown ho to provider history check karein; automatic resend nahi hoga.</p>
        <table class="w-full text-sm"><thead><tr><th class="text-left">Bill ID</th><th class="text-left">Status</th><th class="text-left">Detail</th></tr></thead><tbody>
        @forelse($deliveries as $delivery)
            <tr><td class="py-2">{{ $delivery->transaction_id }}</td><td>{{ $delivery->status }}</td><td>{{ $delivery->error }}</td></tr>
        @empty<tr><td colspan="3" class="py-3">Abhi koi auto-send queue mein nahi hai.</td></tr>@endforelse
        </tbody></table>
    </div>
</div>
</x-pos-layout>

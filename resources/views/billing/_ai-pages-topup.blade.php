<div id="ai-pages" class="bg-white dark:bg-gray-900 rounded-xl shadow-sm border border-gray-200 dark:border-gray-800 p-6 mb-8"
     x-data="{ open: false, pack: {{ $aiPackList[1]['pages'] ?? ($aiPackList[0]['pages'] ?? 100) }} }">
    <div class="flex flex-wrap items-start justify-between gap-4">
        <div>
            <h3 class="text-lg font-semibold text-gray-800 dark:text-gray-100 flex items-center gap-2">
                <svg class="w-5 h-5 text-fuchsia-500" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M13 10V3L4 14h7v7l9-11h-7z"/></svg>
                AI Reader Pages
            </h3>
            <p class="text-sm text-gray-500 dark:text-gray-400 mt-1">
                {{ number_format($aiPages['allowance_remaining']) }} of {{ number_format($aiPages['allowance']) }} monthly pages left &middot; resets {{ $aiPages['resets_on'] }}.
                @if($aiPages['purchased'] > 0)
                    You also have <span class="font-semibold text-gray-700 dark:text-gray-300">{{ number_format($aiPages['purchased']) }} purchased pages</span> that never expire.
                @endif
            </p>
        </div>
        <div class="text-right">
            <p class="text-xs text-gray-400 uppercase tracking-wide">Available now</p>
            <p class="text-2xl font-bold text-gray-900 dark:text-gray-100">{{ number_format($aiPages['total_remaining']) }}</p>
            <p class="text-xs text-gray-400">pages</p>
            <a href="{{ route('billing.ai-pages') }}" class="text-xs font-semibold text-fuchsia-600 dark:text-fuchsia-400 hover:underline">Page history</a>
        </div>
    </div>

    @if($aiTopupPending)
        <div class="mt-4 rounded-lg bg-amber-50 dark:bg-amber-900/20 border border-amber-200 dark:border-amber-700 px-4 py-3 text-sm text-amber-800 dark:text-amber-300">
            Your top-up payment is under review. The pages will be added as soon as our team verifies it.
        </div>
    @elseif($aiCanBuy)
        <div class="mt-5 grid grid-cols-1 sm:grid-cols-3 gap-3">
            @foreach($aiPackList as $aiPack)
            <label class="relative flex flex-col rounded-xl border p-4 cursor-pointer transition"
                   :class="pack === {{ $aiPack['pages'] }} ? 'border-fuchsia-500 bg-fuchsia-50 dark:bg-fuchsia-900/20 ring-1 ring-fuchsia-500' : 'border-gray-200 dark:border-gray-700 hover:border-fuchsia-300'">
                <input type="radio" class="sr-only" :checked="pack === {{ $aiPack['pages'] }}" @change="pack = {{ $aiPack['pages'] }}">
                <span class="text-lg font-bold text-gray-900 dark:text-gray-100">{{ number_format($aiPack['pages']) }} pages</span>
                <span class="text-sm font-semibold text-fuchsia-600 dark:text-fuchsia-400 mt-1">PKR {{ number_format($aiPack['price']) }}</span>
                <span class="text-[11px] text-gray-400 mt-1">Rs {{ number_format($aiPack['price'] / $aiPack['pages'], 2) }} per page</span>
            </label>
            @endforeach
        </div>

        <div class="mt-4 flex flex-wrap items-center justify-between gap-3">
            <p class="text-[11px] text-gray-400 dark:text-gray-500 max-w-md">
                Purchased pages never expire and are only used after your monthly allowance is finished. A page is charged only when a read succeeds.
            </p>
            <button type="button" @click="open = !open"
                    class="bg-fuchsia-600 text-white rounded-lg px-4 py-2 text-sm font-semibold hover:bg-fuchsia-700 transition">
                Buy Pages
            </button>
        </div>

        <div x-show="open" x-cloak class="mt-4 border-t border-gray-200 dark:border-gray-700 pt-4">
            <form method="POST" action="{{ route('payment-proof.store') }}" enctype="multipart/form-data" class="space-y-3">
                @csrf
                <input type="hidden" name="request_type" value="ai_pages">
                <input type="hidden" name="ai_pages" :value="pack">

                @if($aiBank['bank_name'] || $aiBank['account_number'] || $aiBank['iban'])
                <div class="rounded-lg bg-gray-50 dark:bg-gray-800/60 border border-gray-200 dark:border-gray-700 px-3 py-2 text-xs text-gray-600 dark:text-gray-300 space-y-0.5">
                    <p class="font-semibold text-gray-700 dark:text-gray-200">Bank details</p>
                    @if($aiBank['bank_name'])<p>{{ $aiBank['bank_name'] }}</p>@endif
                    @if($aiBank['account_title'])<p>{{ $aiBank['account_title'] }}</p>@endif
                    @if($aiBank['account_number'])<p class="font-mono">{{ $aiBank['account_number'] }}</p>@endif
                    @if($aiBank['iban'])<p class="font-mono">{{ $aiBank['iban'] }}</p>@endif
                    @if($aiBank['instructions'])<p class="text-[11px] text-gray-500 dark:text-gray-400">{{ $aiBank['instructions'] }}</p>@endif
                </div>
                @endif

                <div class="grid grid-cols-1 sm:grid-cols-2 gap-2">
                    <select name="payment_method"
                            class="w-full text-sm rounded-lg border border-gray-300 dark:border-gray-600 bg-white dark:bg-gray-900 px-3 py-2 text-gray-800 dark:text-gray-100">
                        <option value="">Payment method</option>
                        <option value="bank">Bank Transfer</option>
                        <option value="jazzcash">JazzCash</option>
                        <option value="easypaisa">EasyPaisa</option>
                        <option value="other">Other</option>
                    </select>
                    <input type="text" name="reference" maxlength="120" placeholder="Transaction reference (optional)"
                           autocomplete="off" data-lpignore="true" data-form-type="other" data-1p-ignore
                           class="w-full text-sm rounded-lg border border-gray-300 dark:border-gray-600 bg-white dark:bg-gray-900 px-3 py-2 text-gray-800 dark:text-gray-100">
                </div>
                <input type="file" name="proof" accept=".jpg,.jpeg,.png,.pdf" required
                       class="w-full text-sm text-gray-600 dark:text-gray-300 file:mr-3 file:py-2 file:px-3 file:rounded-lg file:border-0 file:text-sm file:font-semibold file:bg-fuchsia-600 file:text-white hover:file:bg-fuchsia-500">
                @error('proof')<p class="text-xs text-red-500">{{ $message }}</p>@enderror
                @error('ai_pages')<p class="text-xs text-red-500">{{ $message }}</p>@enderror
                <p class="text-[11px] text-gray-400">JPG, PNG or PDF &middot; up to 5 MB</p>

                <button type="submit" class="w-full sm:w-auto bg-fuchsia-600 text-white rounded-lg px-5 py-2.5 text-sm font-semibold hover:bg-fuchsia-700">
                    Submit Payment Proof
                </button>
            </form>
        </div>
    @endif
</div>

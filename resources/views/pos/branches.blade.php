<x-pos-layout>
{{-- PRA POS branch management (multi-branch v1, Task 1347) — PRA twin of
     fbr-pos/branches.blade.php. No delete button on purpose: a branch with
     billing history must survive, so it is only activated / deactivated. --}}
<div class="max-w-5xl mx-auto px-4 sm:px-6 lg:px-8 py-6">
    <a href="{{ route('pos.customize') }}" class="inline-flex items-center gap-1.5 text-xs font-semibold text-gray-500 dark:text-gray-400 hover:text-purple-600 dark:hover:text-purple-400 transition mb-3">
        <svg class="w-3.5 h-3.5" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M10 19l-7-7m0 0l7-7m-7 7h18"/></svg>
        {{ __('pos.back_to_customize') }}
    </a>

    <div class="flex items-center justify-between mb-6">
        <div>
            <h1 class="text-2xl font-bold text-gray-900 dark:text-white">{{ __('pos.branches_title') }}</h1>
            <p class="text-sm text-gray-500 dark:text-gray-400 mt-1">{{ __('pos.branches_desc') }}</p>
        </div>
    </div>

    @if(session('success'))<div class="mb-4 p-3 rounded-lg bg-emerald-50 dark:bg-emerald-900/20 text-emerald-700 dark:text-emerald-400 text-sm">{{ session('success') }}</div>@endif
    @if(session('error'))<div class="mb-4 p-3 rounded-lg bg-red-50 dark:bg-red-900/20 text-red-700 dark:text-red-400 text-sm">{{ session('error') }}</div>@endif
    @if($errors->any())<div class="mb-4 p-3 rounded-lg bg-red-50 dark:bg-red-900/20 text-red-700 dark:text-red-400 text-sm">{{ $errors->first() }}</div>@endif

    <div class="bg-white dark:bg-gray-900 rounded-xl border border-gray-200 dark:border-gray-700 shadow-md p-5 mb-5">
        <h2 class="text-sm font-semibold mb-3 text-gray-900 dark:text-white">{{ __('pos.add_branch') }}</h2>
        <form method="POST" action="{{ route('pos.branches.store') }}" class="grid sm:grid-cols-3 gap-3">
            @csrf
            {{-- autocomplete/lpignore guards: the login email must never
                 autofill into the branch name / city fields. --}}
            <input type="text" name="name" value="{{ old('name') }}" placeholder="{{ __('pos.ph_branch_name') }}" required autocomplete="off" data-lpignore="true" data-form-type="other" data-1p-ignore
                   class="rounded-lg border-gray-300 dark:border-gray-600 dark:bg-gray-800 dark:text-white text-sm focus:ring-purple-500 focus:border-purple-500">
            <input type="text" name="city" value="{{ old('city') }}" placeholder="{{ __('pos.city_word') }}" autocomplete="off" data-lpignore="true" data-form-type="other" data-1p-ignore
                   class="rounded-lg border-gray-300 dark:border-gray-600 dark:bg-gray-800 dark:text-white text-sm focus:ring-purple-500 focus:border-purple-500">
            <button class="bg-purple-600 text-white rounded-lg px-4 py-2 text-sm font-semibold hover:bg-purple-700">{{ __('pos.add_branch') }}</button>
        </form>
        {{-- Package limit (branch_limit + paid slots + admin override): the message
             is shown up-front, and the store() gate re-enforces it server-side. --}}
        @if(!($quota['allowed'] ?? true))
        <p class="mt-2 text-xs text-amber-600 dark:text-amber-400">{{ \App\Services\SubscriptionAccessService::localizedLockReason($quota['reason'] ?? __('pos.plan_locked_feature')) }}</p>
        @endif

        {{-- Quota summary: package ki shamil branches + khareede hue paid slots. --}}
        @if($addon['limit'] !== null)
        <p class="mt-2 text-xs text-gray-500 dark:text-gray-400">
            {{ __('pos.eb_quota_line', ['used' => $addon['used'], 'limit' => $addon['limit']]) }}
            @if($addon['override'] !== null)
                <span class="text-gray-400">({{ __('pos.eb_admin_set') }})</span>
            @elseif($addon['included'] !== null)
                <span class="text-gray-400">({{ __('pos.eb_included_line', ['included' => $addon['included'], 'slots' => $addon['slots']]) }})</span>
            @endif
        </p>
        @endif
    </div>

    {{-- ── Paid extra-branch add-on (Rs 10,000/branch/year, owner-approved Aug 2026) ──
         Package ki shamil branches MUFT hain; us se ooper har branch paid hai.
         Qeemat server (BranchAddonService) se aati hai — page kabhi apna hisaab
         nahi lagata, taake approve hone par wahi raqam charge ho. --}}
    @php
        $ebEligible = $addon['eligibility']['allowed'] ?? false;
        $ebExhausted = $addon['limit'] !== null && $addon['used'] >= $addon['limit'];
        $ebForceOpen = session('payment_proof') || $errors->has('proof') || $errors->has('extra_branch_qty');
    @endphp
    @if($addon['pending'])
    <div class="mb-5 rounded-xl border border-amber-200 dark:border-amber-800 bg-amber-50 dark:bg-amber-900/20 p-4">
        <p class="text-sm font-semibold text-amber-800 dark:text-amber-300">{{ __('pos.eb_pending_title') }}</p>
        <p class="text-xs text-amber-700 dark:text-amber-400 mt-1">{{ __('pos.eb_pending_desc') }}</p>
    </div>
    @elseif($ebEligible)
    <div class="mb-5 rounded-xl border {{ $ebExhausted ? 'border-amber-300 dark:border-amber-700 bg-amber-50 dark:bg-amber-900/20' : 'border-gray-200 dark:border-gray-700 bg-white dark:bg-gray-900' }} shadow-md p-5"
         x-data="{ open: {{ $ebForceOpen ? 'true' : 'false' }}, qty: {{ (int) old('extra_branch_qty', 1) }}, quotes: {{ \Illuminate\Support\Js::from($addon['quotes']) }},
                   prorataTpl: {{ \Illuminate\Support\Js::from(__('pos.eb_prorata_note')) }},
                   get quote() { return this.quotes[this.qty] ?? null; } }">
        <div class="flex flex-wrap items-start justify-between gap-3">
            <div>
                <h2 class="text-sm font-semibold text-gray-900 dark:text-white">
                    {{ $ebExhausted ? __('pos.eb_exhausted_title') : __('pos.eb_more_title') }}
                </h2>
                <p class="text-xs mt-1 {{ $ebExhausted ? 'text-amber-700 dark:text-amber-300' : 'text-gray-500 dark:text-gray-400' }}">
                    {{ __('pos.eb_price_line', ['price' => number_format($addon['per_year'])]) }}
                </p>
            </div>
            <button type="button" @click="open = !open"
                    class="bg-purple-600 text-white rounded-lg px-4 py-2 text-sm font-semibold hover:bg-purple-700">
                {{ __('pos.eb_buy_cta') }}
            </button>
        </div>

        <div x-show="open" x-cloak class="mt-4 border-t border-gray-200 dark:border-gray-700 pt-4">
            <form method="POST" action="{{ route('pos.payment-proof.store') }}" enctype="multipart/form-data" class="space-y-3">
                @csrf
                <input type="hidden" name="request_type" value="extra_branch">

                <div class="grid sm:grid-cols-2 gap-3">
                    <div>
                        <label class="block text-xs font-medium text-gray-600 dark:text-gray-300 mb-1">{{ __('pos.eb_qty_label') }}</label>
                        <select name="extra_branch_qty" x-model.number="qty" required
                                class="w-full text-sm rounded-lg border border-gray-300 dark:border-gray-600 bg-white dark:bg-gray-900 px-3 py-2 text-gray-800 dark:text-gray-100">
                            @foreach($addon['quotes'] as $qKey => $q)
                                <option value="{{ $qKey }}" @selected((int) old('extra_branch_qty', 1) === (int) $qKey)>{{ $qKey }}</option>
                            @endforeach
                        </select>
                    </div>
                    <div class="rounded-lg bg-emerald-50 dark:bg-emerald-900/20 border border-emerald-200 dark:border-emerald-800 px-3 py-2 self-end">
                        <p class="text-sm text-emerald-700 dark:text-emerald-300">
                            {{ __('pos.eb_payable_now') }}
                            <span class="font-bold">PKR <span x-text="quote ? Number(quote.price).toLocaleString() : ''"></span></span>
                        </p>
                        <template x-if="quote && quote.prorated">
                            <p class="text-[11px] text-emerald-600 dark:text-emerald-400 mt-0.5"
                               x-text="prorataTpl.replace(':months', quote.months).replace(':full', Number(quote.full_price).toLocaleString())"></p>
                        </template>
                        <template x-if="quote && !quote.prorated">
                            <p class="text-[11px] text-emerald-600 dark:text-emerald-400 mt-0.5">{{ __('pos.eb_full_year_note') }}</p>
                        </template>
                    </div>
                </div>
                @error('extra_branch_qty')<p class="text-xs text-red-500">{{ $message }}</p>@enderror
                <p class="text-[11px] text-gray-500 dark:text-gray-400">{{ __('pos.eb_renewal_note', ['price' => number_format($addon['per_year'])]) }}</p>

                @if($bank['bank_name'] || $bank['account_number'] || $bank['iban'])
                <div class="rounded-lg bg-gray-50 dark:bg-gray-800/60 border border-gray-200 dark:border-gray-700 px-3 py-2 text-xs text-gray-600 dark:text-gray-300 space-y-0.5">
                    <p class="font-semibold text-gray-700 dark:text-gray-200">{{ __('pos.pp_bank_details') }}</p>
                    @if($bank['bank_name'])<p>{{ $bank['bank_name'] }}</p>@endif
                    @if($bank['account_title'])<p>{{ $bank['account_title'] }}</p>@endif
                    @if($bank['account_number'])<p class="font-mono">{{ $bank['account_number'] }}</p>@endif
                    @if($bank['iban'])<p class="font-mono">{{ $bank['iban'] }}</p>@endif
                    @if($bank['instructions'])<p class="text-[11px] text-gray-500 dark:text-gray-400">{{ $bank['instructions'] }}</p>@endif
                </div>
                @endif

                <div class="grid grid-cols-1 sm:grid-cols-2 gap-2">
                    <input type="number" step="0.01" min="0" name="amount" value="{{ old('amount') }}" placeholder="{{ __('pos.pp_amount_paid') }}" autocomplete="off" data-lpignore="true" data-form-type="other" data-1p-ignore
                           class="w-full text-sm rounded-lg border border-gray-300 dark:border-gray-600 bg-white dark:bg-gray-900 px-3 py-2 text-gray-800 dark:text-gray-100">
                    <select name="payment_method"
                            class="w-full text-sm rounded-lg border border-gray-300 dark:border-gray-600 bg-white dark:bg-gray-900 px-3 py-2 text-gray-800 dark:text-gray-100">
                        <option value="">{{ __('pos.pp_payment_method') }}</option>
                        <option value="bank" @selected(old('payment_method') === 'bank')>{{ __('pos.pp_method_bank') }}</option>
                        <option value="jazzcash" @selected(old('payment_method') === 'jazzcash')>JazzCash</option>
                        <option value="easypaisa" @selected(old('payment_method') === 'easypaisa')>EasyPaisa</option>
                        <option value="other" @selected(old('payment_method') === 'other')>{{ __('pos.pp_method_other') }}</option>
                    </select>
                </div>
                <input type="text" name="reference" value="{{ old('reference') }}" maxlength="120" placeholder="{{ __('pos.pp_reference') }}" autocomplete="off" data-lpignore="true" data-form-type="other" data-1p-ignore
                       class="w-full text-sm rounded-lg border border-gray-300 dark:border-gray-600 bg-white dark:bg-gray-900 px-3 py-2 text-gray-800 dark:text-gray-100">
                <input type="file" name="proof" accept=".jpg,.jpeg,.png,.pdf" required
                       class="w-full text-sm text-gray-600 dark:text-gray-300 file:mr-3 file:py-2 file:px-3 file:rounded-lg file:border-0 file:text-sm file:font-semibold file:bg-purple-600 file:text-white hover:file:bg-purple-500">
                @error('proof')<p class="text-xs text-red-500">{{ $message }}</p>@enderror
                <p class="text-[11px] text-gray-400">{{ __('pos.pp_accepted_formats') }}</p>

                <button type="submit" class="w-full sm:w-auto bg-purple-600 text-white rounded-lg px-5 py-2.5 text-sm font-semibold hover:bg-purple-700">
                    {{ __('pos.eb_submit_cta') }}
                </button>
            </form>
        </div>
    </div>
    @elseif($ebExhausted && ($addon['eligibility']['reason_key'] ?? null))
    <div class="mb-5 rounded-xl border border-amber-200 dark:border-amber-800 bg-amber-50 dark:bg-amber-900/20 p-4">
        <p class="text-sm text-amber-800 dark:text-amber-300">{{ __($addon['eligibility']['reason_key']) }}</p>
    </div>
    @endif

    <div class="bg-white dark:bg-gray-900 rounded-xl border border-gray-200 dark:border-gray-700 shadow-md overflow-hidden" x-data="{ editId: null }">
        <table class="w-full text-sm table-cards">
            <thead class="bg-gray-50 dark:bg-gray-800 text-left">
                <tr class="text-xs uppercase tracking-wider text-gray-500 dark:text-gray-400">
                    <th class="px-4 py-3">{{ __('pos.branch_word') }}</th>
                    <th class="px-4 py-3">{{ __('pos.city_word') }}</th>
                    <th class="px-4 py-3">{{ __('pos.status_word') }}</th>
                    <th class="px-4 py-3 text-right">{{ __('pos.actions_label') }}</th>
                </tr>
            </thead>
            <tbody>
            @forelse($branches as $branch)
                <tr class="border-t border-gray-100 dark:border-gray-800">
                    <td class="px-4 py-3 font-semibold text-gray-900 dark:text-white" data-label="{{ __('pos.branch_word') }}">
                        {{ $branch->name }}
                        @if($branch->is_head_office)<span class="ml-1 px-2 py-0.5 bg-purple-100 text-purple-700 dark:bg-purple-900/30 dark:text-purple-300 rounded text-[10px] align-middle">{{ __('pos.main_branch') }}</span>@endif
                    </td>
                    <td class="px-4 py-3 text-gray-600 dark:text-gray-300" data-label="{{ __('pos.city_word') }}">{{ $branch->city ?: '—' }}</td>
                    <td class="px-4 py-3" data-label="{{ __('pos.status_word') }}">
                        @if($branch->is_active ?? true)
                            <span class="px-2 py-1 bg-emerald-100 text-emerald-700 dark:bg-emerald-900/30 dark:text-emerald-300 rounded text-xs">{{ __('pos.active_word') }}</span>
                        @else
                            <span class="px-2 py-1 bg-gray-100 text-gray-700 dark:bg-gray-800 dark:text-gray-300 rounded text-xs">{{ __('pos.inactive_word') }}</span>
                        @endif
                    </td>
                    <td class="px-4 py-3 text-right" data-label="{{ __('pos.actions_label') }}">
                        <button type="button" @click="editId = editId === {{ $branch->id }} ? null : {{ $branch->id }}" class="text-purple-600 dark:text-purple-400 hover:underline text-sm">{{ __('pos.edit') }}</button>
                        <form method="POST" action="{{ route('pos.branches.toggle', $branch->id) }}" class="inline ml-2">
                            @csrf
                            <button class="{{ ($branch->is_active ?? true) ? 'text-red-600' : 'text-emerald-600' }} hover:underline text-sm">{{ ($branch->is_active ?? true) ? __('pos.deactivate') : __('pos.activate') }}</button>
                        </form>
                    </td>
                </tr>
                <tr x-show="editId === {{ $branch->id }}" x-cloak class="border-t border-gray-100 dark:border-gray-800 bg-gray-50 dark:bg-gray-900/40">
                    <td colspan="4" class="px-4 py-4">
                        <form method="POST" action="{{ route('pos.branches.update', $branch->id) }}" class="grid sm:grid-cols-3 gap-3">
                            @csrf @method('PUT')
                            <input type="text" name="name" value="{{ $branch->name }}" required autocomplete="off" data-lpignore="true" data-form-type="other" data-1p-ignore class="rounded-lg border-gray-300 dark:border-gray-600 dark:bg-gray-800 dark:text-white text-sm focus:ring-purple-500 focus:border-purple-500">
                            <input type="text" name="city" value="{{ $branch->city }}" autocomplete="off" data-lpignore="true" data-form-type="other" data-1p-ignore class="rounded-lg border-gray-300 dark:border-gray-600 dark:bg-gray-800 dark:text-white text-sm focus:ring-purple-500 focus:border-purple-500">
                            <div class="flex gap-2">
                                <button class="bg-purple-600 text-white rounded-lg px-4 py-2 text-sm font-semibold hover:bg-purple-700">{{ __('pos.save_changes') }}</button>
                                <button type="button" @click="editId = null" class="text-gray-500 hover:underline text-sm">{{ __('pos.cancel') }}</button>
                            </div>
                        </form>
                    </td>
                </tr>
            @empty
                <tr><td colspan="4" class="px-4 py-8 text-center text-gray-500 dark:text-gray-400">{{ __('pos.no_branches_yet') }}</td></tr>
            @endforelse
            </tbody>
        </table>
    </div>

    <p class="mt-3 text-[11px] text-gray-400 dark:text-gray-500">{{ __('pos.branch_no_delete_hint') }}</p>
</div>
</x-pos-layout>

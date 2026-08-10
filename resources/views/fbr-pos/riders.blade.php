<x-fbr-pos-layout>
{{-- FBR POS Delivery Riders (Aug 2026) — admin-only (checked in controller).
     Port of pos/riders.blade.php with FBR routes and blue theme. --}}
<div class="max-w-6xl mx-auto px-4 sm:px-6 lg:px-8 py-6"
     x-data="{ editRider: null, loginRider: null }">

    <div class="flex flex-col sm:flex-row sm:items-center sm:justify-between gap-3 mb-2">
        <div class="flex items-center gap-3">
            <button type="button"
                    onclick="if (history.length > 1) { history.back(); } else { window.location = '{{ route('fbrpos.dashboard') }}'; }"
                    class="inline-flex items-center gap-1.5 px-3 py-2 rounded-lg bg-gray-100 dark:bg-gray-800 text-gray-700 dark:text-gray-300 text-sm font-semibold hover:bg-gray-200 dark:hover:bg-gray-700 transition"
                    title="{{ __('pos.ti_go_back') }}">
                <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M10 19l-7-7m0 0l7-7m-7 7h18"/></svg>
                {{ __('pos.back_word') }}
            </button>
            <h1 class="text-2xl font-bold text-gray-900 dark:text-white">{{ __('pos.delivery_riders') }}</h1>
        </div>
        <a href="{{ route('fbrpos.deliveries') }}" class="inline-flex items-center gap-1.5 px-4 py-2 rounded-lg bg-blue-600 text-white text-sm font-semibold shadow-sm hover:bg-blue-700 transition">
            <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M13 16V6a1 1 0 00-1-1H4a1 1 0 00-1 1v10a1 1 0 001 1h1m8-1a1 1 0 01-1 1H9m4-1V8a1 1 0 011-1h2.586a1 1 0 01.707.293l3.414 3.414a1 1 0 01.293.707V16a1 1 0 01-1 1h-1m-6-1a2 2 0 104 0m-4 0a2 2 0 114 0m6 0a2 2 0 104 0m-4 0a2 2 0 114 0"/></svg>
            {{ __('pos.deliveries_board') }}
        </a>
    </div>
    <p class="text-sm text-gray-500 dark:text-gray-400 mb-6">{{ __('pos.riders_page_intro') }}</p>

    @if(session('success'))
    <div class="mb-4 p-3 rounded-lg bg-emerald-50 dark:bg-emerald-900/20 text-emerald-700 dark:text-emerald-400 text-sm">{{ session('success') }}</div>
    @endif
    @if(session('error'))
    <div class="mb-4 p-3 rounded-lg bg-red-50 dark:bg-red-900/20 text-red-700 dark:text-red-400 text-sm">{{ session('error') }}</div>
    @endif
    @if($errors->any())
    <div class="mb-4 p-3 rounded-lg bg-red-50 dark:bg-red-900/20 text-red-700 dark:text-red-400 text-sm">
        <ul class="list-disc pl-4">@foreach($errors->all() as $error)<li>{{ $error }}</li>@endforeach</ul>
    </div>
    @endif

    {{-- Add rider --}}
    <div class="bg-white dark:bg-gray-900 rounded-xl border border-gray-200 dark:border-gray-700 shadow-sm p-5 mb-6">
        <h3 class="text-sm font-semibold text-gray-900 dark:text-white mb-4">{{ __('pos.add_new_rider') }}</h3>
        <form method="POST" action="{{ route('fbrpos.riders.store') }}">
            @csrf
            <div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-5 gap-4">
                <div>
                    <label class="block text-xs font-medium text-gray-600 dark:text-gray-400 mb-1">{{ __('pos.rider_name_req') }}</label>
                    <input type="text" name="name" required maxlength="120" placeholder="{{ __('pos.ph_eg_imran_ali') }}" class="w-full rounded-lg border-gray-300 dark:border-gray-600 dark:bg-gray-800 dark:text-white text-sm focus:ring-blue-500 focus:border-blue-500">
                </div>
                <div>
                    <label class="block text-xs font-medium text-gray-600 dark:text-gray-400 mb-1">{{ __('pos.phone_label') }}</label>
                    <input type="text" name="phone" maxlength="30" placeholder="03xx-xxxxxxx" class="w-full rounded-lg border-gray-300 dark:border-gray-600 dark:bg-gray-800 dark:text-white text-sm focus:ring-blue-500 focus:border-blue-500">
                </div>
                <div>
                    <label class="block text-xs font-medium text-gray-600 dark:text-gray-400 mb-1">{{ __('pos.cnic_label') }}</label>
                    <input type="text" name="cnic" maxlength="20" placeholder="{{ __('pos.ph_optional') }}" class="w-full rounded-lg border-gray-300 dark:border-gray-600 dark:bg-gray-800 dark:text-white text-sm focus:ring-blue-500 focus:border-blue-500">
                </div>
                <div>
                    <label class="block text-xs font-medium text-gray-600 dark:text-gray-400 mb-1">{{ __('pos.vehicle_no_label') }}</label>
                    <input type="text" name="vehicle_no" maxlength="30" placeholder="{{ __('pos.ph_optional') }}" class="w-full rounded-lg border-gray-300 dark:border-gray-600 dark:bg-gray-800 dark:text-white text-sm focus:ring-blue-500 focus:border-blue-500">
                </div>
                <div class="flex items-end">
                    <button type="submit" class="w-full px-4 py-2 rounded-lg bg-blue-600 text-white text-sm font-semibold shadow-sm hover:bg-blue-700 transition">{{ __('pos.add_rider') }}</button>
                </div>
            </div>
        </form>
    </div>

    {{-- Riders table --}}
    <div class="bg-white dark:bg-gray-900 rounded-xl border border-gray-200 dark:border-gray-700 shadow-sm overflow-hidden mb-6">
        <div class="overflow-x-auto">
            <table class="min-w-full text-sm">
                <thead class="bg-gray-50 dark:bg-gray-800/60">
                    <tr class="text-left text-[11px] uppercase tracking-wide text-gray-500 dark:text-gray-400">
                        <th class="px-4 py-3">{{ __('pos.rider_label') }}</th>
                        <th class="px-4 py-3">{{ __('pos.phone_label') }}</th>
                        <th class="px-4 py-3">{{ __('pos.vehicle_label') }}</th>
                        <th class="px-4 py-3">{{ __('pos.cash_khata') }}</th>
                        <th class="px-4 py-3">{{ __('pos.login_label') }}</th>
                        <th class="px-4 py-3">{{ __('pos.status_label') }}</th>
                        <th class="px-4 py-3 text-right">{{ __('pos.actions_label') }}</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-gray-100 dark:divide-gray-800">
                    @forelse($riders as $rider)
                    @php
                        $k = $khata[$rider->id] ?? null;
                        $loginUser = $rider->user_id ? ($riderUsers[$rider->user_id] ?? null) : null;
                    @endphp
                    <tr>
                        <td class="px-4 py-3">
                            <div class="font-semibold text-gray-900 dark:text-white">{{ $rider->name }}</div>
                            @if($rider->cnic)<div class="text-[11px] text-gray-400">{{ __('pos.cnic_colon') }} {{ $rider->cnic }}</div>@endif
                        </td>
                        <td class="px-4 py-3 text-gray-600 dark:text-gray-300">{{ $rider->phone ?: '—' }}</td>
                        <td class="px-4 py-3 text-gray-600 dark:text-gray-300">{{ $rider->vehicle_no ?: '—' }}</td>
                        <td class="px-4 py-3">
                            @if($k && $k->owed > 0)
                                <span class="font-bold text-amber-600 dark:text-amber-400">Rs. {{ number_format((float) $k->owed) }}</span>
                                <span class="text-[11px] text-gray-400">({{ $k->bills }}{{ __('pos.sfx_bills') }})</span>
                            @else
                                <span class="text-emerald-600 dark:text-emerald-400 text-xs font-semibold">{{ __('pos.clear') }}</span>
                            @endif
                        </td>
                        <td class="px-4 py-3">
                            @if($loginUser)
                                <div class="text-xs text-gray-600 dark:text-gray-300">{{ $loginUser->email }}</div>
                                @if(isset($riderPasswords[$rider->id]))
                                <div x-data="{ show: false }" class="text-[11px] text-gray-400">
                                    <span x-show="!show"><button type="button" @click="show = true" class="underline hover:text-blue-600">{{ __('pos.show_password') }}</button></span>
                                    <span x-show="show" x-cloak class="font-mono">{{ $riderPasswords[$rider->id] }}</span>
                                </div>
                                @endif
                            @else
                                <span class="text-[11px] text-gray-400">{{ __('pos.no_login') }}</span>
                            @endif
                        </td>
                        <td class="px-4 py-3">
                            @if($rider->is_active)
                                <span class="inline-flex px-2 py-0.5 rounded-full text-[11px] font-semibold bg-emerald-50 dark:bg-emerald-900/20 text-emerald-700 dark:text-emerald-400">{{ __('pos.active_word') }}</span>
                            @else
                                <span class="inline-flex px-2 py-0.5 rounded-full text-[11px] font-semibold bg-gray-100 dark:bg-gray-800 text-gray-500 dark:text-gray-400">{{ __('pos.inactive_word') }}</span>
                            @endif
                        </td>
                        <td class="px-4 py-3 text-right whitespace-nowrap">
                            <button type="button" class="px-2.5 py-1 rounded-lg text-xs font-semibold text-blue-700 dark:text-blue-300 bg-blue-50 dark:bg-blue-900/20 hover:bg-blue-100 dark:hover:bg-blue-900/40 transition"
                                    @click="editRider = {{ json_encode(['id' => $rider->id, 'name' => $rider->name, 'phone' => $rider->phone, 'cnic' => $rider->cnic, 'vehicle_no' => $rider->vehicle_no, 'is_active' => (bool) $rider->is_active], JSON_INVALID_UTF8_SUBSTITUTE) ?: '{}' }}">{{ __('pos.edit') }}</button>
                            <button type="button" class="px-2.5 py-1 rounded-lg text-xs font-semibold text-gray-700 dark:text-gray-300 bg-gray-100 dark:bg-gray-800 hover:bg-gray-200 dark:hover:bg-gray-700 transition"
                                    @click="loginRider = {{ json_encode(['id' => $rider->id, 'name' => $rider->name, 'has_login' => (bool) $loginUser, 'email' => $loginUser->email ?? ''], JSON_INVALID_UTF8_SUBSTITUTE) ?: '{}' }}">{{ $loginUser ? __('pos.reset_password') : __('pos.create_login') }}</button>
                        </td>
                    </tr>
                    @empty
                    <tr><td colspan="7" class="px-4 py-8 text-center text-sm text-gray-400">{{ __('pos.no_riders_yet') }}</td></tr>
                    @endforelse
                </tbody>
            </table>
        </div>
    </div>

    {{-- Recent settlements --}}
    <div class="bg-white dark:bg-gray-900 rounded-xl border border-gray-200 dark:border-gray-700 shadow-sm overflow-hidden">
        <div class="px-4 py-3 border-b border-gray-100 dark:border-gray-800">
            <h3 class="text-sm font-semibold text-gray-900 dark:text-white">{{ __('pos.recent_settlements') }}</h3>
        </div>
        <div class="overflow-x-auto">
            <table class="min-w-full text-sm">
                <thead class="bg-gray-50 dark:bg-gray-800/60">
                    <tr class="text-left text-[11px] uppercase tracking-wide text-gray-500 dark:text-gray-400">
                        <th class="px-4 py-2.5">{{ __('pos.date_label') }}</th>
                        <th class="px-4 py-2.5">{{ __('pos.rider_label') }}</th>
                        <th class="px-4 py-2.5">{{ __('pos.bills_label') }}</th>
                        <th class="px-4 py-2.5">{{ __('pos.amount_label') }}</th>
                        <th class="px-4 py-2.5">{{ __('pos.received_by') }}</th>
                        <th class="px-4 py-2.5">{{ __('pos.notes_label') }}</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-gray-100 dark:divide-gray-800">
                    @forelse($settlements as $s)
                    <tr>
                        <td class="px-4 py-2.5 text-gray-600 dark:text-gray-300">{{ $s->created_at->format('d/m/Y h:i A') }}</td>
                        <td class="px-4 py-2.5 font-medium text-gray-900 dark:text-white">{{ $s->rider->name ?? '—' }}</td>
                        <td class="px-4 py-2.5 text-gray-600 dark:text-gray-300">{{ $s->bill_count }}</td>
                        <td class="px-4 py-2.5 font-semibold text-gray-900 dark:text-white">Rs. {{ number_format((float) $s->total_amount) }}</td>
                        <td class="px-4 py-2.5 text-gray-600 dark:text-gray-300">{{ $s->settledBy->name ?? '—' }}</td>
                        <td class="px-4 py-2.5 text-gray-500 dark:text-gray-400 text-xs">{{ $s->notes ?: '—' }}</td>
                    </tr>
                    @empty
                    <tr><td colspan="6" class="px-4 py-6 text-center text-sm text-gray-400">{{ __('pos.no_settlements_yet') }}</td></tr>
                    @endforelse
                </tbody>
            </table>
        </div>
    </div>

    {{-- Edit rider modal --}}
    <div x-show="editRider" x-cloak class="fixed inset-0 z-50 flex items-center justify-center p-4">
        <div class="absolute inset-0 bg-black/50" @click="editRider = null"></div>
        <div class="relative bg-white dark:bg-gray-900 rounded-xl shadow-xl border border-gray-200 dark:border-gray-700 w-full max-w-md p-5">
            <h3 class="text-sm font-semibold text-gray-900 dark:text-white mb-4">{{ __('pos.edit_rider') }}</h3>
            <form method="POST" :action="'{{ url('/fbr-pos/riders') }}/' + (editRider ? editRider.id : '')">
                @csrf
                @method('PUT')
                <div class="space-y-3">
                    <div>
                        <label class="block text-xs font-medium text-gray-600 dark:text-gray-400 mb-1">{{ __('pos.name_req') }}</label>
                        <input type="text" name="name" required maxlength="120" x-effect="if (editRider) $el.value = editRider.name || ''" class="w-full rounded-lg border-gray-300 dark:border-gray-600 dark:bg-gray-800 dark:text-white text-sm focus:ring-blue-500 focus:border-blue-500">
                    </div>
                    <div>
                        <label class="block text-xs font-medium text-gray-600 dark:text-gray-400 mb-1">{{ __('pos.phone_label') }}</label>
                        <input type="text" name="phone" maxlength="30" x-effect="if (editRider) $el.value = editRider.phone || ''" class="w-full rounded-lg border-gray-300 dark:border-gray-600 dark:bg-gray-800 dark:text-white text-sm focus:ring-blue-500 focus:border-blue-500">
                    </div>
                    <div>
                        <label class="block text-xs font-medium text-gray-600 dark:text-gray-400 mb-1">{{ __('pos.cnic_label') }}</label>
                        <input type="text" name="cnic" maxlength="20" x-effect="if (editRider) $el.value = editRider.cnic || ''" class="w-full rounded-lg border-gray-300 dark:border-gray-600 dark:bg-gray-800 dark:text-white text-sm focus:ring-blue-500 focus:border-blue-500">
                    </div>
                    <div>
                        <label class="block text-xs font-medium text-gray-600 dark:text-gray-400 mb-1">{{ __('pos.vehicle_no_label') }}</label>
                        <input type="text" name="vehicle_no" maxlength="30" x-effect="if (editRider) $el.value = editRider.vehicle_no || ''" class="w-full rounded-lg border-gray-300 dark:border-gray-600 dark:bg-gray-800 dark:text-white text-sm focus:ring-blue-500 focus:border-blue-500">
                    </div>
                    <input type="hidden" name="is_active" :value="editRider && editRider.is_active ? 1 : 0">
                    <label class="flex items-center gap-2 text-sm text-gray-700 dark:text-gray-300">
                        <input type="checkbox" class="rounded border-gray-300 text-blue-600 focus:ring-blue-500"
                               :checked="editRider && editRider.is_active"
                               @change="if (editRider) editRider.is_active = $event.target.checked">
                        {{ __('pos.active_rider_hint') }}
                    </label>
                </div>
                <div class="flex justify-end gap-2 mt-5">
                    <button type="button" class="px-4 py-2 rounded-lg text-sm font-semibold text-gray-600 dark:text-gray-300 bg-gray-100 dark:bg-gray-800 hover:bg-gray-200 dark:hover:bg-gray-700 transition" @click="editRider = null">{{ __('pos.cancel') }}</button>
                    <button type="submit" class="px-4 py-2 rounded-lg bg-blue-600 text-white text-sm font-semibold shadow-sm hover:bg-blue-700 transition">{{ __('pos.save_changes') }}</button>
                </div>
            </form>
        </div>
    </div>

    {{-- Create / reset login modal --}}
    <div x-show="loginRider" x-cloak class="fixed inset-0 z-50 flex items-center justify-center p-4">
        <div class="absolute inset-0 bg-black/50" @click="loginRider = null"></div>
        <div class="relative bg-white dark:bg-gray-900 rounded-xl shadow-xl border border-gray-200 dark:border-gray-700 w-full max-w-md p-5">
            <h3 class="text-sm font-semibold text-gray-900 dark:text-white mb-1" x-text="loginRider && loginRider.has_login ? {{ Js::from(__('pos.reset_rider_password')) }} : {{ Js::from(__('pos.create_rider_login')) }}"></h3>
            <p class="text-xs text-gray-500 dark:text-gray-400 mb-4">{{ __('pos.rider_login_hint') }}</p>
            <form method="POST" :action="'{{ url('/fbr-pos/riders') }}/' + (loginRider ? loginRider.id : '') + '/login'">
                @csrf
                <div class="space-y-3">
                    <template x-if="loginRider && !loginRider.has_login">
                        <div>
                            <label class="block text-xs font-medium text-gray-600 dark:text-gray-400 mb-1">{{ __('pos.login_email_req') }}</label>
                            <input type="email" name="email" required class="w-full rounded-lg border-gray-300 dark:border-gray-600 dark:bg-gray-800 dark:text-white text-sm focus:ring-blue-500 focus:border-blue-500">
                        </div>
                    </template>
                    <template x-if="loginRider && loginRider.has_login">
                        <p class="text-xs text-gray-500 dark:text-gray-400">{{ __('pos.login_colon') }} <span class="font-mono" x-text="loginRider.email"></span></p>
                    </template>
                    <div>
                        <label class="block text-xs font-medium text-gray-600 dark:text-gray-400 mb-1">{{ __('pos.password_req') }}</label>
                        <input type="text" name="password" required minlength="6" maxlength="100" autocomplete="off" class="w-full rounded-lg border-gray-300 dark:border-gray-600 dark:bg-gray-800 dark:text-white text-sm focus:ring-blue-500 focus:border-blue-500">
                    </div>
                </div>
                <div class="flex justify-end gap-2 mt-5">
                    <button type="button" class="px-4 py-2 rounded-lg text-sm font-semibold text-gray-600 dark:text-gray-300 bg-gray-100 dark:bg-gray-800 hover:bg-gray-200 dark:hover:bg-gray-700 transition" @click="loginRider = null">{{ __('pos.cancel') }}</button>
                    <button type="submit" class="px-4 py-2 rounded-lg bg-blue-600 text-white text-sm font-semibold shadow-sm hover:bg-blue-700 transition" x-text="loginRider && loginRider.has_login ? {{ Js::from(__('pos.reset_password')) }} : {{ Js::from(__('pos.create_login')) }}"></button>
                </div>
            </form>
        </div>
    </div>
</div>
</x-fbr-pos-layout>

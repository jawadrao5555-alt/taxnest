<x-pos-layout>
<div class="max-w-5xl mx-auto px-4 sm:px-6 lg:px-8 py-6">
    @include('pos.partials.back-link')

    {{-- ═══ Biometric Device Setup (4 Aug 2026) ═══
         Admin-only: register ZKTeco/compatible devices + map device PINs
         to POS users. Token-based ADMS push endpoint. --}}

    <div class="flex flex-col sm:flex-row sm:items-center sm:justify-between gap-3 mb-6">
        <div>
            <h1 class="text-2xl font-bold text-gray-900 dark:text-white">{{ __('pos.bio_setup_title') }}</h1>
            <p class="text-xs text-gray-500 dark:text-gray-400 mt-0.5">{{ __('pos.bio_setup_sub') }}</p>
        </div>
        <div class="flex gap-2">
            <a href="{{ route('pos.bio-sync.import') }}" class="inline-flex items-center gap-2 px-4 py-2 bg-emerald-600 text-white text-sm font-semibold rounded-lg hover:bg-emerald-700 transition">
                <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 16v1a3 3 0 003 3h10a3 3 0 003-3v-1m-4-8l4-4m0 0l4 4m-4-4v12"/></svg>
                {{ __('pos.bio_import_btn') }}
            </a>
            <button onclick="document.getElementById('add-device-form').classList.toggle('hidden')" class="inline-flex items-center gap-2 px-4 py-2 bg-purple-600 text-white text-sm font-semibold rounded-lg hover:bg-purple-700 transition">
                <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 4v16m8-8H4"/></svg>
                {{ __('pos.bio_add_device') }}
            </button>
        </div>
    </div>

    @if(session('success'))
    <div class="mb-4 px-4 py-3 rounded-lg bg-emerald-50 dark:bg-emerald-900/20 border border-emerald-200 dark:border-emerald-700 text-sm font-semibold text-emerald-700 dark:text-emerald-300">
        {{ session('success') }}
    </div>
    @endif

    @if($errors->any())
    <div class="mb-4 px-4 py-3 rounded-lg bg-red-50 dark:bg-red-900/20 border border-red-200 dark:border-red-700 text-sm text-red-700 dark:text-red-300">
        @foreach($errors->all() as $e)<div>{{ $e }}</div>@endforeach
    </div>
    @endif

    {{-- Add device form (hidden by default) --}}
    <div id="add-device-form" class="hidden mb-6 bg-white dark:bg-gray-900 rounded-xl border border-gray-200 dark:border-gray-700 shadow-sm p-5">
        <h2 class="text-base font-bold text-gray-800 dark:text-white mb-4">{{ __('pos.bio_add_device') }}</h2>
        <form method="POST" action="{{ route('pos.bio-sync.store-device') }}">
            @csrf
            <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
                <div>
                    <label class="block text-xs font-semibold text-gray-600 dark:text-gray-400 mb-1">{{ __('pos.bio_device_label') }} <span class="text-red-500">*</span></label>
                    <input type="text" name="label" required maxlength="100" placeholder="{{ __('pos.bio_device_label_ph') }}"
                           class="w-full rounded-lg border border-gray-300 dark:border-gray-600 bg-white dark:bg-gray-800 text-gray-900 dark:text-gray-100 text-sm px-3 py-2 focus:ring-2 focus:ring-purple-500 transition">
                </div>
                <div>
                    <label class="block text-xs font-semibold text-gray-600 dark:text-gray-400 mb-1">{{ __('pos.bio_device_sn') }} <span class="text-gray-400 font-normal">({{ __('pos.optional') }})</span></label>
                    <input type="text" name="device_sn" maxlength="100" placeholder="{{ __('pos.bio_device_sn_ph') }}"
                           class="w-full rounded-lg border border-gray-300 dark:border-gray-600 bg-white dark:bg-gray-800 text-gray-900 dark:text-gray-100 text-sm px-3 py-2 focus:ring-2 focus:ring-purple-500 transition">
                </div>
            </div>
            <div class="mt-4 flex items-center gap-3">
                <button type="submit" class="px-5 py-2 bg-purple-600 text-white text-sm font-semibold rounded-lg hover:bg-purple-700 transition">{{ __('pos.save') }}</button>
                <button type="button" onclick="document.getElementById('add-device-form').classList.add('hidden')" class="text-sm text-gray-500 hover:text-gray-700 dark:hover:text-gray-300">{{ __('pos.cancel') }}</button>
            </div>
        </form>
    </div>

    {{-- ── Unmapped PIN Alert (Aug 2026) ──────────────────────────────────── --}}
    @if($unmappedPins->isNotEmpty())
    <div class="mb-6 bg-amber-50 dark:bg-amber-900/20 border border-amber-300 dark:border-amber-700 rounded-xl overflow-hidden">
        <div class="flex items-start gap-3 px-4 py-3 bg-amber-100 dark:bg-amber-900/30 border-b border-amber-200 dark:border-amber-700">
            <svg class="w-5 h-5 text-amber-600 dark:text-amber-400 mt-0.5 flex-shrink-0" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 9v2m0 4h.01M10.29 3.86L1.82 18a2 2 0 001.71 3h16.94a2 2 0 001.71-3L13.71 3.86a2 2 0 00-3.42 0z"/></svg>
            <div>
                <p class="text-sm font-bold text-amber-800 dark:text-amber-200">{{ __('pos.bio_unmapped_banner_title', ['count' => $unmappedPins->count()]) }}</p>
                <p class="text-xs text-amber-700 dark:text-amber-300 mt-0.5">{{ __('pos.bio_unmapped_banner_sub') }}</p>
            </div>
        </div>
        <div class="divide-y divide-amber-100 dark:divide-amber-800/40">
            @foreach($unmappedPins as $upin)
            <div class="flex flex-col sm:flex-row sm:items-center gap-3 px-4 py-3">
                <div class="flex-1 min-w-0">
                    <span class="inline-flex items-center gap-1.5">
                        <span class="font-mono font-bold text-gray-900 dark:text-white text-sm">PIN {{ $upin->device_pin }}</span>
                        <span class="px-1.5 py-0.5 rounded-full text-[10px] font-bold bg-amber-200 dark:bg-amber-800 text-amber-800 dark:text-amber-200">
                            {{ $upin->punch_count }} {{ __('pos.bio_punches') }}
                        </span>
                    </span>
                    <p class="text-[11px] text-gray-400 mt-0.5">{{ __('pos.bio_last_seen') }}: {{ \Carbon\Carbon::parse($upin->last_punch_at)->format('d M, h:i A') }}</p>
                </div>
                <form method="POST" action="{{ route('pos.bio-sync.quick-map') }}" class="flex items-center gap-2">
                    @csrf
                    <input type="hidden" name="device_pin" value="{{ $upin->device_pin }}">
                    <select name="user_id" required
                            class="rounded-lg border border-amber-300 dark:border-amber-600 bg-white dark:bg-gray-800 text-gray-900 dark:text-gray-100 text-sm px-3 py-1.5 focus:ring-2 focus:ring-amber-500 transition min-w-[160px]">
                        <option value="">— {{ __('pos.bio_select_user') }} —</option>
                        @foreach($posUsers as $u)
                        <option value="{{ $u->id }}">{{ $u->name }}</option>
                        @endforeach
                    </select>
                    <button type="submit" class="px-3 py-1.5 bg-amber-600 hover:bg-amber-700 text-white text-xs font-semibold rounded-lg transition whitespace-nowrap">
                        {{ __('pos.bio_map_now') }}
                    </button>
                </form>
            </div>
            @endforeach
        </div>
    </div>
    @endif

    {{-- Protocol help banner --}}
    <div class="mb-6 bg-blue-50 dark:bg-blue-900/20 border border-blue-200 dark:border-blue-700 rounded-xl p-4">
        <div class="flex items-start gap-3">
            <svg class="w-5 h-5 text-blue-500 mt-0.5 flex-shrink-0" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M13 16h-1v-4h-1m1-4h.01M21 12a9 9 0 11-18 0 9 9 0 0118 0z"/></svg>
            <div class="text-sm text-blue-800 dark:text-blue-200">
                <p class="font-semibold mb-1">{{ __('pos.bio_how_it_works') }}</p>
                <p class="text-xs leading-relaxed">{{ __('pos.bio_protocol_hint') }}</p>
                <p class="text-xs mt-1 text-blue-600 dark:text-blue-400">{{ __('pos.bio_brands_hint') }}</p>
            </div>
        </div>
    </div>

    {{-- Device cards --}}
    @forelse($devices as $device)
    <div class="mb-6 bg-white dark:bg-gray-900 rounded-xl border border-gray-200 dark:border-gray-700 shadow-sm overflow-hidden">
        {{-- Device header --}}
        <div class="px-5 py-4 bg-gray-50 dark:bg-gray-800 border-b border-gray-100 dark:border-gray-700 flex items-center justify-between gap-3 flex-wrap">
            <div class="flex items-center gap-3">
                <div class="w-9 h-9 rounded-lg bg-purple-100 dark:bg-purple-900/30 flex items-center justify-center">
                    <svg class="w-5 h-5 text-purple-600 dark:text-purple-400" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 3H5a2 2 0 00-2 2v4m6-6h10a2 2 0 012 2v4M9 3v18m0 0h10a2 2 0 002-2V9M9 21H5a2 2 0 01-2-2V9m0 0h18"/></svg>
                </div>
                <div>
                    <p class="font-bold text-gray-900 dark:text-white text-sm">{{ $device->label }}</p>
                    <p class="text-xs text-gray-500 dark:text-gray-400">
                        @if($device->device_sn)SN: {{ $device->device_sn }} · @endif
                        @if($device->last_punch)
                            {{ __('pos.bio_last_punch') }}: {{ \Carbon\Carbon::parse($device->last_punch)->format('d M, h:i A') }}
                        @else
                            {{ __('pos.bio_no_punches_yet') }}
                        @endif
                    </p>
                </div>
            </div>
            <div class="flex items-center gap-2">
                @if(!$device->is_active)
                <span class="px-2 py-0.5 rounded-full bg-red-100 dark:bg-red-900/30 text-red-700 dark:text-red-300 text-xs font-bold">{{ __('pos.bio_inactive') }}</span>
                @endif
                <form method="POST" action="{{ route('pos.bio-sync.toggle-device', $device->id) }}" class="inline">
                    @csrf
                    <button type="submit" class="text-xs px-3 py-1.5 rounded-lg border border-gray-300 dark:border-gray-600 text-gray-600 dark:text-gray-300 hover:bg-gray-100 dark:hover:bg-gray-700 transition">
                        {{ $device->is_active ? __('pos.bio_disable') : __('pos.bio_enable') }}
                    </button>
                </form>
                <form method="POST" action="{{ route('pos.bio-sync.destroy-device', $device->id) }}" class="inline" onsubmit="return confirm({{ Js::from(__('pos.bio_confirm_delete')) }})">
                    @csrf @method('DELETE')
                    <button type="submit" class="text-xs px-3 py-1.5 rounded-lg border border-red-200 dark:border-red-700 text-red-600 dark:text-red-400 hover:bg-red-50 dark:hover:bg-red-900/20 transition">
                        {{ __('pos.delete') }}
                    </button>
                </form>
            </div>
        </div>

        {{-- Push URL (for device config) --}}
        <div class="px-5 py-3 border-b border-gray-100 dark:border-gray-700 bg-white dark:bg-gray-900">
            <p class="text-xs font-semibold text-gray-500 dark:text-gray-400 mb-1">{{ __('pos.bio_push_url_label') }}</p>
            @php
                $pushUrl = url('/bio-sync/' . $device->push_token . '/iclock/cdata');
            @endphp
            <div class="flex items-center gap-2">
                <code id="url-{{ $device->id }}" class="flex-1 text-xs bg-gray-100 dark:bg-gray-800 text-gray-800 dark:text-gray-200 px-3 py-2 rounded-lg font-mono break-all">{{ $pushUrl }}</code>
                <button onclick="navigator.clipboard.writeText(document.getElementById('url-{{ $device->id }}').textContent.trim()); this.textContent='✓'" class="text-xs px-3 py-2 bg-purple-600 text-white rounded-lg hover:bg-purple-700 transition whitespace-nowrap">{{ __('pos.copy') }}</button>
            </div>
            <p class="text-[11px] text-gray-400 mt-1">{{ __('pos.bio_push_url_hint') }}</p>
            <p class="text-[11px] text-amber-600 dark:text-amber-400 mt-1">{{ __('pos.bio_push_url_hint_domain', ['host' => request()->getHost()]) }}</p>
        </div>

        {{-- PIN → User mapping --}}
        <div class="px-5 py-4">
            <p class="text-sm font-bold text-gray-800 dark:text-white mb-3">{{ __('pos.bio_pin_mapping') }}</p>
            <form method="POST" action="{{ route('pos.bio-sync.save-mapping', $device->id) }}" x-data="bioMapping({{ Js::from($device->maps->map(fn($m) => ['pin' => $m->device_pin, 'user_id' => $m->user_id])) }})">
                @csrf
                <template x-for="(row, i) in rows" :key="i">
                    <div class="flex items-center gap-2 mb-2">
                        <input type="text" :name="'mappings[' + i + '][device_pin]'" x-model="row.pin" placeholder="{{ __('pos.bio_pin_ph') }}" maxlength="50"
                               class="w-28 rounded-lg border border-gray-300 dark:border-gray-600 bg-white dark:bg-gray-800 text-gray-900 dark:text-gray-100 text-sm px-3 py-2 focus:ring-2 focus:ring-purple-500 transition">
                        <span class="text-gray-400 text-xs">→</span>
                        <select :name="'mappings[' + i + '][user_id]'" x-model="row.user_id"
                                class="flex-1 rounded-lg border border-gray-300 dark:border-gray-600 bg-white dark:bg-gray-800 text-gray-900 dark:text-gray-100 text-sm px-3 py-2 focus:ring-2 focus:ring-purple-500 transition">
                            <option value="">— {{ __('pos.bio_select_user') }} —</option>
                            @foreach($posUsers as $u)
                            <option value="{{ $u->id }}">{{ $u->name }}</option>
                            @endforeach
                        </select>
                        <button type="button" @click="rows.splice(i, 1)" class="text-red-400 hover:text-red-600 transition">
                            <svg class="w-5 h-5" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"/></svg>
                        </button>
                    </div>
                </template>
                <div class="flex items-center gap-3 mt-3">
                    <button type="button" @click="rows.push({pin:'',user_id:''})" class="text-sm text-purple-600 dark:text-purple-400 hover:underline flex items-center gap-1">
                        <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 4v16m8-8H4"/></svg>
                        {{ __('pos.bio_add_row') }}
                    </button>
                    <button type="submit" class="px-4 py-1.5 bg-teal-600 text-white text-sm font-semibold rounded-lg hover:bg-teal-700 transition">{{ __('pos.bio_save_mapping') }}</button>
                </div>
            </form>
        </div>
    </div>
    @empty
    <div class="bg-white dark:bg-gray-900 rounded-xl border border-gray-200 dark:border-gray-700 shadow-sm p-10 text-center">
        <svg class="w-10 h-10 mx-auto text-gray-300 dark:text-gray-600 mb-3" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5" d="M9 3H5a2 2 0 00-2 2v4m6-6h10a2 2 0 012 2v4M9 3v18m0 0h10a2 2 0 002-2V9M9 21H5a2 2 0 01-2-2V9m0 0h18"/></svg>
        <p class="text-sm font-semibold text-gray-500 dark:text-gray-400">{{ __('pos.bio_no_devices') }}</p>
        <p class="text-xs text-gray-400 mt-1">{{ __('pos.bio_no_devices_hint') }}</p>
    </div>
    @endforelse
</div>

<script>
function bioMapping(initial) {
    return {
        rows: initial.length ? initial.map(r => ({pin: r.pin, user_id: String(r.user_id)})) : [],
    };
}
</script>
</x-pos-layout>

<x-fbr-pos-layout>
<div class="max-w-3xl mx-auto px-4 sm:px-6 lg:px-8 py-6">
    @include('fbr-pos.partials.back-link')

    {{-- ═══ Biometric CSV / Excel Import — FBR panel port (Aug 2026) ═══
         Fallback for devices without internet / ADMS push.
         Accepts CSV or Excel punch export from the device software.
         Parsing/dedupe logic is shared with PRA (PosBiometricController). --}}

    <div class="mb-6">
        <h1 class="text-2xl font-bold text-gray-900 dark:text-white">{{ __('pos.bio_import_title') }}</h1>
        <p class="text-xs text-gray-500 dark:text-gray-400 mt-0.5">{{ __('pos.bio_import_sub') }}</p>
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

    <div class="bg-white dark:bg-gray-900 rounded-xl border border-gray-200 dark:border-gray-700 shadow-sm p-6">
        <form method="POST" action="{{ route('fbrpos.bio-sync.process-import') }}" enctype="multipart/form-data">
            @csrf

            <div class="mb-5">
                <label class="block text-sm font-semibold text-gray-700 dark:text-gray-300 mb-1">{{ __('pos.bio_import_file') }} <span class="text-red-500">*</span></label>
                <input type="file" name="punch_file" required accept=".csv,.txt,.xlsx,.xls"
                       class="w-full text-sm text-gray-700 dark:text-gray-200 file:mr-4 file:py-2 file:px-4 file:rounded-lg file:border-0 file:text-sm file:font-semibold file:bg-blue-50 dark:file:bg-blue-900/30 file:text-blue-700 dark:file:text-blue-300 hover:file:bg-blue-100 dark:hover:file:bg-blue-900/50 transition">
                <p class="text-xs text-gray-400 mt-1">{{ __('pos.bio_import_formats') }}</p>
            </div>

            <div class="mb-6">
                <label class="block text-sm font-semibold text-gray-700 dark:text-gray-300 mb-1">{{ __('pos.bio_import_device') }} <span class="text-gray-400 font-normal text-xs">({{ __('pos.optional') }})</span></label>
                <select name="device_id" class="w-full rounded-lg border border-gray-300 dark:border-gray-600 bg-white dark:bg-gray-800 text-gray-900 dark:text-gray-100 text-sm px-3 py-2 focus:ring-2 focus:ring-blue-500 transition">
                    <option value="">— {{ __('pos.bio_import_no_device') }} —</option>
                    @foreach($devices as $d)
                    <option value="{{ $d->id }}">{{ $d->label }}{{ $d->device_sn ? ' (' . $d->device_sn . ')' : '' }}</option>
                    @endforeach
                </select>
                <p class="text-xs text-gray-400 mt-1">{{ __('pos.bio_import_device_hint') }}</p>
            </div>

            <button type="submit" class="px-6 py-2.5 bg-blue-600 text-white text-sm font-semibold rounded-lg hover:bg-blue-700 transition">
                {{ __('pos.bio_import_submit') }}
            </button>
        </form>
    </div>

    {{-- Column format guide --}}
    <div class="mt-6 bg-gray-50 dark:bg-gray-800 rounded-xl border border-gray-200 dark:border-gray-700 p-5">
        <p class="text-sm font-bold text-gray-800 dark:text-white mb-3">{{ __('pos.bio_import_format_guide') }}</p>
        <div class="overflow-x-auto">
            <table class="text-xs w-full">
                <thead>
                    <tr class="text-gray-500 dark:text-gray-400">
                        <th class="text-left pb-2 pr-4 font-semibold">{{ __('pos.bio_col_name') }}</th>
                        <th class="text-left pb-2 pr-4 font-semibold">{{ __('pos.bio_col_required') }}</th>
                        <th class="text-left pb-2 font-semibold">{{ __('pos.bio_col_example') }}</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-gray-200 dark:divide-gray-700 text-gray-600 dark:text-gray-300">
                    <tr><td class="py-1.5 pr-4 font-mono">pin / employee id / user id</td><td class="py-1.5 pr-4">{{ __('pos.bio_col_recommended') }}</td><td class="py-1.5">1, 42, E005</td></tr>
                    <tr><td class="py-1.5 pr-4 font-mono">name / employee name</td><td class="py-1.5 pr-4">{{ __('pos.bio_col_fallback') }}</td><td class="py-1.5">Ali Hassan</td></tr>
                    <tr><td class="py-1.5 pr-4 font-mono">date / punch date</td><td class="py-1.5 pr-4">✓</td><td class="py-1.5">2026-08-04 or 04/08/2026</td></tr>
                    <tr><td class="py-1.5 pr-4 font-mono">time / punch time</td><td class="py-1.5 pr-4">{{ __('pos.bio_col_recommended') }}</td><td class="py-1.5">09:30 or 09:30:00</td></tr>
                    <tr><td class="py-1.5 pr-4 font-mono">type / status / in/out</td><td class="py-1.5 pr-4">{{ __('pos.optional') }}</td><td class="py-1.5">In / Out / 0 / 1</td></tr>
                </tbody>
            </table>
        </div>
        <p class="text-[11px] text-gray-400 mt-3">{{ __('pos.bio_import_note') }}</p>
    </div>
</div>
</x-fbr-pos-layout>

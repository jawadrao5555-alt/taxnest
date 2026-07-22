<x-pos-layout>
<div class="max-w-3xl mx-auto px-4 sm:px-6 lg:px-8 py-6">
    @include('pos.partials.back-link')

    <div class="mb-5">
        <h1 class="text-xl font-bold text-gray-900 dark:text-white flex items-center gap-2">
            <span class="w-9 h-9 rounded-xl bg-purple-600 flex items-center justify-center">
                <svg class="w-5 h-5 text-white" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.8" d="M9.663 17h4.673M12 3v1m6.364 1.636l-.707.707M21 12h-1M4 12H3m3.343-5.657l-.707-.707m2.828 9.9a5 5 0 117.072 0l-.548.547A3.374 3.374 0 0014 18.469V19a2 2 0 11-4 0v-.531c0-.895-.356-1.754-.988-2.386l-.548-.547z"/></svg>
            </span>
            Feature Suggestion Box
        </h1>
        <p class="text-sm text-gray-500 dark:text-gray-400 mt-1">Aap ki tajweez, hamara agla update! Jo feature aap ko chahiye, yahan likhein — hamari team har tajweez parhti hai.</p>
    </div>

    @if(session('success'))
        <div class="mb-4 p-4 rounded-xl bg-green-50 dark:bg-green-900/20 border border-green-200 dark:border-green-800 text-green-800 dark:text-green-300 text-sm font-medium">✅ {{ session('success') }}</div>
    @endif
    @if(session('error'))
        <div class="mb-4 p-4 rounded-xl bg-red-50 dark:bg-red-900/20 border border-red-200 dark:border-red-800 text-red-800 dark:text-red-300 text-sm font-medium">{{ session('error') }}</div>
    @endif
    @if($errors->any())
        <div class="mb-4 p-4 rounded-xl bg-red-50 dark:bg-red-900/20 border border-red-200 dark:border-red-800 text-red-800 dark:text-red-300 text-sm font-medium">{{ $errors->first() }}</div>
    @endif

    {{-- Submit form --}}
    <div class="bg-white dark:bg-gray-800 rounded-2xl border border-gray-200 dark:border-gray-700 shadow-sm p-5 mb-6">
        <form method="POST" action="{{ route('pos.suggestions.store') }}">
            @csrf
            <label class="block text-sm font-semibold text-gray-700 dark:text-gray-200 mb-1.5">Aap ko kya feature chahiye? <span class="text-red-500">*</span></label>
            <input type="text" name="title" maxlength="150" required value="{{ old('title') }}"
                   placeholder="Misal: Barcode label print karne ka option"
                   autocomplete="off" data-lpignore="true" data-form-type="other" data-1p-ignore
                   class="w-full rounded-xl border-gray-300 dark:border-gray-600 dark:bg-gray-900 dark:text-white text-sm focus:border-purple-500 focus:ring-purple-500">

            <label class="block text-sm font-semibold text-gray-700 dark:text-gray-200 mt-4 mb-1.5">Thori tafseel (optional)</label>
            <textarea name="details" rows="3" maxlength="2000"
                      placeholder="Yeh feature aap ke kaam mein kaise madad karega? Kisi aur software mein dekha ho to us ka naam bhi likh sakte hain."
                      autocomplete="off" data-lpignore="true" data-form-type="other" data-1p-ignore
                      class="w-full rounded-xl border-gray-300 dark:border-gray-600 dark:bg-gray-900 dark:text-white text-sm focus:border-purple-500 focus:ring-purple-500">{{ old('details') }}</textarea>

            <div class="mt-4 flex items-center justify-between flex-wrap gap-3">
                <p class="text-[11px] text-gray-400 dark:text-gray-500">Aap ki tajweez seedha TaxNest team ke paas jati hai.</p>
                <button type="submit" class="inline-flex items-center gap-2 px-5 py-2.5 rounded-xl bg-purple-600 hover:bg-purple-700 text-white text-sm font-semibold transition shadow-sm">
                    <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 19l9 2-9-18-9 18 9-2zm0 0v-8"/></svg>
                    Tajweez Bhejein
                </button>
            </div>
        </form>
    </div>

    {{-- Own company's suggestions + status --}}
    <div class="bg-white dark:bg-gray-800 rounded-2xl border border-gray-200 dark:border-gray-700 shadow-sm overflow-hidden">
        <div class="px-5 py-3.5 border-b border-gray-100 dark:border-gray-700 flex items-center justify-between">
            <h2 class="text-sm font-bold text-gray-800 dark:text-gray-100">Aap ki bheji hui tajaweez</h2>
            <span class="text-[11px] text-gray-400">{{ $suggestions->count() }} total</span>
        </div>

        @if($suggestions->isEmpty())
            <div class="px-5 py-10 text-center">
                <p class="text-2xl mb-2">💡</p>
                <p class="text-sm text-gray-500 dark:text-gray-400">Abhi tak koi tajweez nahi bheji. Pehli tajweez upar wale form se bhejein!</p>
            </div>
        @else
            <div class="divide-y divide-gray-100 dark:divide-gray-700">
                @foreach($suggestions as $sugg)
                    @php
                        $badge = match($sugg->status) {
                            'planned' => ['Plan Mein Shamil', 'bg-purple-100 text-purple-700 dark:bg-purple-900/30 dark:text-purple-300'],
                            'completed' => ['Ban Gaya ✓', 'bg-green-100 text-green-700 dark:bg-green-900/30 dark:text-green-300'],
                            'rejected' => ['Filhal Nahi', 'bg-gray-100 text-gray-600 dark:bg-gray-700 dark:text-gray-300'],
                            default => ['Zair-e-Ghor', 'bg-amber-100 text-amber-700 dark:bg-amber-900/30 dark:text-amber-300'],
                        };
                    @endphp
                    <div class="px-5 py-4">
                        <div class="flex items-start justify-between gap-3 flex-wrap">
                            <div class="min-w-0">
                                <p class="text-sm font-semibold text-gray-800 dark:text-gray-100">{{ $sugg->title }}</p>
                                @if($sugg->details)
                                    <p class="text-[12px] text-gray-500 dark:text-gray-400 mt-1 whitespace-pre-line">{{ $sugg->details }}</p>
                                @endif
                                <p class="text-[11px] text-gray-400 mt-1.5">{{ $sugg->user->name ?? 'User' }} · {{ $sugg->created_at->format('d M Y') }}</p>
                                @if($sugg->admin_note)
                                    <p class="text-[12px] mt-2 px-3 py-2 rounded-lg bg-gray-50 dark:bg-gray-900/50 text-gray-600 dark:text-gray-300 border border-gray-100 dark:border-gray-700"><span class="font-semibold">TaxNest team:</span> {{ $sugg->admin_note }}</p>
                                @endif
                            </div>
                            <span class="flex-shrink-0 px-2.5 py-1 rounded-full text-[11px] font-semibold {{ $badge[1] }}">{{ $badge[0] }}</span>
                        </div>
                    </div>
                @endforeach
            </div>
        @endif
    </div>

</div>
</x-pos-layout>

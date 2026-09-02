{{-- Re-openable large detail views for every update in the bell history.
     Opening the bell does not mark anything read; dismissing this modal marks
     only this update. Seen updates remain re-openable for the full live window. --}}
@foreach($updates as $detailUpdate)
    <div
        x-data="{
            open: false,
            wasSeen: {{ in_array($detailUpdate->id, $seenIds) ? 'true' : 'false' }},
            async dismissDetail() {
                this.open = false;
                if (this.wasSeen) return;
                try {
                    const response = await fetch('{{ $seenEndpoint }}', {
                        method: 'POST',
                        headers: {
                            'X-CSRF-TOKEN': document.querySelector('meta[name=csrf-token]').content,
                            'Accept': 'application/json',
                            'Content-Type': 'application/json'
                        },
                        body: JSON.stringify({ update_id: {{ (int) $detailUpdate->id }} })
                    });
                    const result = response.ok ? await response.json() : null;
                    if (!result || result.ok !== true) return;
                    this.wasSeen = true;
                    window.dispatchEvent(new CustomEvent('whats-new-seen', {
                        detail: { id: {{ (int) $detailUpdate->id }}, wasSeen: false }
                    }));
                } catch (_) {}
            }
        }"
        @open-whats-new-detail.window="if (Number($event.detail.id) === {{ (int) $detailUpdate->id }}) open = true"
        @keydown.escape.window="if (open) dismissDetail()"
        @click.self="dismissDetail()"
        x-show="open"
        x-cloak
        data-whats-new-detail-id="{{ $detailUpdate->id }}"
        class="fixed inset-0 flex items-center justify-center p-3 sm:p-6"
        style="z-index: 145; background: rgba(15, 10, 40, 0.62); backdrop-filter: blur(5px);"
    >
        <div class="w-full max-w-2xl bg-white dark:bg-gray-900 rounded-2xl overflow-hidden"
             style="box-shadow: 0 30px 80px -20px rgba(0,0,0,0.65), 0 0 0 1px rgba(255,255,255,0.08);">
            <div class="relative px-6 sm:px-8 py-6 text-white"
                 style="background: linear-gradient(135deg, hsl(var(--accent-h), var(--accent-s), 42%), hsl(var(--accent-h), var(--accent-s), 24%));">
                <button type="button" @click="dismissDetail()"
                        class="absolute top-3 right-3 w-9 h-9 rounded-full bg-white/15 hover:bg-white/25 flex items-center justify-center text-xl cursor-pointer"
                        aria-label="Close">×</button>
                <div class="text-3xl mb-2">🎉</div>
                <h2 class="text-xl sm:text-2xl font-extrabold leading-snug pr-8">{{ $detailUpdate->title }}</h2>
                <p class="text-[12px] text-white/80 mt-2">
                    <x-wn-type-badge :update="$detailUpdate" :light="true" />
                    · {{ $detailUpdate->created_at->format('d M Y') }}
                </p>
            </div>

            <div class="px-6 sm:px-8 py-6 overflow-y-auto" style="max-height: 58vh;">
                @if($detailUpdate->image_path ?? null)
                    <img src="{{ asset('storage/' . $detailUpdate->image_path) }}"
                         alt="{{ __('pos.update_image_alt') }}" loading="lazy"
                         class="w-full rounded-xl border border-gray-200 dark:border-gray-700 mb-5 cursor-zoom-in"
                         onclick="window.open(this.src, '_blank')">
                @endif
                <ul class="space-y-3">
                    @foreach(($detailUpdate->points ?? []) as $detailPoint)
                        <li class="flex items-start gap-3 text-sm sm:text-base text-gray-700 dark:text-gray-200">
                            <span class="flex-shrink-0 w-6 h-6 rounded-full bg-purple-100 dark:bg-purple-900/40 flex items-center justify-center mt-0.5">
                                <svg class="w-3.5 h-3.5 text-purple-600 dark:text-purple-300" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2.5" d="M5 13l4 4L19 7"/>
                                </svg>
                            </span>
                            <span>{{ $detailPoint }}</span>
                        </li>
                    @endforeach
                </ul>
            </div>

            <div class="px-6 sm:px-8 pb-6">
                <button type="button" @click="dismissDetail()"
                        class="w-full py-3.5 rounded-xl bg-purple-600 hover:bg-purple-700 text-white font-bold text-sm shadow-sm transition cursor-pointer">
                    {{ __('pos.whats_new_got_it') }}
                </button>
            </div>
        </div>
    </div>
@endforeach
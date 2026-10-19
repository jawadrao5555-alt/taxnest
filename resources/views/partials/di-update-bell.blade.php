                        @if($whatsNewList->isNotEmpty())
                        {{-- What's New bell — opening history does NOT mark anything seen. --}}
                        <div class="relative" x-data="{ bellOpen: false, unseen: {{ (int) $whatsNewUnseenCount }}, unreadIds: {{ Js::from($whatsNewUnseenIds) }},
                                }" @whats-new-seen.window="unreadIds = unreadIds.filter(id => Number(id) !== Number($event.detail.id)); unseen = unreadIds.length">
                            <button data-tn-topnav-control="notification" @click="bellOpen = !bellOpen" title="{{ __('pos.ti_app_updates') }}" class="relative p-2 rounded-xl text-gray-700 dark:text-gray-200 hover:bg-gray-100 dark:hover:bg-gray-800 transition cursor-pointer">
                                <svg class="w-5 h-5" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.8" d="M15 17h5l-1.405-1.405A2.032 2.032 0 0118 14.158V11a6.002 6.002 0 00-4-5.659V5a2 2 0 10-4 0v.341C7.67 6.165 6 8.388 6 11v3.159c0 .538-.214 1.055-.595 1.436L4 17h5m6 0v1a3 3 0 11-6 0v-1m6 0H9"/></svg>
                                <span x-show="unseen > 0" x-cloak x-text="unseen"
                                      class="absolute rounded-full bg-red-500 text-white font-bold flex items-center justify-center"
                                      style="top: 1px; right: 1px; min-width: 16px; height: 16px; padding: 0 4px; font-size: 9px;"></span>
                            </button>

                            <div x-show="bellOpen" x-cloak @click.outside="bellOpen = false"
                                 x-transition:enter="transition ease-out duration-150"
                                 x-transition:enter-start="opacity-0 -translate-y-2 scale-95"
                                 x-transition:enter-end="opacity-100 translate-y-0 scale-100"
                                 x-transition:leave="transition ease-in duration-100"
                                 x-transition:leave-start="opacity-100"
                                 x-transition:leave-end="opacity-0 scale-95"
                                 class="profile-dropdown absolute right-0 top-full mt-2 w-80 bg-white dark:bg-gray-900 rounded-xl shadow-2xl shadow-black/20 border border-gray-200/80 dark:border-gray-700/80 overflow-hidden z-[100]">
                                <div class="px-4 py-3 border-b border-gray-100 dark:border-gray-800" style="background: linear-gradient(to right, hsla(var(--accent-h), var(--accent-s), 95%, 1), hsla(var(--accent-h), var(--accent-s), 92%, 1))">
                                    <p class="text-sm font-bold text-gray-900 dark:text-white">{{ __('pos.app_updates_heading') }}</p>
                                    <p class="text-[11px] text-gray-500 dark:text-gray-400">{{ __('pos.app_updates_subtitle') }}</p>
                                </div>
                                <div class="max-h-96 overflow-y-auto divide-y divide-gray-100 dark:divide-gray-800">
                                    @foreach($whatsNewList as $wnu)
                                        <button type="button"
                                                x-data="{ rowUnseen: {{ in_array($wnu->id, $whatsNewSeenIds) ? 'false' : 'true' }} }"
                                                @whats-new-seen.window="if ($event.detail.id === {{ (int) $wnu->id }}) rowUnseen = false"
                                                @click="bellOpen = false; window.dispatchEvent(new CustomEvent('open-whats-new-detail', { detail: { id: {{ (int) $wnu->id }} } }))"
                                                class="block w-full px-4 py-3 text-left hover:bg-purple-50 dark:hover:bg-purple-900/20 transition cursor-pointer">
                                            <div class="flex items-center justify-between gap-2">
                                                <p class="text-[13px] font-semibold text-gray-800 dark:text-gray-100">{{ $wnu->customerTitle() }} <x-wn-type-badge :update="$wnu" /></p>
                                                <span x-show="rowUnseen" x-cloak class="flex-shrink-0 px-1.5 py-0.5 rounded-full bg-red-500 text-white text-[9px] font-bold uppercase">{{ __('pos.new_word') }}</span>
                                            </div>
                                            <p class="text-[10px] text-gray-400 mt-0.5">{{ $wnu->created_at->format('d M Y') }}</p>
                                            @if($wnu->image_path ?? null)
                                                <img src="{{ asset('storage/' . $wnu->image_path) }}" alt="{{ __('pos.update_image_alt') }}" loading="lazy"
                                                     class="w-full rounded-lg border border-gray-200 dark:border-gray-700 mt-1.5 cursor-zoom-in"
                                                     onclick="window.open(this.src, '_blank')">
                                            @endif
                                            <ul class="mt-1.5 space-y-1">
                                                @foreach(($wnu->points ?? []) as $wnp)
                                                    <li class="flex items-start gap-1.5 text-[12px] text-gray-600 dark:text-gray-300">
                                                        <svg class="w-3 h-3 mt-0.5 flex-shrink-0 text-purple-600" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2.5" d="M5 13l4 4L19 7"/></svg>
                                                        <span>{{ $wnp }}</span>
                                                    </li>
                                                @endforeach
                                            </ul>
                                        </button>
                                    @endforeach
                                </div>
                            </div>
                        </div>
                        @endif

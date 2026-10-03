@php $callerRelevant = true; @endphp
                {{-- Caller ID (Task 1039): Android companion app + sale-screen popup.
                     The shared settings save helper confirms the toggle before
                     updating its appearance. Status lines read company columns
                     (hasColumn-guarded for prod schema drift). Download button
                     appears only once caller_app_latest_version is set — the
                     public release gate flips AFTER the owner phone-test. --}}
                @php
                    $tnCallerReady = $callerRelevant && \Illuminate\Support\Facades\Schema::hasColumn('companies', 'caller_id_enabled');
                    $tnCallerOn = $tnCallerReady && ($company->caller_id_enabled ?? false);
                    $tnCallerUser = ($tnCallerReady && ($company->caller_app_user_id ?? null)) ? \App\Models\User::find($company->caller_app_user_id) : null;
                    $tnCallerSeen = ($tnCallerReady && ($company->caller_app_last_seen_at ?? null)) ? \Carbon\Carbon::parse($company->caller_app_last_seen_at) : null;
                    $tnCallerLastEvent = ($tnCallerReady && \Illuminate\Support\Facades\Schema::hasTable('pos_caller_events'))
                        ? \Illuminate\Support\Facades\DB::table('pos_caller_events')->where('company_id', $company->id)->orderByDesc('id')->value('created_at')
                        : null;
                    // NOTE: Blade {{-- --}} comments are NOT stripped inside @php
                    // blocks — one here compiled to invalid PHP and 500'd the page
                    // (white-screen preflight caught it). Use PHP comments only.
                    // Release gate: SystemSetting AND the hosted file must both exist
                    // (APKs are scp'd to live public/downloads, never committed —
                    // repo is public; same pattern as rider/waiter apps) so the
                    // download button can never 404.
                    $tnCallerApkLive = trim((string) \App\Models\SystemSetting::get('caller_app_latest_version', '')) !== ''
                        && is_file(public_path('downloads/taxnest-caller.apk'));
                    // Task 1345: default download ab "clean" build hai (sirf SIM
                    // calls, Play Protect ki blocked chaar permissions se paak —
                    // is liye bina rukawat install hoti hai). WhatsApp wali
                    // ("plus") build ka apna gate hai aur woh apne alag hisse
                    // mein Play Protect band/chaalu karne ke qadam ke sath dikhti
                    // hai — default button jhoota WhatsApp ka wada na kare.
                    $tnCallerPlusApkLive = trim((string) \App\Models\SystemSetting::get('caller_app_plus_latest_version', '')) !== ''
                        && is_file(public_path('downloads/taxnest-caller-plus.apk'));
                    // Unlimited gate (owner, 17 Aug 2026): Caller ID is plan-locked.
                    $tnCallerPlanAllowed = \App\Services\PosFeatureService::planAllows($company, 'caller_id_enabled');
                    // v2 (Task 1101): multi-device rows — legacy companies-row phone
                    // shows alongside as a 'legacy' pseudo-row so an old pairing stays
                    // visible/revocable. Online = contact within the controller window.
                    $tnCallerOffCutoff = now()->subMinutes(\App\Http\Controllers\PosCallerIdController::OFFLINE_AFTER_MINUTES);
                    $tnCallerDevices = [];
                    if ($tnCallerReady && \Illuminate\Support\Facades\Schema::hasTable('pos_caller_devices')) {
                        foreach (\Illuminate\Support\Facades\DB::table('pos_caller_devices')->where('company_id', $company->id)->orderByDesc('id')->get() as $tnCdRow) {
                            $tnCdSeen = $tnCdRow->last_seen_at ? \Carbon\Carbon::parse($tnCdRow->last_seen_at) : null;
                            $tnCallerDevices[] = [
                                'id' => (int) $tnCdRow->id,
                                'user' => optional(\App\Models\User::find($tnCdRow->user_id))->name ?? '—',
                                'device' => (string) ($tnCdRow->device ?? ''),
                                'seen' => $tnCdSeen ? $tnCdSeen->diffForHumans() : null,
                                'online' => $tnCdSeen ? $tnCdSeen->gt($tnCallerOffCutoff) : false,
                            ];
                        }
                    }
                    if ($tnCallerUser) {
                        $tnCallerDevices[] = [
                            'id' => 'legacy',
                            'user' => $tnCallerUser->name,
                            'device' => (string) ($company->caller_app_device ?? ''),
                            'seen' => $tnCallerSeen ? $tnCallerSeen->diffForHumans() : null,
                            'online' => $tnCallerSeen ? $tnCallerSeen->gt($tnCallerOffCutoff) : false,
                        ];
                    }
                @endphp
                @if($tnCallerReady)
                <div class="rounded-2xl border border-gray-200 dark:border-gray-800 bg-white dark:bg-gray-900 p-5 shadow-sm">
                    <div class="flex items-center gap-3">
                        <div class="w-10 h-10 rounded-xl bg-sky-100 text-sky-600 dark:bg-sky-900/30 dark:text-sky-400 flex items-center justify-center shrink-0">
                            <svg class="w-5 h-5" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5" d="M3 5a2 2 0 012-2h3.28a1 1 0 01.948.684l1.498 4.493a1 1 0 01-.502 1.21l-2.257 1.13a11.042 11.042 0 005.516 5.516l1.13-2.257a1 1 0 011.21-.502l4.493 1.498a1 1 0 01.684.949V19a2 2 0 01-2 2h-1C9.716 21 3 14.284 3 6V5z"/></svg>
                        </div>
                        <div class="min-w-0 flex-1">
                            <p class="text-sm font-bold text-gray-900 dark:text-white">{{ __('pos.caller_id_title') }}</p>
                            <p class="text-[11px] text-gray-500 dark:text-gray-400">{{ __('pos.caller_id_sub') }}</p>
                        </div>
                        @if($tnCallerPlanAllowed)
                        <button type="button"
                            @click="save('caller', '{{ route('pos.settings.caller-id', [], false) }}', {enabled: !values.caller}, !values.caller)" :disabled="busy.caller"
                            class="relative inline-flex shrink-0 w-12 h-6 rounded-full transition-colors duration-200" :class="values.caller ? 'bg-sky-500' : 'bg-gray-300 dark:bg-gray-600'">
                            <span class="absolute w-5 h-5 bg-white rounded-full shadow transition-transform duration-200" style="top:2px; left:2px;" :class="values.caller && 'translate-x-6'"></span>
                        </button>
                        @else
                        <span class="shrink-0 inline-flex items-center gap-1 px-2.5 py-1 rounded-full text-[11px] font-bold bg-amber-100 text-amber-700 dark:bg-amber-900/30 dark:text-amber-400">🔒 {{ __('pos.auth_unlimited') }}</span>
                        @endif
                    </div>
                    <div class="mt-3 pt-3 border-t border-gray-100 dark:border-gray-800 space-y-1">
                        @unless($tnCallerPlanAllowed)
                            <p class="text-[11px] font-bold text-amber-600 dark:text-amber-400">{{ __('pos.caller_id_plan_locked') }}</p>
                            <a href="{{ route('pos.billing') }}" class="inline-flex items-center gap-1 text-[11px] font-bold text-sky-600 dark:text-sky-400 hover:underline">
                                <svg class="w-3 h-3 shrink-0" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M13 7l5 5m0 0l-5 5m5-5H6"/></svg>
                                {{ __('pos.upgrade_plan_btn') }}
                            </a>
                        @endunless
                        @if(count($tnCallerDevices))
                            @foreach($tnCallerDevices as $tnCd)
                                <div class="flex items-center gap-2 text-[11px] text-gray-600 dark:text-gray-300" x-data="{ revoked: false, revoking: false }" x-show="!revoked">
                                    <span class="inline-flex items-center gap-1 shrink-0 px-1.5 py-0.5 rounded-full text-[9px] font-bold {{ $tnCd['online'] ? 'bg-emerald-100 text-emerald-700 dark:bg-emerald-900/30 dark:text-emerald-400' : 'bg-gray-100 text-gray-500 dark:bg-gray-800 dark:text-gray-400' }}">
                                        <span class="w-1.5 h-1.5 rounded-full {{ $tnCd['online'] ? 'bg-emerald-500' : 'bg-gray-400' }}"></span>
                                        {{ $tnCd['online'] ? __('pos.caller_dev_online') : __('pos.caller_dev_offline') }}
                                    </span>
                                    <span class="truncate min-w-0">
                                        <span class="font-bold">{{ $tnCd['user'] }}</span>{{ $tnCd['device'] !== '' ? ' · ' . $tnCd['device'] : '' }}
                                        @if($tnCd['seen']) · {{ __('pos.caller_id_last_seen') }}: {{ $tnCd['seen'] }} @endif
                                    </span>
                                    <button type="button"
                                        @click="if (confirm('{{ __('pos.caller_dev_revoke_confirm') }}')) { if (revoking) return; revoking = true; fetch('{{ route('pos.settings.caller-devices.revoke') }}', {method:'POST',headers:{'Content-Type':'application/json','X-CSRF-TOKEN':'{{ csrf_token() }}'},body:JSON.stringify({device_id: '{{ $tnCd['id'] }}'})}).then(r => r.json().then(d => ({ok:r.ok,d}))).then(({ok,d}) => { if (ok && d.success === true) revoked = true; else alert(d.message || {{ Js::from(__('pos.setting_save_failed')) }}); }).catch(() => alert({{ Js::from(__('pos.setting_save_failed')) }})).finally(() => revoking = false); }"
                                        class="ml-auto shrink-0 px-2 py-0.5 rounded-lg text-[10px] font-bold text-red-600 dark:text-red-400 border border-red-200 dark:border-red-800 hover:bg-red-50 dark:hover:bg-red-900/20 transition">{{ __('pos.caller_dev_revoke') }}</button>
                                </div>
                            @endforeach
                        @else
                            <p class="text-[11px] text-gray-500 dark:text-gray-400">{{ __('pos.caller_id_no_device') }}</p>
                        @endif
                        @if($tnCallerLastEvent)
                            <p class="text-[11px] text-gray-600 dark:text-gray-300"><span class="font-bold">{{ __('pos.caller_id_last_event') }}:</span> {{ \Carbon\Carbon::parse($tnCallerLastEvent)->diffForHumans() }}</p>
                        @endif
                        @if($tnCallerApkLive && $tnCallerPlanAllowed)
                            <div class="pt-2">
                                <a href="{{ url('downloads/taxnest-caller.apk') }}" class="inline-flex items-center gap-2 px-3 py-2 rounded-xl bg-sky-600 hover:bg-sky-700 text-white text-xs font-bold transition">
                                    <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 16v1a2 2 0 002 2h12a2 2 0 002-2v-1m-4-4l-4 4m0 0l-4-4m4 4V4"/></svg>
                                    {{ __('pos.caller_id_download') }}
                                </a>
                                <p class="text-[10px] text-gray-400 mt-1">{{ __('pos.caller_id_download_hint') }}</p>
                            </div>
                            @if($tnCallerPlusApkLive)
                                {{-- WhatsApp wali build: alag, kholne par hi qadam dikhein
                                     (Play Protect ki wajah se yeh install ek extra qadam
                                     mangta hai — default button ko saada rakhna hai). --}}
                                <div class="pt-2" x-data="{ plusOpen: false }">
                                    <button type="button" @click="plusOpen = !plusOpen"
                                        class="inline-flex items-center gap-1.5 text-[11px] font-bold text-sky-600 dark:text-sky-400 hover:underline">
                                        <svg class="w-3.5 h-3.5 shrink-0 transition-transform" :class="plusOpen && 'rotate-90'" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 5l7 7-7 7"/></svg>
                                        {{ __('pos.caller_id_plus_title') }}
                                    </button>
                                    <div x-show="plusOpen" x-cloak class="mt-2 rounded-xl bg-sky-50 dark:bg-sky-900/20 border border-sky-200 dark:border-sky-800 p-3">
                                        <p class="text-[11px] text-gray-700 dark:text-gray-200 leading-relaxed">{{ __('pos.caller_id_plus_intro') }}</p>
                                        <ol class="mt-2 space-y-1 text-[11px] text-gray-600 dark:text-gray-300 list-decimal pl-4 leading-relaxed">
                                            <li>{{ __('pos.caller_id_plus_step1') }}</li>
                                            <li>{{ __('pos.caller_id_plus_step2') }}</li>
                                            <li>{{ __('pos.caller_id_plus_step3') }}</li>
                                            <li>{{ __('pos.caller_id_plus_step4') }}</li>
                                        </ol>
                                        <a href="{{ url('downloads/taxnest-caller-plus.apk') }}" class="mt-3 inline-flex items-center gap-2 px-3 py-2 rounded-xl bg-sky-600 hover:bg-sky-700 text-white text-xs font-bold transition">
                                            <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 16v1a2 2 0 002 2h12a2 2 0 002-2v-1m-4-4l-4 4m0 0l-4-4m4 4V4"/></svg>
                                            {{ __('pos.caller_id_plus_download') }}
                                        </a>
                                        <p class="text-[10px] text-amber-700 dark:text-amber-400 mt-2 font-bold">{{ __('pos.caller_id_plus_warn') }}</p>
                                    </div>
                                </div>
                            @endif
                        @endif

                    </div>
                </div>
                @endif


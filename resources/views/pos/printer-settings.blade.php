<x-pos-layout>
<div class="max-w-3xl mx-auto px-4 sm:px-6 lg:px-8 py-6">
    <a href="{{ route('pos.customize') }}" class="inline-flex items-center gap-1.5 text-xs font-semibold text-gray-500 dark:text-gray-400 hover:text-purple-600 dark:hover:text-purple-400 transition mb-3">
        <svg class="w-3.5 h-3.5" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M10 19l-7-7m0 0l7-7m-7 7h18"/></svg>
        {{ __('pos.back_to_customize') }}
    </a>
    <h1 class="text-2xl font-bold text-gray-900 dark:text-white mb-2">{{ __('pos.printer_settings') }}</h1>
    <p class="text-sm text-gray-500 dark:text-gray-400 mb-6">{{ __('pos.printer_settings_sub') }}</p>

    @if(session('success'))
    <div class="mb-4 p-3 rounded-lg bg-emerald-50 dark:bg-emerald-900/20 text-emerald-700 dark:text-emerald-400 text-sm border border-emerald-200 dark:border-emerald-800">{{ session('success') }}</div>
    @endif

    @if($errors->any())
    <div class="mb-4 p-3 rounded-lg bg-red-50 dark:bg-red-900/20 text-red-700 dark:text-red-400 text-sm border border-red-200 dark:border-red-800">
        <ul class="list-disc list-inside">
            @foreach($errors->all() as $error)
            <li>{{ $error }}</li>
            @endforeach
        </ul>
    </div>
    @endif

    {{-- Agent status --}}
    <div class="bg-white dark:bg-gray-900 rounded-xl border border-gray-200 dark:border-gray-700 shadow-sm p-5 mb-5">
        <div class="flex items-center justify-between gap-3 flex-wrap">
            <div class="flex items-center gap-3">
                <span class="inline-flex w-2.5 h-2.5 rounded-full {{ $agentOnline ? 'bg-emerald-500' : 'bg-gray-400' }}"></span>
                <div>
                    <p class="text-sm font-semibold text-gray-900 dark:text-white">{{ __('pos.desktop_agent_status', ['status' => $agentOnline ? __('pos.online') : __('pos.offline')]) }}</p>
                    <p class="text-xs text-gray-500 dark:text-gray-400">
                        @if($company->agent_last_seen)
                            {{ __('pos.last_seen_prefix') }} {{ $company->agent_last_seen->diffForHumans() }}@if($company->agent_version) · v{{ $company->agent_version }}@endif
                        @else
                            {{ __('pos.agent_never_connected') }}
                        @endif
                    </p>
                </div>
            </div>
            <a href="{{ route('pos.agent') }}" class="text-xs font-semibold text-purple-600 dark:text-purple-400 hover:underline">{{ __('pos.agent_setup_link') }}</a>
        </div>
        @if(!$agentOnline)
        <div class="mt-3 p-3 rounded-lg bg-amber-50 dark:bg-amber-900/20 border border-amber-200 dark:border-amber-800 text-xs text-amber-800 dark:text-amber-300">
            {{ __('pos.silent_print_needs_agent') }}
        </div>
        @endif
    </div>

    <form method="POST" action="{{ route('pos.printer-settings') }}" class="space-y-5">
        @csrf
        {{-- Task 1393 marker: proves this form was FRESHLY rendered, so the handler may
             safely rebuild the printer picks and tick-boxes from what the request carries.
             A stale cached copy of this page lacks the marker and leaves them untouched —
             an outdated form and a form with everything unticked are otherwise identical
             on the wire. --}}
        <input type="hidden" name="ps_present" value="1">

        {{-- Master toggle --}}
        <div class="bg-white dark:bg-gray-900 rounded-xl border border-gray-200 dark:border-gray-700 shadow-sm p-5">
            <label class="flex items-start gap-3 cursor-pointer">
                <input type="checkbox" name="silent_print_enabled" value="1" {{ $settings['silent_print_enabled'] ? 'checked' : '' }} class="mt-0.5 rounded border-gray-300 text-purple-600 focus:ring-purple-500 w-4 h-4">
                <span>
                    <span class="block text-sm font-semibold text-gray-900 dark:text-white">{{ __('pos.enable_silent_printing') }}</span>
                    <span class="block text-xs text-gray-500 dark:text-gray-400 mt-0.5">{{ __('pos.enable_silent_printing_hint') }}</span>
                </span>
            </label>
        </div>

        {{-- Task 565: opt-in "Print se pehle poocho (Yes/No)" — payment success par
             auto-print chain se pehle ek fauri Yes/No dialog. Silent print se
             AZAAD (iframe/popup shops par bhi kaam karta hai). Default OFF. --}}
        <div class="bg-white dark:bg-gray-900 rounded-xl border border-gray-200 dark:border-gray-700 shadow-sm p-5">
            <label class="flex items-start gap-3 cursor-pointer">
                <input type="checkbox" name="print_confirm_ask" value="1" {{ !empty($settings['print_confirm_ask']) ? 'checked' : '' }} class="mt-0.5 rounded border-gray-300 text-purple-600 focus:ring-purple-500 w-4 h-4">
                <span>
                    <span class="block text-sm font-semibold text-gray-900 dark:text-white">{{ __('pos.print_confirm_ask_label') }}</span>
                    <span class="block text-xs text-gray-500 dark:text-gray-400 mt-0.5">{{ __('pos.print_confirm_ask_hint') }}</span>
                </span>
            </label>
        </div>

        {{-- Printer pickers --}}
        <div class="bg-white dark:bg-gray-900 rounded-xl border border-gray-200 dark:border-gray-700 shadow-sm p-5">
            <h3 class="text-sm font-semibold text-gray-900 dark:text-white mb-1">{{ __('pos.choose_printers') }}</h3>
            {{-- Pickers stay VISIBLE even before the agent reports printers
                 (customer feedback Jul 2026 — Pizza Master couldn't find where
                 the kitchen printer is set). Empty list = dropdowns show only
                 "Not set" + the amber hint explains how to fill them. --}}
            @php
                // Task 1075: build a lookup set of text-only printer names so the
                // dropdowns below can show an amber warning without extra queries.
                $textOnlyNames = collect($settings['available_printers'])
                    ->filter(fn($p) => !empty($p['isTextOnly']))
                    ->pluck('name')
                    ->flip()
                    ->all();
                $receiptIsTextOnly = $settings['receipt_printer'] && isset($textOnlyNames[$settings['receipt_printer']]);
                // Task 1194: KOT-family dropdowns ride the UNION picker — every
                // counter's printers, counter-labeled, values "uid::name" (plain
                // name = legacy). Saved picks compare by their encoded value.
                $kotOptions = $kotOptions ?? [];
                $kotOptionValues = collect($kotOptions)->pluck('value')->all();
                $kotCur = \App\Models\PosAgentDevice::encodePick($settings['kot_printer'], $settings['kot_printer_device'] ?? null);
                $counterKotCur = \App\Models\PosAgentDevice::encodePick($settings['counter_kot_printer'], $settings['counter_kot_printer_device'] ?? null);
                $kotSelOpt = collect($kotOptions)->firstWhere('value', $kotCur);
                $kotIsTextOnly = $kotSelOpt
                    ? !empty($kotSelOpt['isTextOnly'])
                    : ($settings['kot_printer'] && isset($textOnlyNames[$settings['kot_printer']]));
            @endphp
            @if(count($settings['available_printers']))
                <p class="text-xs text-gray-500 dark:text-gray-400 mb-4">
                    {{ __('pos.printers_found_on_agent') }}
                    @if($settings['printers_reported_at'])
                        ({{ __('pos.updated_prefix') }} {{ \Carbon\Carbon::parse($settings['printers_reported_at'])->diffForHumans() }})
                    @endif
                </p>
            @else
                <div class="mb-4 p-3 rounded-lg bg-amber-50 dark:bg-amber-900/20 border border-amber-200 dark:border-amber-800 text-xs text-amber-800 dark:text-amber-300">
                    {!! __('pos.no_printers_reported_html', ['link' => '<a href="' . e(route('pos.agent')) . '" class="font-semibold underline">' . e(__('pos.desktop_agent')) . '</a>']) !!}
                </div>
            @endif
                <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
                    <div>
                        <label class="block text-xs font-semibold text-gray-700 dark:text-gray-300 mb-1.5">{{ __('pos.bill_receipt_printer') }}</label>
                        <select name="receipt_printer" class="w-full rounded-lg border-gray-300 dark:border-gray-600 dark:bg-gray-800 dark:text-white text-sm focus:ring-purple-500 focus:border-purple-500">
                            <option value="">{{ __('pos.opt_not_set_popup') }}</option>
                            @foreach($settings['available_printers'] as $p)
                            <option value="{{ $p['name'] }}" {{ $settings['receipt_printer'] === $p['name'] ? 'selected' : '' }}>{{ $p['displayName'] ?? $p['name'] }}{{ !empty($p['isDefault']) ? ' ' . __('pos.default_paren') : '' }}</option>
                            @endforeach
                        </select>
                        <p class="mt-1 text-[11px] text-gray-500 dark:text-gray-400">{{ __('pos.bill_printer_hint') }}</p>
                        @if($receiptIsTextOnly)
                        <div class="mt-2 p-2.5 rounded-lg bg-amber-50 dark:bg-amber-900/20 border border-amber-300 dark:border-amber-700 text-xs text-amber-800 dark:text-amber-300">{{ __('pos.printer_text_only_warn') }}</div>
                        @endif
                    </div>
                    <div>
                        <label class="block text-xs font-semibold text-gray-700 dark:text-gray-300 mb-1.5">{{ __('pos.kitchen_kot_printer') }}</label>
                        <select name="kot_printer" class="w-full rounded-lg border-gray-300 dark:border-gray-600 dark:bg-gray-800 dark:text-white text-sm focus:ring-purple-500 focus:border-purple-500">
                            <option value="">{{ __('pos.opt_not_set_popup') }}</option>
                            @foreach($kotOptions as $opt)
                            <option value="{{ $opt['value'] }}" {{ $kotCur === $opt['value'] ? 'selected' : '' }}>{{ $opt['label'] }}</option>
                            @endforeach
                            @if($kotCur !== '' && !in_array($kotCur, $kotOptionValues, true))
                            {{-- Saved pick missing from the lists (printer renamed / counter deregistered): keep it selected so saving unrelated fields doesn't silently drop it. --}}
                            <option value="{{ $kotCur }}" selected>{{ $settings['kot_printer'] }}</option>
                            @endif
                        </select>
                        <p class="mt-1 text-[11px] text-gray-500 dark:text-gray-400">{{ __('pos.kot_printer_hint') }}</p>
                        @if($kotIsTextOnly)
                        <div class="mt-2 p-2.5 rounded-lg bg-amber-50 dark:bg-amber-900/20 border border-amber-300 dark:border-amber-700 text-xs text-amber-800 dark:text-amber-300">{{ __('pos.printer_text_only_warn') }}</div>
                        @endif
                    </div>
                    {{-- Counter KOT Copy (owner request 30 Jul 2026): DINE-IN orders
                         only — every KOT also prints one full copy on this counter
                         printer when the tick is ON. Other order types ignore it. --}}
                    <div>
                        <div class="flex items-center justify-between gap-2 mb-1.5">
                            <label class="block text-xs font-semibold text-gray-700 dark:text-gray-300">{{ __('pos.counter_kot_copy_printer') }} <span class="font-normal text-gray-400">{{ __('pos.dine_in_only_paren') }}</span></label>
                            <label class="flex items-center gap-1.5 cursor-pointer select-none">
                                <input type="checkbox" name="counter_kot_enabled" value="1" {{ $settings['counter_kot_enabled'] ? 'checked' : '' }} class="rounded border-gray-300 text-purple-600 focus:ring-purple-500 w-3.5 h-3.5">
                                <span class="text-[11px] font-semibold text-gray-600 dark:text-gray-400">{{ __('pos.use_it_toggle') }}</span>
                            </label>
                        </div>
                        <select name="counter_kot_printer" class="w-full rounded-lg border-gray-300 dark:border-gray-600 dark:bg-gray-800 dark:text-white text-sm focus:ring-purple-500 focus:border-purple-500">
                            <option value="">{{ __('pos.opt_not_set') }}</option>
                            @foreach($kotOptions as $opt)
                            <option value="{{ $opt['value'] }}" {{ $counterKotCur === $opt['value'] ? 'selected' : '' }}>{{ $opt['label'] }}</option>
                            @endforeach
                            @if($counterKotCur !== '' && !in_array($counterKotCur, $kotOptionValues, true))
                            <option value="{{ $counterKotCur }}" selected>{{ $settings['counter_kot_printer'] }}</option>
                            @endif
                        </select>
                        <p class="mt-1 text-[11px] text-gray-500 dark:text-gray-400">{{ __('pos.counter_kot_hint') }}</p>
                    </div>
                    <div class="flex items-end">
                        <button type="submit" class="w-full sm:w-auto px-6 py-2.5 rounded-xl bg-purple-600 hover:bg-purple-700 text-white text-sm font-bold transition shadow-sm">{{ __('pos.save_printer_settings') }}</button>
                    </div>
                </div>
        </div>

        {{-- Task 1166: Multi-counter devices — one card per PC running the
             Desktop Agent (same company key, own device identity, agent
             v1.9.0+). Hidden entirely for single-PC/legacy-agent shops so
             they see ZERO change. --}}
        @if(isset($devices) && $devices->count())
        <div class="bg-white dark:bg-gray-900 rounded-xl border border-gray-200 dark:border-gray-700 shadow-sm p-5">
            <h3 class="text-sm font-semibold text-gray-900 dark:text-white mb-1">{{ __('pos.counter_devices_title') }}</h3>
            <p class="text-xs text-gray-500 dark:text-gray-400 mb-4">{{ __('pos.counter_devices_sub') }}</p>
            <div class="space-y-3">
                @foreach($devices as $device)
                @php $online = $device->isOnline(); @endphp
                <div class="p-3.5 rounded-lg border {{ $online ? 'border-emerald-200 dark:border-emerald-800' : 'border-gray-200 dark:border-gray-700' }} bg-gray-50/60 dark:bg-gray-800/40">
                    <div class="flex items-center justify-between gap-2 flex-wrap mb-2.5">
                        <div class="flex items-center gap-2 min-w-0">
                            <span class="inline-flex w-2 h-2 rounded-full {{ $online ? 'bg-emerald-500' : 'bg-gray-400' }}"></span>
                            <span class="text-sm font-semibold text-gray-900 dark:text-white truncate">{{ $device->label() }}</span>
                            <span class="text-[11px] font-semibold {{ $online ? 'text-emerald-600 dark:text-emerald-400' : 'text-gray-400' }}">{{ $online ? __('pos.online') : __('pos.offline') }}</span>
                        </div>
                        <span class="text-[11px] text-gray-400 whitespace-nowrap">
                            @if($device->hostname && $device->name){{ $device->hostname }} · @endif
                            @if($device->last_seen_at){{ __('pos.last_seen_prefix') }} {{ $device->last_seen_at->diffForHumans() }}@endif
                            @if($device->agent_version) · v{{ $device->agent_version }}@endif
                        </span>
                    </div>
                    <div class="grid grid-cols-1 sm:grid-cols-2 gap-3">
                        <div>
                            <label class="block text-[11px] font-semibold text-gray-600 dark:text-gray-400 mb-1">{{ __('pos.counter_device_name') }}</label>
                            <input type="text" name="device_name[{{ $device->device_uid }}]" value="{{ $device->name }}" maxlength="60" placeholder="{{ $device->hostname ?: __('pos.counter_device_name_ph') }}" class="w-full rounded-lg border-gray-300 dark:border-gray-600 dark:bg-gray-800 dark:text-white text-sm focus:ring-purple-500 focus:border-purple-500">
                        </div>
                        <div>
                            <label class="block text-[11px] font-semibold text-gray-600 dark:text-gray-400 mb-1">{{ __('pos.counter_receipt_printer') }}</label>
                            <select name="device_receipt_printer[{{ $device->device_uid }}]" class="w-full rounded-lg border-gray-300 dark:border-gray-600 dark:bg-gray-800 dark:text-white text-sm focus:ring-purple-500 focus:border-purple-500">
                                <option value="">{{ __('pos.counter_printer_not_set') }}</option>
                                @foreach(($device->printers ?? []) as $p)
                                <option value="{{ $p['name'] }}" {{ $device->receipt_printer === $p['name'] ? 'selected' : '' }}>{{ $p['displayName'] ?? $p['name'] }}{{ !empty($p['isDefault']) ? ' ' . __('pos.default_paren') : '' }}</option>
                                @endforeach
                            </select>
                            @if(empty($device->printers))
                            <p class="mt-1 text-[11px] text-amber-600 dark:text-amber-400">{{ __('pos.counter_no_printers_yet') }}</p>
                            @endif
                        </div>
                    </div>
                </div>
                @endforeach
            </div>

            @if(isset($assignableTeam) && $assignableTeam->count())
            <div class="mt-5 pt-4 border-t border-gray-100 dark:border-gray-800">
                <h4 class="text-sm font-semibold text-gray-900 dark:text-white mb-1">{{ __('pos.counter_assign_title') }}</h4>
                <p class="text-xs text-gray-500 dark:text-gray-400 mb-3">{{ __('pos.counter_assign_sub') }}</p>
                <div class="space-y-2">
                    @foreach($assignableTeam as $member)
                    <div class="flex items-center justify-between gap-3">
                        <div class="min-w-0">
                            <p class="text-sm text-gray-800 dark:text-gray-200 truncate">{{ $member->name }}</p>
                            @php
                                $roleKey = match ($member->pos_role) {
                                    'pos_manager' => 'role_manager',
                                    'pos_cashier' => 'role_cashier',
                                    default => 'role_admin',
                                };
                            @endphp
                            <p class="text-[11px] text-gray-400">{{ __('pos.' . $roleKey) }}</p>
                        </div>
                        <select name="user_device[{{ $member->id }}]" style="max-width:55%" class="w-56 rounded-lg border-gray-300 dark:border-gray-600 dark:bg-gray-800 dark:text-white text-sm focus:ring-purple-500 focus:border-purple-500">
                            <option value="">{{ __('pos.counter_no_assignment') }}</option>
                            @foreach($devices as $device)
                            <option value="{{ $device->device_uid }}" {{ ($member->pos_device_uid ?? null) === $device->device_uid ? 'selected' : '' }}>{{ $device->label() }}</option>
                            @endforeach
                        </select>
                    </div>
                    @endforeach
                </div>
            </div>
            @endif

            <div class="mt-4 flex justify-end">
                <button type="submit" class="px-6 py-2.5 rounded-xl bg-purple-600 hover:bg-purple-700 text-white text-sm font-bold transition shadow-sm">{{ __('pos.save_printer_settings') }}</button>
            </div>
        </div>
        @endif
    </form>

    {{-- Test print (Aug 2026, live shop): Windows keeps a separate queue for
         every install of the same printer — "XP-80C", "XP-80C (copy 2)",
         "POS-80". Only one is still bound to the live port; the others accept
         a bill, report success and drop it, so the panel shows every job as
         printed while the counter gets no paper. One slip per queue, carrying
         the queue's OWN name, ends that guessing in seconds.
         Outside the settings form on purpose (fetch-driven, nothing to save). --}}
    @php
        // Virtual queues are a trap HERE specifically: "Microsoft Print to PDF"
        // and friends pop a blocking save dialog in the agent's hidden print
        // window, and none of them can ever be the shop's thermal printer. The
        // normal printer pickers still list everything — only the test buttons
        // filter, and only when something real is left to test.
        $tpVirtual = static function (string $name): bool {
            return (bool) preg_match(
                '/(^fax$|onenote|xps document writer|print to pdf|^adobe pdf$|pdfcreator|^cutepdf|^bullzip|^doro pdf|^pdf(24| architect)|^snagit|^fax$)/i',
                trim($name)
            );
        };
        $tpKeep = static function (array $names) use ($tpVirtual): array {
            $real = array_values(array_filter($names, fn($n) => !$tpVirtual($n)));
            return $real ?: $names; // never hide the only button a shop has
        };
        $tpGroups = [];
        if (isset($devices) && $devices->count()) {
            foreach ($devices as $tpDevice) {
                $tpNames = $tpKeep(collect($tpDevice->printers ?? [])->pluck('name')->filter()->unique()->values()->all());
                if ($tpNames) {
                    $tpGroups[] = [
                        'uid' => $tpDevice->device_uid,
                        'label' => $tpDevice->label(),
                        'online' => $tpDevice->isOnline(),
                        'printers' => $tpNames,
                    ];
                }
            }
        }
        if (!$tpGroups) {
            $tpNames = collect($settings['available_printers'])->pluck('name')->filter()->unique()->values()->all();
            if ($tpNames) {
                $tpGroups[] = ['uid' => '', 'label' => null, 'online' => $agentOnline, 'printers' => $tpNames];
            }
        }
        // Duplicate-queue detection: two names that differ only by a trailing
        // "(copy N)" are the same physical printer installed twice.
        $tpDuplicate = false;
        foreach ($tpGroups as $tpGroup) {
            $tpBases = collect($tpGroup['printers'])
                ->map(fn($n) => strtolower(trim(preg_replace('/\s*\(\s*copy\s*\d*\s*\)\s*$/i', '', $n))));
            if ($tpBases->count() !== $tpBases->unique()->count()) {
                $tpDuplicate = true;
            }
        }
        // Built here, never inside @json(...): a nested __() call inside a
        // Blade directive argument truncates the compiled view. Relative URL
        // on purpose — an absolute https route breaks plain-http browsing.
        $tpUrl = route('pos.api.print-jobs.test', [], false);
        $tpMsgs = [
            'sending' => __('pos.test_print_sending'),
            'sent' => __('pos.test_print_sent'),
            'failed' => __('pos.test_print_failed'),
        ];
        // HEX flags keep quotes/tags safe inside inline JS without the escaping
        // that a Blade echo would apply (escaped JSON = syntax error = dead button).
        $tpJsonFlags = JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT;
    @endphp
    @if(count($tpGroups))
    <div class="mt-6 bg-white dark:bg-gray-900 rounded-xl border border-gray-200 dark:border-gray-700 shadow-sm p-5">
        <h3 class="text-sm font-semibold text-gray-900 dark:text-white mb-1">{{ __('pos.test_print_title') }}</h3>
        <p class="text-xs text-gray-500 dark:text-gray-400 mb-3">{{ __('pos.test_print_sub') }}</p>

        @if($tpDuplicate)
        <div class="mb-3 p-3 rounded-lg bg-amber-50 dark:bg-amber-900/20 border border-amber-300 dark:border-amber-700 text-xs text-amber-800 dark:text-amber-300">
            {{ __('pos.test_print_dup_warn') }}
        </div>
        @endif

        <div class="space-y-3">
            @foreach($tpGroups as $tpGroup)
            <div>
                @if($tpGroup['label'])
                <p class="text-[11px] font-semibold text-gray-600 dark:text-gray-400 mb-1.5">
                    {{ $tpGroup['label'] }}
                    <span class="{{ $tpGroup['online'] ? 'text-emerald-600 dark:text-emerald-400' : 'text-gray-400' }}">· {{ $tpGroup['online'] ? __('pos.online') : __('pos.offline') }}</span>
                </p>
                @endif
                <div class="flex flex-wrap gap-2">
                    @foreach($tpGroup['printers'] as $tpName)
                    <button type="button"
                        class="tn-testprint inline-flex items-center gap-1.5 px-3 py-1.5 rounded-lg border border-gray-300 dark:border-gray-600 bg-white dark:bg-gray-800 text-xs font-semibold text-gray-700 dark:text-gray-200 hover:border-purple-400 hover:text-purple-700 dark:hover:text-purple-300 transition disabled:opacity-50"
                        data-printer="{{ $tpName }}" data-device="{{ $tpGroup['uid'] }}">
                        <svg class="w-3.5 h-3.5" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M17 17h2a2 2 0 002-2v-4a2 2 0 00-2-2H5a2 2 0 00-2 2v4a2 2 0 002 2h2m2 4h6a2 2 0 002-2v-4a2 2 0 00-2-2H9a2 2 0 00-2 2v4a2 2 0 002 2zm8-12V5a2 2 0 00-2-2H9a2 2 0 00-2 2v4h10z"/></svg>
                        <span class="tn-testprint-label">{{ $tpName }}</span>
                    </button>
                    @endforeach
                </div>
            </div>
            @endforeach
        </div>

        <p id="tnTestPrintMsg" class="mt-3 text-xs font-semibold hidden"></p>
    </div>

    <script>
    (function () {
        var url = {!! json_encode($tpUrl, $tpJsonFlags) !!};
        var token = document.querySelector('meta[name="csrf-token"]');
        var msgs = {!! json_encode($tpMsgs, $tpJsonFlags) !!};
        var box = document.getElementById('tnTestPrintMsg');
        function say(text, ok) {
            if (!box) return;
            box.textContent = text;
            box.classList.remove('hidden');
            box.classList.toggle('text-emerald-600', !!ok);
            box.classList.toggle('dark:text-emerald-400', !!ok);
            box.classList.toggle('text-red-600', !ok);
            box.classList.toggle('dark:text-red-400', !ok);
        }
        document.querySelectorAll('.tn-testprint').forEach(function (btn) {
            btn.addEventListener('click', function () {
                var printer = btn.getAttribute('data-printer') || '';
                var device = btn.getAttribute('data-device') || '';
                var label = btn.querySelector('.tn-testprint-label');
                var original = label ? label.textContent : '';
                btn.disabled = true;
                if (label) label.textContent = msgs.sending;
                say(msgs.sending, true);
                var headers = { 'Content-Type': 'application/json', 'Accept': 'application/json' };
                if (token) headers['X-CSRF-TOKEN'] = token.getAttribute('content');
                fetch(url, {
                    method: 'POST',
                    headers: headers,
                    credentials: 'same-origin',
                    body: JSON.stringify({ printer: printer, device_uid: device })
                }).then(function (r) {
                    return r.json().catch(function () { return {}; }).then(function (j) {
                        // A print button must never claim success it did not get.
                        if (!r.ok || !j || j.success !== true) {
                            var reason = (j && (j.reason || (j.errors && Object.keys(j.errors)[0]))) || ('HTTP ' + r.status);
                            say(msgs.failed.replace(':reason', reason), false);
                        } else {
                            say(msgs.sent.replace(':printer', printer), true);
                        }
                    });
                }).catch(function () {
                    say(msgs.failed.replace(':reason', 'network'), false);
                }).then(function () {
                    btn.disabled = false;
                    if (label) label.textContent = original;
                });
            });
        });
    })();
    </script>
    @endif

    {{-- Recent failed jobs --}}
    @if($recentFailed->count())
    <div class="mt-6 bg-white dark:bg-gray-900 rounded-xl border border-gray-200 dark:border-gray-700 shadow-sm p-5">
        <h3 class="text-sm font-semibold text-gray-900 dark:text-white mb-3">{{ __('pos.recent_failed_prints') }}</h3>
        <div class="space-y-2">
            @foreach($recentFailed as $job)
            <div class="flex items-start justify-between gap-3 p-2.5 rounded-lg border border-red-100 dark:border-red-900/40 bg-red-50/50 dark:bg-red-900/10">
                <div class="min-w-0">
                    <p class="text-xs font-semibold text-gray-800 dark:text-gray-200">
                        {{ $job->type === 'bill' ? __('pos.bill_word_short') : __('pos.kot_word') }} #{{ $job->type === 'bill' ? $job->transaction_id : $job->restaurant_order_id }}
                        <span class="text-gray-400 font-normal">→ {{ $job->target_printer }}</span>
                    </p>
                    <p class="text-[11px] text-red-600 dark:text-red-400 truncate">{{ $job->error }}</p>
                </div>
                <span class="text-[11px] text-gray-400 whitespace-nowrap">{{ $job->created_at->diffForHumans() }}</span>
            </div>
            @endforeach
        </div>
    </div>
    @endif
</div>
</x-pos-layout>

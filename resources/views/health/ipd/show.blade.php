@php
    use App\Models\HealthAdmission;
    use App\Models\HealthAdmissionCharge;
    use App\Models\HealthAdmissionPayment;
    use Illuminate\Support\Js;

    $scrub = function ($value) {
        $clean = @iconv('UTF-8', 'UTF-8//IGNORE', (string) $value);
        return $clean === false ? '' : $clean;
    };

    $bedPayload = $assignableBeds->map(fn ($b) => [
        'id' => (int) $b->id,
        'code' => $scrub($b->code),
        'ward' => $scrub($b->ward->name ?? ''),
        'rate' => number_format($b->resolvedDailyRate(), 2),
    ])->values()->all();

    $isOpen = $admission->isOpen();
    $careChip = [
        'stable'    => 'bg-emerald-100 text-emerald-800 dark:bg-emerald-900/40 dark:text-emerald-200',
        'improving' => 'bg-sky-100 text-sky-800 dark:bg-sky-900/40 dark:text-sky-200',
        'serious'   => 'bg-amber-100 text-amber-800 dark:bg-amber-900/40 dark:text-amber-200',
        'critical'  => 'bg-rose-100 text-rose-800 dark:bg-rose-900/40 dark:text-rose-200',
    ];
@endphp
<x-health-layout>
    <div class="max-w-6xl mx-auto px-4 sm:px-6 py-5 space-y-5"
         x-data="{ panel: null, beds: {{ Js::from($bedPayload) }} }">

        {{-- ── header ── --}}
        <div class="rounded-2xl bg-white dark:bg-gray-800 border border-gray-200 dark:border-gray-700 p-5">
            <div class="flex flex-wrap items-start justify-between gap-3">
                <div>
                    <div class="flex items-center gap-2 flex-wrap">
                        <h1 class="text-xl sm:text-2xl font-black tracking-tight">{{ $admission->patient->name ?? '—' }}</h1>
                        <span class="px-2 py-0.5 rounded-lg text-[11px] font-bold bg-gray-100 dark:bg-gray-700">{{ __('health.adm_status_' . $admission->status) }}</span>
                        @if($admission->care_status)
                            <span class="px-2 py-0.5 rounded-lg text-[11px] font-bold {{ $careChip[$admission->care_status] ?? '' }}">{{ __('health.care_' . $admission->care_status) }}</span>
                        @endif
                    </div>
                    <p class="text-sm text-gray-500 dark:text-gray-400 mt-1">
                        {{ $admission->admission_no }}
                        @if($admission->patient?->mrn) · {{ $admission->patient->mrn }} @endif
                        @if($admission->patient?->gender) · {{ __('health.gender_' . $admission->patient->gender) }} @endif
                        @if($admission->patient?->age_years) · {{ $admission->patient->age_years }} @endif
                    </p>
                    <p class="text-sm text-gray-500 dark:text-gray-400">
                        {{ __('health.adm_type_' . $admission->admission_type) }}
                        @if($admission->ward) · {{ $admission->ward->name }} @endif
                        @if($admission->bed) · {{ $admission->bed->code }} @endif
                        @if($admission->doctor) · {{ $admission->doctor->name }} @endif
                    </p>
                    <p class="text-xs text-gray-500 dark:text-gray-400 mt-1">
                        {{ __('health.adm_admitted_at') }}: {{ $admission->admitted_at?->format('d M Y H:i') ?? '—' }}
                        · {{ __('health.adm_los') }}: {{ $admission->lengthOfStayDays() ?: '—' }}
                        @if($admission->discharged_at) · {{ __('health.adm_discharged_at') }}: {{ $admission->discharged_at->format('d M Y H:i') }} @endif
                    </p>
                </div>
                <a href="{{ route('health.ipd') }}" class="px-4 py-2.5 rounded-xl bg-gray-100 dark:bg-gray-700 text-sm font-bold">{{ __('health.back') }}</a>
            </div>

            @if($maySeeClinical && ($admission->reason || $admission->provisional_diagnosis))
                <div class="mt-4 grid sm:grid-cols-2 gap-3">
                    @if($admission->reason)
                        <div class="rounded-xl bg-gray-50 dark:bg-gray-900 p-3">
                            <p class="text-xs font-bold text-gray-500 dark:text-gray-400">{{ __('health.adm_reason') }}</p>
                            <p class="text-sm mt-0.5">{{ $admission->reason }}</p>
                        </div>
                    @endif
                    @if($admission->provisional_diagnosis)
                        <div class="rounded-xl bg-gray-50 dark:bg-gray-900 p-3">
                            <p class="text-xs font-bold text-gray-500 dark:text-gray-400">{{ __('health.adm_provisional_diagnosis') }}</p>
                            <p class="text-sm mt-0.5">{{ $admission->provisional_diagnosis }}</p>
                        </div>
                    @endif
                </div>
            @endif

            {{-- ── action bar ── --}}
            <div class="mt-4 flex flex-wrap gap-2">
                @if($mayManage && $admission->status === HealthAdmission::STATUS_REQUESTED)
                    <button type="button" @click="panel = panel === 'admit' ? null : 'admit'" class="px-3 py-2 rounded-xl bg-teal-700 text-white text-xs font-bold">{{ __('health.adm_admit') }}</button>
                    <button type="button" @click="panel = panel === 'reserve' ? null : 'reserve'" class="px-3 py-2 rounded-xl bg-gray-100 dark:bg-gray-700 text-xs font-bold">{{ __('health.bed_reserve') }}</button>
                @endif
                @if($mayManage && $isOpen && $admission->status !== HealthAdmission::STATUS_REQUESTED)
                    <button type="button" @click="panel = panel === 'transfer' ? null : 'transfer'" class="px-3 py-2 rounded-xl bg-gray-100 dark:bg-gray-700 text-xs font-bold">{{ __('health.adm_transfer') }}</button>
                    <button type="button" @click="panel = panel === 'care' ? null : 'care'" class="px-3 py-2 rounded-xl bg-gray-100 dark:bg-gray-700 text-xs font-bold">{{ __('health.adm_care') }}</button>
                @endif
                @if($mayManage && $admission->status === HealthAdmission::STATUS_ADMITTED)
                    <button type="button" @click="panel = panel === 'discharge_request' ? null : 'discharge_request'" class="px-3 py-2 rounded-xl bg-gray-100 dark:bg-gray-700 text-xs font-bold">{{ __('health.adm_discharge_request') }}</button>
                @endif
                @if($mayCharge && ($isOpen || $admission->status === HealthAdmission::STATUS_REQUESTED))
                    <button type="button" @click="panel = panel === 'charge' ? null : 'charge'" class="px-3 py-2 rounded-xl bg-gray-100 dark:bg-gray-700 text-xs font-bold">{{ __('health.charge_add') }}</button>
                    <button type="button" @click="panel = panel === 'payment' ? null : 'payment'" class="px-3 py-2 rounded-xl bg-gray-100 dark:bg-gray-700 text-xs font-bold">{{ __('health.payment_add') }}</button>
                @endif
                @if($mayCharge && $isOpen)
                    <form method="POST" action="{{ route('health.ipd.run-daily', $admission->id) }}">
                        @csrf
                        <button type="submit" class="px-3 py-2 rounded-xl bg-gray-100 dark:bg-gray-700 text-xs font-bold">{{ __('health.daily_charges_run_now') }}</button>
                    </form>
                @endif
                @if($mayDischarge && $isOpen)
                    <button type="button" @click="panel = panel === 'clear' ? null : 'clear'" class="px-3 py-2 rounded-xl bg-gray-100 dark:bg-gray-700 text-xs font-bold">{{ __('health.adm_clear') }}</button>
                    <button type="button" @click="panel = panel === 'discharge' ? null : 'discharge'" class="px-3 py-2 rounded-xl bg-emerald-700 text-white text-xs font-bold">{{ __('health.adm_discharge') }}</button>
                @endif
                @if($mayManage && $admission->status === HealthAdmission::STATUS_REQUESTED)
                    <button type="button" @click="panel = panel === 'cancel' ? null : 'cancel'" class="px-3 py-2 rounded-xl bg-rose-100 text-rose-800 dark:bg-rose-900/40 dark:text-rose-200 text-xs font-bold">{{ __('health.adm_cancel') }}</button>
                @endif
            </div>
        </div>

        {{-- ── panels ── --}}
        @if($mayManage)
            @foreach([['admit', route('health.ipd.admit', $admission->id), __('health.adm_admit')], ['reserve', route('health.ipd.reserve', $admission->id), __('health.bed_reserve')]] as [$key, $action, $label])
                <div x-show="panel === '{{ $key }}'" x-cloak class="rounded-2xl bg-white dark:bg-gray-800 border border-gray-200 dark:border-gray-700 p-5">
                    <form method="POST" action="{{ $action }}" class="flex flex-wrap items-end gap-3">
                        @csrf
                        <label class="block flex-1 min-w-[220px]">
                            <span class="text-xs font-bold text-gray-500 dark:text-gray-400">{{ __('health.bed') }}</span>
                            <select name="health_bed_id" required class="mt-1 w-full rounded-xl border-gray-300 dark:border-gray-600 dark:bg-gray-900 text-sm">
                                <template x-for="b in beds" :key="b.id">
                                    <option :value="b.id" x-text="b.ward + ' — ' + b.code + ' (' + b.rate + ')'"></option>
                                </template>
                            </select>
                        </label>
                        <button type="submit" class="px-4 py-2.5 rounded-xl bg-teal-700 text-white text-sm font-bold">{{ $label }}</button>
                    </form>
                    @if($assignableBeds->isEmpty())
                        <p class="text-xs text-rose-600 dark:text-rose-300 mt-2">{{ __('health.no_assignable_beds') }}</p>
                    @endif
                </div>
            @endforeach

            <div x-show="panel === 'transfer'" x-cloak class="rounded-2xl bg-white dark:bg-gray-800 border border-gray-200 dark:border-gray-700 p-5">
                <form method="POST" action="{{ route('health.ipd.transfer', $admission->id) }}" class="flex flex-wrap items-end gap-3">
                    @csrf
                    <label class="block flex-1 min-w-[220px]">
                        <span class="text-xs font-bold text-gray-500 dark:text-gray-400">{{ __('health.adm_transfer_to') }}</span>
                        <select name="health_bed_id" required class="mt-1 w-full rounded-xl border-gray-300 dark:border-gray-600 dark:bg-gray-900 text-sm">
                            <template x-for="b in beds" :key="b.id">
                                <option :value="b.id" x-text="b.ward + ' — ' + b.code + ' (' + b.rate + ')'"></option>
                            </template>
                        </select>
                    </label>
                    <label class="block flex-1 min-w-[220px]">
                        <span class="text-xs font-bold text-gray-500 dark:text-gray-400">{{ __('health.note') }}</span>
                        <input type="text" name="note" maxlength="300" class="mt-1 w-full rounded-xl border-gray-300 dark:border-gray-600 dark:bg-gray-900 text-sm">
                    </label>
                    <button type="submit" class="px-4 py-2.5 rounded-xl bg-teal-700 text-white text-sm font-bold">{{ __('health.adm_transfer') }}</button>
                </form>
            </div>

            <div x-show="panel === 'care'" x-cloak class="rounded-2xl bg-white dark:bg-gray-800 border border-gray-200 dark:border-gray-700 p-5">
                <form method="POST" action="{{ route('health.ipd.care', $admission->id) }}" class="space-y-3">
                    @csrf
                    <div class="flex flex-wrap items-end gap-3">
                        <label class="block">
                            <span class="text-xs font-bold text-gray-500 dark:text-gray-400">{{ __('health.adm_care_status') }}</span>
                            <select name="care_status" class="mt-1 rounded-xl border-gray-300 dark:border-gray-600 dark:bg-gray-900 text-sm">
                                @foreach(HealthAdmission::CARE_STATUSES as $care)
                                    <option value="{{ $care }}" @selected($admission->care_status === $care)>{{ __('health.care_' . $care) }}</option>
                                @endforeach
                            </select>
                        </label>
                        <button type="submit" class="px-4 py-2.5 rounded-xl bg-teal-700 text-white text-sm font-bold">{{ __('health.save') }}</button>
                    </div>
                    <label class="block">
                        <span class="text-xs font-bold text-gray-500 dark:text-gray-400">{{ __('health.adm_care_note') }}</span>
                        <textarea name="care_note" rows="3" maxlength="2000" class="mt-1 w-full rounded-xl border-gray-300 dark:border-gray-600 dark:bg-gray-900 text-sm"></textarea>
                    </label>
                </form>
            </div>

            <div x-show="panel === 'discharge_request'" x-cloak class="rounded-2xl bg-white dark:bg-gray-800 border border-gray-200 dark:border-gray-700 p-5">
                <form method="POST" action="{{ route('health.ipd.discharge-request', $admission->id) }}" class="space-y-3">
                    @csrf
                    <h2 class="text-base font-black">{{ __('health.adm_discharge_request') }}</h2>
                    <p class="text-xs text-gray-500 dark:text-gray-400">{{ __('health.adm_discharge_request_hint') }}</p>
                    <div class="grid sm:grid-cols-2 gap-3">
                        <label class="block">
                            <span class="text-xs font-bold text-gray-500 dark:text-gray-400">{{ __('health.discharge_type') }}</span>
                            <select name="discharge_type" class="mt-1 w-full rounded-xl border-gray-300 dark:border-gray-600 dark:bg-gray-900 text-sm">
                                @foreach(HealthAdmission::DISCHARGE_TYPES as $type)
                                    <option value="{{ $type }}">{{ __('health.discharge_type_' . $type) }}</option>
                                @endforeach
                            </select>
                        </label>
                        <label class="block">
                            <span class="text-xs font-bold text-gray-500 dark:text-gray-400">{{ __('health.follow_up_date') }}</span>
                            <input type="date" name="follow_up_date" class="mt-1 w-full rounded-xl border-gray-300 dark:border-gray-600 dark:bg-gray-900 text-sm">
                        </label>
                    </div>
                    <label class="block">
                        <span class="text-xs font-bold text-gray-500 dark:text-gray-400">{{ __('health.final_diagnosis') }}</span>
                        <input type="text" name="final_diagnosis" maxlength="500" class="mt-1 w-full rounded-xl border-gray-300 dark:border-gray-600 dark:bg-gray-900 text-sm">
                    </label>
                    <label class="block">
                        <span class="text-xs font-bold text-gray-500 dark:text-gray-400">{{ __('health.discharge_summary') }}</span>
                        <textarea name="discharge_summary" rows="4" maxlength="5000" class="mt-1 w-full rounded-xl border-gray-300 dark:border-gray-600 dark:bg-gray-900 text-sm"></textarea>
                    </label>
                    <label class="block">
                        <span class="text-xs font-bold text-gray-500 dark:text-gray-400">{{ __('health.discharge_advice') }}</span>
                        <textarea name="discharge_advice" rows="3" maxlength="5000" class="mt-1 w-full rounded-xl border-gray-300 dark:border-gray-600 dark:bg-gray-900 text-sm"></textarea>
                    </label>
                    <button type="submit" class="px-4 py-2.5 rounded-xl bg-teal-700 text-white text-sm font-bold">{{ __('health.save') }}</button>
                </form>
            </div>

            <div x-show="panel === 'cancel'" x-cloak class="rounded-2xl bg-white dark:bg-gray-800 border border-gray-200 dark:border-gray-700 p-5">
                <form method="POST" action="{{ route('health.ipd.cancel', $admission->id) }}" class="flex flex-wrap items-end gap-3">
                    @csrf
                    <label class="block flex-1 min-w-[260px]">
                        <span class="text-xs font-bold text-gray-500 dark:text-gray-400">{{ __('health.reason') }}</span>
                        <input type="text" name="cancel_reason" required maxlength="300" class="mt-1 w-full rounded-xl border-gray-300 dark:border-gray-600 dark:bg-gray-900 text-sm">
                    </label>
                    <button type="submit" class="px-4 py-2.5 rounded-xl bg-rose-700 text-white text-sm font-bold">{{ __('health.adm_cancel') }}</button>
                </form>
            </div>
        @endif

        @if($mayCharge)
            <div x-show="panel === 'charge'" x-cloak class="rounded-2xl bg-white dark:bg-gray-800 border border-gray-200 dark:border-gray-700 p-5">
                <form method="POST" action="{{ route('health.ipd.charges.store', $admission->id) }}" class="space-y-3">
                    @csrf
                    <h2 class="text-base font-black">{{ __('health.charge_add') }}</h2>
                    <p class="text-xs text-gray-500 dark:text-gray-400">{{ __('health.charge_auto_hint') }}</p>
                    <div class="grid sm:grid-cols-2 lg:grid-cols-4 gap-3">
                        <label class="block">
                            <span class="text-xs font-bold text-gray-500 dark:text-gray-400">{{ __('health.charge_category') }}</span>
                            <select name="category" class="mt-1 w-full rounded-xl border-gray-300 dark:border-gray-600 dark:bg-gray-900 text-sm">
                                @foreach($chargeCategories as $category)
                                    <option value="{{ $category }}">{{ __('health.charge_cat_' . $category) }}</option>
                                @endforeach
                            </select>
                        </label>
                        <label class="block lg:col-span-2">
                            <span class="text-xs font-bold text-gray-500 dark:text-gray-400">{{ __('health.description') }}</span>
                            <input type="text" name="description" required maxlength="300" class="mt-1 w-full rounded-xl border-gray-300 dark:border-gray-600 dark:bg-gray-900 text-sm">
                        </label>
                        <label class="block">
                            <span class="text-xs font-bold text-gray-500 dark:text-gray-400">{{ __('health.charge_date') }}</span>
                            <input type="date" name="charge_date" value="{{ now()->toDateString() }}" class="mt-1 w-full rounded-xl border-gray-300 dark:border-gray-600 dark:bg-gray-900 text-sm">
                        </label>
                        <label class="block">
                            <span class="text-xs font-bold text-gray-500 dark:text-gray-400">{{ __('health.unit_price') }}</span>
                            <input type="number" step="0.01" min="0" name="unit_price" required class="mt-1 w-full rounded-xl border-gray-300 dark:border-gray-600 dark:bg-gray-900 text-sm">
                        </label>
                        <label class="block">
                            <span class="text-xs font-bold text-gray-500 dark:text-gray-400">{{ __('health.quantity') }}</span>
                            <input type="number" step="0.01" min="0.01" name="quantity" value="1" required class="mt-1 w-full rounded-xl border-gray-300 dark:border-gray-600 dark:bg-gray-900 text-sm">
                        </label>
                        @if($mayDischarge)
                            <label class="block">
                                <span class="text-xs font-bold text-gray-500 dark:text-gray-400">{{ __('health.concession') }}</span>
                                <input type="number" step="0.01" min="0" name="concession_amount" value="0" class="mt-1 w-full rounded-xl border-gray-300 dark:border-gray-600 dark:bg-gray-900 text-sm">
                            </label>
                            <label class="block">
                                <span class="text-xs font-bold text-gray-500 dark:text-gray-400">{{ __('health.concession_reason') }}</span>
                                <input type="text" name="concession_reason" maxlength="300" class="mt-1 w-full rounded-xl border-gray-300 dark:border-gray-600 dark:bg-gray-900 text-sm">
                            </label>
                        @endif
                        <label class="block">
                            <span class="text-xs font-bold text-gray-500 dark:text-gray-400">{{ __('health.reference') }}</span>
                            <input type="text" name="reference" maxlength="120" class="mt-1 w-full rounded-xl border-gray-300 dark:border-gray-600 dark:bg-gray-900 text-sm">
                        </label>
                    </div>
                    <button type="submit" class="px-4 py-2.5 rounded-xl bg-teal-700 text-white text-sm font-bold">{{ __('health.charge_post') }}</button>
                </form>
            </div>

            <div x-show="panel === 'payment'" x-cloak class="rounded-2xl bg-white dark:bg-gray-800 border border-gray-200 dark:border-gray-700 p-5">
                <form method="POST" action="{{ route('health.ipd.payments.store', $admission->id) }}" class="flex flex-wrap items-end gap-3">
                    @csrf
                    <label class="block">
                        <span class="text-xs font-bold text-gray-500 dark:text-gray-400">{{ __('health.payment_kind') }}</span>
                        <select name="kind" class="mt-1 rounded-xl border-gray-300 dark:border-gray-600 dark:bg-gray-900 text-sm">
                            @foreach(HealthAdmissionPayment::KINDS as $kind)
                                <option value="{{ $kind }}">{{ __('health.pay_kind_' . $kind) }}</option>
                            @endforeach
                        </select>
                    </label>
                    <label class="block">
                        <span class="text-xs font-bold text-gray-500 dark:text-gray-400">{{ __('health.amount') }}</span>
                        <input type="number" step="0.01" min="0.01" name="amount" required class="mt-1 rounded-xl border-gray-300 dark:border-gray-600 dark:bg-gray-900 text-sm">
                    </label>
                    <label class="block">
                        <span class="text-xs font-bold text-gray-500 dark:text-gray-400">{{ __('health.payment_method') }}</span>
                        <select name="method" class="mt-1 rounded-xl border-gray-300 dark:border-gray-600 dark:bg-gray-900 text-sm">
                            @foreach($paymentMethods as $method)
                                <option value="{{ $method }}">{{ __('health.pay_method_' . $method) }}</option>
                            @endforeach
                        </select>
                    </label>
                    <label class="block flex-1 min-w-[180px]">
                        <span class="text-xs font-bold text-gray-500 dark:text-gray-400">{{ __('health.reference') }}</span>
                        <input type="text" name="reference" maxlength="120" class="mt-1 w-full rounded-xl border-gray-300 dark:border-gray-600 dark:bg-gray-900 text-sm">
                    </label>
                    <button type="submit" class="px-4 py-2.5 rounded-xl bg-teal-700 text-white text-sm font-bold">{{ __('health.save') }}</button>
                </form>
            </div>
        @endif

        @if($mayDischarge)
            <div x-show="panel === 'clear'" x-cloak class="rounded-2xl bg-white dark:bg-gray-800 border border-gray-200 dark:border-gray-700 p-5">
                <form method="POST" action="{{ route('health.ipd.clear', $admission->id) }}" class="flex flex-wrap items-end gap-3">
                    @csrf
                    <label class="block">
                        <span class="text-xs font-bold text-gray-500 dark:text-gray-400">{{ __('health.stay_concession') }}</span>
                        <input type="number" step="0.01" min="0" name="concession_amount" value="{{ (float) $admission->concession_amount }}"
                               class="mt-1 rounded-xl border-gray-300 dark:border-gray-600 dark:bg-gray-900 text-sm">
                    </label>
                    <label class="block flex-1 min-w-[240px]">
                        <span class="text-xs font-bold text-gray-500 dark:text-gray-400">{{ __('health.concession_reason') }}</span>
                        <input type="text" name="concession_reason" maxlength="300" value="{{ $admission->concession_reason }}"
                               class="mt-1 w-full rounded-xl border-gray-300 dark:border-gray-600 dark:bg-gray-900 text-sm">
                    </label>
                    <button type="submit" class="px-4 py-2.5 rounded-xl bg-teal-700 text-white text-sm font-bold">{{ __('health.adm_clear') }}</button>
                </form>
            </div>

            <div x-show="panel === 'discharge'" x-cloak class="rounded-2xl bg-white dark:bg-gray-800 border border-gray-200 dark:border-gray-700 p-5">
                <form method="POST" action="{{ route('health.ipd.discharge', $admission->id) }}" class="space-y-3">
                    @csrf
                    <h2 class="text-base font-black">{{ __('health.adm_discharge') }}</h2>

                    @if($blockers)
                        <div class="rounded-xl bg-rose-50 dark:bg-rose-900/30 border border-rose-200 dark:border-rose-800 p-3 space-y-1">
                            @foreach($blockers as $blocker)
                                <p class="text-xs font-bold text-rose-800 dark:text-rose-200">{{ $blocker['message'] }}</p>
                            @endforeach
                        </div>
                    @endif

                    <div class="grid sm:grid-cols-2 gap-3">
                        <label class="block">
                            <span class="text-xs font-bold text-gray-500 dark:text-gray-400">{{ __('health.discharge_type') }}</span>
                            <select name="discharge_type" class="mt-1 w-full rounded-xl border-gray-300 dark:border-gray-600 dark:bg-gray-900 text-sm">
                                @foreach(HealthAdmission::DISCHARGE_TYPES as $type)
                                    <option value="{{ $type }}" @selected($admission->discharge_type === $type)>{{ __('health.discharge_type_' . $type) }}</option>
                                @endforeach
                            </select>
                        </label>
                        <label class="block">
                            <span class="text-xs font-bold text-gray-500 dark:text-gray-400">{{ __('health.follow_up_date') }}</span>
                            <input type="date" name="follow_up_date" value="{{ $admission->follow_up_date?->toDateString() }}"
                                   class="mt-1 w-full rounded-xl border-gray-300 dark:border-gray-600 dark:bg-gray-900 text-sm">
                        </label>
                    </div>

                    <label class="flex items-start gap-2 text-xs">
                        <input type="checkbox" name="force" value="1" class="mt-0.5 rounded">
                        <span class="text-gray-600 dark:text-gray-300">{{ __('health.discharge_force') }}</span>
                    </label>
                    <label class="block">
                        <span class="text-xs font-bold text-gray-500 dark:text-gray-400">{{ __('health.discharge_force_reason') }}</span>
                        <input type="text" name="force_reason" maxlength="300" class="mt-1 w-full rounded-xl border-gray-300 dark:border-gray-600 dark:bg-gray-900 text-sm">
                    </label>

                    <button type="submit" class="px-4 py-2.5 rounded-xl bg-emerald-700 text-white text-sm font-bold">{{ __('health.adm_discharge') }}</button>
                </form>
            </div>
        @endif

        {{-- ── the bill ── --}}
        <div class="grid lg:grid-cols-3 gap-5">
            <div class="lg:col-span-2 rounded-2xl bg-white dark:bg-gray-800 border border-gray-200 dark:border-gray-700 overflow-hidden">
                <div class="px-5 py-4 border-b border-gray-100 dark:border-gray-700">
                    <h2 class="text-base font-black">{{ __('health.charges') }}</h2>
                </div>
                <div class="overflow-x-auto">
                    <table class="w-full text-sm">
                        <thead class="bg-gray-50 dark:bg-gray-900 text-xs text-gray-500 dark:text-gray-400">
                            <tr>
                                <th class="text-start px-4 py-2 font-bold">{{ __('health.charge_date') }}</th>
                                <th class="text-start px-4 py-2 font-bold">{{ __('health.charge_category') }}</th>
                                <th class="text-start px-4 py-2 font-bold">{{ __('health.description') }}</th>
                                <th class="text-end px-4 py-2 font-bold">{{ __('health.quantity') }}</th>
                                <th class="text-end px-4 py-2 font-bold">{{ __('health.amount') }}</th>
                                <th class="px-4 py-2"></th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-gray-100 dark:divide-gray-700">
                            @forelse($charges as $charge)
                                <tr class="{{ $charge->status === HealthAdmissionCharge::STATUS_REVERSED ? 'opacity-50 line-through' : '' }}">
                                    <td class="px-4 py-2 text-xs">{{ $charge->charge_date?->format('d M Y') }}</td>
                                    <td class="px-4 py-2 text-xs">{{ __('health.charge_cat_' . $charge->category) }}</td>
                                    <td class="px-4 py-2">
                                        {{ $charge->description }}
                                        @if($charge->concession_amount > 0)
                                            <span class="block text-[11px] text-amber-700 dark:text-amber-300">
                                                {{ __('health.concession') }}: {{ number_format((float) $charge->concession_amount, 2) }}
                                            </span>
                                        @endif
                                        @if($charge->reversal_reason)
                                            <span class="block text-[11px] text-rose-700 dark:text-rose-300">{{ $charge->reversal_reason }}</span>
                                        @endif
                                    </td>
                                    <td class="px-4 py-2 text-end">{{ rtrim(rtrim(number_format((float) $charge->quantity, 2), '0'), '.') }}</td>
                                    <td class="px-4 py-2 text-end font-bold">{{ number_format((float) $charge->net_amount, 2) }}</td>
                                    <td class="px-4 py-2 text-end">
                                        @if($mayCharge && $charge->status === HealthAdmissionCharge::STATUS_POSTED)
                                            <form method="POST" action="{{ route('health.ipd.charges.reverse', [$admission->id, $charge->id]) }}"
                                                  class="flex items-center gap-1 justify-end">
                                                @csrf
                                                <input type="text" name="reversal_reason" required maxlength="300" placeholder="{{ __('health.reason') }}"
                                                       class="w-28 rounded-lg border-gray-300 dark:border-gray-600 dark:bg-gray-900 text-[11px] py-1">
                                                <button type="submit" class="text-[11px] font-bold text-rose-700 dark:text-rose-300">{{ __('health.reverse') }}</button>
                                            </form>
                                        @endif
                                    </td>
                                </tr>
                            @empty
                                <tr><td colspan="6" class="px-4 py-8 text-center text-sm text-gray-500">{{ __('health.no_charges') }}</td></tr>
                            @endforelse
                        </tbody>
                    </table>
                </div>
            </div>

            <div class="space-y-5">
                <div class="rounded-2xl bg-white dark:bg-gray-800 border border-gray-200 dark:border-gray-700 p-5">
                    <h2 class="text-base font-black">{{ __('health.bill_summary') }}</h2>
                    <dl class="mt-3 space-y-1.5 text-sm">
                        @foreach([
                            'health.gross' => $summary['gross'],
                            'health.concession' => $summary['concession'],
                            'health.net' => $summary['net'],
                            'health.advances' => $summary['advances'],
                            'health.refunds' => $summary['refunds'],
                        ] as $label => $value)
                            <div class="flex justify-between gap-3">
                                <dt class="text-gray-500 dark:text-gray-400">{{ __($label) }}</dt>
                                <dd class="font-bold">{{ number_format((float) $value, 2) }}</dd>
                            </div>
                        @endforeach
                        <div class="flex justify-between gap-3 pt-2 border-t border-gray-100 dark:border-gray-700">
                            <dt class="font-black">{{ __('health.outstanding') }}</dt>
                            <dd class="font-black {{ $summary['outstanding'] > 0 ? 'text-rose-700 dark:text-rose-300' : 'text-emerald-700 dark:text-emerald-300' }}">
                                {{ number_format((float) $summary['outstanding'], 2) }}
                            </dd>
                        </div>
                        @if($summary['refund_due'] > 0)
                            <div class="flex justify-between gap-3">
                                <dt class="font-black text-amber-700 dark:text-amber-300">{{ __('health.refund_due') }}</dt>
                                <dd class="font-black">{{ number_format((float) $summary['refund_due'], 2) }}</dd>
                            </div>
                        @endif
                        @if($summary['deposit_short'] > 0)
                            <div class="flex justify-between gap-3">
                                <dt class="text-amber-700 dark:text-amber-300">{{ __('health.deposit_short') }}</dt>
                                <dd class="font-bold">{{ number_format((float) $summary['deposit_short'], 2) }}</dd>
                            </div>
                        @endif
                    </dl>
                    <p class="text-xs mt-3 {{ $admission->cleared_at ? 'text-emerald-700 dark:text-emerald-300' : 'text-gray-500' }}">
                        {{ $admission->cleared_at ? __('health.cleared_at', ['at' => $admission->cleared_at->format('d M Y H:i')]) : __('health.not_cleared') }}
                    </p>
                </div>

                <div class="rounded-2xl bg-white dark:bg-gray-800 border border-gray-200 dark:border-gray-700 p-5">
                    <h2 class="text-base font-black">{{ __('health.payments') }}</h2>
                    <ul class="mt-3 space-y-2 text-sm">
                        @forelse($payments as $payment)
                            <li class="flex justify-between gap-3">
                                <span>
                                    <span class="font-bold">{{ __('health.pay_kind_' . $payment->kind) }}</span>
                                    <span class="block text-[11px] text-gray-500">
                                        {{ __('health.pay_method_' . $payment->method) }} · {{ $payment->created_at?->format('d M Y H:i') }}
                                    </span>
                                </span>
                                <span class="font-bold {{ $payment->kind === 'refund' ? 'text-rose-700 dark:text-rose-300' : '' }}">
                                    {{ number_format((float) $payment->amount, 2) }}
                                </span>
                            </li>
                        @empty
                            <li class="text-sm text-gray-500">{{ __('health.no_payments') }}</li>
                        @endforelse
                    </ul>
                </div>
            </div>
        </div>

        {{-- ── operations on this stay ── --}}
        @if($operations->isNotEmpty())
            <div class="rounded-2xl bg-white dark:bg-gray-800 border border-gray-200 dark:border-gray-700 p-5">
                <h2 class="text-base font-black">{{ __('health.operations') }}</h2>
                <ul class="mt-3 divide-y divide-gray-100 dark:divide-gray-700">
                    @foreach($operations as $operation)
                        <li class="py-2.5 flex flex-wrap justify-between gap-3 text-sm">
                            <span>
                                @if(Route::has('health.operations.show'))
                                    <a href="{{ route('health.operations.show', $operation->id) }}" class="font-bold text-teal-700 dark:text-teal-300">{{ $operation->title }}</a>
                                @else
                                    <span class="font-bold">{{ $operation->title }}</span>
                                @endif
                                <span class="block text-[11px] text-gray-500">
                                    {{ $operation->operation_no }}
                                    @if($operation->surgeon) · {{ $operation->surgeon->name }} @endif
                                    @if($operation->scheduled_start) · {{ $operation->scheduled_start->format('d M Y H:i') }} @endif
                                </span>
                            </span>
                            <span class="px-2 py-0.5 rounded-lg text-[11px] font-bold bg-gray-100 dark:bg-gray-700 self-start">
                                {{ __('health.op_status_' . $operation->status) }}
                            </span>
                        </li>
                    @endforeach
                </ul>
            </div>
        @endif

        {{-- ── timeline ── --}}
        <div class="rounded-2xl bg-white dark:bg-gray-800 border border-gray-200 dark:border-gray-700 p-5">
            <h2 class="text-base font-black">{{ __('health.adm_timeline') }}</h2>
            <ul class="mt-3 space-y-3">
                @forelse($events as $event)
                    <li class="flex gap-3">
                        <span class="mt-1.5 w-2 h-2 rounded-full bg-teal-600 shrink-0"></span>
                        <div class="min-w-0">
                            <p class="text-sm font-bold">{{ __('health.adm_event_' . $event->event) }}</p>
                            @if($event->note && ($maySeeClinical || $event->event !== 'care_note'))
                                <p class="text-sm text-gray-600 dark:text-gray-300 break-words">{{ $event->note }}</p>
                            @endif
                            <p class="text-[11px] text-gray-500">
                                {{ $event->occurred_at?->format('d M Y H:i') }}
                                @if($event->actor_name) · {{ $event->actor_name }} @endif
                            </p>
                        </div>
                    </li>
                @empty
                    <li class="text-sm text-gray-500">{{ __('health.no_events') }}</li>
                @endforelse
            </ul>
        </div>
    </div>
</x-health-layout>

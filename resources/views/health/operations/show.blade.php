@php
    use App\Models\HealthOperation;
    use App\Models\HealthOperationTeamMember;
    use Illuminate\Support\Js;

    $scrub = function ($value) {
        $clean = @iconv('UTF-8', 'UTF-8//IGNORE', (string) $value);
        return $clean === false ? '' : $clean;
    };

    // Team and consumables are edited as whole lists — the server replaces the
    // set, so the rows only have to arrive complete, not diffed.
    $teamPayload = $operation->team->map(fn ($m) => [
        'name' => $scrub($m->name),
        'role' => $m->role,
        'health_doctor_id' => $m->health_doctor_id ? (int) $m->health_doctor_id : '',
        'fee_amount' => (string) $m->fee_amount,
        'note' => $scrub($m->note),
    ])->values()->all();

    $consumablePayload = $operation->consumables->map(fn ($c) => [
        'item_name' => $scrub($c->item_name),
        'unit' => $scrub($c->unit),
        'quantity' => (string) $c->quantity,
        'unit_price' => (string) $c->unit_price,
        'is_billable' => (bool) $c->is_billable,
        'note' => $scrub($c->note),
    ])->values()->all();

    $checklist = $operation->checklist();
    $locked = in_array($operation->status, [HealthOperation::STATUS_COMPLETED, HealthOperation::STATUS_CANCELLED], true);
    $statusChip = [
        'scheduled'   => 'bg-sky-100 text-sky-800 dark:bg-sky-900/40 dark:text-sky-200',
        'in_progress' => 'bg-amber-100 text-amber-800 dark:bg-amber-900/40 dark:text-amber-200',
        'completed'   => 'bg-emerald-100 text-emerald-800 dark:bg-emerald-900/40 dark:text-emerald-200',
        'cancelled'   => 'bg-rose-100 text-rose-800 dark:bg-rose-900/40 dark:text-rose-200',
        'postponed'   => 'bg-gray-200 text-gray-700 dark:bg-gray-700 dark:text-gray-200',
    ];
@endphp
<x-health-layout>
    <div class="max-w-6xl mx-auto px-4 sm:px-6 py-5 space-y-5"
         x-data="{
            panel: null,
            team: {{ Js::from($teamPayload) }},
            consumables: {{ Js::from($consumablePayload) }},
            addMember() { this.team.push({ name: '', role: 'assistant', health_doctor_id: '', fee_amount: '0', note: '' }); },
            addConsumable() { this.consumables.push({ item_name: '', unit: '', quantity: '1', unit_price: '0', is_billable: true, note: '' }); },
            consumableTotal() {
                return this.consumables.reduce((sum, c) => sum + (parseFloat(c.quantity) || 0) * (parseFloat(c.unit_price) || 0), 0).toFixed(2);
            }
         }">

        {{-- ── header ── --}}
        <div class="rounded-2xl bg-white dark:bg-gray-800 border border-gray-200 dark:border-gray-700 p-5">
            <div class="flex flex-wrap items-start justify-between gap-3">
                <div>
                    <div class="flex items-center gap-2 flex-wrap">
                        <h1 class="text-xl sm:text-2xl font-black tracking-tight">{{ $operation->title }}</h1>
                        <span class="px-2 py-0.5 rounded-lg text-[11px] font-bold {{ $statusChip[$operation->status] ?? '' }}">{{ __('health.op_status_' . $operation->status) }}</span>
                        <span class="px-2 py-0.5 rounded-lg text-[11px] font-bold bg-gray-100 dark:bg-gray-700">{{ __('health.op_urgency_' . $operation->urgency) }}</span>
                        @if($operation->is_package)
                            <span class="px-2 py-0.5 rounded-lg text-[11px] font-bold bg-teal-100 text-teal-800 dark:bg-teal-900/40 dark:text-teal-200">{{ __('health.op_package') }}</span>
                        @endif
                    </div>
                    <p class="text-sm text-gray-500 dark:text-gray-400 mt-1">
                        {{ $operation->operation_no }} · {{ $operation->patient->name ?? '—' }}
                        @if($operation->admission)
                            · <a href="{{ route('health.ipd.show', $operation->admission->id) }}" class="font-bold text-teal-700 dark:text-teal-300">{{ $operation->admission->admission_no }}</a>
                        @else
                            · {{ __('health.op_daycare') }}
                        @endif
                    </p>
                    <p class="text-sm text-gray-500 dark:text-gray-400">
                        {{ $operation->theatre->name ?? __('health.no_theatre') }}
                        · {{ $operation->scheduled_start?->format('d M Y H:i') ?? __('health.op_unslotted') }}
                        @if($operation->scheduled_end) – {{ $operation->scheduled_end->format('H:i') }} @endif
                    </p>
                    <p class="text-sm text-gray-500 dark:text-gray-400">
                        {{ __('health.op_surgeon') }}: {{ $operation->surgeon->name ?? '—' }}
                        · {{ __('health.op_anaesthetist') }}: {{ $operation->anaesthetist->name ?? '—' }}
                        @if($operation->anaesthesia_type) · {{ __('health.anaesthesia_' . $operation->anaesthesia_type) }} @endif
                    </p>
                </div>
                <a href="{{ route('health.operations') }}" class="px-4 py-2.5 rounded-xl bg-gray-100 dark:bg-gray-700 text-sm font-bold">{{ __('health.back') }}</a>
            </div>

            @if($mayManage && !$locked)
                <div class="mt-4 flex flex-wrap gap-2">
                    <button type="button" @click="panel = panel === 'reschedule' ? null : 'reschedule'" class="px-3 py-2 rounded-xl bg-gray-100 dark:bg-gray-700 text-xs font-bold">{{ __('health.op_reschedule') }}</button>
                    <button type="button" @click="panel = panel === 'preop' ? null : 'preop'" class="px-3 py-2 rounded-xl bg-gray-100 dark:bg-gray-700 text-xs font-bold">{{ __('health.op_preop') }}</button>
                    <button type="button" @click="panel = panel === 'team' ? null : 'team'" class="px-3 py-2 rounded-xl bg-gray-100 dark:bg-gray-700 text-xs font-bold">{{ __('health.op_team') }}</button>
                    <button type="button" @click="panel = panel === 'consumables' ? null : 'consumables'" class="px-3 py-2 rounded-xl bg-gray-100 dark:bg-gray-700 text-xs font-bold">{{ __('health.op_consumables') }}</button>
                    @if($operation->status === HealthOperation::STATUS_SCHEDULED)
                        <form method="POST" action="{{ route('health.operations.start', $operation->id) }}">
                            @csrf
                            <button type="submit" class="px-3 py-2 rounded-xl bg-teal-700 text-white text-xs font-bold">{{ __('health.op_start_now') }}</button>
                        </form>
                    @endif
                    @if($operation->status === HealthOperation::STATUS_IN_PROGRESS)
                        <button type="button" @click="panel = panel === 'complete' ? null : 'complete'" class="px-3 py-2 rounded-xl bg-emerald-700 text-white text-xs font-bold">{{ __('health.op_complete') }}</button>
                    @endif
                    <button type="button" @click="panel = panel === 'cancel' ? null : 'cancel'" class="px-3 py-2 rounded-xl bg-rose-100 text-rose-800 dark:bg-rose-900/40 dark:text-rose-200 text-xs font-bold">{{ __('health.op_cancel') }}</button>
                </div>
            @endif

            @if($operation->status === HealthOperation::STATUS_CANCELLED && $operation->cancel_reason)
                <p class="mt-3 text-xs font-bold text-rose-700 dark:text-rose-300">{{ $operation->cancel_reason }}</p>
            @endif
        </div>

        @if($mayManage && !$locked)
            {{-- ── reschedule ── --}}
            <div x-show="panel === 'reschedule'" x-cloak class="rounded-2xl bg-white dark:bg-gray-800 border border-gray-200 dark:border-gray-700 p-5">
                <form method="POST" action="{{ route('health.operations.reschedule', $operation->id) }}" class="space-y-3">
                    @csrf
                    <h2 class="text-base font-black">{{ __('health.op_reschedule') }}</h2>
                    <div class="grid sm:grid-cols-2 lg:grid-cols-4 gap-3">
                        <label class="block">
                            <span class="text-xs font-bold text-gray-500 dark:text-gray-400">{{ __('health.op_start') }}</span>
                            <input type="datetime-local" name="scheduled_start" value="{{ $operation->scheduled_start?->format('Y-m-d\TH:i') }}"
                                   class="mt-1 w-full rounded-xl border-gray-300 dark:border-gray-600 dark:bg-gray-900 text-sm">
                        </label>
                        <label class="block">
                            <span class="text-xs font-bold text-gray-500 dark:text-gray-400">{{ __('health.op_end') }}</span>
                            <input type="datetime-local" name="scheduled_end" value="{{ $operation->scheduled_end?->format('Y-m-d\TH:i') }}"
                                   class="mt-1 w-full rounded-xl border-gray-300 dark:border-gray-600 dark:bg-gray-900 text-sm">
                        </label>
                        <label class="block">
                            <span class="text-xs font-bold text-gray-500 dark:text-gray-400">{{ __('health.theatre') }}</span>
                            <select name="health_operation_theatre_id" class="mt-1 w-full rounded-xl border-gray-300 dark:border-gray-600 dark:bg-gray-900 text-sm">
                                <option value="">—</option>
                                @foreach($theatres as $theatre)
                                    <option value="{{ $theatre->id }}" @selected($operation->health_operation_theatre_id == $theatre->id)>{{ $theatre->name }}</option>
                                @endforeach
                            </select>
                        </label>
                        <label class="block">
                            <span class="text-xs font-bold text-gray-500 dark:text-gray-400">{{ __('health.op_urgency') }}</span>
                            <select name="urgency" class="mt-1 w-full rounded-xl border-gray-300 dark:border-gray-600 dark:bg-gray-900 text-sm">
                                @foreach(HealthOperation::URGENCIES as $urgency)
                                    <option value="{{ $urgency }}" @selected($operation->urgency === $urgency)>{{ __('health.op_urgency_' . $urgency) }}</option>
                                @endforeach
                            </select>
                        </label>
                        <label class="block">
                            <span class="text-xs font-bold text-gray-500 dark:text-gray-400">{{ __('health.op_surgeon') }}</span>
                            <select name="primary_surgeon_id" class="mt-1 w-full rounded-xl border-gray-300 dark:border-gray-600 dark:bg-gray-900 text-sm">
                                <option value="">—</option>
                                @foreach($doctors as $doctor)
                                    <option value="{{ $doctor->id }}" @selected($operation->primary_surgeon_id == $doctor->id)>{{ $doctor->name }}</option>
                                @endforeach
                            </select>
                        </label>
                        <label class="block">
                            <span class="text-xs font-bold text-gray-500 dark:text-gray-400">{{ __('health.op_anaesthetist') }}</span>
                            <select name="anaesthetist_id" class="mt-1 w-full rounded-xl border-gray-300 dark:border-gray-600 dark:bg-gray-900 text-sm">
                                <option value="">—</option>
                                @foreach($doctors as $doctor)
                                    <option value="{{ $doctor->id }}" @selected($operation->anaesthetist_id == $doctor->id)>{{ $doctor->name }}</option>
                                @endforeach
                            </select>
                        </label>
                        <label class="block">
                            <span class="text-xs font-bold text-gray-500 dark:text-gray-400">{{ __('health.op_anaesthesia') }}</span>
                            <select name="anaesthesia_type" class="mt-1 w-full rounded-xl border-gray-300 dark:border-gray-600 dark:bg-gray-900 text-sm">
                                <option value="">—</option>
                                @foreach($anaesthesiaTypes as $type)
                                    <option value="{{ $type }}" @selected($operation->anaesthesia_type === $type)>{{ __('health.anaesthesia_' . $type) }}</option>
                                @endforeach
                            </select>
                        </label>
                        <label class="block">
                            <span class="text-xs font-bold text-gray-500 dark:text-gray-400">{{ __('health.op_consent') }}</span>
                            <input type="text" name="consent_reference" value="{{ $operation->consent_reference }}" maxlength="120"
                                   class="mt-1 w-full rounded-xl border-gray-300 dark:border-gray-600 dark:bg-gray-900 text-sm">
                        </label>
                    </div>
                    <label class="block">
                        <span class="text-xs font-bold text-gray-500 dark:text-gray-400">{{ __('health.reason') }}</span>
                        <input type="text" name="reschedule_reason" maxlength="300" class="mt-1 w-full rounded-xl border-gray-300 dark:border-gray-600 dark:bg-gray-900 text-sm">
                    </label>
                    <button type="submit" class="px-4 py-2.5 rounded-xl bg-teal-700 text-white text-sm font-bold">{{ __('health.save') }}</button>
                </form>
            </div>

            {{-- ── pre-op ── --}}
            <div x-show="panel === 'preop'" x-cloak class="rounded-2xl bg-white dark:bg-gray-800 border border-gray-200 dark:border-gray-700 p-5">
                <form method="POST" action="{{ route('health.operations.pre-op', $operation->id) }}" class="space-y-3">
                    @csrf
                    <h2 class="text-base font-black">{{ __('health.op_preop') }}</h2>
                    @forelse($checklist as $item)
                        <label class="flex items-start gap-2 text-sm">
                            <input type="checkbox" name="ticked[]" value="{{ $item['item'] }}" @checked($item['done']) class="mt-0.5 rounded">
                            <span>{{ $item['item'] }}</span>
                        </label>
                    @empty
                        <p class="text-sm text-gray-500">{{ __('health.op_no_checklist') }}</p>
                    @endforelse
                    <label class="block">
                        <span class="text-xs font-bold text-gray-500 dark:text-gray-400">{{ __('health.op_preop_notes') }}</span>
                        <textarea name="pre_op_notes" rows="3" maxlength="2000" class="mt-1 w-full rounded-xl border-gray-300 dark:border-gray-600 dark:bg-gray-900 text-sm">{{ $operation->pre_op_notes }}</textarea>
                    </label>
                    <button type="submit" class="px-4 py-2.5 rounded-xl bg-teal-700 text-white text-sm font-bold">{{ __('health.save') }}</button>
                </form>
            </div>

            {{-- ── team ── --}}
            <div x-show="panel === 'team'" x-cloak class="rounded-2xl bg-white dark:bg-gray-800 border border-gray-200 dark:border-gray-700 p-5">
                <form method="POST" action="{{ route('health.operations.team', $operation->id) }}" class="space-y-3">
                    @csrf
                    <h2 class="text-base font-black">{{ __('health.op_team') }}</h2>
                    <template x-for="(member, i) in team" :key="i">
                        <div class="grid sm:grid-cols-2 lg:grid-cols-5 gap-2 items-end">
                            <label class="block">
                                <span class="text-xs font-bold text-gray-500 dark:text-gray-400">{{ __('health.name') }}</span>
                                <input type="text" :name="'team[' + i + '][name]'" x-model="member.name" maxlength="150"
                                       class="mt-1 w-full rounded-xl border-gray-300 dark:border-gray-600 dark:bg-gray-900 text-sm">
                            </label>
                            <label class="block">
                                <span class="text-xs font-bold text-gray-500 dark:text-gray-400">{{ __('health.op_role') }}</span>
                                <select :name="'team[' + i + '][role]'" x-model="member.role" class="mt-1 w-full rounded-xl border-gray-300 dark:border-gray-600 dark:bg-gray-900 text-sm">
                                    @foreach($teamRoles as $role)
                                        <option value="{{ $role }}">{{ __('health.op_role_' . $role) }}</option>
                                    @endforeach
                                </select>
                            </label>
                            <label class="block">
                                <span class="text-xs font-bold text-gray-500 dark:text-gray-400">{{ __('health.doctor') }}</span>
                                <select :name="'team[' + i + '][health_doctor_id]'" x-model="member.health_doctor_id" class="mt-1 w-full rounded-xl border-gray-300 dark:border-gray-600 dark:bg-gray-900 text-sm">
                                    <option value="">—</option>
                                    @foreach($doctors as $doctor)
                                        <option value="{{ $doctor->id }}">{{ $doctor->name }}</option>
                                    @endforeach
                                </select>
                            </label>
                            <label class="block">
                                <span class="text-xs font-bold text-gray-500 dark:text-gray-400">{{ __('health.op_fee') }}</span>
                                <input type="number" step="0.01" min="0" :name="'team[' + i + '][fee_amount]'" x-model="member.fee_amount"
                                       class="mt-1 w-full rounded-xl border-gray-300 dark:border-gray-600 dark:bg-gray-900 text-sm">
                            </label>
                            <div class="flex gap-2 items-end">
                                <label class="block flex-1">
                                    <span class="text-xs font-bold text-gray-500 dark:text-gray-400">{{ __('health.note') }}</span>
                                    <input type="text" :name="'team[' + i + '][note]'" x-model="member.note" maxlength="300"
                                           class="mt-1 w-full rounded-xl border-gray-300 dark:border-gray-600 dark:bg-gray-900 text-sm">
                                </label>
                                <button type="button" @click="team.splice(i, 1)" class="px-3 py-2.5 rounded-xl bg-rose-100 text-rose-800 dark:bg-rose-900/40 dark:text-rose-200 text-xs font-bold">{{ __('health.remove') }}</button>
                            </div>
                        </div>
                    </template>
                    <div class="flex gap-2">
                        <button type="button" @click="addMember()" class="px-4 py-2.5 rounded-xl bg-gray-100 dark:bg-gray-700 text-sm font-bold">{{ __('health.op_team_add') }}</button>
                        <button type="submit" class="px-4 py-2.5 rounded-xl bg-teal-700 text-white text-sm font-bold">{{ __('health.save') }}</button>
                    </div>
                </form>
            </div>

            {{-- ── consumables ── --}}
            <div x-show="panel === 'consumables'" x-cloak class="rounded-2xl bg-white dark:bg-gray-800 border border-gray-200 dark:border-gray-700 p-5">
                <form method="POST" action="{{ route('health.operations.consumables', $operation->id) }}" class="space-y-3">
                    @csrf
                    <h2 class="text-base font-black">{{ __('health.op_consumables') }}</h2>
                    @if($operation->is_package)
                        <p class="text-xs text-amber-700 dark:text-amber-300">{{ __('health.op_package_consumable_hint') }}</p>
                    @endif
                    <template x-for="(row, i) in consumables" :key="i">
                        <div class="grid sm:grid-cols-2 lg:grid-cols-6 gap-2 items-end">
                            <label class="block lg:col-span-2">
                                <span class="text-xs font-bold text-gray-500 dark:text-gray-400">{{ __('health.item') }}</span>
                                <input type="text" :name="'consumables[' + i + '][item_name]'" x-model="row.item_name" maxlength="200"
                                       class="mt-1 w-full rounded-xl border-gray-300 dark:border-gray-600 dark:bg-gray-900 text-sm">
                            </label>
                            <label class="block">
                                <span class="text-xs font-bold text-gray-500 dark:text-gray-400">{{ __('health.unit') }}</span>
                                <input type="text" :name="'consumables[' + i + '][unit]'" x-model="row.unit" maxlength="20"
                                       class="mt-1 w-full rounded-xl border-gray-300 dark:border-gray-600 dark:bg-gray-900 text-sm">
                            </label>
                            <label class="block">
                                <span class="text-xs font-bold text-gray-500 dark:text-gray-400">{{ __('health.quantity') }}</span>
                                <input type="number" step="0.01" min="0" :name="'consumables[' + i + '][quantity]'" x-model="row.quantity"
                                       class="mt-1 w-full rounded-xl border-gray-300 dark:border-gray-600 dark:bg-gray-900 text-sm">
                            </label>
                            <label class="block">
                                <span class="text-xs font-bold text-gray-500 dark:text-gray-400">{{ __('health.unit_price') }}</span>
                                <input type="number" step="0.01" min="0" :name="'consumables[' + i + '][unit_price]'" x-model="row.unit_price"
                                       class="mt-1 w-full rounded-xl border-gray-300 dark:border-gray-600 dark:bg-gray-900 text-sm">
                            </label>
                            <div class="flex gap-2 items-center">
                                <label class="flex items-center gap-1 text-xs font-bold">
                                    <input type="hidden" :name="'consumables[' + i + '][is_billable]'" :value="row.is_billable ? 1 : 0">
                                    <input type="checkbox" x-model="row.is_billable" class="rounded">
                                    <span>{{ __('health.billable') }}</span>
                                </label>
                                <button type="button" @click="consumables.splice(i, 1)" class="px-3 py-2 rounded-xl bg-rose-100 text-rose-800 dark:bg-rose-900/40 dark:text-rose-200 text-xs font-bold">{{ __('health.remove') }}</button>
                            </div>
                        </div>
                    </template>
                    <p class="text-sm font-bold">{{ __('health.total') }}: <span x-text="consumableTotal()"></span></p>
                    <div class="flex gap-2">
                        <button type="button" @click="addConsumable()" class="px-4 py-2.5 rounded-xl bg-gray-100 dark:bg-gray-700 text-sm font-bold">{{ __('health.op_consumable_add') }}</button>
                        <button type="submit" class="px-4 py-2.5 rounded-xl bg-teal-700 text-white text-sm font-bold">{{ __('health.save') }}</button>
                    </div>
                </form>
            </div>

            {{-- ── complete ── --}}
            @if($operation->status === HealthOperation::STATUS_IN_PROGRESS)
                <div x-show="panel === 'complete'" x-cloak class="rounded-2xl bg-white dark:bg-gray-800 border border-gray-200 dark:border-gray-700 p-5">
                    <form method="POST" action="{{ route('health.operations.complete', $operation->id) }}" class="space-y-3">
                        @csrf
                        <h2 class="text-base font-black">{{ __('health.op_complete') }}</h2>
                        <div class="grid sm:grid-cols-2 lg:grid-cols-4 gap-3">
                            <label class="block">
                                <span class="text-xs font-bold text-gray-500 dark:text-gray-400">{{ __('health.op_actual_start') }}</span>
                                <input type="datetime-local" name="actual_start" value="{{ $operation->actual_start?->format('Y-m-d\TH:i') }}"
                                       class="mt-1 w-full rounded-xl border-gray-300 dark:border-gray-600 dark:bg-gray-900 text-sm">
                            </label>
                            <label class="block">
                                <span class="text-xs font-bold text-gray-500 dark:text-gray-400">{{ __('health.op_actual_end') }}</span>
                                <input type="datetime-local" name="actual_end" class="mt-1 w-full rounded-xl border-gray-300 dark:border-gray-600 dark:bg-gray-900 text-sm">
                            </label>
                            <label class="block">
                                <span class="text-xs font-bold text-gray-500 dark:text-gray-400">{{ __('health.op_outcome') }}</span>
                                <select name="outcome" required class="mt-1 w-full rounded-xl border-gray-300 dark:border-gray-600 dark:bg-gray-900 text-sm">
                                    @foreach($outcomes as $outcome)
                                        <option value="{{ $outcome }}">{{ __('health.op_outcome_' . $outcome) }}</option>
                                    @endforeach
                                </select>
                            </label>
                            <label class="block">
                                <span class="text-xs font-bold text-gray-500 dark:text-gray-400">{{ __('health.op_blood_loss') }}</span>
                                <input type="number" min="0" name="blood_loss_ml" class="mt-1 w-full rounded-xl border-gray-300 dark:border-gray-600 dark:bg-gray-900 text-sm">
                            </label>
                            <label class="block">
                                <span class="text-xs font-bold text-gray-500 dark:text-gray-400">{{ __('health.concession') }}</span>
                                <input type="number" step="0.01" min="0" name="concession_amount" value="{{ (float) $operation->concession_amount }}"
                                       class="mt-1 w-full rounded-xl border-gray-300 dark:border-gray-600 dark:bg-gray-900 text-sm">
                            </label>
                            <label class="block lg:col-span-2">
                                <span class="text-xs font-bold text-gray-500 dark:text-gray-400">{{ __('health.concession_reason') }}</span>
                                <input type="text" name="concession_reason" maxlength="300" class="mt-1 w-full rounded-xl border-gray-300 dark:border-gray-600 dark:bg-gray-900 text-sm">
                            </label>
                            <label class="flex items-center gap-2 text-sm font-bold">
                                <input type="hidden" name="specimen_sent" value="0">
                                <input type="checkbox" name="specimen_sent" value="1" class="rounded">
                                <span>{{ __('health.op_specimen') }}</span>
                            </label>
                        </div>
                        <label class="block">
                            <span class="text-xs font-bold text-gray-500 dark:text-gray-400">{{ __('health.op_findings') }}</span>
                            <textarea name="findings" rows="2" maxlength="4000" class="mt-1 w-full rounded-xl border-gray-300 dark:border-gray-600 dark:bg-gray-900 text-sm"></textarea>
                        </label>
                        <label class="block">
                            <span class="text-xs font-bold text-gray-500 dark:text-gray-400">{{ __('health.op_notes') }}</span>
                            <textarea name="operative_notes" rows="4" maxlength="8000" class="mt-1 w-full rounded-xl border-gray-300 dark:border-gray-600 dark:bg-gray-900 text-sm"></textarea>
                        </label>
                        <label class="block">
                            <span class="text-xs font-bold text-gray-500 dark:text-gray-400">{{ __('health.op_complications') }}</span>
                            <textarea name="complications" rows="2" maxlength="2000" class="mt-1 w-full rounded-xl border-gray-300 dark:border-gray-600 dark:bg-gray-900 text-sm"></textarea>
                        </label>
                        <label class="block">
                            <span class="text-xs font-bold text-gray-500 dark:text-gray-400">{{ __('health.op_post_instructions') }}</span>
                            <textarea name="post_op_instructions" rows="2" maxlength="4000" class="mt-1 w-full rounded-xl border-gray-300 dark:border-gray-600 dark:bg-gray-900 text-sm"></textarea>
                        </label>
                        <p class="text-xs text-gray-500 dark:text-gray-400">{{ __('health.op_complete_charge_hint') }}</p>
                        <button type="submit" class="px-4 py-2.5 rounded-xl bg-emerald-700 text-white text-sm font-bold">{{ __('health.op_complete') }}</button>
                    </form>
                </div>
            @endif

            {{-- ── cancel ── --}}
            <div x-show="panel === 'cancel'" x-cloak class="rounded-2xl bg-white dark:bg-gray-800 border border-gray-200 dark:border-gray-700 p-5">
                <form method="POST" action="{{ route('health.operations.cancel', $operation->id) }}" class="flex flex-wrap items-end gap-3">
                    @csrf
                    <label class="block flex-1 min-w-[240px]">
                        <span class="text-xs font-bold text-gray-500 dark:text-gray-400">{{ __('health.reason') }}</span>
                        <input type="text" name="cancel_reason" required maxlength="300" class="mt-1 w-full rounded-xl border-gray-300 dark:border-gray-600 dark:bg-gray-900 text-sm">
                    </label>
                    <label class="flex items-center gap-2 text-sm font-bold">
                        <input type="checkbox" name="postpone" value="1" class="rounded">
                        <span>{{ __('health.op_postpone_instead') }}</span>
                    </label>
                    <button type="submit" class="px-4 py-2.5 rounded-xl bg-rose-700 text-white text-sm font-bold">{{ __('health.save') }}</button>
                </form>
            </div>
        @endif

        {{-- ── record ── --}}
        <div class="grid lg:grid-cols-3 gap-5">
            <div class="lg:col-span-2 space-y-5">
                <div class="rounded-2xl bg-white dark:bg-gray-800 border border-gray-200 dark:border-gray-700 p-5">
                    <h2 class="text-base font-black">{{ __('health.op_preop') }}</h2>
                    @if($checklist)
                        <ul class="mt-3 space-y-1.5 text-sm">
                            @foreach($checklist as $item)
                                <li class="flex items-center gap-2">
                                    <span class="{{ $item['done'] ? 'text-emerald-600' : 'text-gray-400' }}">{{ $item['done'] ? '✔' : '○' }}</span>
                                    <span class="{{ $item['done'] ? '' : 'text-gray-500' }}">{{ $item['item'] }}</span>
                                </li>
                            @endforeach
                        </ul>
                    @else
                        <p class="text-sm text-gray-500 mt-2">{{ __('health.op_no_checklist') }}</p>
                    @endif
                    @if($operation->pre_op_notes)
                        <p class="text-sm text-gray-600 dark:text-gray-300 mt-3">{{ $operation->pre_op_notes }}</p>
                    @endif
                    @if($operation->consent_reference)
                        <p class="text-xs text-gray-500 mt-2">{{ __('health.op_consent') }}: {{ $operation->consent_reference }}</p>
                    @endif
                </div>

                @if($operation->status === HealthOperation::STATUS_COMPLETED)
                    <div class="rounded-2xl bg-white dark:bg-gray-800 border border-gray-200 dark:border-gray-700 p-5 space-y-3">
                        <h2 class="text-base font-black">{{ __('health.op_record') }}</h2>
                        <p class="text-sm">
                            <span class="font-bold">{{ __('health.op_outcome') }}:</span>
                            {{ $operation->outcome ? __('health.op_outcome_' . $operation->outcome) : '—' }}
                            @if($operation->actual_start)
                                · {{ $operation->actual_start->format('d M Y H:i') }}
                                @if($operation->actual_end) – {{ $operation->actual_end->format('H:i') }} @endif
                            @endif
                            @if($operation->blood_loss_ml) · {{ __('health.op_blood_loss') }}: {{ $operation->blood_loss_ml }} @endif
                            @if($operation->specimen_sent) · {{ __('health.op_specimen') }} @endif
                        </p>
                        @foreach([['health.op_findings', $operation->findings], ['health.op_notes', $operation->operative_notes], ['health.op_complications', $operation->complications], ['health.op_post_instructions', $operation->post_op_instructions]] as [$label, $value])
                            @if($value)
                                <div>
                                    <p class="text-xs font-bold text-gray-500 dark:text-gray-400">{{ __($label) }}</p>
                                    <p class="text-sm whitespace-pre-line">{{ $value }}</p>
                                </div>
                            @endif
                        @endforeach
                    </div>
                @endif

                <div class="rounded-2xl bg-white dark:bg-gray-800 border border-gray-200 dark:border-gray-700 p-5">
                    <h2 class="text-base font-black">{{ __('health.op_consumables') }}</h2>
                    <ul class="mt-3 divide-y divide-gray-100 dark:divide-gray-700 text-sm">
                        @forelse($operation->consumables as $consumable)
                            <li class="py-2 flex justify-between gap-3">
                                <span>
                                    {{ $consumable->item_name }}
                                    <span class="text-[11px] text-gray-500">
                                        {{ rtrim(rtrim(number_format((float) $consumable->quantity, 2), '0'), '.') }} {{ $consumable->unit }}
                                        @unless($consumable->is_billable) · {{ __('health.not_billable') }} @endunless
                                    </span>
                                </span>
                                <span class="font-bold">{{ number_format((float) $consumable->amount, 2) }}</span>
                            </li>
                        @empty
                            <li class="py-2 text-gray-500">{{ __('health.no_consumables') }}</li>
                        @endforelse
                    </ul>
                </div>
            </div>

            <div class="space-y-5">
                <div class="rounded-2xl bg-white dark:bg-gray-800 border border-gray-200 dark:border-gray-700 p-5">
                    <h2 class="text-base font-black">{{ __('health.op_charge') }}</h2>
                    <dl class="mt-3 space-y-1.5 text-sm">
                        <div class="flex justify-between gap-3">
                            <dt class="text-gray-500 dark:text-gray-400">{{ __('health.op_price') }}</dt>
                            <dd class="font-bold">{{ number_format((float) $operation->price, 2) }}</dd>
                        </div>
                        <div class="flex justify-between gap-3">
                            <dt class="text-gray-500 dark:text-gray-400">{{ __('health.concession') }}</dt>
                            <dd class="font-bold">{{ number_format((float) $operation->concession_amount, 2) }}</dd>
                        </div>
                        <div class="flex justify-between gap-3">
                            <dt class="text-gray-500 dark:text-gray-400">{{ __('health.op_consumables') }}</dt>
                            <dd class="font-bold">{{ number_format($operation->billableConsumableTotal(), 2) }}</dd>
                        </div>
                        <div class="flex justify-between gap-3 pt-2 border-t border-gray-100 dark:border-gray-700">
                            <dt class="font-black">{{ __('health.total') }}</dt>
                            <dd class="font-black">{{ number_format($operation->netCharge() + $operation->billableConsumableTotal(), 2) }}</dd>
                        </div>
                    </dl>
                    <p class="text-xs mt-3 {{ $operation->charge_posted_at ? 'text-emerald-700 dark:text-emerald-300' : 'text-gray-500' }}">
                        {{ $operation->charge_posted_at ? __('health.op_charge_posted') : __('health.op_charge_pending') }}
                    </p>
                </div>

                <div class="rounded-2xl bg-white dark:bg-gray-800 border border-gray-200 dark:border-gray-700 p-5">
                    <h2 class="text-base font-black">{{ __('health.op_team') }}</h2>
                    <ul class="mt-3 space-y-2 text-sm">
                        @forelse($operation->team as $member)
                            <li class="flex justify-between gap-3">
                                <span>
                                    <span class="font-bold">{{ $member->name }}</span>
                                    <span class="block text-[11px] text-gray-500">{{ __('health.op_role_' . $member->role) }}</span>
                                </span>
                                @if((float) $member->fee_amount > 0)
                                    <span class="font-bold">{{ number_format((float) $member->fee_amount, 2) }}</span>
                                @endif
                            </li>
                        @empty
                            <li class="text-gray-500">{{ __('health.no_team') }}</li>
                        @endforelse
                    </ul>
                </div>
            </div>
        </div>
    </div>
</x-health-layout>

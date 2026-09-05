@php
    use App\Models\HealthBed;
    use App\Models\HealthRoom;
    use App\Models\HealthWard;
    use Illuminate\Support\Js;

    // Payloads are built here, never inside an x-data attribute: a multi-line
    // array literal in a Blade directive breaks Blade's bracket matching and
    // 500s the page, and Js::from does the JS-context escaping properly.
    $scrub = function ($value) {
        $clean = @iconv('UTF-8', 'UTF-8//IGNORE', (string) $value);
        return $clean === false ? '' : $clean;
    };

    $wardPayload = $wards->map(fn ($w) => [
        'id' => (int) $w->id,
        'name' => $scrub($w->name),
        'code' => $scrub($w->code),
        'type' => $w->type,
        'gender_policy' => $w->gender_policy,
        'floor' => $scrub($w->floor),
        'branch_id' => $w->branch_id ? (int) $w->branch_id : '',
        'health_department_id' => $w->health_department_id ? (int) $w->health_department_id : '',
        'daily_rate' => (string) $w->daily_rate,
        'nursing_daily_rate' => (string) $w->nursing_daily_rate,
        'notes' => $scrub($w->notes),
    ])->values()->all();

    $blankWard = [
        'id' => null, 'name' => '', 'code' => '', 'type' => 'general', 'gender_policy' => 'any',
        'floor' => '', 'branch_id' => '', 'health_department_id' => '',
        'daily_rate' => '0', 'nursing_daily_rate' => '0', 'notes' => '',
    ];

    $roomPayload = $rooms->map(fn ($r) => [
        'id' => (int) $r->id,
        'health_ward_id' => (int) $r->health_ward_id,
        'name' => $scrub($r->name),
        'room_type' => $r->room_type,
        'daily_rate' => $r->daily_rate === null ? '' : (string) $r->daily_rate,
        'nursing_daily_rate' => $r->nursing_daily_rate === null ? '' : (string) $r->nursing_daily_rate,
        'notes' => $scrub($r->notes),
    ])->values()->all();

    $blankRoom = [
        'id' => null, 'health_ward_id' => '', 'name' => '', 'room_type' => 'general',
        'daily_rate' => '', 'nursing_daily_rate' => '', 'notes' => '',
    ];

    $bedPayload = $beds->map(fn ($b) => [
        'id' => (int) $b->id,
        'health_ward_id' => (int) $b->health_ward_id,
        'health_room_id' => $b->health_room_id ? (int) $b->health_room_id : '',
        'code' => $scrub($b->code),
        'label' => $scrub($b->label),
        'daily_rate' => $b->daily_rate === null ? '' : (string) $b->daily_rate,
        'nursing_daily_rate' => $b->nursing_daily_rate === null ? '' : (string) $b->nursing_daily_rate,
    ])->values()->all();

    $blankBed = [
        'id' => null, 'health_ward_id' => '', 'health_room_id' => '', 'code' => '',
        'label' => '', 'daily_rate' => '', 'nursing_daily_rate' => '',
    ];

    $roomsByWard = $rooms->groupBy('health_ward_id');
    $bedsByWard = $beds->groupBy('health_ward_id');

    $statusChip = [
        'available' => 'bg-emerald-100 text-emerald-800 dark:bg-emerald-900/40 dark:text-emerald-200',
        'occupied'  => 'bg-rose-100 text-rose-800 dark:bg-rose-900/40 dark:text-rose-200',
        'reserved'  => 'bg-amber-100 text-amber-800 dark:bg-amber-900/40 dark:text-amber-200',
        'cleaning'  => 'bg-sky-100 text-sky-800 dark:bg-sky-900/40 dark:text-sky-200',
        'blocked'   => 'bg-gray-200 text-gray-700 dark:bg-gray-700 dark:text-gray-200',
    ];
@endphp
<x-health-layout>
    <div class="max-w-6xl mx-auto px-4 sm:px-6 py-5 space-y-5"
         x-data="{
            wards: {{ Js::from($wardPayload) }},
            rooms: {{ Js::from($roomPayload) }},
            beds: {{ Js::from($bedPayload) }},
            blankWard: {{ Js::from($blankWard) }},
            blankRoom: {{ Js::from($blankRoom) }},
            blankBed: {{ Js::from($blankBed) }},
            form: null,
            values: {},
            openWard(id) {
                const found = id ? this.wards.find(w => w.id === id) : null;
                this.values = Object.assign({}, found || this.blankWard);
                this.form = 'ward';
            },
            openRoom(id, wardId) {
                const found = id ? this.rooms.find(r => r.id === id) : null;
                this.values = Object.assign({}, found || this.blankRoom);
                if (!found && wardId) { this.values.health_ward_id = wardId; }
                this.form = 'room';
            },
            openBed(id, wardId) {
                const found = id ? this.beds.find(b => b.id === id) : null;
                this.values = Object.assign({}, found || this.blankBed);
                if (!found && wardId) { this.values.health_ward_id = wardId; }
                this.form = 'bed';
            },
            roomsFor(wardId) {
                return this.rooms.filter(r => String(r.health_ward_id) === String(wardId));
            }
         }">

        <div class="flex flex-wrap items-end justify-between gap-3">
            <div>
                <h1 class="text-xl sm:text-2xl font-black tracking-tight">{{ __('health.facility_title') }}</h1>
                <p class="text-sm text-gray-500 dark:text-gray-400 mt-0.5">{{ __('health.facility_subtitle') }}</p>
            </div>
            <div class="flex flex-wrap gap-2">
                <a href="{{ route('health.ipd') }}"
                   class="px-4 py-2.5 rounded-xl bg-gray-100 dark:bg-gray-700 text-sm font-bold">{{ __('health.nav_ipd') }}</a>
                @if($mayManage)
                    <button type="button" @click="openWard(null)"
                            class="px-4 py-2.5 rounded-xl bg-teal-700 hover:bg-teal-800 text-white text-sm font-bold transition">
                        {{ __('health.ward_add') }}
                    </button>
                @endif
            </div>
        </div>

        @if($mayManage)
            {{-- ── ward form ── --}}
            <div x-show="form === 'ward'" x-cloak class="rounded-2xl bg-white dark:bg-gray-800 border border-gray-200 dark:border-gray-700 p-5">
                <form method="POST" :action="values.id ? '{{ url('/health/ipd/wards') }}/' + values.id : '{{ url('/health/ipd/wards') }}'" class="space-y-4">
                    @csrf
                    <template x-if="values.id"><input type="hidden" name="_method" value="PUT"></template>
                    <h2 class="text-base font-black" x-text="values.id ? '{{ __('health.ward_edit') }}' : '{{ __('health.ward_add') }}'"></h2>

                    <div class="grid sm:grid-cols-2 lg:grid-cols-3 gap-3">
                        <label class="block">
                            <span class="text-xs font-bold text-gray-500 dark:text-gray-400">{{ __('health.ward_name') }}</span>
                            <input type="text" name="name" x-model="values.name" required maxlength="255"
                                   class="mt-1 w-full rounded-xl border-gray-300 dark:border-gray-600 dark:bg-gray-900 text-sm">
                        </label>
                        <label class="block">
                            <span class="text-xs font-bold text-gray-500 dark:text-gray-400">{{ __('health.ward_code') }}</span>
                            <input type="text" name="code" x-model="values.code" maxlength="32"
                                   class="mt-1 w-full rounded-xl border-gray-300 dark:border-gray-600 dark:bg-gray-900 text-sm">
                        </label>
                        <label class="block">
                            <span class="text-xs font-bold text-gray-500 dark:text-gray-400">{{ __('health.ward_type') }}</span>
                            <select name="type" x-model="values.type" class="mt-1 w-full rounded-xl border-gray-300 dark:border-gray-600 dark:bg-gray-900 text-sm">
                                @foreach(HealthWard::TYPES as $type)
                                    <option value="{{ $type }}">{{ __('health.ward_type_' . $type) }}</option>
                                @endforeach
                            </select>
                        </label>
                        <label class="block">
                            <span class="text-xs font-bold text-gray-500 dark:text-gray-400">{{ __('health.ward_gender_policy') }}</span>
                            <select name="gender_policy" x-model="values.gender_policy" class="mt-1 w-full rounded-xl border-gray-300 dark:border-gray-600 dark:bg-gray-900 text-sm">
                                @foreach(HealthWard::GENDER_POLICIES as $policy)
                                    <option value="{{ $policy }}">{{ __('health.ward_gender_' . $policy) }}</option>
                                @endforeach
                            </select>
                        </label>
                        <label class="block">
                            <span class="text-xs font-bold text-gray-500 dark:text-gray-400">{{ __('health.ward_floor') }}</span>
                            <input type="text" name="floor" x-model="values.floor" maxlength="40"
                                   class="mt-1 w-full rounded-xl border-gray-300 dark:border-gray-600 dark:bg-gray-900 text-sm">
                        </label>
                        <label class="block">
                            <span class="text-xs font-bold text-gray-500 dark:text-gray-400">{{ __('health.branch') }}</span>
                            <select name="branch_id" x-model="values.branch_id" class="mt-1 w-full rounded-xl border-gray-300 dark:border-gray-600 dark:bg-gray-900 text-sm">
                                <option value="">{{ __('health.all_branches') }}</option>
                                @foreach($branches as $branch)
                                    <option value="{{ $branch->id }}">{{ $branch->name }}</option>
                                @endforeach
                            </select>
                        </label>
                        <label class="block">
                            <span class="text-xs font-bold text-gray-500 dark:text-gray-400">{{ __('health.department') }}</span>
                            <select name="health_department_id" x-model="values.health_department_id" class="mt-1 w-full rounded-xl border-gray-300 dark:border-gray-600 dark:bg-gray-900 text-sm">
                                <option value="">—</option>
                                @foreach($departments as $department)
                                    <option value="{{ $department->id }}">{{ $department->name }}</option>
                                @endforeach
                            </select>
                        </label>
                        <label class="block">
                            <span class="text-xs font-bold text-gray-500 dark:text-gray-400">{{ __('health.ward_daily_rate') }}</span>
                            <input type="number" step="0.01" min="0" name="daily_rate" x-model="values.daily_rate" required
                                   class="mt-1 w-full rounded-xl border-gray-300 dark:border-gray-600 dark:bg-gray-900 text-sm">
                        </label>
                        <label class="block">
                            <span class="text-xs font-bold text-gray-500 dark:text-gray-400">{{ __('health.ward_nursing_rate') }}</span>
                            <input type="number" step="0.01" min="0" name="nursing_daily_rate" x-model="values.nursing_daily_rate" required
                                   class="mt-1 w-full rounded-xl border-gray-300 dark:border-gray-600 dark:bg-gray-900 text-sm">
                        </label>
                    </div>

                    <label class="block">
                        <span class="text-xs font-bold text-gray-500 dark:text-gray-400">{{ __('health.notes') }}</span>
                        <textarea name="notes" x-model="values.notes" rows="2" maxlength="500"
                                  class="mt-1 w-full rounded-xl border-gray-300 dark:border-gray-600 dark:bg-gray-900 text-sm"></textarea>
                    </label>

                    <div class="flex gap-2">
                        <button type="submit" class="px-4 py-2.5 rounded-xl bg-teal-700 hover:bg-teal-800 text-white text-sm font-bold">{{ __('health.save') }}</button>
                        <button type="button" @click="form = null" class="px-4 py-2.5 rounded-xl bg-gray-100 dark:bg-gray-700 text-sm font-bold">{{ __('health.cancel') }}</button>
                    </div>
                </form>
            </div>

            {{-- ── room form ── --}}
            <div x-show="form === 'room'" x-cloak class="rounded-2xl bg-white dark:bg-gray-800 border border-gray-200 dark:border-gray-700 p-5">
                <form method="POST" :action="values.id ? '{{ url('/health/ipd/rooms') }}/' + values.id : '{{ url('/health/ipd/rooms') }}'" class="space-y-4">
                    @csrf
                    <template x-if="values.id"><input type="hidden" name="_method" value="PUT"></template>
                    <h2 class="text-base font-black" x-text="values.id ? '{{ __('health.room_edit') }}' : '{{ __('health.room_add') }}'"></h2>

                    <div class="grid sm:grid-cols-2 lg:grid-cols-4 gap-3">
                        <label class="block">
                            <span class="text-xs font-bold text-gray-500 dark:text-gray-400">{{ __('health.ward') }}</span>
                            <select name="health_ward_id" x-model="values.health_ward_id" required class="mt-1 w-full rounded-xl border-gray-300 dark:border-gray-600 dark:bg-gray-900 text-sm">
                                <template x-for="w in wards" :key="w.id">
                                    <option :value="w.id" x-text="w.name"></option>
                                </template>
                            </select>
                        </label>
                        <label class="block">
                            <span class="text-xs font-bold text-gray-500 dark:text-gray-400">{{ __('health.room_name') }}</span>
                            <input type="text" name="name" x-model="values.name" required maxlength="60"
                                   class="mt-1 w-full rounded-xl border-gray-300 dark:border-gray-600 dark:bg-gray-900 text-sm">
                        </label>
                        <label class="block">
                            <span class="text-xs font-bold text-gray-500 dark:text-gray-400">{{ __('health.room_type') }}</span>
                            <select name="room_type" x-model="values.room_type" class="mt-1 w-full rounded-xl border-gray-300 dark:border-gray-600 dark:bg-gray-900 text-sm">
                                @foreach(HealthRoom::TYPES as $type)
                                    <option value="{{ $type }}">{{ __('health.room_type_' . $type) }}</option>
                                @endforeach
                            </select>
                        </label>
                        <label class="block">
                            <span class="text-xs font-bold text-gray-500 dark:text-gray-400">{{ __('health.rate_override') }}</span>
                            <input type="number" step="0.01" min="0" name="daily_rate" x-model="values.daily_rate"
                                   placeholder="{{ __('health.rate_inherit') }}"
                                   class="mt-1 w-full rounded-xl border-gray-300 dark:border-gray-600 dark:bg-gray-900 text-sm">
                        </label>
                        <label class="block">
                            <span class="text-xs font-bold text-gray-500 dark:text-gray-400">{{ __('health.nursing_rate_override') }}</span>
                            <input type="number" step="0.01" min="0" name="nursing_daily_rate" x-model="values.nursing_daily_rate"
                                   placeholder="{{ __('health.rate_inherit') }}"
                                   class="mt-1 w-full rounded-xl border-gray-300 dark:border-gray-600 dark:bg-gray-900 text-sm">
                        </label>
                    </div>
                    <p class="text-xs text-gray-500 dark:text-gray-400">{{ __('health.rate_inherit_hint') }}</p>

                    <div class="flex gap-2">
                        <button type="submit" class="px-4 py-2.5 rounded-xl bg-teal-700 hover:bg-teal-800 text-white text-sm font-bold">{{ __('health.save') }}</button>
                        <button type="button" @click="form = null" class="px-4 py-2.5 rounded-xl bg-gray-100 dark:bg-gray-700 text-sm font-bold">{{ __('health.cancel') }}</button>
                    </div>
                </form>
            </div>

            {{-- ── bed form ── --}}
            <div x-show="form === 'bed'" x-cloak class="rounded-2xl bg-white dark:bg-gray-800 border border-gray-200 dark:border-gray-700 p-5">
                <form method="POST" :action="values.id ? '{{ url('/health/ipd/beds') }}/' + values.id : '{{ url('/health/ipd/beds') }}'" class="space-y-4">
                    @csrf
                    <template x-if="values.id"><input type="hidden" name="_method" value="PUT"></template>
                    <h2 class="text-base font-black" x-text="values.id ? '{{ __('health.bed_edit') }}' : '{{ __('health.bed_add') }}'"></h2>

                    <div class="grid sm:grid-cols-2 lg:grid-cols-4 gap-3">
                        <label class="block">
                            <span class="text-xs font-bold text-gray-500 dark:text-gray-400">{{ __('health.ward') }}</span>
                            <select name="health_ward_id" x-model="values.health_ward_id" required class="mt-1 w-full rounded-xl border-gray-300 dark:border-gray-600 dark:bg-gray-900 text-sm">
                                <template x-for="w in wards" :key="w.id">
                                    <option :value="w.id" x-text="w.name"></option>
                                </template>
                            </select>
                        </label>
                        <label class="block">
                            <span class="text-xs font-bold text-gray-500 dark:text-gray-400">{{ __('health.room') }}</span>
                            <select name="health_room_id" x-model="values.health_room_id" class="mt-1 w-full rounded-xl border-gray-300 dark:border-gray-600 dark:bg-gray-900 text-sm">
                                <option value="">—</option>
                                <template x-for="r in roomsFor(values.health_ward_id)" :key="r.id">
                                    <option :value="r.id" x-text="r.name"></option>
                                </template>
                            </select>
                        </label>
                        <label class="block">
                            <span class="text-xs font-bold text-gray-500 dark:text-gray-400">{{ __('health.bed_code') }}</span>
                            <input type="text" name="code" x-model="values.code" required maxlength="40"
                                   class="mt-1 w-full rounded-xl border-gray-300 dark:border-gray-600 dark:bg-gray-900 text-sm">
                        </label>
                        <label class="block">
                            <span class="text-xs font-bold text-gray-500 dark:text-gray-400">{{ __('health.bed_label') }}</span>
                            <input type="text" name="label" x-model="values.label" maxlength="60"
                                   class="mt-1 w-full rounded-xl border-gray-300 dark:border-gray-600 dark:bg-gray-900 text-sm">
                        </label>
                        <label class="block">
                            <span class="text-xs font-bold text-gray-500 dark:text-gray-400">{{ __('health.rate_override') }}</span>
                            <input type="number" step="0.01" min="0" name="daily_rate" x-model="values.daily_rate"
                                   placeholder="{{ __('health.rate_inherit') }}"
                                   class="mt-1 w-full rounded-xl border-gray-300 dark:border-gray-600 dark:bg-gray-900 text-sm">
                        </label>
                        <label class="block">
                            <span class="text-xs font-bold text-gray-500 dark:text-gray-400">{{ __('health.nursing_rate_override') }}</span>
                            <input type="number" step="0.01" min="0" name="nursing_daily_rate" x-model="values.nursing_daily_rate"
                                   placeholder="{{ __('health.rate_inherit') }}"
                                   class="mt-1 w-full rounded-xl border-gray-300 dark:border-gray-600 dark:bg-gray-900 text-sm">
                        </label>
                    </div>

                    <div class="flex gap-2">
                        <button type="submit" class="px-4 py-2.5 rounded-xl bg-teal-700 hover:bg-teal-800 text-white text-sm font-bold">{{ __('health.save') }}</button>
                        <button type="button" @click="form = null" class="px-4 py-2.5 rounded-xl bg-gray-100 dark:bg-gray-700 text-sm font-bold">{{ __('health.cancel') }}</button>
                    </div>
                </form>
            </div>
        @endif

        {{-- ── the facility itself ── --}}
        @forelse($wards as $ward)
            @php
                $wardBeds = $bedsByWard->get($ward->id, collect());
                $wardRooms = $roomsByWard->get($ward->id, collect());
            @endphp
            <div class="rounded-2xl bg-white dark:bg-gray-800 border border-gray-200 dark:border-gray-700 overflow-hidden">
                <div class="px-5 py-4 flex flex-wrap items-center justify-between gap-3 border-b border-gray-100 dark:border-gray-700">
                    <div>
                        <div class="flex items-center gap-2 flex-wrap">
                            <h2 class="text-base font-black">{{ $ward->name }}</h2>
                            @if($ward->code)
                                <span class="text-xs font-mono text-gray-500">{{ $ward->code }}</span>
                            @endif
                            <span class="px-2 py-0.5 rounded-lg text-[11px] font-bold bg-gray-100 dark:bg-gray-700">{{ __('health.ward_type_' . $ward->type) }}</span>
                            <span class="px-2 py-0.5 rounded-lg text-[11px] font-bold bg-gray-100 dark:bg-gray-700">{{ __('health.ward_gender_' . $ward->gender_policy) }}</span>
                            @unless($ward->is_active)
                                <span class="px-2 py-0.5 rounded-lg text-[11px] font-bold bg-gray-200 text-gray-700 dark:bg-gray-700">{{ __('health.inactive') }}</span>
                            @endunless
                        </div>
                        <p class="text-xs text-gray-500 dark:text-gray-400 mt-1">
                            {{ __('health.ward_rate_line', ['room' => number_format((float) $ward->daily_rate, 2), 'nursing' => number_format((float) $ward->nursing_daily_rate, 2)]) }}
                            · {{ __('health.bed_count', ['count' => $wardBeds->count()]) }}
                            @if($ward->branch) · {{ $ward->branch->name }} @endif
                        </p>
                    </div>
                    @if($mayManage)
                        <div class="flex flex-wrap gap-2">
                            <button type="button" @click="openWard({{ $ward->id }})" class="px-3 py-2 rounded-xl bg-gray-100 dark:bg-gray-700 text-xs font-bold">{{ __('health.edit') }}</button>
                            <button type="button" @click="openRoom(null, {{ $ward->id }})" class="px-3 py-2 rounded-xl bg-gray-100 dark:bg-gray-700 text-xs font-bold">{{ __('health.room_add') }}</button>
                            <button type="button" @click="openBed(null, {{ $ward->id }})" class="px-3 py-2 rounded-xl bg-teal-700 text-white text-xs font-bold">{{ __('health.bed_add') }}</button>
                            <form method="POST" action="{{ route('health.ipd.wards.toggle', $ward->id) }}">
                                @csrf
                                <button type="submit" class="px-3 py-2 rounded-xl bg-gray-100 dark:bg-gray-700 text-xs font-bold">
                                    {{ $ward->is_active ? __('health.deactivate') : __('health.reactivate') }}
                                </button>
                            </form>
                        </div>
                    @endif
                </div>

                @if($wardRooms->isNotEmpty())
                    <div class="px-5 py-3 flex flex-wrap gap-2 border-b border-gray-100 dark:border-gray-700">
                        @foreach($wardRooms as $room)
                            <span class="inline-flex items-center gap-2 px-3 py-1.5 rounded-xl bg-gray-50 dark:bg-gray-900 text-xs">
                                <span class="font-bold">{{ $room->name }}</span>
                                <span class="text-gray-500">{{ __('health.room_type_' . $room->room_type) }}</span>
                                @if($room->daily_rate !== null)
                                    <span class="text-gray-500">{{ number_format((float) $room->daily_rate, 2) }}</span>
                                @endif
                                @if($mayManage)
                                    <button type="button" @click="openRoom({{ $room->id }}, {{ $ward->id }})" class="text-teal-700 dark:text-teal-300 font-bold">{{ __('health.edit') }}</button>
                                @endif
                            </span>
                        @endforeach
                    </div>
                @endif

                <div class="p-5 grid grid-cols-2 sm:grid-cols-3 lg:grid-cols-5 gap-3">
                    @forelse($wardBeds as $bed)
                        <div class="rounded-xl border border-gray-200 dark:border-gray-700 p-3 {{ $bed->is_active ? '' : 'opacity-50' }}">
                            <div class="flex items-center justify-between gap-2">
                                <span class="font-black text-sm">{{ $bed->code }}</span>
                                <span class="px-2 py-0.5 rounded-lg text-[10px] font-bold {{ $statusChip[$bed->status] ?? '' }}">
                                    {{ __('health.bed_status_' . $bed->status) }}
                                </span>
                            </div>
                            @if($bed->label)
                                <p class="text-xs text-gray-500 mt-0.5">{{ $bed->label }}</p>
                            @endif
                            @if($bed->admission && $bed->admission->patient)
                                <p class="text-xs mt-1 font-bold text-rose-700 dark:text-rose-300">{{ $bed->admission->patient->name }}</p>
                            @endif
                            <p class="text-[11px] text-gray-500 mt-1">
                                {{ number_format($bed->resolvedDailyRate(), 2) }} / {{ __('health.per_day') }}
                            </p>
                            @if($mayManage)
                                <div class="flex gap-2 mt-2">
                                    <button type="button" @click="openBed({{ $bed->id }}, {{ $ward->id }})" class="text-xs font-bold text-teal-700 dark:text-teal-300">{{ __('health.edit') }}</button>
                                    <form method="POST" action="{{ route('health.ipd.beds.toggle', $bed->id) }}">
                                        @csrf
                                        <button type="submit" class="text-xs font-bold text-gray-500">{{ $bed->is_active ? __('health.deactivate') : __('health.reactivate') }}</button>
                                    </form>
                                </div>
                            @endif
                        </div>
                    @empty
                        <p class="text-sm text-gray-500 col-span-full">{{ __('health.no_beds_in_ward') }}</p>
                    @endforelse
                </div>
            </div>
        @empty
            <div class="rounded-2xl bg-white dark:bg-gray-800 border border-gray-200 dark:border-gray-700 p-10 text-center">
                <p class="text-sm text-gray-500 dark:text-gray-400">{{ __('health.no_wards') }}</p>
            </div>
        @endforelse
    </div>
</x-health-layout>

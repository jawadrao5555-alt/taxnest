<x-health-layout>
    @php
        $caps = $healthCapabilities ?? [];
        $estimatedPaisa = $order->items->sum('price_paisa');
    @endphp
    <section class="mx-auto max-w-4xl space-y-5 px-4 py-6 sm:px-6">
        <a href="{{ route('health.lab') }}" class="text-sm font-semibold text-teal-700 underline dark:text-teal-300">{{ __('health.nav_lab') }}</a>
        <div class="flex flex-wrap items-end justify-between gap-3">
            <div>
                <h1 class="text-2xl font-bold">{{ __('lab_workflow.order') }} <bdi dir="ltr">{{ $order->order_no }}</bdi></h1>
                <p>{{ $order->patient?->name }} · <bdi>{{ $order->patient?->mrn }}</bdi></p>
                @if($canOpenVisit && $order->health_visit_id)
                    <p><a href="{{ route('health.clinical.visit', $order->health_visit_id) }}"
                          class="font-semibold text-teal-700 underline dark:text-teal-300">{{ __('lab_workflow.open_visit') }}</a></p>
                @endif
                <p>{{ __('lab_workflow.estimated_total') }} <bdi dir="ltr">PKR {{ number_format($estimatedPaisa / 100, 2) }}</bdi></p>
            </div>
            <div class="flex flex-wrap items-center gap-3">
                @if($estimatedPaisa > 0 && in_array('billing.charge', $caps, true))
                    <form method="POST" action="{{ route('health.lab.orders.billing', $order) }}">
                        @csrf
                        <button class="rounded-lg border border-teal-700 px-4 py-2 font-semibold text-teal-700 dark:border-teal-300 dark:text-teal-300">{{ __('lab_workflow.send_fees') }}</button>
                    </form>
                @endif
                @if(in_array('billing.view', $caps, true))
                    <a href="{{ route('health.billing.patient', $order->health_patient_id) }}" class="font-semibold text-teal-700 underline dark:text-teal-300">{{ __('lab_workflow.open_billing') }}</a>
                @endif
                @if($order->items_count > 0 && $order->items_count === $order->verified_items_count && in_array('lab.report', $caps, true))
                    <a href="{{ route('health.lab.orders.report', $order) }}" class="rounded-lg bg-teal-700 px-4 py-2 font-semibold text-white">{{ __('lab_workflow.open_report') }}</a>
                @endif
            </div>
        </div>

        @foreach($order->items as $item)
            @php
                $specimen = $item->specimens->first();
                $latest = $item->relationLoaded('results') ? $item->results->first() : null;
                $editing = $latest && !$latest->verified_at;
                $canEnter = in_array('lab.result', $caps, true) && (!$editing || (int) $latest->entered_by === (int) $healthUser->id);
            @endphp
            <article class="space-y-4 rounded-xl border border-gray-300 p-4 dark:border-gray-700">
                <h2 class="text-lg font-semibold" title="{{ $item->test_code }}">{{ $item->test_name }}</h2>

                @if($specimen)
                    <div class="flex flex-wrap items-center gap-3">
                        <p>{{ __('lab_workflow.specimen') }} <bdi dir="ltr">{{ $specimen->barcode }}</bdi> · {{ __('lab_workflow.status_'.$specimen->status) }}</p>
                        @if(in_array('lab.collect', $caps, true))
                            <a href="{{ route('health.lab.orders.label', [$order, $item, $specimen]) }}" class="font-semibold text-teal-700 underline dark:text-teal-300">{{ __('lab_workflow.print_label') }}</a>
                        @endif
                    </div>
                    @if(in_array('lab.collect', $caps, true))
                        @if($specimen->status === 'pending')
                            <form method="POST" action="{{ route('health.lab.orders.collect', [$order, $item]) }}">
                                @csrf
                                <input type="hidden" name="specimen_id" value="{{ $specimen->id }}">
                                <button class="rounded-lg bg-teal-700 px-4 py-2 text-white">{{ __('lab_workflow.mark_collected') }}</button>
                            </form>
                        @elseif(!$item->results_exists && $specimen->status === 'collected')
                            <form method="POST" action="{{ route('health.lab.orders.reject', [$order, $item]) }}" class="flex flex-wrap items-end gap-2">
                                @csrf
                                <input type="hidden" name="specimen_id" value="{{ $specimen->id }}">
                                <label class="grow text-sm font-medium">
                                    {{ __('lab_workflow.rejection_reason') }}
                                    <input name="reason" maxlength="300" required class="mt-1 block w-full rounded-lg border-gray-300 bg-white dark:border-gray-600 dark:bg-gray-800">
                                </label>
                                <button class="rounded-lg border border-red-700 px-4 py-2 text-red-700 dark:border-red-300 dark:text-red-300">{{ __('lab_workflow.reject') }}</button>
                            </form>
                        @endif
                    @endif
                    @if($item->specimens->count() > 1)
                        <details class="text-sm">
                            <summary>{{ __('lab_workflow.specimen_history') }}</summary>
                            <ul class="mt-1 space-y-1">
                                @foreach($item->specimens as $attempt)
                                    <li><bdi dir="ltr">{{ $attempt->barcode }}</bdi> · {{ __('lab_workflow.status_'.$attempt->status) }}</li>
                                @endforeach
                            </ul>
                        </details>
                    @endif
                @endif

                @if($latest)
                    <p class="font-semibold">{{ __('lab_workflow.'.($editing ? 'draft_result' : 'verified_result'), ['number' => $latest->revision]) }}</p>
                @endif
                @if($editing && in_array('lab.verify', $caps, true))
                    <div class="space-y-2 rounded-lg bg-gray-100 p-3 dark:bg-gray-800">
                        <p class="whitespace-pre-wrap">{{ $latest->value }} {{ $latest->unit }}</p>
                        @if($latest->reference)
                            <p>{{ __('lab_workflow.reference') }} {{ $latest->reference }}</p>
                        @endif
                        @if($latest->amendment_reason)
                            <p>{{ __('lab_workflow.amendment_reason') }} {{ $latest->amendment_reason }}</p>
                        @endif
                        @if((int) $latest->entered_by !== (int) $healthUser->id)
                            <form method="POST" action="{{ route('health.lab.orders.verify', [$order, $item]) }}">
                                @csrf
                                <input type="hidden" name="result_id" value="{{ $latest->id }}">
                                <input type="hidden" name="result_version" value="{{ $latest->version }}">
                                <button class="rounded-lg bg-teal-700 px-4 py-2 font-semibold text-white">{{ __('lab_workflow.verify_result') }}</button>
                            </form>
                        @else
                            <p>{{ __('lab_workflow.independent_verifier') }}</p>
                        @endif
                    </div>
                @endif

                @if($canEnter && $specimen?->status === 'collected')
                    <details @unless($latest?->verified_at) open @endunless>
                        <summary class="font-semibold">{{ __('lab_workflow.'.($latest?->verified_at ? 'amend_result' : 'enter_result')) }}</summary>
                        <form method="POST" action="{{ route('health.lab.orders.result', [$order, $item]) }}" class="mt-3 space-y-3">
                            @csrf
                            <input type="hidden" name="specimen_id" value="{{ $specimen->id }}">
                            <input type="hidden" name="expected_revision" value="{{ $latest?->revision ?? 0 }}">
                            <input type="hidden" name="expected_version" value="{{ $latest?->version ?? 0 }}">
                            <label class="block text-sm font-medium">
                                {{ __('lab_workflow.result_value') }}
                                <textarea name="value" maxlength="2000" rows="3" required class="mt-1 block w-full rounded-lg border-gray-300 bg-white dark:border-gray-600 dark:bg-gray-800">{{ $latest?->value }}</textarea>
                            </label>
                            <div class="grid gap-2 sm:grid-cols-2">
                                <label class="text-sm font-medium">
                                    {{ __('lab_workflow.unit') }}
                                    <input name="unit" value="{{ $latest?->unit }}" maxlength="50" class="mt-1 block w-full rounded-lg border-gray-300 bg-white dark:border-gray-600 dark:bg-gray-800">
                                </label>
                                <label class="text-sm font-medium">
                                    {{ __('lab_workflow.reference') }}
                                    <input name="reference" value="{{ $latest?->reference }}" maxlength="140" class="mt-1 block w-full rounded-lg border-gray-300 bg-white dark:border-gray-600 dark:bg-gray-800">
                                </label>
                            </div>
                            @if($latest?->verified_at || ($latest?->revision ?? 0) > 1)
                                <label class="block text-sm font-medium">
                                    {{ __('lab_workflow.amendment_reason') }}
                                    <input name="amendment_reason" value="{{ $editing ? $latest?->amendment_reason : '' }}" maxlength="300" required class="mt-1 block w-full rounded-lg border-gray-300 bg-white dark:border-gray-600 dark:bg-gray-800">
                                </label>
                            @endif
                            <button class="rounded-lg bg-teal-700 px-4 py-2 font-semibold text-white">{{ __('lab_workflow.save_result') }}</button>
                        </form>
                    </details>
                @endif
            </article>
        @endforeach
    </section>
</x-health-layout>

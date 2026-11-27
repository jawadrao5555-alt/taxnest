<x-school-erp-layout :title="__('school_erp_fees.title')">
    <h1 class="school-title">{{ __('school_erp_fees.title') }}</h1>
    <p>{{ $company->name }}</p>

    @if($campuses->isNotEmpty())
        <form class="school-form school-card" method="GET" action="{{ route('school-erp.fees') }}">
            <label>{{ __('school_erp_fees.campus') }}
                <select name="campus_id">
                    <option value="">{{ __('school_erp_fees.all_campuses') }}</option>
                    @foreach($campuses as $campus)
                        <option value="{{ $campus->id }}" @selected($campusId === (int) $campus->id)>{{ $campus->name }}</option>
                    @endforeach
                </select>
            </label>
            <button class="school-button">{{ __('school_erp_fees.filter') }}</button>
        </form>
    @endif
    <dl class="school-grid school-card mt-4">
        <div><dt>{{ __('school_erp_fees.invoiced') }}</dt><dd>PKR {{ number_format($totals->invoiced / 100, 2) }}</dd></div>
        <div><dt>{{ __('school_erp_fees.received') }}</dt><dd>PKR {{ number_format($totals->received / 100, 2) }}</dd></div>
        <div><dt>{{ __('school_erp_fees.balance') }}</dt><dd>PKR {{ number_format(($totals->invoiced - $totals->received) / 100, 2) }}</dd></div>
    </dl>

    @if($canWrite && $terms->isNotEmpty())
        <div class="school-grid mt-4">
            <section class="school-card">
                <h2>{{ __('school_erp_fees.new_schedule') }}</h2>
                <form class="school-form" method="POST" action="{{ route('school-erp.fees.schedule') }}">
                    @csrf
                    <label>{{ __('school_erp_fees.term') }}
                        <select name="term_id" required>
                            @foreach($terms as $term)
                                <option value="{{ $term->id }}">{{ $term->name }}</option>
                            @endforeach
                        </select>
                    </label>
                    <label>{{ __('school_erp_fees.fee_label') }}<input name="label" maxlength="100" required></label>
                    <label>{{ __('school_erp_fees.year_due') }}<input type="date" name="due_on" required></label>
                    <label>{{ __('school_erp_fees.amount') }}<input name="amount" inputmode="decimal" maxlength="12" required></label>
                    <button class="school-button">{{ __('school_erp_fees.save_schedule') }}</button>
                </form>
            </section>
            @if($schedules->isNotEmpty())
                <section class="school-card">
                    <h2>{{ __('school_erp_fees.issue_invoice') }}</h2>
                    <form class="school-form" method="POST" action="{{ route('school-erp.fees.issue') }}">
                        @csrf
                        <label>{{ __('school_erp_fees.student_reference') }}<input name="student_reference" maxlength="64" required></label>
                        <label>{{ __('school_erp_fees.schedule') }}
                            <select name="schedule_id" required>
                                @foreach($schedules as $schedule)
                                    <option value="{{ $schedule->id }}">{{ $schedule->label }} · {{ $schedule->due_on }}</option>
                                @endforeach
                            </select>
                        </label>
                        <button class="school-button">{{ __('school_erp_fees.issue_invoice') }}</button>
                    </form>
                </section>
            @endif
        </div>
    @endif

    @forelse($invoices as $invoice)
        <article class="school-card mt-4" id="fee-{{ $invoice->id }}">
            <h2>{{ $invoice->label }} · FEE-{{ $invoice->id }}</h2>
            <p>{{ $invoice->display_name }} · {{ $invoice->student_reference }} · {{ $invoice->due_on }}</p>
            <p>{{ __('school_erp_fees.invoiced') }} PKR {{ number_format($invoice->amount_paisa / 100, 2) }} ·
                {{ __('school_erp_fees.balance') }} PKR {{ number_format(($invoice->amount_paisa - $invoice->paid_paisa) / 100, 2) }}</p>
            @foreach($receipts->get($invoice->id, []) as $receipt)
                <div class="school-note">
                    <a class="underline" href="{{ route('school-erp.fees.receipt', $receipt->id) }}">{{ __('school_erp_fees.receipt') }} RCP-{{ $receipt->id }}</a> · PKR {{ number_format($receipt->amount_paisa / 100, 2) }} ·
                    {{ __('school_erp_fees.'.$receipt->method) }} · {{ $receipt->created_at }}
                    @if($receipt->reversal_reason !== null)
                        <p>{{ __('school_erp_fees.reversed') }} · {{ $receipt->reversal_reason }}</p>
                    @elseif($canWrite)
                        <details>
                            <summary>{{ __('school_erp_fees.reverse') }}</summary>
                            <form class="school-form" method="POST" action="{{ route('school-erp.fees.reverse', [$invoice->id, $receipt->id]) }}">
                                @csrf
                                <label>{{ __('school_erp_fees.reason') }}<input name="reason" maxlength="200" required></label>
                                <button class="school-button">{{ __('school_erp_fees.reverse') }}</button>
                            </form>
                        </details>
                    @endif
                </div>
            @endforeach
            @if($canWrite && $invoice->amount_paisa > $invoice->paid_paisa)
                <details class="mt-3">
                    <summary>{{ __('school_erp_fees.record_receipt') }}</summary>
                    <form class="school-form" method="POST" action="{{ route('school-erp.fees.receive', $invoice->id) }}">
                        @csrf
                        <input type="hidden" name="request_key" value="{{ \Illuminate\Support\Str::uuid() }}">
                        <label>{{ __('school_erp_fees.amount') }}<input name="amount" inputmode="decimal" maxlength="12" required></label>
                        <label>{{ __('school_erp_fees.method') }}
                            <select name="method" required>
                                @foreach(['cash', 'bank', 'card'] as $method)
                                    <option value="{{ $method }}">{{ __('school_erp_fees.'.$method) }}</option>
                                @endforeach
                            </select>
                        </label>
                        <label>{{ __('school_erp_fees.reference') }}<input name="reference" maxlength="100"></label>
                        <button class="school-button">{{ __('school_erp_fees.record_receipt') }}</button>
                    </form>
                </details>
            @endif
        </article>
    @empty
        <p class="school-card mt-4">{{ __('school_erp_fees.no_invoices') }}</p>
    @endforelse
    {{ $invoices->links() }}
</x-school-erp-layout>

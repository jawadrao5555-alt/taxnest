<x-school-erp-layout :title="__('school_erp_fees.receipt')">
    <article class="school-card">
        <h1 class="school-title">{{ __('school_erp_fees.receipt') }} RCP-{{ $record->id }}</h1>
        <p>{{ $company->name }}</p>
        <p>{{ $record->display_name }} · {{ $record->student_reference }}</p>
        <p>{{ $record->label }} · FEE-{{ $record->invoice_id }}</p>
        <p>PKR {{ number_format($record->amount_paisa / 100, 2) }} · {{ __('school_erp_fees.'.$record->method) }}</p>
        @if($record->reference)
            <p>{{ __('school_erp_fees.reference') }} · {{ $record->reference }}</p>
        @endif
        <p>{{ $record->created_at }}</p>
        @if($record->reversal_reason !== null)
            <p class="school-note">{{ __('school_erp_fees.reversed') }} · {{ $record->reversal_reason }}</p>
        @endif
    </article>
</x-school-erp-layout>

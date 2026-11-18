@extends('fbr-pos.hospital.layout')
@section('content')
@if(session('hospital_correction'))<p role="status" class="notice">{{ session('hospital_correction') }}</p>@endif
@if(!empty($hospitalIsOwner))<p><a href="/fbr-pos/hospital/history{{ !empty($hospitalUnassigned) ? '' : '?unassigned=1' }}">{{ __(!empty($hospitalUnassigned) ? 'hospital.current_scope' : 'hospital.unassigned_scope') }}</a></p>@endif
@if(session('ims_status'))
<p role="status" class="notice">{{ session('ims_status') }}</p>
@endif
<h1>{{ __('hospital.history') }}</h1><section class="card"><form method="get"><input type="hidden" name="unassigned" value="{{ !empty($hospitalUnassigned) ? 1 : 0 }}"><label for="search">{{ __('hospital.search') }}</label><input id="search" name="search" value="{{ request('search') }}" maxlength="100"><div class="grid"><label>{{ __('hospital_ledger.from') }}<input type="date" name="from" value="{{ request('from') }}"></label><label>{{ __('hospital_ledger.to') }}<input type="date" name="to" value="{{ request('to') }}"></label><label>{{ __('hospital_ledger.status') }}<select name="status"><option value="">{{ __('hospital_ledger.all') }}</option>@foreach(['partial','settled'] as $state)<option value="{{ $state }}" @selected(request('status')===$state)>{{ __('hospital_ledger.'.$state) }}</option>@endforeach</select></label>@foreach(['paid_min','paid_max','balance_min','balance_max'] as $filter)<label>{{ __('hospital_ledger.'.$filter) }} (PKR)<input type="number" min="0" max="99999999.99" step="0.01" name="{{ $filter }}" value="{{ request($filter) }}"></label>@endforeach</div><button>{{ __('hospital.search') }}</button></form><div class="scroll"><table><thead><tr><th>{{ __('hospital.invoice') }}</th><th>{{ __('hospital.patient') }}</th><th>{{ __('hospital.service') }}</th><th>PKR</th><th>{{ __('hospital_ledger.paid') }}</th><th>{{ __('hospital.balance') }}</th><th>{{ __('hospital_ledger.status') }}</th><th>{{ __('hospital_ledger.date') }}</th><th>Payment receipts / FBR IMS</th></tr></thead><tbody>@forelse($invoices as $invoice)<tr><td><a href="/fbr-pos/hospital/invoices/{{ $invoice->id }}{{ !empty($hospitalUnassigned) ? '?unassigned=1' : '' }}">HI-{{ $invoice->id }}</a></td><td>{{ str_starts_with((string) $invoice->patient_reference, 'Walk-in-') ? 'Walk-in' : $invoice->patient_reference }} {{ $invoice->patient_name }}</td><td>{{ $invoice->service_lines ? implode(' + ', array_column(json_decode($invoice->service_lines,true),'label')) : __('hospital.'.$invoice->service_head) }}</td><td>{{ number_format($invoice->total_paisa/100,2) }}</td><td>{{ number_format($invoice->paid_paisa/100,2) }}</td><td>{{ number_format($invoice->balance_paisa/100,2) }}</td><td>{{ __($invoice->balance_paisa==0 ? 'hospital_ledger.settled' : 'hospital_ledger.partial') }}</td><td>{{ $invoice->created_at }}</td>
<td>
    @forelse($paymentsByInvoice->get($invoice->id, []) as $payment)
        <div style="margin-block: 0.5rem;">
            <a href="/fbr-pos/hospital/receipts/{{ $payment->id }}{{ !empty($hospitalUnassigned) ? '?unassigned=1' : '' }}">Open receipt HP-{{ $payment->id }}</a>
            @if(($payment->fbr_status ?? null) === 'submitted' && !empty($payment->fbr_invoice_number))
                <div><strong>FBR IMS accepted</strong><br>FBR invoice: {{ $payment->fbr_invoice_number }}</div>
            @elseif(in_array(($payment->fbr_status ?? null), ['pending', 'offline', 'verification_pending'], true))
                <div>FBR IMS: {{ str_replace('_', ' ', $payment->fbr_status) }}</div>
            @endif
            @include('fbr-pos.partials.collection-ims-retry', [
                'imsReceipt' => $payment, 'imsRetryRoute' => 'fbrpos.hospital.ims-retry', 'showImsFlash' => false,
            ])
        </div>
    @empty
        <span>—</span>
    @endforelse
</td></tr>@empty<tr><td colspan="9">{{ __('hospital.empty') }}</td></tr>@endforelse</tbody></table></div>{{ $invoices->links() }}</section>
@endsection

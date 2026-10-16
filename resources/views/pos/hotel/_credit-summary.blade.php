@if($summary)
@php($creditSummaryLocale = ($creditReceipt ?? false) ? 'en' : app()->getLocale())
<div data-credit-status="{{ $summary['status'] }}" class="my-3 space-y-1" style="overflow-wrap:anywhere">
<p class="font-semibold">{{ __('hotel_credit.status_'.$summary['status'], [], $creditSummaryLocale) }}</p>
@if($summary['fiscal_number'])<p>{{ __('hotel_credit.fiscal_number', [], $creditSummaryLocale) }}: {{ $summary['fiscal_number'] }}</p>@endif
<p>{{ __('hotel_credit.credit_amount', [], $creditSummaryLocale) }}: Rs {{ number_format($creditAmount, 2) }}</p>
<p>{{ __('hotel_correction.reason', [], $creditSummaryLocale) }}: {{ $summary['reason'] }}</p>
<p>{{ __('hotel_credit.refunded_amount', [], $creditSummaryLocale) }}: Rs {{ number_format($summary['refunded'], 2) }}</p>
<p>{{ __('hotel_credit.remaining_credit', [], $creditSummaryLocale) }}: Rs {{ number_format($summary['remaining'], 2) }}</p>
<p>{{ __('hotel_credit.available_refund', [], $creditSummaryLocale) }}: @if($summary['refundable'] !== null)Rs {{ number_format($summary['refundable'], 2) }}@else{{ __('hotel_credit.review_guest_funds', [], $creditSummaryLocale) }}@endif</p>
</div>
@endif

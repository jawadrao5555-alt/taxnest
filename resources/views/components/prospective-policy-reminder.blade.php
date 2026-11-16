@props(['guard' => 'web'])
@php
    $policyNotice = null;
    foreach (\App\Services\ProspectiveSubscriptionPolicy::available() ? [$guard] : [] as $policyGuard) {
        if (auth($policyGuard)->check()) {
            $policyCompany = \App\Models\Company::find(auth($policyGuard)->user()->company_id);
            if ($policyCompany) $policyNotice = \App\Services\ProspectiveReminderView::forCompany($policyCompany);
            break;
        }
    }
@endphp
@if($policyNotice)
<aside class="no-print" data-prospective-reminder="{{ $policyNotice['kind'] }}" data-reminder-until="{{ $policyNotice['until'] }}" role="status" style="margin:12px 0;padding:12px 16px;border:1px solid #b7791f;border-radius:8px;background:#fff8e1;color:#573b09;line-height:1.6" @if(app()->getLocale()==='ur') dir="rtl" @endif>{{ $policyNotice['text'] }}</aside>
@endif

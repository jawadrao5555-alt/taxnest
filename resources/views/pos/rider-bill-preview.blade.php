<x-pos-layout><div class="max-w-xl mx-auto p-5">
@if(!$preview['available']) <div class="p-4 rounded border text-sm">Bill preview is disabled by the business.</div>
@else
<div class="bg-white dark:bg-gray-900 rounded-xl border p-5"><h1 class="font-bold">{{ $preview['business']['name'] ?? __('pos.rider_bill_preview') }}</h1>
@if(isset($preview['customer'])) <div class="text-sm mt-3">{{ implode(' · ', $preview['customer']) }}</div>@endif
<div class="mt-4 space-y-2">@foreach($preview['items'] as $item)<div class="flex justify-between text-sm"><span>{{ $item['name'] }}@isset($item['quantity']) × {{ $item['quantity'] }}@endisset</span>@isset($item['line_total'])<span>Rs. {{ number_format($item['line_total'], 2) }}</span>@endisset</div>@endforeach</div>
@if(isset($preview['tax']))<div class="text-sm mt-3">Tax ({{ $preview['tax']['rate'] }}%): Rs. {{ number_format($preview['tax']['amount'], 2) }}</div>@endif
<div class="font-bold mt-4">Grand Total: Rs. {{ number_format($preview['grand_total'], 2) }}</div>@if(isset($preview['ntn']))<div class="text-xs mt-2">NTN: {{ $preview['ntn'] }}</div>@endif
@if(!empty($preview['qr']['available']) && !empty($preview['qr']['payload']))
<div class="mt-4">{!! \SimpleSoftwareIO\QrCode\Facades\QrCode::format('svg')->size(120)->generate($preview['qr']['payload']) !!}</div>
@endif
</div>@endif</div></x-pos-layout>
@if(isset($financialHistory))
@php($fh = $financialHistory)
<section id="financial-history" class="bg-white dark:bg-gray-900 border border-gray-200 dark:border-gray-700 rounded-xl p-4 my-5 space-y-4">
    <div class="flex flex-wrap items-center justify-between gap-3">
        <div><h2 class="font-bold text-lg">{{ __('pos_history.title') }}</h2><p class="text-sm text-gray-500">{{ $fh['from'] }} → {{ $fh['to'] }} · {{ strtoupper($fh['tab']) }} · {{ __('pos_history.business_dates') }}</p></div>
        @if($canExportsPlan)
        <a class="px-3 py-2 rounded-lg bg-emerald-700 text-white text-sm" href="{{ route('pos.reports.csv', ['tab' => $tab, 'cashier' => $selectedCashier, 'from' => $fh['from'], 'to' => $fh['to'], 'reconciliation' => 1]) }}">{{ __('pos_history.csv') }}</a>
        @endif
    </div>
    <p class="text-sm text-gray-600 dark:text-gray-300">{{ __('pos_history.basis') }}</p>
        @if($user?->isPosAdmin() && !$user->posHidesLocalStream() && $user->posBillingScope() === 'both')
        <a class="px-4 py-2 text-purple-700 underline" href="{{ route('pos.reports', ['tab' => 'combined', 'cashier' => $selectedCashier, 'from' => $fh['from'], 'to' => $fh['to']]) }}">{{ __('pos_history.combined') }}</a>
        @endif

    @if(!$fh['complete'])
    <div role="status" class="p-3 bg-amber-50 text-amber-900 rounded-lg"><strong>{{ __('pos_history.partial') }}</strong>
        <p>{{ __('pos_history.gap_explanation') }}</p>
        @foreach($fh['gaps'] as $gap)<p class="text-sm">{{ $gap['date'] }} @if($user?->isPosAdmin()) · {{ $gap['number'] }} @endif</p>@endforeach
    </div>
    @endif
    <div class="grid grid-cols-2 md:grid-cols-4 gap-3">
        @foreach(['gross', 'returned', 'net', 'tax'] as $metric)
        <div class="rounded-lg bg-gray-50 dark:bg-gray-800 p-3"><p class="text-xs text-gray-500">{{ __('pos_history.' . $metric) }}{{ !$fh['complete'] ? ' · ' . __('pos_history.known_only') : '' }}</p><p class="font-bold">PKR {{ number_format($fh['summary'][$metric], 2) }}</p></div>
        @endforeach
    </div>
    <p class="text-sm text-gray-500">{{ __('pos_history.source_counts', ['live' => $fh['live_count'], 'retained' => $fh['retained_count']]) }} {{ __('pos_history.provisional', ['amount' => number_format($fh['provisional']['net'], 2)]) }}</p>
    <div class="overflow-x-auto"><table class="w-full text-sm"><thead><tr class="text-left border-b"><th class="p-2">{{ __('pos_history.business_dates') }}</th><th>{{ __('pos_history.gross') }}</th><th>{{ __('pos_history.returned') }}</th><th>{{ __('pos_history.net') }}</th><th>{{ __('pos_history.tax') }}</th></tr></thead><tbody>
    @forelse($fh['daily'] as $date => $row)<tr class="border-b border-gray-100 dark:border-gray-800"><td class="p-2">{{ $date }}</td>@foreach(['gross','returned','net','tax'] as $metric)<td>{{ number_format($row[$metric], 2) }}</td>@endforeach</tr>
    @empty<tr><td class="p-3" colspan="5">{{ $fh['complete'] ? __('pos_history.no_known_finals') : __('pos_history.partial') }}</td></tr>@endforelse
    </tbody></table></div>
    <div class="grid md:grid-cols-2 gap-4">
        @foreach(['payments' => __('pos_history.payment'), 'cashiers' => __('pos_history.cashier')] as $field => $label)
        <details><summary class="cursor-pointer font-semibold">{{ $label }}</summary><table class="w-full text-sm"><thead><tr><th class="text-left p-2">{{ $label }}</th><th>{{ __('pos_history.net') }}</th><th>{{ __('pos_history.tax') }}</th></tr></thead><tbody>
        @foreach($fh[$field] as $key => $row)<tr><td class="p-2">{{ $field === 'cashiers' ? ($teamMembers->firstWhere('id', $key)?->name ?? ('#' . $key)) : \App\Support\PosPaymentLabels::label($key) }}</td><td class="text-center">{{ number_format($row['net'], 2) }}</td><td class="text-center">{{ number_format($row['tax'], 2) }}</td></tr>@endforeach
        </tbody></table></details>
        @endforeach
    </div>
    @if($canAnalyticsPlan)
    <details><summary class="cursor-pointer font-semibold">{{ __('pos_history.products') }}</summary>
    <p class="text-sm text-gray-500 my-2">{{ __('pos_history.product_basis') }}</p>
    <div class="overflow-auto max-h-96"><table class="w-full text-sm"><thead><tr class="text-left"><th class="p-2">{{ __('pos.product_col') }}</th><th>{{ __('pos_history.category_size') }}</th><th>{{ __('pos_history.channel') }}</th><th>{{ __('pos.receipt_qty') }}</th><th>{{ __('pos_history.net') }}</th></tr></thead><tbody>
    @foreach(collect($fh['products'])->sortBy(fn ($row) => ($row['category'] ?? '') . '|' . $row['name'] . '|' . $row['size']) as $row)
    <tr class="border-b border-gray-100 dark:border-gray-800"><td class="p-2">{{ $row['name'] }} <span class="text-xs text-gray-500">{{ $row['product_id'] ? '#' . $row['product_id'] : '' }}</span></td><td>{{ $row['category'] }} · {{ $row['size'] ?: '—' }}</td><td>{{ $row['channel'] }}<small class="block text-gray-500">{{ $row['basis'] }}</small></td><td>{{ $row['qty'] }}</td><td>{{ number_format($row['net'], 2) }}</td></tr>
    @endforeach
    </tbody></table></div></details>
    @endif
    <p class="text-xs text-gray-500">{{ __('pos_history.refund_note') }}</p>
</section>
<div class="p-3 mb-4 text-sm bg-blue-50 text-blue-900 rounded-lg">{{ __('pos_history.detail_note') }}</div>
@endif

@if(isset($financialHistory))
@php($fh = $financialHistory)
<section id="financial-products" class="bg-white dark:bg-gray-900 rounded-xl border p-4 my-5">
    @if($canAnalyticsPlan)
    <details open><summary class="cursor-pointer font-semibold">{{ __('pos_history.products') }}</summary>
    <p class="text-sm text-gray-500 my-2">{{ __('pos_history.product_basis') }}</p>
    <div class="overflow-auto max-h-96"><table class="w-full text-sm"><thead><tr class="text-left"><th class="p-2">{{ __('pos.product_col') }}</th><th>{{ __('pos_history.category_size') }}</th><th>{{ __('pos_history.channel') }}</th><th>{{ __('pos.receipt_qty') }}</th><th>{{ __('pos_history.net') }}</th></tr></thead><tbody>
    @foreach(collect($fh['products'])->sortBy(fn ($row) => ($row['category'] ?? '') . '|' . $row['name'] . '|' . $row['size']) as $row)
    <tr class="border-b border-gray-100 dark:border-gray-800"><td class="p-2">{{ $row['name'] }} <span class="text-xs text-gray-500">{{ $row['product_id'] ? '#' . $row['product_id'] : '' }}</span></td><td>{{ $row['category'] }} · {{ $row['size'] ?: '—' }}</td><td>{{ $row['channel'] }}<small class="block text-gray-500">{{ $row['basis'] }}</small></td><td class="text-center">{{ $row['qty'] }}</td><td>{{ number_format($row['net'], 2) }}</td></tr>
    @endforeach
    </tbody></table></div></details>
    @endif

</section>
@endif

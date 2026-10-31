<?php
return [
    'title' => 'Business-day reconciliation', 'business_dates' => 'Business dates', 'csv' => 'Download reconciled CSV',
    'basis' => 'Final sales and credit notes include surviving/archived bills plus retained deletion aggregates. Provisional bills stay separate. Filters apply to both sources.',
    'combined' => 'Combined streams', 'partial' => 'Partial history — deleted breakdown unavailable',
    'gap_explanation' => 'Some older deleted records predate aggregate retention. Their retained day-close reports remain separate evidence; missing cashier/product detail is not zero sales and cannot be reconstructed here.',
    'gross' => 'Final gross sales', 'returned' => 'Returns / credit notes', 'net' => 'Net final amount', 'tax' => 'Net tax', 'known_only' => 'known amounts only',
    'source_counts' => ':live surviving bill/credit rows; :retained deleted sales represented only by aggregates.',
    'provisional' => 'Provisional/draft amount: PKR :amount (excluded from final net).',
    'no_known_finals' => 'No final activity recorded in the available sources for this scope.',
    'payment' => 'Payment method', 'cashier' => 'Cashier', 'products' => 'Product / size / category reconciliation',
    'product_basis' => 'Deal component shares replace their parent bundle revenue. Standalone items stay separate. New snapshots freeze labels; legacy labels are identified. Charges and rounding differences have their own row. Quantities and money are net of returns.',
    'category_size' => 'Category / size', 'channel' => 'Sale channel / basis',
    'refund_note' => 'A credit note reduces sales; it does not by itself prove cash was paid back. Aggregates cannot recover deleted bill details. Optional support-copy consent and retention are unchanged.',
    'detail_note' => 'The charts and invoice lists below describe available bill detail only, using their displayed periods. Gross-sales analytics exclude returns. Use the reconciliation above for net amounts and retained deleted-sale totals.',
];

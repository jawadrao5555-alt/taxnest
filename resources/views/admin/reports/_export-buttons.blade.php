@php
    $qs = http_build_query(array_filter([
        'date_from' => $from, 'date_to' => $to,
        'company_id' => $companyId, 'branch_id' => $branchId,
    ]));
@endphp
<div class="flex items-center gap-2">
    <a href="{{ $route }}?{{ $qs }}&export=pdf" class="bg-rose-600 hover:bg-rose-700 text-white text-xs font-bold px-3 py-2 rounded">📄 PDF</a>
    <a href="{{ $route }}?{{ $qs }}&export=excel" class="bg-emerald-600 hover:bg-emerald-700 text-white text-xs font-bold px-3 py-2 rounded">📊 Excel</a>
    <a href="{{ $route }}?{{ $qs }}&export=json" class="bg-blue-600 hover:bg-blue-700 text-white text-xs font-bold px-3 py-2 rounded">{ } JSON</a>
</div>

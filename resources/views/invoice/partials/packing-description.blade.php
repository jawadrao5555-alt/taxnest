@php($packing = $item->selling_snapshot)
@if(($packing['version'] ?? null) === 1 && isset($packing['selling_quantity'], $packing['label']))
    <br><small>{{ $packing['selling_quantity'] }} × {{ $packing['label'] }}</small>
@endif

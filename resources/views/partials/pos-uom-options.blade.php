{{--
    Grouped unit (UoM) <option>s for BOTH POS panels — the ONLY place option
    markup for a unit dropdown lives. Feed it PosUnitCatalog::groupsFor().

        @include('partials.pos-uom-options', ['uomGroups' => $uomGroups, 'uomSelected' => $currentUom])

    First group = the shop's business-category units, second = every other
    catalogue code (plus an unknown stored value, verbatim, so an existing
    product always renders selected). Works for plain selects and for
    x-model selects alike (Alpine reads the model, the `selected` attribute
    only matters on a plain form / old() redisplay).
--}}
@php
    $uomGroups = $uomGroups ?? \App\Services\PosUnitCatalog::groupsFor(null);
    $uomSelected = isset($uomSelected) ? \App\Services\PosUnitCatalog::normalize($uomSelected) : ($uomGroups['current'] ?? null);
@endphp
<optgroup label="{{ __('pos.uom_group_recommended') }}">
    @foreach($uomGroups['recommended'] as $opt)
        <option value="{{ $opt['code'] }}" data-measure="{{ $opt['measure'] ? 1 : 0 }}" @selected($uomSelected === $opt['code'])>{{ $opt['text'] }}</option>
    @endforeach
</optgroup>
@if(!empty($uomGroups['rest']))
<optgroup label="{{ __('pos.uom_group_rest') }}">
    @foreach($uomGroups['rest'] as $opt)
        <option value="{{ $opt['code'] }}" data-measure="{{ $opt['measure'] ? 1 : 0 }}" @selected($uomSelected === $opt['code'])>{{ $opt['text'] }}</option>
    @endforeach
</optgroup>
@endif

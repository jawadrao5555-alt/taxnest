@php
    $catalogs = json_decode(file_get_contents(resource_path('data/fbr/dsi-1.0.44.json')), true, 512, JSON_THROW_ON_ERROR)['catalogs'];
@endphp
<datalist id="dsi-sro-schedules">
    @foreach($catalogs['sro'] as $schedule)
        <option value="{{ $schedule }}"></option>
    @endforeach
</datalist>
<datalist id="dsi-item-serials">
    @foreach($catalogs['serial'] as $serial)
        <option value="{{ $serial }}"></option>
    @endforeach
</datalist>

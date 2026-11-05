@extends('pos.di-sandbox.layout')
@section('title', 'Automatic PRA + FBR reporting')
@section('content')
<section class="card">
    <h2>{{ $company->name }} · automatic reporting</h2>
    <p>Finalize one bill as usual. Its original sale, stock and payment stay single; PRA and the selected federal channel retain their own acknowledgement.</p>
    @if($selected)
        <p><strong>{{ $selected[0]==='ims' ? 'FBR POS IMS' : 'FBR Digital Invoice' }} · {{ strtoupper($selected[1]->environment) }}</strong></p>
        <p>Review this mapping once for future finalized bills. It applies one flat federal rate/classification to every sold item. Use the manual review workbench for mixed classifications or unsupported statutory calculations.</p>
        <form method="post" action="{{ route('pos.federal-auto.save') }}">@csrf
            <input type="hidden" name="channel" value="{{ $selected[0] }}">
            <input type="hidden" name="environment" value="{{ $selected[1]->environment }}">
            <label for="auto-enabled">Automatic reporting</label>
            <select id="auto-enabled" name="enabled"><option value="0" @selected(!$rule?->enabled)>OFF</option><option value="1" @selected($rule?->enabled)>ON · future bills only</option></select>
            <label for="auto-hs">{{ $selected[0]==='di' ? 'Reviewed HS code (required)' : 'Reviewed PCT code (optional)' }}</label><input id="auto-hs" name="hs_code" value="{{ old('hs_code',$rule?->mapping['hs_code'] ?? '') }}" placeholder="0000.0000">
            @if($selected[0]==='ims')
                <p>A blank PCT uses the existing FBR POS compatibility value 00000000. This does not select a tax exemption or prove acceptance by your installed IMS. Review the applicable rate separately.</p>
            @endif
            <label for="auto-rate">Reviewed federal percentage (separate from PRA)</label><input id="auto-rate" type="number" min="0" max="100" step="0.01" name="tax_rate" value="{{ old('tax_rate',$rule?->mapping['tax_rate'] ?? '') }}">
            @if($selected[0]==='di')
                <label for="auto-uom">FBR unit of measure</label><input id="auto-uom" name="uom" value="{{ old('uom',$rule?->mapping['uom'] ?? '') }}">
                <label for="auto-sale-type">FBR sale type</label><input id="auto-sale-type" name="sale_type" value="{{ old('sale_type',$rule?->mapping['sale_type'] ?? '') }}">
                <label for="auto-province">Walk-in buyer province</label><input id="auto-province" name="buyer_province" value="{{ old('buyer_province',$rule?->mapping['buyer_province'] ?? '') }}">
                <label for="auto-address">Walk-in buyer locality / address</label><input id="auto-address" name="buyer_address" value="{{ old('buyer_address',$rule?->mapping['buyer_address'] ?? '') }}">
                <p>Automatic DI uses unregistered walk-in buyer reporting. Named/registered buyers must use the reviewed workbench until their identity mapping is configured.</p>
                @if($selected[1]->environment==='sandbox')
                    <label for="auto-scenario">Assigned Sandbox scenario</label><input id="auto-scenario" name="scenario_id" value="{{ old('scenario_id',$rule?->mapping['scenario_id'] ?? '') }}">
                @endif
                <label for="auto-sro">SRO schedule (if applicable)</label><input id="auto-sro" name="sro_schedule" value="{{ old('sro_schedule',$rule?->mapping['sro_schedule'] ?? '') }}">
                <label for="auto-sro-item">SRO item (if applicable)</label><input id="auto-sro-item" name="sro_item" value="{{ old('sro_item',$rule?->mapping['sro_item'] ?? '') }}">
            @endif
            <label><input type="checkbox" name="all_items_confirmed" value="1">This flat mapping applies to all items and these sales qualify for the buyer treatment shown above.</label>
            <label><input type="checkbox" name="mapping_reviewed" value="1">I reviewed the tax classification, rate and selected environment. Enable future automatic submissions using this mapping.</label>
            <button>Save automatic reporting</button>
        </form>
    @else
        <p>Select and configure exactly one federal channel in <a href="{{ route('pos.tax-integrations') }}">Tax Integrations</a> first. No automatic federal reporting is active.</p>
    @endif
</section>
<section class="card"><h2>Automatic reporting history</h2>
    <p>The receipt shows separate acknowledgement numbers beneath two QR codes. Pending/blocked/uncertain reports show their actual state. Reprint after acknowledgement for both QR codes; no automatic duplicate print is issued.</p>
    <table><thead><tr><th>Original bill ID</th><th>Channel</th><th>Environment</th><th>Delivery</th><th>Action</th></tr></thead><tbody>
    @forelse($rows as $row)<tr><td>{{ $row->source_transaction_id }}</td><td>{{ strtoupper($row->channel) }}</td><td>{{ $row->environment }}</td><td>{{ $row->state }}</td><td>
        @if($row->submission_id)<a href="{{ $row->channel==='ims' ? route('pos.tax-integrations.ims.show',$row->submission_id) : route('pos.di-sandbox.show',$row->submission_id) }}">View acknowledgement / recovery</a>@else{{ $row->error_code ?: 'Queued' }}@endif
    </td></tr>@empty<tr><td colspan="5">No automatic reports yet.</td></tr>@endforelse
    </tbody></table>{{ $rows->links() }}
</section>
@endsection

<x-app-layout>
<div class="max-w-7xl mx-auto p-6 space-y-6">
    <h1 class="text-2xl font-bold">Source invoice review</h1>
    <p>{{ $batch->original_filename }} · {{ $batch->smart_source_system }} · {{ $batch->status }}</p>
    @if(session('success')) <p class="text-green-700">{{ session('success') }}</p> @endif
    @if($errors->any()) <ul class="text-red-700">@foreach($errors->all() as $error)<li>{{ $error }}</li>@endforeach</ul> @endif
    <p>Check original dates, buyer verification, SKU conversions, tax treatment and totals. Approval creates drafts only. Submit to FBR from the existing Invoices screen after reviewing each draft.</p>

    @if(!$batch->approved_at)
    <details class="border rounded p-4">
        <summary class="font-semibold">Verified company mappings (company administrator)</summary>
        <p class="text-sm my-2">Record checked evidence. Existing mappings remain in version history; new versions apply when source review is refreshed.</p>
        @if(auth()->user()?->role === 'company_admin')
        <form method="POST" action="/invoices/import/{{ $batch->id }}/verified-mapping" class="space-y-3" x-data="{kind:'sku'}">
            @csrf
            <select name="kind" x-model="kind" class="border p-2"><option value="sku">SKU</option><option value="buyer">Buyer / outlet</option></select>
            <label>Source code <input required name="source_code" class="border p-2"></label>
            <div x-show="kind === 'sku'" class="grid grid-cols-2 gap-3">
                @foreach(['description'=>'Actual product description','hs_code'=>'Verified HS code (digits)','litres_per_case'=>'Verified litres per case','mrp'=>'Printed retail MRP per litre, if required','sro_schedule_no'=>'SRO number','sro_serial_no'=>'SRO serial'] as $key=>$label)
                <label>{{ $label }}<input :disabled="kind !== 'sku'" name="details[{{ $key }}]" class="border p-2 block w-full"></label>
                @endforeach
                <label>Verified UoM<select :disabled="kind !== 'sku'" name="details[uom]" class="border p-2 block"><option value="">Select</option><option>Liter</option></select></label>
                <label>Verified schedule<select :disabled="kind !== 'sku'" name="details[schedule_type]" class="border p-2 block"><option value="">Select</option>@foreach(\App\Services\InvoiceImportService::VALID_SCHEDULE_TYPES as $type)<option>{{ $type }}</option>@endforeach</select></label>
                <label>Verified tax rate (%)<input :disabled="kind !== 'sku'" name="details[tax_rate]" class="border p-2 block"></label>
            </div>
            <div x-show="kind === 'buyer'" class="grid grid-cols-2 gap-3">
                @foreach(['buyer_name'=>'Buyer name','buyer_address'=>'Buyer address','buyer_ntn'=>'Verified NTN','buyer_cnic'=>'Verified CNIC'] as $key=>$label)
                <label>{{ $label }}<input :disabled="kind !== 'buyer'" name="details[{{ $key }}]" class="border p-2 block w-full"></label>
                @endforeach
                <label>Registration status<select :disabled="kind !== 'buyer'" name="details[registration_type]" class="border p-2"><option value="">Select verified status</option><option>Registered</option><option>Unregistered</option></select></label>
                <label>Province<select :disabled="kind !== 'buyer'" name="details[destination_province]" class="border p-2"><option value="">Select</option>@foreach(\App\Services\InvoiceImportService::VALID_PROVINCES as $province)<option>{{ $province }}</option>@endforeach</select></label>
            </div>
            <label class="block">Verification evidence / reference<textarea required name="evidence" class="border p-2 block w-full"></textarea></label>
            <label class="block"><input required type="checkbox" name="verified" value="1"> I checked these values and their source evidence.</label>
            <button class="bg-blue-700 text-white rounded p-2">Save verified mapping</button>
        </form>
        @endif
        <table class="w-full my-4 text-sm"><thead><tr><th>Kind</th><th>Code</th><th>Version</th><th>Details / evidence</th></tr></thead><tbody>
        @foreach($mappings as $mapping)<tr class="border-b"><td>{{ $mapping->kind }}</td><td>{{ $mapping->source_code }}</td><td>{{ $mapping->version }}</td><td>{{ json_encode($mapping->details) }}<br>{{ $mapping->evidence }}</td></tr>@endforeach
        </tbody></table>
    </details>
    @endif

    <form method="POST" action="/invoices/import/{{ $batch->id }}/source-review" class="space-y-4">
        @csrf
        @foreach(($batch->smart_review['invoices'] ?? []) as $invoice)
        <section class="border rounded p-4 space-y-3">
            <h2 class="font-bold">Original invoice {{ $invoice['source_invoice_number'] }} · {{ $invoice['invoice_date'] }}</h2>
            <table class="text-sm w-full"><thead><tr><th></th><th>Gross</th><th>Discount</th><th>VAT</th><th>Net</th></tr></thead><tbody>
            @foreach(['source_totals'=>'All original rows','excluded_totals'=>'Reviewed exclusions','draft_totals'=>'Remaining draft amounts'] as $key=>$label)
            <tr><td>{{ $label }}</td>@foreach(['gross','discount','vat','net'] as $field)<td>{{ \App\Services\InvoiceImportMoney::decimal($invoice[$key][$field]) }}</td>@endforeach</tr>
            @endforeach
            </tbody></table>
            @foreach($invoice['errors'] as $error)<p class="text-red-700 text-sm">{{ $error }}</p>@endforeach
            <div class="overflow-x-auto"><table class="text-sm w-full"><thead><tr><th>Row</th><th>Buyer / outlet</th><th>SKU / description</th><th>Case → litre</th><th>HS / schedule / MRP</th><th>Source net / VAT</th><th>Review</th></tr></thead><tbody>
            @foreach($batch->rowsArray() as $row)
            @if(in_array($row['row'], $invoice['rows'], true))
            <tr class="border-b align-top"><td>{{ $row['row'] }}</td><td>{{ $row['original_data']['source_outlet'] ?? '' }}<br>{{ $row['data']['buyer_name'] ?? '' }}<br>{{ $row['data']['_buyer_registration_type'] ?? 'Verification required' }}</td>
            <td>{{ $row['original_data']['source_sku'] ?? '' }}<br>{{ $row['original_data']['description'] ?? '' }}</td>
            <td>{{ $row['original_data']['quantity'] ?? '' }} → {{ $row['data']['quantity'] ?? '' }}</td>
            <td>{{ $row['data']['hs_code'] ?? '' }} / {{ $row['data']['schedule_type'] ?? '' }} / {{ $row['data']['mrp'] ?? '' }}</td>
            <td>{{ $row['original_data']['source_net'] ?? '' }} / {{ $row['original_data']['source_vat'] ?? '' }}</td>
            <td>{{ $row['classification'] }}
                @if(!$batch->approved_at && auth()->user()?->role === 'company_admin')
                <details><summary>Verify line financial allocation</summary>
                    <p>Reported net: {{ $row['original_data']['source_reported_net'] ?? '' }}. Enter checked amounts; do not infer VAT from the displayed percentage.</p>
                    @foreach(['discount','vat','net'] as $field)
                    <label class="block">{{ $field }}<input name="amounts[{{ $row['row'] }}][{{ $field }}]" class="border p-1" placeholder="{{ $row['original_data']['source_'.$field] ?? '' }}"></label>
                    @endforeach
                    <label>Evidence<input name="amounts[{{ $row['row'] }}][evidence]" class="border p-1"></label>
                </details>
                @endif
                @if($row['classification'] === 'packaging_review')
                <label class="block">Reason to exclude packaging<input name="exclusions[{{ $row['row'] }}]" maxlength="500" value="{{ $row['exclusion_reason'] ?? '' }}" @disabled($batch->approved_at) class="border p-2"></label>
                @endif
            </td></tr>
            @endif
            @endforeach
            </tbody></table></div>
        </section>
        @endforeach
        @if(!$batch->approved_at)
        <button class="border rounded p-2">Refresh review with current mappings and exclusions</button>
        <label class="block"><input type="checkbox" name="approve" value="1"> I reviewed every source invoice, verified mapping and exclusion. Approve draft creation.</label>
        <button class="bg-green-700 text-white rounded p-2">Review and approve drafts</button>
        @endif
    </form>
    @if($batch->approved_at)
    <div x-data="{busy:false,message:''}">
        <button class="bg-blue-700 text-white rounded p-2" :disabled="busy" @click="busy=true; fetch('/invoices/import/{{ $batch->id }}/process',{method:'POST',headers:{'Accept':'application/json','X-CSRF-TOKEN':'{{ csrf_token() }}'}}).then(async r=>{const d=await r.json();message=d.error||'Draft creation queued. Refresh this page to see progress.'}).catch(()=>message='Request failed. Please refresh and retry.').finally(()=>busy=false)">Create / resume drafts</button>
        <p x-text="message"></p>
    </div>
    @endif
    <h2 class="font-bold">Draft and FBR receipt tracking</h2>
    <p class="text-sm">This shows stored submission results. It makes no FBR request.</p>
    <table class="w-full text-sm"><thead><tr><th>Original invoice</th><th>Draft</th><th>Status</th><th>FBR receipt</th><th>Latest stored response</th></tr></thead><tbody>
    @foreach($receipts as $receipt)<tr class="border-b"><td>{{ $receipt->source_invoice_number }}</td><td><a class="text-blue-700" href="/invoice/{{ $receipt->invoice_id }}">{{ $receipt->invoice?->invoice_number }}</a></td><td>{{ $receipt->invoice?->status }} / {{ $receipt->invoice?->fbr_status }}</td><td>{{ $receipt->invoice?->fbr_invoice_number }}</td><td class="max-w-lg break-words">{{ $receipt->invoice?->fbrLogs()->latest('id')->first()?->response_payload }}</td></tr>@endforeach
    </tbody></table>
</div>
</x-app-layout>

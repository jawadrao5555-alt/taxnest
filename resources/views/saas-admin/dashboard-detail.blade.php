<x-admin-layout><div class="tn-admin-main tn-dashboard-detail space-y-5">
<a class="text-indigo-400" href="{{ route('saas.admin.dashboard') }}">← Admin Dashboard</a>
<h1 class="text-2xl font-bold" data-dashboard-detail="{{ $d['metric'] }}">{{ $d['title'] }} details</h1>
<p class="tn-detail-explanation">{{ $d['explanation'] }}</p>
@if($errors->any())<p role="alert">{{ $errors->first() }}</p>@endif
<form class="tn-dashboard-detail-filters grid gap-3 sm:grid-cols-3 lg:grid-cols-5" method="GET">
@foreach(['q'=>'Search name / invoice / ID','company_id'=>'Company ID','from'=>'From date','to'=>'To date','min'=>'Minimum amount','max'=>'Maximum amount'] as $key=>$label)
@continue(in_array($key,['min','max']) && !$d['amount'])
<label class="text-sm">{{ $label }}<input class="block w-full bg-gray-900 border-gray-700 rounded" name="{{ $key }}" type="{{ in_array($key,['from','to'])?'date':(in_array($key,['min','max','company_id'])?'number':'search') }}" @if(in_array($key,['min','max'])) step="0.01" @endif value="{{ $filters[$key] ?? '' }}"></label>@endforeach
<label>{{ $d['status_label'] }}<select class="block w-full bg-gray-900 rounded" name="status"><option value="">All</option>@foreach($statuses as $state)<option value="{{ $state }}" @selected((string)($filters['status']??'')===(string)$state)>{{ $d['status']==='active' ? ($state?'Active':'Inactive') : $state }}</option>@endforeach</select></label>
@if(!$d['fixed_product'])<label>Product<select class="block w-full bg-gray-900 rounded" name="product"><option value="">All</option>@foreach(['di'=>'DI','pos'=>'PRA POS','fbrpos'=>'FBR POS','health'=>'Health','erps'=>'ERPs'] as $key=>$label)<option value="{{ $key }}" @selected(($filters['product']??'')===$key)>{{ $label }}</option>@endforeach</select></label>@else<p>Product: <strong>{{ ['di'=>'DI','pos'=>'PRA POS','fbrpos'=>'FBR POS'][$d['fixed_product']] }}</strong></p>@endif
@if(in_array($d['metric'],['pos_transactions','fbrpos_transactions']))
@foreach(['mode'=>'Saved invoice mode','fiscal_status'=>($d['metric']==='pos_transactions'?'Saved PRA status':'Saved FBR status')] as $key=>$label)
<label>{{ $label }}<select class="block w-full bg-gray-900 rounded" name="{{ $key }}" aria-label="{{ $label }}"><option value="">All (including not recorded)</option>@foreach($filterOptions[$key] as $value)<option value="{{ $value }}" @selected(($filters[$key]??'')===$value)>{{ $value }}</option>@endforeach</select></label>
@endforeach
@endif
@if($d['metric']==='di_invoices')<label>DI environment<select class="block w-full bg-gray-900 rounded" name="environment" aria-label="DI environment"><option value="">All / other / not recorded</option>@foreach(['production'=>'Production','sandbox'=>'Sandbox'] as $key=>$label)<option value="{{ $key }}" @selected(($filters['environment']??'')===$key)>{{ $label }}</option>@endforeach</select></label>@endif
<label>Sort<select class="block w-full bg-gray-900 rounded" name="sort">@foreach(['newest'=>'Newest','oldest'=>'Oldest','amount_high'=>'Amount high','amount_low'=>'Amount low'] as $key=>$label)<option value="{{ $key }}" @selected(($filters['sort']??'newest')===$key)>{{ $label }}</option>@endforeach</select></label>
<label>Rows<select class="block w-full bg-gray-900 rounded" name="per_page">@foreach([25,50,100] as $n)<option @selected(($filters['per_page']??25)==$n)>{{ $n }}</option>@endforeach</select></label>
<button class="tn-detail-primary">Apply filters</button><a class="tn-detail-secondary" href="{{ route('saas.admin.dashboard.detail',$d['metric']) }}">Reset</a>
<button name="export" value="csv" class="tn-detail-secondary">Export filtered CSV</button>
</form>
<div class="flex flex-wrap gap-5"><p><strong>{{ number_format($count) }}</strong> matching records</p>@if($revenue!==null)<p><strong>PKR {{ number_format($revenue,2) }}</strong> {{ $d['metric']==='active_subscriptions'?'subscription prices':($d['metric']==='pending_payment_proofs'?'pending payment amount':'revenue') }}</p>@endif</div>
<p class="tn-detail-selection" role="status">Currently showing: <strong>{{ $d['title'] }}</strong>@if(isset($filters['status']) && $filters['status'] !== '') — Status: <strong>{{ $d['status']==='active' ? ($filters['status'] ? 'Active' : 'Inactive') : $filters['status'] }}</strong>@endif @if(!empty($filters['product'])) — Product: <strong>{{ ['di'=>'DI','pos'=>'PRA POS','fbrpos'=>'FBR POS','health'=>'Health','erps'=>'ERPs'][$filters['product']] }}</strong>@endif</p>
<div class="tn-detail-statuses">@if(isset($filters['status']) && $filters['status'] !== '')<a class="tn-detail-secondary" href="{{ request()->fullUrlWithQuery(['status'=>null,'page'=>null,'export'=>null]) }}">Clear status</a>@endif @foreach($breakdown as $group)<a href="{{ request()->fullUrlWithQuery(['status'=>$group->state,'page'=>null,'export'=>null]) }}" class="tn-detail-status" data-selected="{{ isset($filters['status']) && (string)$filters['status']===(string)$group->state ? 'true' : 'false' }}" @if(isset($filters['status']) && (string)$filters['status']===(string)$group->state) aria-current="true" @endif>{{ $d['status']==='active' ? ($group->state?'Active':'Inactive') : $group->state }}: {{ number_format($group->records) }}</a>@endforeach</div>
<div class="tn-dashboard-detail-scroll overflow-x-auto"><table class="w-full text-sm"><thead><tr>@foreach(array_merge(['ID','Company / product','Reference',$d['status_label'],'Amount','Created'],$d['is_invoice']?[($d['metric']==='di_invoices'?'FBR invoice number / environment':'Fiscal number / saved mode')]:($d['metric']==='active_subscriptions'?['Subscription dates']:[]),['Open']) as $label)<th class="p-3 text-left">{{ $label }}</th>@endforeach</tr></thead><tbody>
@forelse($rows as $row)<tr class="border-t border-gray-800"><td class="p-3">{{ $row['id'] }}</td><td class="p-3">{{ $row['company'] }}<br>{{ $row['product'] }} — Company ID: {{ $row['company_id'] }}</td><td class="p-3">@if(in_array($d['metric'],['di_invoices','pos_transactions','fbrpos_transactions']) && $row['company_id'])
<button type="button" class="tn-invoice-preview-link" data-invoice-preview="{{ route('saas.admin.invoice.document',['product'=>$row['product'],'company'=>$row['company_id'],'record'=>$row['id'],'action'=>'preview']) }}" data-invoice-download="{{ route('saas.admin.invoice.document',['product'=>$row['product'],'company'=>$row['company_id'],'record'=>$row['id'],'action'=>'download']) }}" data-invoice-label="{{ $d['title'] }} — {{ $row['company'] }} — {{ $row['reference'] }}">{{ $row['reference'] }} <span class="tn-preview-hint">Preview</span></button>
@else {{ $row['reference'] }} @endif</td><td class="p-3">{{ $row['status'] }}@if($d['is_invoice'])<br>Saved status: {{ $row['fiscal_status'] ?: 'Not recorded' }}@endif</td><td class="p-3">{{ $row['amount']===null?'—':number_format((float)$row['amount'],2) }}</td><td class="p-3">{{ $row['created_at'] }}</td>@if($d['is_invoice'])<td class="p-3">{{ $row['fiscal_number'] ?: 'No fiscal number recorded' }}<br>{{ $d['metric']==='di_invoices'?$row['environment']:($row['mode'] ?: 'Mode not recorded') }}</td>@elseif($d['metric']==='active_subscriptions')<td class="p-3">{{ $row['subscription_start'] }} — {{ $row['subscription_end'] }}</td>@endif<td class="p-3">@if($row['company_id'])<a class="text-indigo-400" href="{{ route('saas.admin.companies.show',$row['company_id']) }}">Company details</a>@endif</td></tr>
@empty<tr><td colspan="{{ $d['column_count'] }}" class="p-5">No records match these filters.</td></tr>@endforelse
</tbody></table></div>{{ $records->links() }}
<dialog id="tn-invoice-preview" aria-labelledby="tn-invoice-preview-title">
<div class="tn-preview-toolbar"><div><h2 id="tn-invoice-preview-title">Invoice preview</h2><p id="tn-invoice-preview-label"></p></div><button type="button" data-preview-close aria-label="Close invoice preview">Close</button></div>
<p role="status" id="tn-invoice-preview-status">Loading invoice…</p>
<iframe title="Invoice document preview" id="tn-invoice-preview-frame" hidden></iframe>
<div class="tn-preview-toolbar"><button type="button" data-preview-print disabled>Print invoice</button><a data-preview-download hidden>Download PDF</a></div>
</dialog>
<script>
(() => {
    const dialog = document.getElementById('tn-invoice-preview');
    const frame = document.getElementById('tn-invoice-preview-frame');
    const status = document.getElementById('tn-invoice-preview-status');
    const download = dialog.querySelector('[data-preview-download]');
    const print = dialog.querySelector('[data-preview-print]');
    let controller, opener;
    const clear = () => {
        controller?.abort(); controller = null;
        frame.onload = null; frame.hidden = true; frame.removeAttribute('src'); print.disabled = true;
        download.hidden = true; download.removeAttribute('href');
    };
    dialog.addEventListener('close', () => { clear(); opener?.focus(); });
    dialog.querySelector('[data-preview-close]').addEventListener('click', () => dialog.close());
    print.addEventListener('click', () => { frame.contentWindow.focus(); frame.contentWindow.print(); });
    for (const link of document.querySelectorAll('[data-invoice-preview]')) link.addEventListener('click', async () => {
        clear(); opener = link;
        document.getElementById('tn-invoice-preview-label').textContent = link.dataset.invoiceLabel;
        status.textContent = 'Loading invoice…'; dialog.showModal();
        const request = new AbortController(); controller = request;
        const timeout = setTimeout(() => request.abort(), 60000);
        try {
            const previewUrl = new URL(link.dataset.invoicePreview, window.location.href);
            if (previewUrl.origin !== window.location.origin) throw new Error('Invalid invoice preview origin');
            const response = await fetch(previewUrl.href, {signal:request.signal,credentials:'same-origin',headers:{Accept:'application/pdf'}});
            if (!response.ok || !response.headers.get('Content-Type')?.includes('application/pdf')) throw new Error('Preview unavailable');
            const blob = await response.blob();
            const signature = new Uint8Array(await blob.slice(0, 5).arrayBuffer());
            if (String.fromCharCode(...signature) !== '%PDF-') throw new Error('Invalid invoice document');
            if (!dialog.open || controller !== request) return;
            // Keep the validated document on its authenticated same-origin URL.
            // A blob iframe is blocked by the production default-src policy.
            frame.hidden = false;
            download.href = link.dataset.invoiceDownload; download.hidden = false;
            frame.onload = () => { if(dialog.open && controller === request) print.disabled = false; };
            frame.src = previewUrl.href;
            status.textContent = 'Read-only invoice preview. Download PDF or print using the preview controls.';
        } catch (error) {
            if (dialog.open && controller === request) status.textContent = 'Invoice preview could not load. Close and try again.';
        } finally { clearTimeout(timeout); }
    });
})();
</script>
</div></x-admin-layout>

<x-admin-layout><div class="tn-admin-main space-y-5">
<a class="text-indigo-400" href="{{ route('saas.admin.dashboard') }}">← Admin Dashboard</a>
<h1 class="text-2xl font-bold" data-dashboard-detail="{{ $d['metric'] }}">{{ $d['title'] }} details</h1>
<p>All-time totals appear when no filters are selected. Dates use record creation time. DI revenue includes locked invoices; PRA totals include completed transactions; FBR totals include all invoice modes and statuses, matching the dashboard.</p>
@if($errors->any())<p role="alert">{{ $errors->first() }}</p>@endif
<form class="grid gap-3 sm:grid-cols-3 lg:grid-cols-5" method="GET">
@foreach(['q'=>'Search name / invoice / ID','company_id'=>'Company ID','from'=>'From date','to'=>'To date','min'=>'Minimum amount','max'=>'Maximum amount'] as $key=>$label)
<label class="text-sm">{{ $label }}<input class="block w-full bg-gray-900 border-gray-700 rounded" name="{{ $key }}" type="{{ in_array($key,['from','to'])?'date':(in_array($key,['min','max','company_id'])?'number':'search') }}" @if(in_array($key,['min','max'])) step="0.01" @endif value="{{ $filters[$key] ?? '' }}"></label>@endforeach
<label>Status / role<select class="block w-full bg-gray-900 rounded" name="status"><option value="">All</option>@foreach($statuses as $state)<option value="{{ $state }}" @selected((string)($filters['status']??'')===(string)$state)>{{ $d['status']==='active' ? ($state?'Active':'Inactive') : $state }}</option>@endforeach</select></label>
<label>Product<select class="block w-full bg-gray-900 rounded" name="product"><option value="">All</option>@foreach(['di'=>'DI','pos'=>'PRA POS','fbrpos'=>'FBR POS','health'=>'Health','erps'=>'ERPs'] as $key=>$label)<option value="{{ $key }}" @selected(($filters['product']??'')===$key)>{{ $label }}</option>@endforeach</select></label>
@if(in_array($d['metric'],['pos_transactions','fbrpos_transactions']))
<label>Invoice mode<input class="block w-full bg-gray-900 rounded" name="mode" value="{{ $filters['mode']??'' }}" placeholder="e.g. local / fbr / pra"></label>
<label>Fiscal status<input class="block w-full bg-gray-900 rounded" name="fiscal_status" value="{{ $filters['fiscal_status']??'' }}" placeholder="e.g. submitted / pending"></label>@endif
<label>Sort<select class="block w-full bg-gray-900 rounded" name="sort">@foreach(['newest'=>'Newest','oldest'=>'Oldest','amount_high'=>'Amount high','amount_low'=>'Amount low'] as $key=>$label)<option value="{{ $key }}" @selected(($filters['sort']??'newest')===$key)>{{ $label }}</option>@endforeach</select></label>
<label>Rows<select class="block w-full bg-gray-900 rounded" name="per_page">@foreach([25,50,100] as $n)<option @selected(($filters['per_page']??25)==$n)>{{ $n }}</option>@endforeach</select></label>
<button class="bg-indigo-600 rounded p-2">Apply filters</button><a class="p-2" href="{{ route('saas.admin.dashboard.detail',$d['metric']) }}">Reset</a>
<button name="export" value="csv" class="bg-gray-700 rounded p-2">Export filtered CSV</button>
</form>
<div class="flex flex-wrap gap-5"><p><strong>{{ number_format($count) }}</strong> matching records</p>@if($revenue!==null)<p><strong>PKR {{ number_format($revenue,2) }}</strong> {{ $d['metric']==='active_subscriptions'?'subscription prices':($d['metric']==='pending_payment_proofs'?'pending payment amount':'revenue') }}</p>@endif</div>
<div class="flex flex-wrap gap-3">@foreach($breakdown as $group)<a href="{{ request()->fullUrlWithQuery(['status'=>$group->state,'page'=>null,'export'=>null]) }}" class="border rounded p-2">{{ $d['status']==='active' ? ($group->state?'Active':'Inactive') : $group->state }}: {{ number_format($group->records) }}</a>@endforeach</div>
<div class="overflow-x-auto"><table class="w-full text-sm"><thead><tr>@foreach(['ID','Company / product','Reference','State / fiscal status','Amount','Created','Fiscal number / mode','Subscription dates','Open'] as $label)<th class="p-3 text-left">{{ $label }}</th>@endforeach</tr></thead><tbody>
@forelse($rows as $row)<tr class="border-t border-gray-800"><td class="p-3">{{ $row['id'] }}</td><td class="p-3">{{ $row['company'] }}<br>{{ $row['product'] }} #{{ $row['company_id'] }}</td><td class="p-3">{{ $row['reference'] }}</td><td class="p-3">{{ $row['status'] }}<br>{{ $row['fiscal_status'] }}</td><td class="p-3">{{ $row['amount']===null?'—':number_format((float)$row['amount'],2) }}</td><td class="p-3">{{ $row['created_at'] }}</td><td class="p-3">{{ $row['fiscal_number'] }}<br>{{ $row['mode'] }}</td><td class="p-3">{{ $row['subscription_start'] }} — {{ $row['subscription_end'] }}</td><td class="p-3">@if($row['company_id'])<a class="text-indigo-400" href="{{ route('saas.admin.companies.show',$row['company_id']) }}">Company details</a>@endif</td></tr>
@empty<tr><td colspan="9" class="p-5">No records match these filters.</td></tr>@endforelse
</tbody></table></div>{{ $records->links() }}
</div></x-admin-layout>

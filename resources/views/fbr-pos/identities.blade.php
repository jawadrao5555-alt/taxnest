<x-fbr-pos-layout>
<div class="max-w-4xl mx-auto p-5 space-y-5">
<a href="{{ route('fbrpos.settings') }}">← FBR settings</a>
<h1 class="text-2xl font-bold">Branch / terminal FBR identity</h1>
<p>Map the registered FBR POS ID to the PC running that terminal’s IMS. Unmapped counters keep the company setting. Saved invoices retain their original POS ID and agent; changing a mapping affects new invoices.</p>
@if(session('success'))<p role="status">{{ session('success') }}</p>@endif
@if($errors->any())<p role="alert">{{ $errors->first() }}</p>@endif
<form method="POST" class="grid gap-4">@csrf
<label>Branch<select name="branch_id" class="block w-full text-black"><option value="0">No branch</option>@foreach($branches as $branch)<option value="{{ $branch->id }}">{{ $branch->name }}</option>@endforeach</select></label>
<label>Terminal<select name="terminal_id" class="block w-full text-black"><option value="0">All terminals in the selected branch</option>@foreach($terminals as $terminal)<option value="{{ $terminal->id }}">{{ $terminal->terminal_name }} (branch {{ $terminal->branch_id ?: 'none' }})</option>@endforeach</select></label>
<label>FBR POS ID<input name="pos_id" required pattern="[1-9][0-9]{0,9}" class="block w-full text-black"></label>
<label>IMS agent<select name="device_uid" required class="block w-full text-black"><option value="">Select a registered PC</option>@foreach($devices as $device)<option value="{{ $device->device_uid }}">{{ $device->label() }} — {{ $device->isOnline() ? 'online' : 'offline' }}</option>@endforeach</select></label>
<button class="bg-blue-600 text-white rounded p-3">Save mapping</button>
</form>
<div class="overflow-x-auto"><table class="w-full"><thead><tr><th>Branch</th><th>Terminal</th><th>POS ID</th><th>Agent</th></tr></thead><tbody>@forelse($mappings as $map)<tr><td>{{ $branches->firstWhere('id',$map->branch_id)?->name ?? 'No branch' }}</td><td>{{ $terminals->firstWhere('id',$map->terminal_id)?->terminal_name ?? 'All' }}</td><td>{{ $map->pos_id }}</td><td>{{ $devices->firstWhere('device_uid',$map->device_uid)?->label() ?? $map->device_uid }}</td></tr>@empty<tr><td colspan="4">No mappings: company POS ID remains in use.</td></tr>@endforelse</tbody></table></div>
<p>If an original PC is unavailable after submission, verify the invoice through the recovery screen before retrying. Do not submit a held invoice from another POS ID.</p>
</div></x-fbr-pos-layout>

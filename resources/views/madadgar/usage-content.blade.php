@php($t = fn($key) => \Illuminate\Support\Facades\Lang::get('ai_usage.'.$key, [], $admin ? 'en' : app()->getLocale()))
<div class="p-4 max-w-5xl mx-auto space-y-5">
<h1 class="text-2xl font-bold">{{ $t('title') }}</h1>
<p>{{ $company->name }} · {{ $t('privacy') }}</p>
<form method="get"><label for="usage-month">{{ $t('month') }}</label> <input id="usage-month" type="month" name="month" value="{{ $month }}" required><button type="submit">{{ $t('view') }}</button></form>
<dl class="grid grid-cols-2 gap-3"><dt>{{ $t('events') }}</dt><dd>{{ $summary->events }}</dd><dt>{{ $t('calls') }}</dt><dd>{{ $summary->provider_reservations ?? 0 }}</dd><dt>{{ $t('blocked') }}</dt><dd>{{ $summary->blocked ?? 0 }}</dd><dt>{{ $t('errors') }}</dt><dd>{{ $summary->errors ?? 0 }}</dd><dt>{{ $t('tokens') }}</dt><dd>{{ $summary->input_tokens ?? 'Unknown' }} / {{ $summary->output_tokens ?? 'Unknown' }}</dd><dt>{{ $t('latency') }}</dt><dd>{{ $summary->latency_ms===null ? 'Unknown' : number_format($summary->latency_ms).' ms' }}</dd><dt>{{ $t('known') }}</dt><dd>{{ $summary->known_cost===null ? 'Unknown' : $summary->known_cost }} @if($summary->unknown_cost_events) (incomplete: {{ $summary->unknown_cost_events }} unknown events) @endif</dd><dt>{{ $t('committed') }}</dt><dd>{{ $summary->committed_allowance ?? 'Unknown' }}</dd><dt>{{ $t('cap') }}</dt><dd>{{ $policy?->monthly_budget ?? 'Not configured' }}</dd></dl>
<p>{{ $t('note') }}</p>
@if($admin)
<form method="post" action="{{ route('admin.company-ai-budget',$company->id) }}" class="space-y-3">@csrf
<h2 class="text-xl">Explicit rates and budget</h2><p>Model: gpt-4o-mini. Currency: USD. Leave blank for unknown rates / no configured cap. Zero is an explicit value. This does not enable AI or change credentials.</p>
@foreach(['input_per_million'=>'USD per million input tokens','output_per_million'=>'USD per million output tokens','monthly_budget'=>'Monthly cap in USD'] as $field=>$label)
<div><label for="{{ $field }}">{{ $label }}</label><input id="{{ $field }}" name="{{ $field }}" type="number" min="0" step="0.000001" value="{{ old($field,$policy?->$field) }}">@error($field)<p role="alert">{{ $message }}</p>@enderror</div>
@endforeach
<button type="submit">Save explicit rates / budget</button></form>
@endif
<h2 class="text-xl">{{ $t('latest') }}</h2><div class="overflow-x-auto"><table><thead><tr><th>{{ $t('time') }}</th><th>{{ $t('source') }}</th><th>{{ $t('state') }}</th><th>{{ $t('ms') }}</th><th>{{ $t('estimate') }}</th><th>{{ $t('reserved') }}</th><th>{{ $t('error') }}</th></tr></thead><tbody>@forelse($events as $event)<tr><td>{{ $event->created_at }}</td><td>{{ $event->source }}</td><td>{{ $event->state }}</td><td>{{ $event->latency_ms ?? 'Unknown' }}</td><td>{{ $event->estimated_cost ?? 'Unknown' }}</td><td>{{ $event->reserved_cost ?? 'Unknown' }}</td><td>{{ $event->error_code ?? 'None' }}</td></tr>@empty<tr><td colspan="7">{{ $t('empty') }}</td></tr>@endforelse</tbody></table></div>
<h2 class="text-xl">{{ $t('escalations') }}</h2><ul>@forelse($escalations as $item)<li>Ref #{{ $item->id }} · {{ $item->status }} · {{ $item->created_at }} (updated {{ $item->updated_at }})</li>@empty<li>{{ $t('none') }}</li>@endforelse</ul>
</div>

<x-admin-layout>
<section id="platform-expenses" class="p-4 sm:p-6 max-w-6xl mx-auto">
<style>#platform-expenses input,#platform-expenses textarea{display:block;background:#fff;color:#111827;padding:10px;border:1px solid #64748b;border-radius:6px;max-width:100%;width:100%;min-height:44px}#platform-expenses button,#platform-expenses .expense-link{display:inline-flex;align-items:center;justify-content:center;background:#075985;color:white;padding:12px 16px;min-height:44px;border-radius:6px}#platform-expenses label{display:block;margin:8px 0}#platform-expenses th,#platform-expenses td{padding:10px;text-align:left;vertical-align:top}#platform-expenses summary{cursor:pointer;min-height:44px}#platform-expenses [hidden]{display:none!important}</style>
<h1 class="text-2xl font-bold">Platform expense records</h1>
<p class="my-3">TaxNest platform record keeping only. Recording or reversing an entry does not send money, refund a payment, or change tenant sales or Hospital accounts. PKR only. Dates use {{ config('app.timezone') }}; the entered record date is distinct from the saved audit timestamp.</p>
<p class="my-3">Append-only: correct a saved entry with a full reversal, then record its replacement. Original records and actor history remain visible.</p>
<div id="expense-result" role="status" aria-live="polite"></div>
<button type="button" id="expense-retry" hidden>Retry saved request</button>
<a class="expense-link my-3" href="{{ route('saas.admin.platform-expenses') }}">Refresh ledger</a>
<form id="expense-record" data-expense-write method="post" action="{{ route('saas.admin.platform-expenses.store') }}" class="my-4 p-4 border rounded">
@csrf<input type="hidden" name="request_id" value="{{ (string) \Illuminate\Support\Str::uuid() }}">
<h2 class="text-xl">Record an expense</h2>
<div class="grid md:grid-cols-2 gap-3">
<label>Expense date<input type="date" name="entry_date" required value="{{ now()->toDateString() }}"></label>
<label>Category<input name="category" required maxlength="80" pattern="[a-z][a-z0-9_\-]{0,79}" placeholder="e.g. hosting"></label>
<label>Amount (PKR)<input name="amount" inputmode="decimal" required pattern="(?:0|[1-9][0-9]{0,9})(?:\.[0-9]{1,2})?" placeholder="0.00"></label>
<label>Reference<input name="reference" required maxlength="160"></label>
</div><label>Notes (optional, no credentials or bank details)<textarea name="notes" maxlength="2000"></textarea></label>
<button type="submit">Record expense only</button>
</form>
<form method="get" class="grid md:grid-cols-2 gap-3 my-4" id="expense-filters">
<label>From date<input type="date" name="from" value="{{ request('from') }}"></label><label>To date<input type="date" name="to" value="{{ request('to') }}"></label>
<label>Exact category<input name="category" value="{{ request('category') }}" maxlength="80"></label><label>Exact reference<input name="reference" value="{{ request('reference') }}" maxlength="160"></label>
<button type="submit">Filter records</button>
</form>
<div id="expense-totals" class="my-4">Filtered recorded expenses: PKR {{ $totals['expenses'] }} · Reversals: PKR {{ $totals['reversals'] }} · Net recorded expenses: PKR {{ $totals['net'] }}. These are entry-date totals, not cash or bank balances.</div>
<div id="expense-history" class="overflow-x-auto"><table class="w-full"><thead><tr><th>ID / date</th><th>Category / reference</th><th>Recorded amount</th><th>Actor / audit</th><th>Correction</th></tr></thead><tbody>
@foreach($entries as $entry)
<tr data-expense-id="{{ $entry->id }}"><td>#{{ $entry->id }}<br>{{ $entry->entry_date }}<br>{{ $entry->kind }}</td><td>{{ $entry->category }}<br>{{ $entry->reference }}<br><span class="text-sm">{{ $entry->notes }}</span></td><td>PKR {{ \App\Services\PlatformExpenseLedger::money($entry->amount_paisa) }}</td><td>{{ $entry->actor_name }} (#{{ $entry->admin_id }})<br>{{ $entry->created_at }}<br><small>Request {{ $entry->request_id }}</small></td><td>
@if($entry->kind==='reversal') Reverses #{{ $entry->reverses_id }}
@elseif(in_array($entry->id,$reversed)) Reversed (original retained)
@else
<details><summary>Record full reversal</summary><form data-expense-write method="post" action="{{ route('saas.admin.platform-expenses.reverse',$entry->id) }}">@csrf
<input type="hidden" name="request_id" value="{{ (string) \Illuminate\Support\Str::uuid() }}">
<label>Reversal date<input type="date" name="entry_date" required value="{{ now()->toDateString() }}"></label>
<label>Reversal reference<input name="reference" required maxlength="160"></label>
<label>Reason<textarea name="notes" maxlength="2000"></textarea></label><button type="submit">Record reversal only</button>
</form></details>
@endif</td></tr>
@endforeach
</tbody></table></div>{{ $entries->links() }}
</section>
<script>
(()=>{const key='platform-expense-pending-'+@json($actor->id),result=document.querySelector('#expense-result'),retry=document.querySelector('#expense-retry');let busy=false;
function pending(){try{return JSON.parse(sessionStorage.getItem(key)||'null')}catch{return null}}
function showPending(){const p=pending();retry.hidden=!p;if(p)result.textContent='A saved request needs confirmation. Retry it before recording another expense. It keeps the same request ID.';}
async function send(p){if(busy)return;const url=new URL(p.action,location.origin);if(url.origin!==location.origin||!/^\/admin\/platform-expenses(?:\/[0-9]+\/reverse)?$/.test(url.pathname)){result.textContent='Invalid saved request destination. No request sent.';return;}busy=true;try{const r=await fetch(p.action,{method:'POST',headers:{'Content-Type':'application/json','Accept':'application/json','X-CSRF-TOKEN':document.querySelector('#expense-record [name=_token]').value},body:JSON.stringify(p.data)});const data=await r.json();if(!r.ok){if(r.status===422){sessionStorage.removeItem(key);retry.hidden=true;}result.textContent=data.message||'Request rejected. Original records are unchanged.';return}sessionStorage.removeItem(key);retry.hidden=true;result.textContent='Recorded '+data.kind+' #'+data.id+' · PKR '+data.amount+'. No money was sent. Refresh ledger to view it.';document.querySelector('#expense-record').reset();document.querySelectorAll('[name=request_id]').forEach(el=>el.value=crypto.randomUUID());}catch{result.textContent='Response unavailable. Retry saved request to recover the same record, not a new expense.';}finally{busy=false;retry.hidden=!pending();}}
document.querySelectorAll('[data-expense-write]').forEach(form=>form.addEventListener('submit',e=>{e.preventDefault();if(busy)return;if(pending()){showPending();return;}const data=Object.fromEntries(new FormData(form));delete data._token;const p={action:form.getAttribute('action'),data};try{sessionStorage.setItem(key,JSON.stringify(p));if(!pending())throw Error();}catch{result.textContent='Safe retry storage is unavailable. No request was sent.';return}send(p);}));retry.addEventListener('click',()=>{const p=pending();if(p)send(p)});showPending();})();
</script>
</x-admin-layout>

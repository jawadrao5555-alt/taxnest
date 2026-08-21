@php $title = 'Local Bills'; @endphp
<x-dynamic-component :component="'pos.local.layout'">
    <div class="grid grid-cols-2 sm:grid-cols-4 gap-4 mb-6">
        <div class="bg-slate-900/60 border border-slate-800 rounded-xl p-4">
            <div class="text-[11px] uppercase tracking-wider text-slate-400">Today's Local Bills</div>
            <div class="text-2xl font-bold accent mt-1">{{ number_format($stats['today']) }}</div>
        </div>
        <div class="bg-slate-900/60 border border-slate-800 rounded-xl p-4">
            <div class="text-[11px] uppercase tracking-wider text-slate-400">Today's Amount</div>
            <div class="text-2xl font-bold text-white mt-1">Rs {{ number_format($stats['today_sum'], 2) }}</div>
        </div>
        <div class="bg-slate-900/60 border border-slate-800 rounded-xl p-4">
            <div class="text-[11px] uppercase tracking-wider text-slate-400">Total Bills (filtered)</div>
            <div class="text-2xl font-bold accent mt-1">{{ number_format($stats['total']) }}</div>
        </div>
        <div class="bg-slate-900/60 border border-slate-800 rounded-xl p-4">
            <div class="text-[11px] uppercase tracking-wider text-slate-400">Total Amount (filtered)</div>
            <div class="text-2xl font-bold text-white mt-1">Rs {{ number_format($stats['sum'], 2) }}</div>
        </div>
    </div>

    <form method="GET" class="bg-slate-900/60 border border-slate-800 rounded-xl p-4 mb-6 grid grid-cols-2 sm:grid-cols-5 gap-3">
        <input type="text" name="q" value="{{ request('q') }}" placeholder="Search invoice / customer / phone" class="col-span-2 sm:col-span-2 bg-slate-800 border border-slate-700 rounded-lg px-3 py-2 text-sm text-white placeholder-slate-500 focus:border-violet-500 focus:ring-1 focus:ring-violet-500">
        <input type="date" name="from" value="{{ request('from') }}" class="bg-slate-800 border border-slate-700 rounded-lg px-3 py-2 text-sm text-white">
        <input type="date" name="to" value="{{ request('to') }}" class="bg-slate-800 border border-slate-700 rounded-lg px-3 py-2 text-sm text-white">
        <select name="cashier" class="bg-slate-800 border border-slate-700 rounded-lg px-3 py-2 text-sm text-white">
            <option value="">All Cashiers</option>
            @foreach($cashiers as $c)
                <option value="{{ $c->id }}" @selected(request('cashier') == $c->id)>{{ $c->name }}</option>
            @endforeach
        </select>
        <div class="col-span-2 sm:col-span-5 flex gap-2 justify-end">
            <a href="{{ route('pos.local.index') }}" class="px-4 py-2 text-xs rounded-lg bg-slate-800 text-slate-300 hover:bg-slate-700">Clear</a>
            <button type="submit" class="px-5 py-2 text-xs font-semibold rounded-lg accent-bg text-white hover:opacity-90">Filter</button>
            <a href="{{ route('pos.local.export', request()->query()) }}" class="px-4 py-2 text-xs rounded-lg bg-emerald-900/40 text-emerald-300 border border-emerald-700/40 hover:bg-emerald-900/60">⬇ CSV</a>
        </div>
    </form>

    {{-- ═══ Viewer Accounts (Task 665) — OWNER ONLY ═══════════════════════════
         Sirf asal owner (role=company_admin) khud read-only viewer logins bana
         aur manage kar sakta hai. pos_manager ko ye section bilkul nazar nahi
         aata (aur endpoints bhi 403 dete hain — guard controller mein hai).
         Passwords SIRF yahan dikhte hain; /pos/team local_viewer ko filter
         karta hai is liye wahan leak nahi hote. --}}
    @if($canManageViewers)
    <div class="bg-slate-900/60 border border-violet-800/40 rounded-xl p-5 mb-6"
         x-data="{ showCreate: {{ $errors->any() && old('viewer_form') ? 'true' : 'false' }} }">
        <div class="flex items-start justify-between gap-3 flex-wrap mb-3">
            <div>
                <h3 class="text-sm font-semibold text-white flex items-center gap-2"><span class="accent">🔐</span> Viewer Accounts</h3>
                <p class="text-xs text-slate-400 mt-0.5 max-w-2xl">
                    Read-only logins for this portal — they see local bills only, nothing else in the POS.
                    They sign in at <code class="accent">/pos/login</code> with the email and password you set here.
                    Only you (the owner) can see or manage this section.
                </p>
            </div>
            @if($viewers->count() < $viewerCap)
            <button type="button" @click="showCreate = !showCreate" class="px-3 py-1.5 text-xs font-semibold rounded-lg accent-bg text-white hover:opacity-90 shrink-0">+ New Viewer</button>
            @else
            <span class="text-[11px] text-slate-500 shrink-0">Limit reached ({{ $viewerCap }} accounts)</span>
            @endif
        </div>

        @if($errors->any())
        <div class="bg-red-900/30 border border-red-700/50 text-red-200 px-3 py-2 rounded-lg text-xs mb-3">
            <ul class="list-disc list-inside space-y-0.5">
                @foreach($errors->all() as $err)<li>{{ $err }}</li>@endforeach
            </ul>
        </div>
        @endif

        @if($viewers->count() < $viewerCap)
        <div x-show="showCreate" x-cloak class="bg-slate-950/60 border border-violet-800/30 rounded-lg p-4 mb-3">
            <form method="POST" action="{{ route('pos.local.viewers.store') }}" class="grid grid-cols-1 sm:grid-cols-3 gap-3">
                @csrf
                <input type="hidden" name="viewer_form" value="1">
                {{-- Anti-autofill guard set (pos-sale-screen rule): warna owner ka
                     apna login email in fields mein ghus jata hai. --}}
                <input type="text" name="name" value="{{ old('name') }}" required maxlength="100" placeholder="Full name"
                       autocomplete="one-time-code" data-lpignore="true" data-form-type="other" data-1p-ignore
                       class="bg-slate-800 border border-slate-700 rounded-lg px-3 py-2 text-sm text-white placeholder-slate-500 focus:border-violet-500 focus:ring-1 focus:ring-violet-500">
                <input type="email" name="email" value="{{ old('email') }}" required maxlength="190" placeholder="Login email"
                       autocomplete="one-time-code" data-lpignore="true" data-form-type="other" data-1p-ignore
                       class="bg-slate-800 border border-slate-700 rounded-lg px-3 py-2 text-sm text-white placeholder-slate-500 focus:border-violet-500 focus:ring-1 focus:ring-violet-500">
                <input type="text" name="password" required minlength="8" placeholder="Password (min 8)"
                       autocomplete="one-time-code" data-lpignore="true" data-form-type="other" data-1p-ignore
                       class="bg-slate-800 border border-slate-700 rounded-lg px-3 py-2 text-sm text-white placeholder-slate-500 focus:border-violet-500 focus:ring-1 focus:ring-violet-500">
                <div class="sm:col-span-3 flex justify-end gap-2">
                    <button type="button" @click="showCreate = false" class="px-3 py-2 text-xs rounded-lg bg-slate-800 text-slate-300 hover:bg-slate-700">Cancel</button>
                    <button type="submit" class="px-5 py-2 text-xs font-semibold rounded-lg accent-bg text-white hover:opacity-90">Create Viewer Account</button>
                </div>
            </form>
        </div>
        @endif

        @if($viewers->isEmpty())
        <p class="text-xs text-slate-500">No viewer account yet. Create one to give someone read-only access to these bills.</p>
        @else
        <div class="space-y-2">
            @foreach($viewers as $v)
            <div class="bg-slate-950/60 border border-slate-800 rounded-lg p-3" x-data="{ edit: false, showPw: false }">
                <div x-show="!edit" class="flex items-center justify-between gap-3 flex-wrap">
                    <div class="flex-1 min-w-[180px]">
                        <div class="text-sm font-medium text-white">{{ $v->name }}</div>
                        <div class="text-xs text-slate-400">{{ $v->email }}</div>
                        <div class="text-xs text-slate-500 mt-0.5 flex items-center gap-1.5">
                            <span>Password:</span>
                            @if(isset($viewerPasswords[$v->id]))
                                <span class="font-mono text-slate-300" x-text="showPw ? {{ \Illuminate\Support\Js::from($viewerPasswords[$v->id]) }} : '••••••••'"></span>
                                <button type="button" @click="showPw = !showPw" class="text-slate-400 hover:text-violet-300 transition" x-text="showPw ? 'hide' : 'show'"></button>
                            @else
                                <span class="italic">set a new password to view it</span>
                            @endif
                        </div>
                    </div>
                    <div class="flex items-center gap-2 flex-wrap">
                        <span class="text-[10px] uppercase tracking-wider px-2 py-0.5 rounded {{ $v->is_active ? 'bg-emerald-900/40 text-emerald-300' : 'bg-slate-800 text-slate-500' }}">{{ $v->is_active ? 'Active' : 'Disabled' }}</span>
                        <button type="button" @click="edit = true" class="text-xs px-2.5 py-1 rounded bg-slate-800 text-slate-300 hover:bg-slate-700">Edit</button>
                        <form method="POST" action="{{ route('pos.local.viewers.toggle', $v->id) }}" class="inline">
                            @csrf
                            <button type="submit" class="text-xs px-2.5 py-1 rounded {{ $v->is_active ? 'bg-amber-900/40 text-amber-300' : 'bg-emerald-900/40 text-emerald-300' }} hover:opacity-80">{{ $v->is_active ? 'Disable' : 'Enable' }}</button>
                        </form>
                        <form method="POST" action="{{ route('pos.local.viewers.delete', $v->id) }}" class="inline" onsubmit="return confirm('Remove this viewer account? They lose access immediately.');">
                            @csrf
                            @method('DELETE')
                            <button type="submit" class="text-xs px-2.5 py-1 rounded bg-red-900/40 text-red-300 hover:bg-red-900/60">Remove</button>
                        </form>
                    </div>
                </div>
                <form x-show="edit" x-cloak method="POST" action="{{ route('pos.local.viewers.update', $v->id) }}" class="grid grid-cols-1 sm:grid-cols-3 gap-2">
                    @csrf
                    @method('PUT')
                    <input type="hidden" name="viewer_form" value="1">
                    <input type="text" name="name" value="{{ $v->name }}" required maxlength="100"
                           autocomplete="one-time-code" data-lpignore="true" data-form-type="other" data-1p-ignore
                           class="bg-slate-800 border border-slate-700 rounded-lg px-3 py-2 text-sm text-white focus:border-violet-500 focus:ring-1 focus:ring-violet-500">
                    <input type="email" name="email" value="{{ $v->email }}" required maxlength="190"
                           autocomplete="one-time-code" data-lpignore="true" data-form-type="other" data-1p-ignore
                           class="bg-slate-800 border border-slate-700 rounded-lg px-3 py-2 text-sm text-white focus:border-violet-500 focus:ring-1 focus:ring-violet-500">
                    <input type="text" name="password" minlength="8" placeholder="New password (blank = keep)"
                           autocomplete="one-time-code" data-lpignore="true" data-form-type="other" data-1p-ignore
                           class="bg-slate-800 border border-slate-700 rounded-lg px-3 py-2 text-sm text-white placeholder-slate-500 focus:border-violet-500 focus:ring-1 focus:ring-violet-500">
                    <div class="sm:col-span-3 flex justify-end gap-2">
                        <button type="button" @click="edit = false" class="px-3 py-1.5 text-xs rounded-lg bg-slate-800 text-slate-300 hover:bg-slate-700">Cancel</button>
                        <button type="submit" class="px-4 py-1.5 text-xs font-semibold rounded-lg accent-bg text-white hover:opacity-90">Save</button>
                    </div>
                </form>
            </div>
            @endforeach
        </div>
        @endif
    </div>
    @endif

    <div class="bg-slate-900/60 border border-slate-800 rounded-xl overflow-hidden">
        <div class="overflow-x-auto">
            <table class="w-full text-sm">
                <thead class="bg-slate-900 border-b border-slate-800">
                    <tr class="text-left text-[11px] uppercase tracking-wider text-slate-400">
                        <th class="px-4 py-3">Invoice #</th>
                        <th class="px-4 py-3">Date / Time</th>
                        <th class="px-4 py-3">Cashier</th>
                        <th class="px-4 py-3">Customer</th>
                        <th class="px-4 py-3 text-center">Items</th>
                        <th class="px-4 py-3 text-right">Total</th>
                        <th class="px-4 py-3 text-center">Payment</th>
                        <th class="px-4 py-3 text-center">State</th>
                        <th class="px-4 py-3 text-right">View</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-slate-800/60">
                    @forelse($bills as $b)
                    <tr class="hover:bg-slate-800/40 transition">
                        <td class="px-4 py-3 font-mono text-xs accent">{{ $b->invoice_number }}</td>
                        <td class="px-4 py-3 text-xs text-slate-300">{{ $b->created_at?->format('d M Y, h:i A') }}</td>
                        <td class="px-4 py-3 text-xs text-slate-300">{{ $b->creator->name ?? 'N/A' }}</td>
                        <td class="px-4 py-3 text-xs text-slate-300">
                            {{-- Task 791: dine-in bill with no customer → "Dine-in", not "Walk-in" --}}
                            {{ $b->customer_name ?: ($b->order_type === 'dine_in' ? __('pos.dine_in') : __('pos.walk_in')) }}
                            @if($b->order_type && in_array($b->order_type, ['dine_in', 'takeaway', 'delivery'], true))
                                <span class="inline-flex mt-0.5 px-1.5 py-0.5 rounded text-xs font-semibold uppercase tracking-wide
                                    {{ $b->order_type === 'dine_in' ? 'bg-teal-900/40 text-teal-300' : ($b->order_type === 'delivery' ? 'bg-orange-900/40 text-orange-300' : 'bg-blue-900/40 text-blue-300') }}">
                                    {{ __('pos.ot_' . $b->order_type) }}
                                </span>
                            @endif
                        </td>
                        <td class="px-4 py-3 text-center text-xs text-slate-400">{{ $b->items->count() }}</td>
                        <td class="px-4 py-3 text-right font-semibold text-white">Rs {{ number_format($b->total_amount, 2) }}</td>
                        <td class="px-4 py-3 text-center"><span class="text-[10px] uppercase px-2 py-0.5 rounded bg-slate-800 text-slate-300">{{ $b->payment_method }}</span></td>
                        <td class="px-4 py-3 text-center">
                            @if($b->is_archived)
                                {{-- Task 507 (11 Aug 2026): "Archived" ka matlab wazeh —
                                     day-close par mehfooz bill hai, koi pending action nahi. --}}
                                <span class="text-[10px] uppercase px-2 py-0.5 rounded bg-slate-800 text-slate-400 cursor-help" title="{{ __('pos.local_archived_explain') }}">Archived ✓</span>
                            @else
                                <span class="text-[10px] uppercase px-2 py-0.5 rounded bg-violet-900/40 text-violet-300">Live</span>
                            @endif
                        </td>
                        <td class="px-4 py-3 text-right">
                            <a href="{{ route('pos.local.show', $b->id) }}" class="text-xs px-3 py-1 rounded-md bg-slate-800 text-slate-200 hover:bg-slate-700">Open</a>
                        </td>
                    </tr>
                    @empty
                    <tr><td colspan="9" class="px-4 py-10 text-center text-slate-500 text-sm">No local bills found.</td></tr>
                    @endforelse
                </tbody>
            </table>
        </div>
        @if($bills->hasPages())
        <div class="px-4 py-3 border-t border-slate-800">{{ $bills->links() }}</div>
        @endif
        @if($bills->contains(fn ($b) => $b->is_archived))
        {{-- Task 507: archived-state legend — owner ne archived bill ko pending samjha tha. --}}
        <div class="px-4 py-2.5 border-t border-slate-800 text-[11px] text-slate-500">
            ℹ <span class="uppercase text-slate-400">Archived</span> = {{ __('pos.local_archived_explain') }}
        </div>
        @endif
    </div>
</x-dynamic-component>

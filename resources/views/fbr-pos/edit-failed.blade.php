<x-fbr-pos-layout>
<div class="max-w-5xl mx-auto" x-data="{ items: {{ $transaction->items->toJson() }}, savingNow: false,
    lineGross(it){ return (parseFloat(it.quantity)||0) * (parseFloat(it.unit_price)||0); },
    lineNet(it){ const g = this.lineGross(it); const d = Math.min(parseFloat(it.item_discount)||0, g); return g - d; },
    lineTax(it){ if (it.is_tax_exempt) return 0; return this.lineNet(it) * (parseFloat(it.tax_rate)||0) / 100; },
    lineTotal(it){ return this.lineNet(it) + this.lineTax(it); },
    cartSubtotal(){ return this.items.reduce((s, it) => s + this.lineNet(it), 0); },
    cartTax(){ return this.items.reduce((s, it) => s + this.lineTax(it), 0); },
    cartTotal(){ return this.items.reduce((s, it) => s + this.lineTotal(it), 0); },
    fmt(n){ return Number(n).toLocaleString('en-PK', { minimumFractionDigits: 2, maximumFractionDigits: 2 }); }
}">

    {{-- 🔴 Header with prominent failure reason --}}
    <div class="mb-6 p-5 rounded-xl bg-gradient-to-r from-red-50 to-amber-50 dark:from-red-900/30 dark:to-amber-900/30 border-2 border-red-300 dark:border-red-700 shadow-md">
        <div class="flex items-start gap-4">
            <div class="flex-shrink-0 w-12 h-12 rounded-full bg-red-600 text-white flex items-center justify-center">
                <svg class="w-6 h-6" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 9v2m0 4h.01m-6.938 4h13.856c1.54 0 2.502-1.667 1.732-3L13.732 4c-.77-1.333-2.694-1.333-3.464 0L3.34 16c-.77 1.333.192 3 1.732 3z"/></svg>
            </div>
            <div class="flex-1 min-w-0">
                <div class="text-xs font-bold uppercase tracking-wider text-red-700 dark:text-red-300">FBR Submission Failed — Edit & Retry</div>
                <h1 class="text-2xl font-black text-slate-900 dark:text-white mt-1">{{ $transaction->invoice_number }}</h1>
                <div class="text-sm text-slate-700 dark:text-slate-300 mt-1 font-semibold">
                    {{ $transaction->created_at->format('d M Y · h:i A') }}
                    @if($transaction->customer_name) · Customer: <span class="font-bold">{{ $transaction->customer_name }}</span> @endif
                </div>
                @if($lastError)
                <div class="mt-3 p-3 rounded-lg bg-white dark:bg-slate-900 border border-red-200 dark:border-red-800">
                    <div class="text-xs font-bold uppercase tracking-wide text-red-700 dark:text-red-400 mb-1">FBR Last Error</div>
                    <div class="text-sm text-slate-800 dark:text-slate-200 font-mono break-words">{{ $lastError }}</div>
                </div>
                @endif
            </div>
        </div>
    </div>

    @if(session('error'))
    <div class="mb-4 p-4 rounded-lg bg-red-100 dark:bg-red-900/40 border border-red-400 text-red-800 dark:text-red-200 text-sm font-semibold">
        {{ session('error') }}
    </div>
    @endif

    <form method="POST" action="{{ route('fbrpos.updateAndRetry', $transaction->id) }}"
          @submit="savingNow = true">
        @csrf

        {{-- 📝 Editable items table --}}
        <div class="bg-white dark:bg-slate-900 rounded-xl border-2 border-slate-200 dark:border-slate-700 shadow-md overflow-hidden mb-5">
            <div class="px-5 py-4 border-b border-slate-200 dark:border-slate-700 bg-slate-50 dark:bg-slate-800">
                <h3 class="text-base font-black text-slate-900 dark:text-white flex items-center gap-2">
                    <svg class="w-5 h-5 text-blue-600" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M11 5H6a2 2 0 00-2 2v11a2 2 0 002 2h11a2 2 0 002-2v-5m-1.414-9.414a2 2 0 112.828 2.828L11.828 15H9v-2.828l8.586-8.586z"/></svg>
                    Edit Line Items — Fix the issue (HS code, qty, price, tax %)
                </h3>
                <p class="text-xs text-slate-600 dark:text-slate-400 mt-1 font-semibold">Original cart preserved as audit snapshot. Totals auto-recalculate.</p>
            </div>

            <div class="overflow-x-auto">
                <table class="min-w-full divide-y divide-slate-200 dark:divide-slate-700">
                    <thead class="bg-slate-50 dark:bg-slate-800">
                        <tr>
                            <th class="px-3 py-3 text-left text-xs font-bold text-slate-700 dark:text-slate-200 uppercase">Item Name</th>
                            <th class="px-3 py-3 text-left text-xs font-bold text-slate-700 dark:text-slate-200 uppercase">HS Code</th>
                            <th class="px-3 py-3 text-center text-xs font-bold text-slate-700 dark:text-slate-200 uppercase">UoM</th>
                            <th class="px-3 py-3 text-right text-xs font-bold text-slate-700 dark:text-slate-200 uppercase">Qty</th>
                            <th class="px-3 py-3 text-right text-xs font-bold text-slate-700 dark:text-slate-200 uppercase">Price</th>
                            <th class="px-3 py-3 text-right text-xs font-bold text-slate-700 dark:text-slate-200 uppercase">Tax %</th>
                            <th class="px-3 py-3 text-center text-xs font-bold text-slate-700 dark:text-slate-200 uppercase">Exempt</th>
                            <th class="px-3 py-3 text-right text-xs font-bold text-slate-700 dark:text-slate-200 uppercase">Discount</th>
                            <th class="px-3 py-3 text-right text-xs font-bold text-slate-900 dark:text-white uppercase">Line Total</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-slate-200 dark:divide-slate-700">
                        <template x-for="(item, idx) in items" :key="item.id">
                        <tr class="hover:bg-slate-50 dark:hover:bg-slate-800/50">
                            <td class="px-3 py-2">
                                <input type="hidden" :name="'items[' + idx + '][id]'" :value="item.id">
                                <input type="text" :name="'items[' + idx + '][item_name]'" x-model="item.item_name"
                                       required maxlength="255"
                                       class="w-full px-2 py-1.5 text-sm rounded border border-slate-300 dark:border-slate-600 bg-white dark:bg-slate-800 text-slate-900 dark:text-white focus:ring-2 focus:ring-blue-500 font-semibold">
                            </td>
                            <td class="px-3 py-2">
                                <input type="text" :name="'items[' + idx + '][hs_code]'" x-model="item.hs_code"
                                       maxlength="20" placeholder="0000.0000"
                                       class="w-28 px-2 py-1.5 text-sm rounded border border-slate-300 dark:border-slate-600 bg-white dark:bg-slate-800 text-slate-900 dark:text-white focus:ring-2 focus:ring-blue-500 font-mono">
                            </td>
                            <td class="px-3 py-2">
                                <select :name="'items[' + idx + '][uom]'" x-model="item.uom"
                                        class="w-20 px-2 py-1.5 text-sm rounded border border-slate-300 dark:border-slate-600 bg-white dark:bg-slate-800 text-slate-900 dark:text-white focus:ring-2 focus:ring-blue-500">
                                    @foreach(['U','KG','GM','LTR','ML','MTR','SQM','PCS','PKT','DOZ','BOX','SET','BAG','BTL','CTN','ROL','FT','IN','YDS','TIN','CAN','BUN'] as $u)
                                    <option value="{{ $u }}">{{ $u }}</option>
                                    @endforeach
                                </select>
                            </td>
                            <td class="px-3 py-2">
                                <input type="number" :name="'items[' + idx + '][quantity]'" x-model.number="item.quantity"
                                       step="0.001" min="0.001" required
                                       class="w-20 px-2 py-1.5 text-sm text-right rounded border border-slate-300 dark:border-slate-600 bg-white dark:bg-slate-800 text-slate-900 dark:text-white focus:ring-2 focus:ring-blue-500 font-bold">
                            </td>
                            <td class="px-3 py-2">
                                <input type="number" :name="'items[' + idx + '][unit_price]'" x-model.number="item.unit_price"
                                       step="0.01" min="0.01" required
                                       class="w-24 px-2 py-1.5 text-sm text-right rounded border border-slate-300 dark:border-slate-600 bg-white dark:bg-slate-800 text-slate-900 dark:text-white focus:ring-2 focus:ring-blue-500 font-bold">
                            </td>
                            <td class="px-3 py-2">
                                <input type="number" :name="'items[' + idx + '][tax_rate]'" x-model.number="item.tax_rate"
                                       step="0.01" min="0" max="100"
                                       :disabled="item.is_tax_exempt"
                                       :class="item.is_tax_exempt ? 'opacity-40 cursor-not-allowed' : ''"
                                       class="w-16 px-2 py-1.5 text-sm text-right rounded border border-slate-300 dark:border-slate-600 bg-white dark:bg-slate-800 text-slate-900 dark:text-white focus:ring-2 focus:ring-blue-500 font-bold">
                            </td>
                            <td class="px-3 py-2 text-center">
                                <input type="hidden" :name="'items[' + idx + '][is_tax_exempt]'" :value="item.is_tax_exempt ? '1' : '0'">
                                <input type="checkbox" x-model="item.is_tax_exempt"
                                       class="w-4 h-4 text-emerald-600 rounded border-slate-300 focus:ring-emerald-500">
                            </td>
                            <td class="px-3 py-2">
                                <input type="number" :name="'items[' + idx + '][item_discount]'" x-model.number="item.item_discount"
                                       step="0.01" min="0"
                                       class="w-20 px-2 py-1.5 text-sm text-right rounded border border-slate-300 dark:border-slate-600 bg-white dark:bg-slate-800 text-slate-900 dark:text-white focus:ring-2 focus:ring-blue-500">
                            </td>
                            <td class="px-3 py-2 text-right text-sm font-black text-emerald-700 dark:text-emerald-300 tabular-nums whitespace-nowrap">
                                <span x-text="'Rs ' + fmt(lineTotal(item))"></span>
                            </td>
                        </tr>
                        </template>
                    </tbody>
                </table>
            </div>

            {{-- 💰 Live recomputed totals — server applies discount/loyalty deterministically on save --}}
            @php
                $svcCharge = (float) ($transaction->fbr_service_charge ?? 0);
                $loyalty = (float) ($transaction->loyalty_redemption_amount ?? 0);
                $hasDiscount = $transaction->discount_amount > 0;
                $hasLoyalty = $loyalty > 0;
            @endphp
            <div class="px-5 py-4 bg-blue-50 dark:bg-blue-900/20 border-t-2 border-blue-300 dark:border-blue-700">
                <div class="max-w-md ml-auto space-y-1 text-sm">
                    <div class="flex justify-between text-slate-700 dark:text-slate-200 font-semibold">
                        <span>Subtotal (after item discounts)</span>
                        <span class="tabular-nums" x-text="'Rs ' + fmt(cartSubtotal())"></span>
                    </div>
                    <div class="flex justify-between text-slate-700 dark:text-slate-200 font-semibold">
                        <span>Tax</span>
                        <span class="tabular-nums" x-text="'Rs ' + fmt(cartTax())"></span>
                    </div>
                    @if($svcCharge > 0)
                    <div class="flex justify-between text-slate-700 dark:text-slate-200 font-semibold">
                        <span>FBR Service Charge</span>
                        <span class="tabular-nums">Rs {{ number_format($svcCharge, 2) }}</span>
                    </div>
                    @endif

                    <div class="flex justify-between pt-2 mt-2 border-t-2 border-blue-400 dark:border-blue-600 text-base font-black text-blue-800 dark:text-blue-300">
                        <span>Preview (before bill discount/loyalty)</span>
                        <span class="tabular-nums" x-text="'Rs ' + fmt(cartTotal() + {{ $svcCharge }})"></span>
                    </div>

                    @if($hasDiscount || $hasLoyalty)
                    <div class="mt-2 p-2 rounded bg-amber-100 dark:bg-amber-900/30 border border-amber-300 dark:border-amber-700 text-xs text-amber-900 dark:text-amber-200 font-bold">
                        ⚠ Bill-level adjustments will be re-applied by the server on save:
                        <ul class="mt-1 ml-3 list-disc space-y-0.5">
                            @if($hasDiscount)
                            <li>Bill discount: <b>{{ ucfirst($transaction->discount_type) }} {{ $transaction->discount_value }}{{ $transaction->discount_type === 'percentage' ? '%' : '' }}</b> (auto-recalculated on new subtotal)</li>
                            @endif
                            @if($hasLoyalty)
                            <li>Loyalty redemption: <b>Rs {{ number_format($loyalty, 2) }}</b> (preserved)</li>
                            @endif
                        </ul>
                    </div>
                    @endif

                    <div class="text-xs text-slate-600 dark:text-slate-400 text-right font-semibold pt-2">
                        Original total: Rs {{ number_format($transaction->total_amount, 2) }}
                    </div>
                </div>
            </div>
        </div>

        {{-- 📝 Optional edit reason --}}
        <div class="mb-5 bg-white dark:bg-slate-900 rounded-xl border border-slate-200 dark:border-slate-700 p-4">
            <label class="block text-xs font-bold uppercase tracking-wider text-slate-700 dark:text-slate-200 mb-2">
                Edit Reason (optional, for audit log)
            </label>
            <input type="text" name="edit_reason" maxlength="500"
                   placeholder="e.g. Fixed wrong HS code on line 2"
                   class="w-full px-3 py-2 text-sm rounded-lg border border-slate-300 dark:border-slate-600 bg-white dark:bg-slate-800 text-slate-900 dark:text-white focus:ring-2 focus:ring-blue-500">
        </div>

        {{-- 🚀 Action buttons --}}
        <div class="flex flex-col sm:flex-row gap-3 sticky bottom-0 bg-white dark:bg-slate-900 p-4 rounded-xl border-2 border-slate-200 dark:border-slate-700 shadow-2xl">
            <a href="{{ route('fbrpos.show', $transaction->id) }}"
               class="flex-1 sm:flex-initial px-5 py-3 rounded-lg bg-slate-200 dark:bg-slate-700 text-slate-800 dark:text-white font-bold text-center hover:bg-slate-300 dark:hover:bg-slate-600 transition">
                ← Cancel
            </a>
            <button type="submit" :disabled="savingNow"
                    :class="savingNow ? 'opacity-60 cursor-wait' : 'hover:from-emerald-700 hover:to-blue-700'"
                    class="flex-1 px-5 py-3 rounded-lg bg-gradient-to-r from-emerald-600 to-blue-600 text-white font-black text-base shadow-lg flex items-center justify-center gap-2 transition">
                <svg x-show="!savingNow" class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 13l4 4L19 7"/></svg>
                <svg x-show="savingNow" class="w-5 h-5 animate-spin" fill="none" viewBox="0 0 24 24"><circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"></circle><path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4zm2 5.291A7.962 7.962 0 014 12H0c0 3.042 1.135 5.824 3 7.938l3-2.647z"></path></svg>
                <span x-text="savingNow ? 'Saving & submitting to FBR…' : '💾 Save & Re-Submit to FBR'"></span>
            </button>
        </div>
    </form>

    <div class="mt-4 p-4 rounded-xl bg-amber-50 dark:bg-amber-900/20 border border-amber-300 dark:border-amber-700 text-xs text-amber-800 dark:text-amber-200">
        <div class="font-bold mb-1">ℹ How this works:</div>
        <ul class="list-disc list-inside space-y-0.5 font-semibold">
            <li>Original cart is snapshotted to audit log <b>before</b> any change.</li>
            <li>Common FBR rejections: wrong HS code, wrong tax rate, missing UoM. Fix and resubmit.</li>
            <li>If FBR still rejects after edit, your changes are kept — you can edit again and retry.</li>
            <li>Once FBR accepts the bill, this edit option disappears (locked for compliance).</li>
        </ul>
    </div>
</div>
</x-fbr-pos-layout>

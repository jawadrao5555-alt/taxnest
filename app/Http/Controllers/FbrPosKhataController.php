<?php

namespace App\Http\Controllers;

use App\Models\Company;
use App\Models\FbrCustomerLedger;
use App\Models\PosCustomer;
use App\Services\PkPhone;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;

/**
 * FBR POS Udhaar/Khata (Aug 2026 — Retail Core).
 * One page: customers with outstanding balance + wasooli (payment received)
 * + per-customer ledger. pos_customers.khata_balance is the cached running
 * balance; every mutation here goes ledger-row + balance in ONE DB transaction.
 */
class FbrPosKhataController extends Controller
{
    use \App\Http\Controllers\Concerns\FbrPlanGate;

    private function user() { return Auth::guard('fbrpos')->user(); }
    private function companyId(): int { return (int) $this->user()->company_id; }

    /**
     * Khata is owner/manager territory — cashiers and viewers must not see
     * outstanding balances or record wasooli (financial mutation).
     */
    private function assertNotCashier(): void
    {
        $u = $this->user();
        if (in_array($u->pos_role ?? '', ['pos_cashier', 'local_viewer'], true)) {
            abort(403, 'Sirf admin/manager khata dekh sakte hain.');
        }
    }

    public function index(Request $request)
    {
        if ($resp = $this->fbrPlanGate('khata_enabled')) return $resp;
        $this->assertNotCashier();
        $companyId = $this->companyId();

        $customers = PosCustomer::where('company_id', $companyId)
            ->where('khata_balance', '!=', 0)
            ->orderByDesc('khata_balance')
            ->get();

        $totalOutstanding = (float) $customers->where('khata_balance', '>', 0)->sum('khata_balance');

        // Wasooli received in the last 30 days — quick health signal for the owner.
        $recentWasooli = (float) FbrCustomerLedger::where('company_id', $companyId)
            ->where('entry_type', 'wasooli')
            ->where('created_at', '>=', now()->subDays(30))
            ->sum('amount');

        // (Khata upgrade Aug 2026) UMAR (aging) — bucket each customer's
        // outstanding by the age of their OLDEST unpaid udhaar, allocating
        // wasooli oldest-first (FIFO). Computed in ONE pass over the whole
        // company's ledger (not a query per customer) so it stays fast for a
        // shop with thousands of ledger rows. Also feeds the WhatsApp reminder
        // (oldest unpaid udhaar date) and the oldest-debt-first default sort.
        $aging = $this->computeAging($companyId);

        // Attach per-customer aging fields for the view (bucket, oldest-date,
        // days) and re-sort oldest-debt-first by default.
        $customers = $customers->map(function ($c) use ($aging) {
            $a = $aging[$c->id] ?? null;
            $c->khata_bucket = $a['bucket'] ?? null;         // '0_15' | '16_30' | '31_60' | '60_plus'
            $c->khata_oldest_days = $a['days'] ?? null;       // int|null
            $c->khata_oldest_date = $a['oldest_date'] ?? null; // Y-m-d string|null
            // wa.me deep link (reused PkPhone normaliser) — null when unroutable,
            // so a customer with no/bad phone shows NO dead button.
            $c->khata_wa_url = $this->reminderWaUrl($c);
            $c->khata_last_reminder_days = $c->khata_last_reminder_at
                ? (int) $c->khata_last_reminder_at->diffInDays(now())
                : null;
            return $c;
        })->sortByDesc(fn ($c) => $c->khata_oldest_days ?? -1)->values();

        // Four bucket totals for the clickable filters at the top.
        $bucketTotals = [
            '0_15' => 0.0, '16_30' => 0.0, '31_60' => 0.0, '60_plus' => 0.0,
        ];
        foreach ($customers as $c) {
            if ($c->khata_balance > 0 && $c->khata_bucket && isset($bucketTotals[$c->khata_bucket])) {
                $bucketTotals[$c->khata_bucket] += (float) $c->khata_balance;
            }
        }

        return view('fbr-pos.khata', [
            'customers' => $customers,
            'totalOutstanding' => $totalOutstanding,
            'recentWasooli' => abs($recentWasooli),
            'bucketTotals' => $bucketTotals,
            'shopName' => Company::find($companyId)->name ?? '',
        ]);
    }

    /**
     * (Khata upgrade Aug 2026) One-pass FIFO aging for the whole company.
     *
     * WHY one pass: a shop can have thousands of ledger rows. We pull every
     * customer's udhaar + wasooli entries ONCE, ordered oldest-first, then
     * allocate each wasooli against the oldest still-unpaid udhaar (FIFO). The
     * age of the OLDEST udhaar row that still has an unpaid remainder decides
     * the customer's bucket. Returns [customer_id => ['bucket','days','oldest_date']].
     */
    private function computeAging(int $companyId): array
    {
        // Only udhaar (credit UP) and wasooli/return (DOWN) touch the FIFO stack.
        // Ordered by created_at then id so same-second rows stay deterministic.
        $rows = FbrCustomerLedger::where('company_id', $companyId)
            ->whereIn('entry_type', ['udhaar', 'wasooli', 'return_adjust'])
            ->orderBy('created_at')
            ->orderBy('id')
            ->get(['customer_id', 'entry_type', 'amount', 'created_at']);

        // Group rows per customer while preserving chronological order.
        $byCustomer = [];
        foreach ($rows as $r) {
            $byCustomer[$r->customer_id][] = $r;
        }

        $out = [];
        $now = now();
        foreach ($byCustomer as $customerId => $entries) {
            // FIFO stack of open udhaar lots: each = ['remaining' => x, 'date' => Carbon].
            $lots = [];
            foreach ($entries as $e) {
                if ($e->entry_type === 'udhaar') {
                    $lots[] = ['remaining' => round((float) $e->amount, 2), 'date' => $e->created_at];
                } else {
                    // wasooli/return amounts are stored negative — pay down oldest lots first.
                    $pay = abs(round((float) $e->amount, 2));
                    foreach ($lots as &$lot) {
                        if ($pay <= 0) break;
                        if ($lot['remaining'] <= 0) continue;
                        $take = min($lot['remaining'], $pay);
                        $lot['remaining'] = round($lot['remaining'] - $take, 2);
                        $pay = round($pay - $take, 2);
                    }
                    unset($lot);
                }
            }

            // Oldest lot still carrying an unpaid remainder = the aging anchor.
            $oldest = null;
            foreach ($lots as $lot) {
                if ($lot['remaining'] > 0.001) { $oldest = $lot['date']; break; }
            }
            if ($oldest === null) {
                continue; // fully paid off — no aging bucket.
            }
            $days = (int) $oldest->diffInDays($now);
            $out[$customerId] = [
                'days' => $days,
                'oldest_date' => $oldest->format('Y-m-d'),
                'bucket' => $days <= 15 ? '0_15' : ($days <= 30 ? '16_30' : ($days <= 60 ? '31_60' : '60_plus')),
            ];
        }

        return $out;
    }

    /**
     * (Khata upgrade Aug 2026) Build the WhatsApp reminder wa.me link for a
     * customer. Reuses PkPhone (the same normaliser the WhatsApp bill feature
     * uses) — returns NULL when the number is missing/unroutable so the view
     * never renders a dead button.
     */
    private function reminderWaUrl(PosCustomer $c): ?string
    {
        $normalized = PkPhone::normalize($c->phone);
        if ($normalized === null) {
            return null;
        }
        $shop = Company::find($this->companyId())->name ?? '';
        $oldest = $c->khata_oldest_date
            ? ' (' . \Illuminate\Support\Carbon::parse($c->khata_oldest_date)->format('d M Y') . ' se)'
            : '';
        // Polite Roman-Urdu message: shop name, current baqaya, oldest udhaar date.
        $msg = __('pos.khata_reminder_wa_message', [
            'shop' => $shop,
            'balance' => number_format((float) $c->khata_balance, 0),
            'oldest' => $oldest,
        ]);
        return PkPhone::waUrl($normalized, $msg);
    }

    public function ledger($customerId)
    {
        if ($resp = $this->fbrPlanGate('khata_enabled')) return $resp;
        $this->assertNotCashier();
        $companyId = $this->companyId();
        $customer = PosCustomer::where('company_id', $companyId)->findOrFail($customerId);

        $entries = FbrCustomerLedger::where('company_id', $companyId)
            ->where('customer_id', $customer->id)
            ->with('transaction:id,invoice_number,total_amount,created_at')
            ->orderByDesc('id')
            ->limit(200)
            ->get();

        return response()->json([
            'success' => true,
            'customer' => [
                'id' => $customer->id,
                'name' => $customer->name,
                'phone' => $customer->phone,
                'khata_balance' => (float) $customer->khata_balance,
            ],
            'entries' => $entries->map(fn ($e) => [
                'id' => $e->id,
                'entry_type' => $e->entry_type,
                'amount' => (float) $e->amount,
                'balance_after' => (float) $e->balance_after,
                'note' => $e->note,
                'invoice_number' => $e->transaction?->invoice_number,
                'date' => $e->created_at->format('d M Y h:i A'),
            ]),
        ]);
    }

    public function wasooli(Request $request)
    {
        if ($resp = $this->fbrPlanGate('khata_enabled')) return $resp;
        $this->assertNotCashier();
        $request->validate([
            'customer_id' => 'required|integer',
            'amount' => 'required|numeric|min:0.01',
            'note' => 'nullable|string|max:300',
        ]);

        $companyId = $this->companyId();

        return DB::transaction(function () use ($request, $companyId) {
            $customer = PosCustomer::lockForUpdate()
                ->where('company_id', $companyId)
                ->findOrFail($request->customer_id);

            $amount = round((float) $request->amount, 2);
            $outstanding = round((float) $customer->khata_balance, 2);

            // Overpay guard: wasooli can never exceed the outstanding balance —
            // otherwise the khata goes negative and the ledger loses meaning.
            if ($outstanding <= 0) {
                return redirect()->route('fbrpos.khata')
                    ->with('error', "{$customer->name} par koi udhaar baqi nahi.");
            }
            if ($amount > $outstanding) {
                return redirect()->route('fbrpos.khata')
                    ->with('error', "Wasooli Rs " . number_format($amount, 2) . " outstanding Rs " . number_format($outstanding, 2) . " se zyada hai — pehle amount theek karein.");
            }

            $newBalance = round($outstanding - $amount, 2);

            $entry = FbrCustomerLedger::create([
                'company_id' => $companyId,
                'customer_id' => $customer->id,
                'entry_type' => 'wasooli',
                'amount' => -1 * $amount,
                'balance_after' => $newBalance,
                'transaction_id' => null,
                'note' => $request->note ?: 'Wasooli received',
                'created_by' => $this->user()->id,
            ]);

            $customer->update(['khata_balance' => $newBalance]);

            // (Khata upgrade Aug 2026) hand the fresh wasooli entry id back so the
            // khata page can offer "Rasid print" for the payment just recorded.
            return redirect()->route('fbrpos.khata')
                ->with('success', "Wasooli Rs " . number_format($amount, 2) . " — {$customer->name} ka naya balance Rs " . number_format($newBalance, 2))
                ->with('wasooli_receipt_id', $entry->id);
        });
    }

    /**
     * (Khata upgrade Aug 2026) Thermal Wasooli ki rasid — a small payment slip
     * for the wasooli just recorded. Manager-only, company-scoped, keyed on the
     * wasooli ledger entry. Follows the FBR thermal receipt conventions (never
     * forces body width to the physical paper width — see thermal-print-width).
     */
    public function wasooliReceipt($entryId)
    {
        if ($resp = $this->fbrPlanGate('khata_enabled')) return $resp;
        $this->assertNotCashier();
        $companyId = $this->companyId();

        $entry = FbrCustomerLedger::where('company_id', $companyId)
            ->where('entry_type', 'wasooli')
            ->with(['customer:id,name,phone', 'creator:id,name'])
            ->findOrFail($entryId);

        $company = Company::find($companyId);

        return view('fbr-pos.wasooli-receipt', [
            'company' => $company,
            'entry' => $entry,
        ]);
    }

    /**
     * (Khata upgrade Aug 2026) Stamp khata_last_reminder_at after a WhatsApp
     * yaad-dehani is sent (the browser navigates to wa.me, then pings this).
     * Manager-only, company-scoped. Returns JSON for the AJAX caller.
     */
    public function markReminderSent(Request $request, $customerId)
    {
        if ($resp = $this->fbrPlanGate('khata_enabled')) return $resp;
        $this->assertNotCashier();
        $companyId = $this->companyId();

        $customer = PosCustomer::where('company_id', $companyId)->findOrFail($customerId);

        // (Khata upgrade Aug 2026) Only stamp when the column exists. On a drifted
        // PROD DB (prod-schema-drift-selfheal.md) that lags the migration the
        // reminder still opens WhatsApp — we just skip the stamp instead of 500ing.
        if (PosCustomer::khataColumnExists('khata_last_reminder_at')) {
            $customer->update(['khata_last_reminder_at' => now()]);
        }

        return response()->json([
            'success' => true,
            'reminded_at' => $customer->khata_last_reminder_at
                ? $customer->khata_last_reminder_at->format('d M Y h:i A')
                : null,
        ]);
    }
}

<?php

namespace App\Http\Controllers;

use App\Models\FbrCustomerLedger;
use App\Models\PosCustomer;
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

        return view('fbr-pos.khata', [
            'customers' => $customers,
            'totalOutstanding' => $totalOutstanding,
            'recentWasooli' => abs($recentWasooli),
        ]);
    }

    public function ledger($customerId)
    {
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

            FbrCustomerLedger::create([
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

            return redirect()->route('fbrpos.khata')
                ->with('success', "Wasooli Rs " . number_format($amount, 2) . " — {$customer->name} ka naya balance Rs " . number_format($newBalance, 2));
        });
    }
}

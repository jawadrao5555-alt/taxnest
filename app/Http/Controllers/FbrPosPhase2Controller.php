<?php

namespace App\Http\Controllers;

use App\Models\FbrPosCashMovement;
use App\Models\FbrPosHeldSale;
use App\Models\FbrPosLoyaltyLedger;
use App\Models\FbrPosLoyaltySetting;
use App\Models\FbrPosPromotion;
use App\Models\FbrPosShift;
use App\Models\FbrPosTerminal;
use App\Models\FbrPosTransaction;
use App\Models\FbrPosTransactionItem;
use App\Models\PosCustomer;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class FbrPosPhase2Controller extends Controller
{
    private function user() { return Auth::guard('fbrpos')->user(); }
    private function companyId(): int { return (int) $this->user()->company_id; }

    // ========================= TERMINALS =========================

    public function terminals()
    {
        $terminals = FbrPosTerminal::where('company_id', $this->companyId())
            ->orderBy('terminal_name')->get();
        return view('fbr-pos.phase2.terminals', compact('terminals'));
    }

    public function storeTerminal(Request $r)
    {
        $r->validate([
            'terminal_name' => 'required|string|max:100',
            'location' => 'nullable|string|max:100',
        ]);
        $code = 'FBR-' . strtoupper(Str::random(4)) . '-C' . $this->companyId();
        FbrPosTerminal::create([
            'company_id' => $this->companyId(),
            'terminal_name' => $r->terminal_name,
            'terminal_code' => $code,
            'location' => $r->location,
            'is_active' => true,
        ]);
        return back()->with('success', 'Counter added: ' . $code);
    }

    public function toggleTerminal($id)
    {
        $t = FbrPosTerminal::where('company_id', $this->companyId())->findOrFail($id);
        $t->update(['is_active' => !$t->is_active]);
        return back()->with('success', 'Counter ' . ($t->is_active ? 'activated' : 'deactivated'));
    }

    public function deleteTerminal($id)
    {
        $t = FbrPosTerminal::where('company_id', $this->companyId())->findOrFail($id);
        if ($t->transactions()->exists()) {
            return back()->with('error', 'Cannot delete: counter has transactions. Deactivate instead.');
        }
        $t->delete();
        return back()->with('success', 'Counter deleted');
    }

    // ========================= HELD SALES =========================

    public function holdSale(Request $r)
    {
        $r->validate([
            'hold_name' => 'required|string|max:100',
            'cart_data' => 'required|array',
        ]);
        $held = FbrPosHeldSale::create([
            'company_id' => $this->companyId(),
            'terminal_id' => $r->terminal_id,
            'user_id' => $this->user()->id,
            'hold_name' => $r->hold_name,
            'customer_name' => $r->customer_name,
            'customer_phone' => $r->customer_phone,
            'cart_data' => $r->cart_data,
            'notes' => $r->notes,
        ]);
        return response()->json(['success' => true, 'id' => $held->id]);
    }

    public function listHeld()
    {
        $held = FbrPosHeldSale::where('company_id', $this->companyId())
            ->orderByDesc('created_at')->limit(50)->get();
        return response()->json($held);
    }

    public function recallHeld($id)
    {
        $held = FbrPosHeldSale::where('company_id', $this->companyId())->findOrFail($id);
        $data = $held->cart_data;
        // Atomic claim — the conditional DELETE decides the winner when two
        // terminals recall the same parked cart concurrently. Only the request
        // whose delete actually removed the row gets the cart back; the loser
        // gets 409 and must refresh its held list.
        $claimed = FbrPosHeldSale::where('company_id', $this->companyId())
            ->where('id', $id)->delete();
        if (!$claimed) {
            return response()->json(['success' => false, 'message' => 'Already recalled on another terminal'], 409);
        }
        return response()->json(['success' => true, 'cart' => $data]);
    }

    public function deleteHeld($id)
    {
        FbrPosHeldSale::where('company_id', $this->companyId())->where('id', $id)->delete();
        return response()->json(['success' => true]);
    }

    // ========================= PROMOTIONS =========================

    public function promotions()
    {
        $promos = FbrPosPromotion::where('company_id', $this->companyId())
            ->orderByDesc('id')->paginate(20);
        return view('fbr-pos.phase2.promotions', compact('promos'));
    }

    public function storePromotion(Request $r)
    {
        $r->validate([
            'name' => 'required|string|max:100',
            'code' => 'nullable|string|max:50',
            'type' => 'required|in:percent,fixed',
            'value' => 'required|numeric|min:0',
            'min_amount' => 'nullable|numeric|min:0',
            'max_discount' => 'nullable|numeric|min:0',
            'valid_from' => 'nullable|date',
            'valid_until' => 'nullable|date|after_or_equal:valid_from',
            'usage_limit' => 'nullable|integer|min:1',
        ]);

        FbrPosPromotion::create([
            'company_id' => $this->companyId(),
            'name' => $r->name,
            'code' => $r->code ? strtoupper($r->code) : null,
            'type' => $r->type,
            'value' => $r->value,
            'min_amount' => $r->min_amount ?? 0,
            'max_discount' => $r->max_discount,
            'applies_to' => 'all',
            'valid_from' => $r->valid_from,
            'valid_until' => $r->valid_until,
            'usage_limit' => $r->usage_limit,
            'is_active' => true,
        ]);
        return back()->with('success', 'Promotion created');
    }

    public function togglePromotion($id)
    {
        $p = FbrPosPromotion::where('company_id', $this->companyId())->findOrFail($id);
        $p->update(['is_active' => !$p->is_active]);
        return back()->with('success', 'Promotion ' . ($p->is_active ? 'activated' : 'deactivated'));
    }

    public function deletePromotion($id)
    {
        FbrPosPromotion::where('company_id', $this->companyId())->where('id', $id)->delete();
        return back()->with('success', 'Promotion deleted');
    }

    public function validatePromo(Request $r)
    {
        $r->validate(['code' => 'required|string', 'subtotal' => 'required|numeric']);
        $promo = FbrPosPromotion::where('company_id', $this->companyId())
            ->where('code', strtoupper($r->code))->first();
        if (!$promo) return response()->json(['ok' => false, 'msg' => 'Invalid promo code']);
        $check = $promo->isUsable((float) $r->subtotal);
        if (!$check['ok']) return response()->json($check);
        $disc = $promo->calcDiscount((float) $r->subtotal);
        return response()->json([
            'ok' => true, 'msg' => $promo->name,
            'discount' => round($disc, 2),
            'promotion_id' => $promo->id,
            'promotion_name' => $promo->name,
        ]);
    }

    // ========================= LOYALTY =========================

    public function loyaltySettings(Request $r)
    {
        $settings = FbrPosLoyaltySetting::forCompany($this->companyId());

        if ($r->isMethod('post')) {
            $r->validate([
                'is_enabled' => 'nullable|boolean',
                'rs_per_point' => 'required|numeric|min:1',
                'point_value' => 'required|numeric|min:0.01',
                'min_redeem_points' => 'required|integer|min:1',
            ]);
            $settings->update([
                'is_enabled' => $r->boolean('is_enabled'),
                'rs_per_point' => $r->rs_per_point,
                'point_value' => $r->point_value,
                'min_redeem_points' => $r->min_redeem_points,
            ]);
            return back()->with('success', 'Loyalty settings saved');
        }

        return view('fbr-pos.phase2.loyalty', compact('settings'));
    }

    public function customerPoints($phone)
    {
        $customer = PosCustomer::where('company_id', $this->companyId())
            ->where('phone', $phone)->first();
        if (!$customer) return response()->json(['ok' => false]);
        $settings = FbrPosLoyaltySetting::forCompany($this->companyId());
        return response()->json([
            'ok' => true,
            'id' => $customer->id,
            'name' => $customer->name,
            'phone' => $customer->phone,
            'points' => (int) $customer->loyalty_points,
            'point_value' => (float) $settings->point_value,
            'min_redeem' => (int) $settings->min_redeem_points,
            'enabled' => (bool) $settings->is_enabled,
        ]);
    }

    // ========================= SHIFTS =========================

    public function currentShift()
    {
        return FbrPosShift::where('company_id', $this->companyId())
            ->where('user_id', $this->user()->id)
            ->where('status', 'open')->latest('id')->first();
    }

    public function shiftsIndex()
    {
        $shifts = FbrPosShift::where('company_id', $this->companyId())
            ->orderByDesc('id')->paginate(20);
        $current = $this->currentShift();
        return view('fbr-pos.phase2.shifts', compact('shifts', 'current'));
    }

    public function openShift(Request $r)
    {
        if ($this->currentShift()) {
            return back()->with('error', 'Shift already open');
        }
        $r->validate([
            'opening_cash' => 'required|numeric|min:0',
            'terminal_id' => 'nullable|integer',
        ]);
        FbrPosShift::create([
            'company_id' => $this->companyId(),
            'terminal_id' => $r->terminal_id,
            'user_id' => $this->user()->id,
            'opened_at' => now(),
            'opening_cash' => $r->opening_cash,
            'status' => 'open',
        ]);
        return back()->with('success', 'Shift opened. Opening cash: Rs ' . number_format($r->opening_cash, 2));
    }

    public function closeShift(Request $r)
    {
        $shift = $this->currentShift();
        if (!$shift) return back()->with('error', 'No open shift');
        $r->validate(['closing_cash' => 'required|numeric|min:0']);

        $totals = $this->shiftTotals($shift);
        $cashMovements = $shift->movements()->get()->reduce(function ($carry, $m) {
            return $carry + (in_array($m->type, ['cash_in', 'float']) ? $m->amount : -$m->amount);
        }, 0);
        $expectedCash = $shift->opening_cash + $totals['cash'] + $cashMovements - $totals['returns_cash'];

        $shift->update([
            'closed_at' => now(),
            'closing_cash' => $r->closing_cash,
            'expected_cash' => $expectedCash,
            'variance' => $r->closing_cash - $expectedCash,
            'total_sales' => $totals['sales'],
            'total_cash' => $totals['cash'],
            'total_card' => $totals['card'],
            'total_other' => $totals['other'],
            'total_returns' => $totals['returns'],
            'sales_count' => $totals['sales_count'],
            'returns_count' => $totals['returns_count'],
            'notes' => $r->notes,
            'status' => 'closed',
        ]);
        return redirect()->route('fbrpos.phase2.shift.report', $shift->id)
            ->with('success', 'Shift closed. Variance: Rs ' . number_format($shift->variance, 2));
    }

    public function shiftReport($id)
    {
        $shift = FbrPosShift::where('company_id', $this->companyId())->findOrFail($id);
        $movements = $shift->movements()->orderBy('id')->get();
        return view('fbr-pos.phase2.shift-report', compact('shift', 'movements'));
    }

    public function cashMovement(Request $r)
    {
        $shift = $this->currentShift();
        if (!$shift) return back()->with('error', 'Open a shift first');
        $r->validate([
            'type' => 'required|in:cash_in,cash_out,drop,float',
            'amount' => 'required|numeric|min:0.01',
            'reason' => 'required|string|max:200',
        ]);
        FbrPosCashMovement::create([
            'shift_id' => $shift->id,
            'user_id' => $this->user()->id,
            'type' => $r->type,
            'amount' => $r->amount,
            'reason' => $r->reason,
        ]);
        return back()->with('success', 'Cash movement recorded');
    }

    private function shiftTotals(FbrPosShift $shift): array
    {
        $start = $shift->opened_at;
        $end = $shift->closed_at ?? now();

        $sales = FbrPosTransaction::where('company_id', $shift->company_id)
            ->where('shift_id', $shift->id)
            ->where('transaction_type', 'sale')
            ->where('status', 'completed')->get();

        $returns = FbrPosTransaction::where('company_id', $shift->company_id)
            ->where('shift_id', $shift->id)
            ->where('transaction_type', 'return')
            ->where('status', 'completed')->get();

        $cash = $card = $other = $returnsCash = 0;
        foreach ($sales as $s) {
            $bd = is_array($s->payment_breakdown) ? $s->payment_breakdown : [['method' => $s->payment_method, 'amount' => $s->total_amount]];
            foreach ($bd as $p) {
                $amt = (float) ($p['amount'] ?? 0);
                if (($p['method'] ?? '') === 'cash') $cash += $amt;
                elseif (in_array($p['method'] ?? '', ['card', 'credit_card', 'debit_card'])) $card += $amt;
                else $other += $amt;
            }
        }
        foreach ($returns as $rt) {
            $returnsCash += (float) $rt->total_amount;
        }

        return [
            'sales' => $sales->sum('total_amount'),
            'cash' => $cash,
            'card' => $card,
            'other' => $other,
            'returns' => $returns->sum('total_amount'),
            'returns_cash' => $returnsCash,
            'sales_count' => $sales->count(),
            'returns_count' => $returns->count(),
        ];
    }

    // ========================= RETURNS / REFUNDS =========================

    public function returnForm($id)
    {
        $original = FbrPosTransaction::with('items')->where('company_id', $this->companyId())->findOrFail($id);
        if ($original->transaction_type === 'return') {
            return back()->with('error', 'Cannot return a return');
        }
        return view('fbr-pos.phase2.return', compact('original'));
    }

    public function processReturn(Request $r, $id)
    {
        $original = FbrPosTransaction::with('items')->where('company_id', $this->companyId())->findOrFail($id);
        $r->validate([
            'items' => 'required|array|min:1',
            'items.*.item_id' => 'required|integer',
            'items.*.return_qty' => 'required|numeric|min:0',
            // 'khata' (Aug 2026 — Retail Core): refund goes into the customer's
            // udhaar ledger as a credit (balance DOWN) instead of cash out.
            'refund_method' => 'required|in:cash,card,store_credit,khata',
        ]);

        if ($r->refund_method === 'khata' && !$original->customer_id) {
            return back()->with('error', 'Khata refund ke liye bill par customer hona zaroori hai.');
        }

        return DB::transaction(function () use ($r, $original) {
            $totalSubtotal = 0; $totalTax = 0; $totalDiscount = 0;
            $returnItems = [];

            foreach ($r->items as $row) {
                $qty = (float) $row['return_qty'];
                if ($qty <= 0) continue;
                $orig = $original->items->firstWhere('id', $row['item_id']);
                if (!$orig) continue;
                $remaining = (float) $orig->quantity - (float) $orig->returned_quantity;
                if ($qty > $remaining) {
                    return back()->with('error', "Item {$orig->item_name}: cannot return more than {$remaining}");
                }
                $ratio = $qty / max((float) $orig->quantity, 0.001);
                $sub = round((float) $orig->subtotal * $ratio, 2);
                $tax = round((float) $orig->tax_amount * $ratio, 2);
                $disc = round((float) ($orig->item_discount ?? 0) * $ratio, 2);

                $totalSubtotal += $sub;
                $totalTax += $tax;
                $totalDiscount += $disc;

                $returnItems[] = [
                    'parent_item_id' => $orig->id,
                    'product_id' => $orig->product_id,
                    'item_name' => $orig->item_name,
                    'hs_code' => $orig->hs_code,
                    'uom' => $orig->uom,
                    'quantity' => $qty,
                    'unit_price' => $orig->unit_price,
                    'item_discount' => $disc,
                    'tax_rate' => $orig->tax_rate,
                    'tax_amount' => $tax,
                    'subtotal' => $sub,
                    'total' => $sub + $tax,
                    'is_tax_exempt' => $orig->is_tax_exempt,
                ];

                // Update parent returned_quantity
                $orig->update(['returned_quantity' => (float) $orig->returned_quantity + $qty]);
            }

            if (empty($returnItems)) {
                return back()->with('error', 'No items selected for return');
            }

            $shift = $this->currentShift();
            $invNum = 'RET-' . date('ymd') . '-' . strtoupper(Str::random(5));

            $return = FbrPosTransaction::create([
                'company_id' => $this->companyId(),
                'terminal_id' => $original->terminal_id,
                'shift_id' => $shift?->id,
                'invoice_number' => $invNum,
                'invoice_mode' => $original->invoice_mode,
                'transaction_type' => 'return',
                'parent_transaction_id' => $original->id,
                'customer_name' => $original->customer_name,
                'customer_phone' => $original->customer_phone,
                'customer_ntn' => $original->customer_ntn,
                'customer_id' => $original->customer_id,
                'subtotal' => $totalSubtotal,
                'discount_amount' => $totalDiscount,
                'tax_amount' => $totalTax,
                'total_amount' => $totalSubtotal + $totalTax,
                'payment_method' => $r->refund_method,
                'payment_breakdown' => [['method' => $r->refund_method, 'amount' => $totalSubtotal + $totalTax]],
                'status' => 'completed',
                'fbr_status' => 'local',
                'created_by' => $this->user()->id,
            ]);

            foreach ($returnItems as $it) {
                $it['transaction_id'] = $return->id;
                FbrPosTransactionItem::create($it);
            }

            // ── KHATA REFUND (Aug 2026 — Retail Core) ────────────────────────────
            // Refund credited into the customer's udhaar ledger — balance goes DOWN.
            if ($r->refund_method === 'khata' && $original->customer_id) {
                $cust = \App\Models\PosCustomer::lockForUpdate()->find($original->customer_id);
                if ($cust) {
                    $newBalance = round((float) $cust->khata_balance - (float) $return->total_amount, 2);
                    \App\Models\FbrCustomerLedger::create([
                        'company_id' => $this->companyId(),
                        'customer_id' => $cust->id,
                        'entry_type' => 'return_adjust',
                        'amount' => -1 * (float) $return->total_amount,
                        'balance_after' => $newBalance,
                        'transaction_id' => $return->id,
                        'note' => "Return {$invNum} — khata adjust (bill {$original->invoice_number})",
                        'created_by' => $this->user()->id,
                    ]);
                    $cust->update(['khata_balance' => $newBalance]);
                }
            }

            // ── STOCK RESTORE (Aug 2026 — Retail Core) ───────────────────────────
            // Returned goods go back on the shelf when stock tracking is ON.
            $company = \App\Models\Company::find($this->companyId());
            if ($company && $company->inventory_enabled) {
                foreach ($returnItems as $it) {
                    if (!empty($it['product_id']) && (float) $it['quantity'] > 0) {
                        try {
                            \App\Services\InventoryService::addStock(
                                $this->companyId(),
                                $it['product_id'],
                                (float) $it['quantity'],
                                (float) $it['unit_price'],
                                \App\Models\InventoryMovement::TYPE_RETURN_IN,
                                null,
                                ['type' => 'fbr_pos_return', 'id' => $return->id, 'number' => $invNum],
                                null,
                                $this->user()->id
                            );
                        } catch (\Throwable $stockEx) {
                            \Illuminate\Support\Facades\Log::warning('FBR POS return stock restore failed', ['tx' => $return->id, 'err' => $stockEx->getMessage()]);
                        }
                    }
                }
            }

            // ── FBR CREDIT NOTE (Aug 2026 — Retail Core) ─────────────────────────
            // If the ORIGINAL bill was FBR-submitted and reporting is ON, queue this
            // return for the Desktop Agent exactly like a sale — buildFbrPosPayload
            // detects transaction_type='return' and emits InvoiceType=3 + RefUSIN +
            // negative quantities (IMS credit-note model). Without an FBR-numbered
            // parent the credit note has no RefUSIN, so it stays local.
            if ($company && $company->fbr_reporting_enabled
                && !empty($original->fbr_invoice_number)
                && $company->agentServesFbr() && $company->agent_enabled) {
                $return->update(['fbr_status' => 'pending']);
            }

            return redirect()->route('fbrpos.show', $return->id)
                ->with('success', 'Return processed: Refund Rs ' . number_format($return->total_amount, 2));
        });
    }
}

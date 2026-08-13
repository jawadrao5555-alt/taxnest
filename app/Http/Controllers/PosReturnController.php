<?php

namespace App\Http\Controllers;

use App\Models\Company;
use App\Models\InventoryMovement;
use App\Models\PosProduct;
use App\Models\PosTransaction;
use App\Models\PosTransactionItem;
use App\Services\PraIntegrationService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

/**
 * PRA POS Return / Credit-Note flow (Task 570, Aug 2026).
 *
 * Modeled on FbrPosPhase2Controller::returnForm/processReturn with PRA rules:
 *  - Manager/owner-only (posCashierBlocked → 403); local-scoped staff blocked
 *    (returns live in the PRA stream).
 *  - Refund methods are cash/card ONLY — PRA POS has no khata/credit bills.
 *  - Return rows store POSITIVE amounts (FBR convention); PraIntegrationService
 *    flips the sign and emits InvoiceType=3 + RefUSIN when submitting.
 *  - PRA whole-rupee header total; tax-inclusive parents keep the menu-money
 *    snapshot semantics (header subtotal = menu sum − included tax).
 *  - Only parents with a PRA fiscal number are reported (credit note needs a
 *    RefUSIN PRA has actually seen); everything else stays a LOCAL return
 *    (pra_status NULL — same category as reporting-OFF finals).
 */
class PosReturnController extends Controller
{
    /** Gate shared by both endpoints. Returns an error response or null. */
    private function gate()
    {
        $user = auth('pos')->user();
        if (!$user || $user->posCashierBlocked()) {
            abort(403, __('pos.return_manager_only'));
        }
        // Billing Scope: local-scoped staff run the offline stream only —
        // returns/credit notes belong to the PRA stream.
        if (($user->posBillingScope() ?? 'both') === 'local') {
            abort(403, __('pos.return_manager_only'));
        }
        if (!Schema::hasColumn('pos_transactions', 'transaction_type')) {
            // Prod schema drift (migration not yet applied) — fail loudly,
            // never write a return the reports cannot recognize.
            abort(503, 'Return flow unavailable: database migration pending.');
        }
        return null;
    }

    /**
     * Which parent bills can be returned: completed finals in the PRA stream.
     * Provisionals keep their existing delete flow; returns of returns never.
     */
    public static function returnableReason(PosTransaction $txn): ?string
    {
        if (($txn->transaction_type ?? 'sale') === 'return') {
            return 'return_of_return';
        }
        if ($txn->status !== 'completed') {
            return 'not_completed';
        }
        if ($txn->invoice_mode === 'local' || $txn->pra_status === 'local') {
            return 'provisional';
        }
        return null;
    }

    public function returnForm($id)
    {
        $this->gate();
        $companyId = app('currentCompanyId');
        // withoutGlobalScope: a reporting-OFF final archived by day-close is
        // still a real sale — its goods can come back.
        $original = PosTransaction::withoutGlobalScope('hide_archived')
            ->with('items')->where('company_id', $companyId)->findOrFail($id);

        if ($reason = self::returnableReason($original)) {
            return redirect()->route('pos.transaction.show', $original->id)
                ->with('error', __('pos.return_not_allowed_' . $reason));
        }

        return view('pos.return-form', compact('original'));
    }

    public function processReturn(Request $r, $id)
    {
        $this->gate();
        $companyId = app('currentCompanyId');

        $r->validate([
            'items' => 'required|array|min:1',
            'items.*.item_id' => 'required|integer',
            'items.*.return_qty' => 'required|numeric|min:0',
            // PRA POS has no khata/credit bills — cash or card refund only.
            'refund_method' => 'required|in:cash,card',
        ]);

        $user = auth('pos')->user();

        $result = DB::transaction(function () use ($r, $id, $companyId, $user) {
            // Re-fetch UNDER LOCK — concurrent returns of the same bill must
            // serialize here or both see the same remaining quantities and
            // double-refund/double-restock.
            $original = PosTransaction::withoutGlobalScope('hide_archived')
                ->where('company_id', $companyId)
                ->lockForUpdate()->findOrFail($id);
            $original->load('items');

            if ($reason = self::returnableReason($original)) {
                return ['error' => __('pos.return_not_allowed_' . $reason)];
            }

            $taxInclusive = (bool) ($original->tax_inclusive ?? false);

            $lineSum = 0.0;   // Σ prorated item subtotals (menu money on inclusive bills)
            $taxSum = 0.0;    // Σ prorated item tax
            $exemptSum = 0.0; // Σ prorated exempt-line subtotals (taxable/exempt split)
            $returnItems = [];

            foreach ($r->items as $row) {
                $qty = (float) $row['return_qty'];
                if ($qty <= 0) {
                    continue;
                }
                $orig = $original->items->firstWhere('id', (int) $row['item_id']);
                if (!$orig) {
                    continue;
                }
                $remaining = round((float) $orig->quantity - (float) ($orig->returned_quantity ?? 0), 3);
                if ($qty > $remaining + 0.0005) {
                    return ['error' => __('pos.return_over_qty', ['item' => $orig->item_name, 'qty' => rtrim(rtrim(number_format($remaining, 3), '0'), '.')])];
                }
                $ratio = $qty / max((float) $orig->quantity, 0.001);
                $sub = round((float) $orig->subtotal * $ratio, 2);
                $tax = round((float) ($orig->tax_amount ?? 0) * $ratio, 2);
                $itemDisc = round((float) ($orig->item_discount_amount ?? 0) * $ratio, 2);

                $lineSum += $sub;
                $taxSum += $tax;
                if ($orig->is_tax_exempt) {
                    $exemptSum += $sub;
                }

                $returnItems[] = [
                    'parent_item_id' => $orig->id,
                    'item_type' => $orig->item_type,
                    'item_id' => $orig->item_id,
                    'item_name' => $orig->item_name,
                    'quantity' => $qty,
                    'unit_price' => $orig->unit_price,
                    'cost_price' => $orig->cost_price,
                    'subtotal' => $sub,
                    'is_tax_exempt' => (bool) $orig->is_tax_exempt,
                    'is_third_schedule' => (bool) ($orig->is_third_schedule ?? false),
                    'tax_rate' => $orig->tax_rate,
                    'tax_amount' => $tax,
                    // Informational — the sold line's own item-discount share.
                    'item_discount_amount' => $itemDisc,
                ];

                // Over-return guard state ON the parent line.
                $orig->update(['returned_quantity' => round((float) ($orig->returned_quantity ?? 0) + $qty, 3)]);
            }

            if (empty($returnItems)) {
                return ['error' => __('pos.return_no_items')];
            }

            // Customer money returned before the bill-level discount share:
            // inclusive lines already carry their tax inside the menu price.
            $returnValue = $taxInclusive ? $lineSum : $lineSum + $taxSum;

            // ── PARENT BILL-LEVEL DISCOUNT PRORATION ─────────────────────────
            // Item subtotals are already net of ITEM discounts; the bill-level
            // discount reduced what the customer actually paid, so the refund
            // carries its proportional share. The return row's discount_amount
            // holds ONLY this share, capped across multiple partial returns.
            $billDiscShare = 0.0;
            $parentBillDisc = round((float) ($original->discount_amount ?? 0), 2);
            $parentGoods = round((float) $original->subtotal + (float) $original->tax_amount, 2);
            if ($parentBillDisc > 0 && $parentGoods > 0) {
                $ratio = $returnValue / $parentGoods;
                $billDiscShare = round($parentBillDisc * min($ratio, 1), 2);
                $alreadyShared = round((float) PosTransaction::withoutGlobalScope('hide_archived')
                    ->where('company_id', $companyId)
                    ->where('parent_transaction_id', $original->id)
                    ->where('transaction_type', 'return')->sum('discount_amount'), 2);
                $billDiscShare = max(0.0, min($billDiscShare, round($parentBillDisc - $alreadyShared, 2)));
                $billDiscShare = min($billDiscShare, round($returnValue, 2));
            }

            // ── HEADER MATH (PRA conventions) ────────────────────────────────
            // Whole-rupee grand total on every PRA write path; inclusive bills
            // keep 2dp header tax + ex-tax-consistent subtotal, exclusive bills
            // whole-rupee header tax (mirrors the sale-side storeInvoice math).
            if ($taxInclusive) {
                $headerTax = round($taxSum, 2);
                $headerSubtotal = round($lineSum - $headerTax, 2);
            } else {
                $headerTax = round($taxSum);
                $headerSubtotal = round($lineSum, 2);
            }
            $refundTotal = round($returnValue - $billDiscShare);
            if ($refundTotal < 0) {
                $refundTotal = 0.0;
            }

            $invNum = 'RET-' . date('ymd') . '-' . strtoupper(Str::random(5));

            $data = [
                'company_id' => $companyId,
                'terminal_id' => $original->terminal_id,
                'invoice_number' => $invNum,
                // Returns live in the PRA stream (never 'local').
                'invoice_mode' => 'pra',
                'transaction_type' => 'return',
                'parent_transaction_id' => $original->id,
                'customer_id' => $original->customer_id,
                'customer_name' => $original->customer_name,
                'customer_phone' => $original->customer_phone,
                'subtotal' => $headerSubtotal,
                'discount_amount' => $billDiscShare,
                'tax_rate' => $original->tax_rate,
                'tax_amount' => $headerTax,
                'exempt_amount' => round($exemptSum, 2),
                'total_amount' => $refundTotal,
                // Card bucket normalization: 'card' is stored as 'debit_card'.
                'payment_method' => $r->refund_method === 'card' ? 'debit_card' : 'cash',
                'status' => 'completed',
                // NULL pra_status = local return (reporting-OFF-final category);
                // eligible returns flip to 'pending' below.
                'pra_status' => null,
                'created_by' => $user->id,
            ];
            // Tax-inclusive snapshot rides the return so payload math branches
            // exactly like the parent (schema-drift guard: columns may lag).
            if (Schema::hasColumn('pos_transactions', 'tax_inclusive')) {
                $data['tax_inclusive'] = $taxInclusive;
                $data['tax_menu_rate'] = $original->tax_menu_rate ?? null;
            }

            $return = PosTransaction::create($data);

            foreach ($returnItems as $it) {
                $it['transaction_id'] = $return->id;
                PosTransactionItem::create($it);
            }

            // ── STOCK RESTORE (symmetry guard) ───────────────────────────────
            // Only restore products the ORIGINAL sale actually deducted — if
            // tracking was OFF at sale time there is no deduct movement, and
            // restoring would mint stock out of thin air.
            $company = Company::find($companyId);
            if ($company && $company->inventory_enabled) {
                $deductedProductIds = InventoryMovement::where('company_id', $companyId)
                    ->where('reference_type', 'pos_transaction')
                    ->where('reference_id', $original->id)
                    ->where('type', InventoryMovement::TYPE_SALE)
                    ->pluck('product_id')->map(fn ($v) => (int) $v)->all();
                foreach ($returnItems as $it) {
                    if (($it['item_type'] ?? null) === 'product'
                        && !empty($it['item_id'])
                        && in_array((int) $it['item_id'], $deductedProductIds, true)
                        && (float) $it['quantity'] > 0) {
                        try {
                            \App\Services\InventoryService::addStock(
                                $companyId,
                                (int) $it['item_id'],
                                (float) $it['quantity'],
                                (float) $it['unit_price'],
                                InventoryMovement::TYPE_RETURN_IN,
                                null,
                                ['type' => 'pos_return', 'id' => $return->id, 'number' => $invNum],
                                'POS return restock (bill ' . $original->invoice_number . ')',
                                $user->id
                            );
                            // Mirror sync: pos_products.stock_quantity stays in
                            // lockstep with inventory_stocks on EVERY write path.
                            PosProduct::where('company_id', $companyId)
                                ->where('id', (int) $it['item_id'])
                                ->whereNotNull('stock_quantity')
                                ->increment('stock_quantity', (int) round((float) $it['quantity']));
                        } catch (\Throwable $stockEx) {
                            Log::warning('POS return stock restore failed', ['tx' => $return->id, 'err' => $stockEx->getMessage()]);
                        }
                    }
                }
            }

            // ── PRA CREDIT NOTE ELIGIBILITY ──────────────────────────────────
            // Only a parent PRA has actually numbered gives a valid RefUSIN.
            // 'pending' queues it for cloud submit (below, post-commit) or the
            // desktop agent in fiscal-device mode — same pipeline as sales.
            $praEligible = !empty($original->pra_invoice_number)
                && $company && $company->praReportingActive();
            if ($praEligible) {
                $return->update(['pra_status' => 'pending']);
            }

            return ['return' => $return, 'praEligible' => $praEligible, 'company' => $company];
        });

        if (isset($result['error'])) {
            return back()->with('error', $result['error']);
        }

        $return = $result['return'];

        // PRA submission AFTER commit — network I/O never inside the DB txn.
        // sendInvoice handles cloud, fiscal-device queueing, and the failure
        // fallback (pending/offline) itself; the row is already 'pending' so a
        // hard crash here still leaves it retryable by the sync job/agent.
        if ($result['praEligible']) {
            try {
                $svc = new PraIntegrationService($result['company']);
                $svc->sendInvoice($return->fresh());
            } catch (\Throwable $e) {
                Log::warning('POS return PRA submit failed post-commit', ['tx' => $return->id, 'err' => $e->getMessage()]);
            }
        }

        return redirect()->route('pos.transaction.show', $return->id)
            ->with('success', __('pos.return_processed', ['amount' => number_format((float) $return->total_amount)]));
    }
}

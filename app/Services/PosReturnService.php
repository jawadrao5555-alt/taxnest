<?php

namespace App\Services;

use App\Models\Company;
use App\Models\InventoryMovement;
use App\Models\PosProduct;
use App\Models\PosTransaction;
use App\Models\PosTransactionItem;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

/**
 * PRA POS Return / Credit-Note processing (Task 570; extracted to a shared
 * service in Task 586 so the manual return form AND the rider auto-return
 * both run the exact same math and guards).
 *
 * Invariants carried over unchanged from the manual flow:
 *  - Parent re-fetched UNDER LOCK; concurrent returns serialize (over-return
 *    guard via returned_quantity on the parent lines).
 *  - Return rows store POSITIVE amounts (FBR convention); PraIntegrationService
 *    flips to InvoiceType=3 + RefUSIN at submit time.
 *  - Bill-level discount share prorated and capped across partial returns.
 *  - PRA whole-rupee refund total; tax-inclusive parents keep menu-money
 *    header semantics.
 *  - Stock restored ONLY for products the original sale actually deducted
 *    (inventory_movements TYPE_SALE symmetry guard) + pos_products mirror.
 *  - Only parents with a PRA fiscal number queue a credit note ('pending');
 *    everything else stays a local return (pra_status NULL).
 *
 * Task 586 additions (rider auto-return path only — manual form unchanged):
 *  - allow_provisional: a provisional/local parent produces a LOCAL-stream
 *    return (invoice_mode 'local' + pra_status 'local', never reported).
 *  - wastage: returned goods were spoiled — stock restore is SKIPPED and the
 *    return row carries is_wastage=1 for future reporting.
 */
class PosReturnService
{
    /**
     * Which parent bills can be returned: completed finals in the PRA stream.
     * Provisionals keep their existing delete flow; returns of returns never.
     * (Single source of truth — PosReturnController::returnableReason delegates here.)
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

    /** Any return bill already created against this parent (partial or full). */
    public static function hasExistingReturn(PosTransaction $txn): bool
    {
        return PosTransaction::withoutGlobalScope('hide_archived')
            ->where('company_id', $txn->company_id)
            ->where('parent_transaction_id', $txn->id)
            ->where('transaction_type', 'return')
            ->exists();
    }

    /**
     * Create a return bill (credit note) against $originalId.
     *
     * @param array|null $items  [['item_id' => parent line id, 'return_qty' => qty], ...]
     *                           NULL = FULL return of every line's remaining
     *                           quantity (computed under the same lock).
     * @param string $refundMethod 'cash' | 'card'
     * @param array $opts  wastage: bool (skip stock restore + flag row),
     *                     allow_provisional: bool (rider path — local parent
     *                     produces a local-stream return).
     *
     * @return array ['error' => msg] | ['return' => PosTransaction,
     *               'praEligible' => bool, 'company' => Company]
     */
    public static function createReturn(int $companyId, int $originalId, ?array $items, string $refundMethod, ?int $userId, array $opts = []): array
    {
        $wastage = (bool) ($opts['wastage'] ?? false);
        $allowProvisional = (bool) ($opts['allow_provisional'] ?? false);

        return DB::transaction(function () use ($companyId, $originalId, $items, $refundMethod, $userId, $wastage, $allowProvisional) {
            // Re-fetch UNDER LOCK — concurrent returns of the same bill must
            // serialize here or both see the same remaining quantities and
            // double-refund/double-restock.
            $original = PosTransaction::withoutGlobalScope('hide_archived')
                ->where('company_id', $companyId)
                ->lockForUpdate()->findOrFail($originalId);
            $original->load('items');

            $reason = self::returnableReason($original);
            $provisionalParent = ($reason === 'provisional');
            if ($reason !== null && !($provisionalParent && $allowProvisional)) {
                return ['error' => __('pos.return_not_allowed_' . $reason)];
            }

            // NULL items = full return: every line's remaining quantity,
            // computed under this same lock (race-free).
            if ($items === null) {
                $items = $original->items->map(fn ($it) => [
                    'item_id' => $it->id,
                    'return_qty' => round((float) $it->quantity - (float) ($it->returned_quantity ?? 0), 3),
                ])->all();
            }

            $taxInclusive = (bool) ($original->tax_inclusive ?? false);

            $lineSum = 0.0;   // Σ prorated item subtotals (menu money on inclusive bills)
            $taxSum = 0.0;    // Σ prorated item tax
            $exemptSum = 0.0; // Σ prorated exempt-line subtotals (taxable/exempt split)
            $returnItems = [];

            foreach ($items as $row) {
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
                // Returns live in the PRA stream (never 'local') — EXCEPT a
                // provisional parent's auto return (Task 586), which mirrors
                // the parent's local triple so the local stream/day-close
                // treats parent and return consistently.
                'invoice_mode' => $provisionalParent ? 'local' : 'pra',
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
                'payment_method' => $refundMethod === 'card' ? 'debit_card' : 'cash',
                'status' => 'completed',
                // NULL pra_status = local return (reporting-OFF-final category);
                // provisional parents mirror the 'local' triple; eligible
                // returns flip to 'pending' below.
                'pra_status' => $provisionalParent ? 'local' : null,
                'created_by' => $userId,
            ];
            // Tax-inclusive snapshot rides the return so payload math branches
            // exactly like the parent (schema-drift guard: columns may lag).
            if (Schema::hasColumn('pos_transactions', 'tax_inclusive')) {
                $data['tax_inclusive'] = $taxInclusive;
                $data['tax_menu_rate'] = $original->tax_menu_rate ?? null;
            }
            // Wastage flag (Task 586) — schema-drift guard.
            if (Schema::hasColumn('pos_transactions', 'is_wastage')) {
                $data['is_wastage'] = $wastage;
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
            // Wastage (Task 586): goods came back spoiled — NOTHING is restored.
            $company = Company::find($companyId);
            if (!$wastage && $company && $company->inventory_enabled) {
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
                            InventoryService::addStock(
                                $companyId,
                                (int) $it['item_id'],
                                (float) $it['quantity'],
                                (float) $it['unit_price'],
                                InventoryMovement::TYPE_RETURN_IN,
                                null,
                                ['type' => 'pos_return', 'id' => $return->id, 'number' => $invNum],
                                'POS return restock (bill ' . $original->invoice_number . ')',
                                $userId
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
            // 'pending' queues it for cloud submit (post-commit) or the desktop
            // agent in fiscal-device mode — same pipeline as sales. Provisional
            // parents NEVER report (their return is a local-stream record).
            $praEligible = !$provisionalParent
                && !empty($original->pra_invoice_number)
                && $company && $company->praReportingActive();
            if ($praEligible) {
                $return->update(['pra_status' => 'pending']);
            }

            return ['return' => $return, 'praEligible' => $praEligible, 'company' => $company];
        });
    }

    /**
     * PRA submission AFTER commit — network I/O never inside the DB txn.
     * sendInvoice handles cloud, fiscal-device queueing, and the failure
     * fallback (pending/offline) itself; the row is already 'pending' so a
     * hard crash here still leaves it retryable by the sync job/agent.
     */
    public static function submitToPraPostCommit(array $result): void
    {
        if (empty($result['praEligible']) || empty($result['return'])) {
            return;
        }
        try {
            $svc = new PraIntegrationService($result['company']);
            $svc->sendInvoice($result['return']->fresh());
        } catch (\Throwable $e) {
            Log::warning('POS return PRA submit failed post-commit', ['tx' => $result['return']->id, 'err' => $e->getMessage()]);
        }
    }
}

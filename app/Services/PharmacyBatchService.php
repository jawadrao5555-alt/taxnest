<?php

namespace App\Services;

use App\Models\Company;
use App\Models\InventoryMovement;
use App\Models\InventoryStock;
use App\Models\PharmacyStockAction;
use App\Models\Product;
use App\Models\ProductBatch;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Batch & expiry stock for FBR POS Pharmacy Mode (Task 1558).
 *
 * ── The one invariant everything here protects ────────────────────────────
 * inventory_stocks.quantity stays the single authoritative number. Batches are
 * a SUB-LEDGER that explains how that number is split, never a replacement for
 * it. So for every product/branch:
 *
 *     aggregate  =  Σ active batch quantities  +  untracked remainder
 *
 * The untracked remainder is what a shop already had on the shelf before it
 * ever recorded a batch. It is not an error and it is never "healed" away:
 * a medical store that switches the module on this morning must keep billing
 * this afternoon. FEFO simply eats the dated batches first and falls through to
 * the remainder, so the untracked pile shrinks naturally as the shop receives
 * real batches.
 *
 * Consequently NOTHING in here writes inventory_stocks. Aggregate movement is
 * left entirely to InventoryService, which every existing purchase, sale,
 * transfer, correction and return path already calls. This service only ever
 * moves the batch rows beside it, in the same transaction as its caller.
 */
class PharmacyBatchService
{
    /** A batch inside this many days is "short-dated" — warn, never refuse. */
    public const NEAR_EXPIRY_DAYS = 90;

    /** Rounding used for every batch quantity, matching the column's decimal:3. */
    private const Q = 3;

    // ─────────────────────────────────────────────────────────────────────
    //  Entitlement
    // ─────────────────────────────────────────────────────────────────────

    /**
     * Is batch/expiry tracking live for this shop?
     *
     * Pharmacy mode must be live (package gate AND the shop's own switch) and
     * the batch child flag on. Anything else and every method here becomes a
     * no-op, so a non-pharmacy FBR shop's stock paths behave byte-for-byte as
     * they did before this feature existed.
     */
    public static function trackingEnabled(?Company $company): bool
    {
        if (!PosFeatureService::pharmacyLive($company)) {
            return false;
        }

        // The batch table is a SUB-ledger of inventory_stocks, and every stock
        // path that would keep the two in step is itself gated on the
        // inventory_enabled COLUMN (the old dual-switch trap). If the column is
        // off, the aggregate is never touched — so planning batch allocations
        // here would draw the sub-ledger down against a total that never moves.
        // Answer no, and the shop simply bills the way it does today.
        if (!$company || !$company->inventory_enabled) {
            return false;
        }

        return (bool) (PosFeatureService::forCompany($company)->batch_expiry ?? false);
    }

    // ─────────────────────────────────────────────────────────────────────
    //  Receiving
    // ─────────────────────────────────────────────────────────────────────

    /**
     * Record (or top up) a batch. Call it from the SAME transaction that added
     * the aggregate stock, right after InventoryService::addStock().
     *
     * A batch's identity is company + product + branch + number + expiry, so
     * receiving the same batch twice tops the existing row up instead of
     * splitting the shelf in two. Cost is blended the same way the aggregate
     * average is, so per-batch valuation cannot drift from the stock page.
     *
     * Returns null (silently) when the line carries no batch number at all —
     * a pharmacy is allowed to receive an untracked item, it just does not get
     * expiry control on it.
     */
    public static function receive(
        int $companyId,
        int $productId,
        ?int $branchId,
        float $quantity,
        ?string $batchNumber,
        ?string $expiryDate = null,
        float $costPrice = 0,
        array $extra = []
    ): ?ProductBatch {
        $batchNumber = self::cleanBatchNumber($batchNumber);
        if ($batchNumber === null || $quantity == 0.0) {
            return null;
        }

        $expiry = self::normalizeExpiry($expiryDate);

        return DB::transaction(function () use ($companyId, $productId, $branchId, $quantity, $batchNumber, $expiry, $costPrice, $extra) {
            $batch = ProductBatch::where('company_id', $companyId)
                ->where('product_id', $productId)
                ->where('branch_id', $branchId)
                ->where('batch_number', $batchNumber)
                ->whereRaw($expiry === null ? 'expiry_date IS NULL' : 'expiry_date = ?', $expiry === null ? [] : [$expiry])
                ->lockForUpdate()
                ->first();

            if (!$batch) {
                $batch = new ProductBatch([
                    'company_id' => $companyId,
                    'product_id' => $productId,
                    'branch_id' => $branchId,
                    'batch_number' => $batchNumber,
                    'expiry_date' => $expiry,
                    'quantity' => 0,
                    'cost_price' => 0,
                    'status' => ProductBatch::STATUS_ACTIVE,
                ]);
            }

            $before = (float) $batch->quantity;
            $after  = round($before + $quantity, self::Q);

            // Blend the cost exactly like the aggregate average does: only a
            // real incoming rate moves it, and only over the quantity that
            // actually arrived. A zero rate (unpriced correction) leaves the
            // batch's own cost alone rather than dragging it to zero.
            if ($costPrice > 0 && $quantity > 0) {
                $batch->cost_price = $before > 0 && $after > 0
                    ? round((($before * (float) $batch->cost_price) + ($quantity * $costPrice)) / $after, 2)
                    : round($costPrice, 2);
            }

            $batch->quantity = max(0, $after);
            if (array_key_exists('retail_price', $extra) && $extra['retail_price'] !== null && $extra['retail_price'] !== '') {
                $batch->retail_price = round((float) $extra['retail_price'], 2);
            }
            foreach (['supplier_id', 'purchase_order_id', 'received_at', 'notes', 'created_by'] as $k) {
                if (!empty($extra[$k])) {
                    $batch->{$k} = $extra[$k];
                }
            }
            if (!$batch->received_at) {
                $batch->received_at = now()->toDateString();
            }
            // Topping a quarantined batch up does NOT release it — only an
            // explicit release may do that, or a write-off decision quietly
            // undoes itself the next time the shop receives the same number.
            if (!$batch->exists) {
                $batch->status = ProductBatch::STATUS_ACTIVE;
            }
            $batch->save();

            return $batch;
        });
    }

    // ─────────────────────────────────────────────────────────────────────
    //  Selling — FEFO
    // ─────────────────────────────────────────────────────────────────────

    /**
     * Plan which batches a sale line comes off, shortest expiry first.
     *
     * Returns:
     *   allocations — [['batch_id','batch_number','expiry','quantity','cost'], …]
     *   untracked   — quantity taken from the pre-batch remainder
     *   primary     — the batch the cashier is told about and that prints
     *   short_dated — true when any allocated batch is inside the warning window
     *   error       — a translated message when the line CANNOT be sold
     *
     * A hard refusal only ever happens for expired stock. Everything else
     * degrades to the untracked remainder, because refusing a sale over our own
     * bookkeeping would shut a counter that was working fine yesterday.
     */
    public static function planAllocation(
        int $companyId,
        Product $product,
        ?int $branchId,
        float $quantity,
        ?int $forceBatchId = null,
        bool $allowExpired = false
    ): array {
        $out = [
            'allocations' => [],
            'untracked' => 0.0,
            'primary' => null,
            'short_dated' => false,
            'expired_blocked' => false,
            'error' => null,
        ];
        $quantity = round($quantity, self::Q);
        if ($quantity <= 0) {
            return $out;
        }

        $batches = self::sellableQuery($companyId, $product->id, $branchId)->get();
        $aggregate = (float) (InventoryStock::where('company_id', $companyId)
            ->where('product_id', $product->id)
            ->where('branch_id', $branchId)
            ->value('quantity') ?? 0);
        $tracked = (float) ProductBatch::where('company_id', $companyId)
            ->where('product_id', $product->id)
            ->where('branch_id', $branchId)
            ->whereIn('status', [ProductBatch::STATUS_ACTIVE, ProductBatch::STATUS_QUARANTINED])
            ->sum('quantity');
        $untrackedPool = round(max(0, $aggregate - $tracked), self::Q);

        // Owner override: the counter asked for one specific batch. Honour it
        // exactly — no FEFO spill — so a deliberate choice cannot be silently
        // "corrected" into a different expiry date on the customer's strip.
        //
        // The batch id arrives from the browser, so it is looked up ONLY within
        // this company + THIS product + THIS branch. A wider lookup would let a
        // hand-edited request draw down some other medicine's (or some other
        // branch's) batch while the sale deducted the aggregate in front of the
        // cashier — two ledgers moving in different places for the same sale.
        // The status/expiry checks stay below so the shop still gets the real
        // reason ("expired", "quarantined") instead of a blank "not found".
        if ($forceBatchId) {
            $picked = $batches->firstWhere('id', $forceBatchId)
                ?: ProductBatch::where('company_id', $companyId)
                    ->where('product_id', $product->id)
                    ->where('branch_id', $branchId)
                    ->where('id', $forceBatchId)
                    ->first();
            if (!$picked) {
                $out['error'] = __('pos.ph_batch_not_found');
                return $out;
            }
            if ($picked->isExpired() && !$allowExpired) {
                $out['error'] = __('pos.ph_batch_expired_block', ['batch' => $picked->batch_number]);
                $out['expired_blocked'] = true;
                return $out;
            }
            if ($picked->status !== ProductBatch::STATUS_ACTIVE) {
                $out['error'] = __('pos.ph_batch_quarantined_block', ['batch' => $picked->batch_number]);
                return $out;
            }
            $take = min($quantity, (float) $picked->quantity);
            if ($take > 0) {
                $out['allocations'][] = self::allocationRow($picked, $take);
            }
            $remaining = round($quantity - $take, self::Q);
            if ($remaining > 0) {
                $out['untracked'] = min($remaining, $untrackedPool);
            }
            $out['primary'] = $out['allocations'][0] ?? null;
            $out['short_dated'] = self::anyShortDated($out['allocations']);
            return $out;
        }

        $remaining = $quantity;
        foreach ($batches as $batch) {
            if ($remaining <= 0) {
                break;
            }
            $take = min($remaining, (float) $batch->quantity);
            if ($take <= 0) {
                continue;
            }
            $out['allocations'][] = self::allocationRow($batch, $take);
            $remaining = round($remaining - $take, self::Q);
        }

        if ($remaining > 0) {
            // Nothing dated left. Fall through to the pre-batch remainder if
            // the shop genuinely has one; otherwise the only stock standing
            // between the counter and the sale is EXPIRED, and that is the one
            // thing a medical store may never hand over.
            $fromUntracked = min($remaining, $untrackedPool);
            $out['untracked'] = round($fromUntracked, self::Q);
            $remaining = round($remaining - $fromUntracked, self::Q);

            if ($remaining > 0 && !$allowExpired && self::hasExpiredStock($companyId, $product->id, $branchId)) {
                $out['error'] = __('pos.ph_only_expired_left', ['item' => $product->name]);
                $out['expired_blocked'] = true;
                return $out;
            }
            // Anything still left over is an ordinary short-stock situation.
            // The existing stock rules already decide whether that blocks the
            // sale, so it is deliberately NOT an error here.
            if ($remaining > 0) {
                $out['untracked'] = round($out['untracked'] + $remaining, self::Q);
            }
        }

        $out['primary'] = $out['allocations'][0] ?? null;
        $out['short_dated'] = self::anyShortDated($out['allocations']);

        return $out;
    }

    /**
     * Apply a plan produced by planAllocation() — decrement the batch rows.
     * Must run inside the caller's transaction, right beside the aggregate
     * deduction, so the sub-ledger can never survive a rolled-back sale.
     */
    public static function applyAllocation(array $allocations): void
    {
        foreach ($allocations as $row) {
            $id = (int) ($row['batch_id'] ?? 0);
            $qty = round((float) ($row['quantity'] ?? 0), self::Q);
            if ($id <= 0 || $qty <= 0) {
                continue;
            }
            $batch = ProductBatch::lockForUpdate()->find($id);
            if (!$batch) {
                continue;
            }
            // Never below zero: the aggregate is the authority, and a negative
            // batch would poison every valuation report reading this table.
            $batch->quantity = round(max(0, (float) $batch->quantity - $qty), self::Q);
            $batch->save();
        }
    }

    /**
     * Put returned quantity back on the EXACT batches it left on.
     *
     * Restores in reverse allocation order so a partial return of a
     * two-batch line gives back the last batch consumed first — the same strip
     * the customer is most likely holding. Anything that cannot be matched to
     * a batch (a legacy sale, or a line that came off the untracked remainder)
     * simply is not restored here: the aggregate restore already covered it,
     * and inventing a batch to hold it would be a lie in the expiry report.
     *
     * Callers must pass the allocation that is still OUTSTANDING, not the raw
     * sale allocation — see remainingAllocation() for why.
     *
     * @param  array $allocation  the still-unreturned batch split of the line
     * @param  float $quantity    how much of the line is coming back
     * @return array              the portion actually restored, per batch
     */
    public static function restoreAllocation(array $allocation, float $quantity): array
    {
        $quantity = round($quantity, self::Q);
        if ($quantity <= 0 || !$allocation) {
            return [];
        }

        $restored = [];
        foreach (array_reverse($allocation) as $row) {
            if ($quantity <= 0) {
                break;
            }
            $id = (int) ($row['batch_id'] ?? 0);
            $sold = round((float) ($row['quantity'] ?? 0), self::Q);
            if ($id <= 0 || $sold <= 0) {
                continue;
            }
            $give = min($quantity, $sold);
            $batch = ProductBatch::lockForUpdate()->find($id);
            if (!$batch) {
                continue;
            }
            $batch->quantity = round((float) $batch->quantity + $give, self::Q);
            $batch->save();
            $restored[] = ['batch_id' => $id, 'batch_number' => $batch->batch_number, 'quantity' => $give];
            $quantity = round($quantity - $give, self::Q);
        }

        return $restored;
    }

    /**
     * What of a sale line's batch split has NOT been returned yet.
     *
     * A line sold from two batches can come back in two separate partial
     * returns. Restoring against the raw sale allocation both times would hand
     * the same batch its quantity back twice and mint stock that was never
     * sold — and it would be expiry-dated stock, so it would go straight into
     * the near-expiry and claim reports as if the shop still held it.
     *
     * Every return row records the batch split it actually restored, so the
     * outstanding split is simply the sale's own allocation with those earlier
     * restores subtracted, batch by batch, in the original order.
     *
     * @param  array $sold      the sale line's stored batch_allocation
     * @param  array $returned  the allocations already restored by earlier returns
     * @return array            the split still available to restore
     */
    public static function remainingAllocation(array $sold, array $returned): array
    {
        $taken = [];
        foreach ($returned as $rows) {
            foreach ((array) $rows as $row) {
                $id = (int) ($row['batch_id'] ?? 0);
                $qty = round((float) ($row['quantity'] ?? 0), self::Q);
                if ($id > 0 && $qty > 0) {
                    $taken[$id] = round(($taken[$id] ?? 0) + $qty, self::Q);
                }
            }
        }
        if (!$taken) {
            return $sold;
        }

        $out = [];
        foreach ($sold as $row) {
            $id = (int) ($row['batch_id'] ?? 0);
            $qty = round((float) ($row['quantity'] ?? 0), self::Q);
            if ($id <= 0 || $qty <= 0) {
                continue;
            }
            $eat = min($qty, $taken[$id] ?? 0);
            if ($eat > 0) {
                $taken[$id] = round($taken[$id] - $eat, self::Q);
                $qty = round($qty - $eat, self::Q);
            }
            if ($qty > 0) {
                $row['quantity'] = $qty;
                $out[] = $row;
            }
        }

        return $out;
    }

    // ─────────────────────────────────────────────────────────────────────
    //  Expiry control
    // ─────────────────────────────────────────────────────────────────────

    /**
     * Quarantine, release or write off a batch, with a reason and a person.
     *
     * Only a WRITE-OFF moves stock: the quantity leaves the shelf for good, so
     * the aggregate is deducted through the normal inventory ledger (an
     * adjustment_out movement stamped with the batch) and the batch row is
     * emptied and closed. Quarantine and release move nothing — they only
     * decide whether FEFO is allowed to touch the batch — which is exactly what
     * a shop means when it pulls a suspect batch off the counter but keeps it
     * on the premises for the distributor to inspect.
     */
    public static function act(
        ProductBatch $batch,
        string $action,
        array $opts = []
    ): PharmacyStockAction {
        return DB::transaction(function () use ($batch, $action, $opts) {
            $batch = ProductBatch::lockForUpdate()->findOrFail($batch->id);
            $quantity = round((float) ($opts['quantity'] ?? $batch->quantity), self::Q);
            $quantity = min($quantity, (float) $batch->quantity);

            if ($action === PharmacyStockAction::ACTION_QUARANTINE) {
                $batch->status = ProductBatch::STATUS_QUARANTINED;
                $batch->save();
                $quantity = (float) $batch->quantity;
            } elseif ($action === PharmacyStockAction::ACTION_RELEASE) {
                $batch->status = ProductBatch::STATUS_ACTIVE;
                $batch->save();
                $quantity = (float) $batch->quantity;
            } elseif ($action === PharmacyStockAction::ACTION_WRITE_OFF) {
                if ($quantity > 0) {
                    InventoryService::deductStock(
                        $batch->company_id,
                        $batch->product_id,
                        $quantity,
                        (float) $batch->cost_price,
                        InventoryMovement::TYPE_ADJUSTMENT_OUT,
                        $batch->branch_id,
                        ['type' => 'pharmacy_writeoff', 'id' => $batch->id, 'number' => $batch->batch_number],
                        trim(($opts['reason'] ?? 'expired') . ' — ' . ($opts['notes'] ?? '')),
                        $opts['created_by'] ?? null,
                        [
                            'batch_id' => $batch->id,
                            'batch_number' => $batch->batch_number,
                            'batch_expiry' => $batch->expiry_date?->toDateString(),
                        ]
                    );
                }
                $batch->quantity = round(max(0, (float) $batch->quantity - $quantity), self::Q);
                $batch->status = ProductBatch::STATUS_WRITTEN_OFF;
                $batch->save();
            }

            return PharmacyStockAction::create([
                'company_id' => $batch->company_id,
                'branch_id' => $batch->branch_id,
                'product_id' => $batch->product_id,
                'batch_id' => $batch->id,
                'action' => $action,
                'quantity' => $quantity,
                'cost_value' => round($quantity * (float) $batch->cost_price, 2),
                'reason' => $opts['reason'] ?? null,
                'responsible_name' => $opts['responsible_name'] ?? null,
                'responsible_user_id' => $opts['responsible_user_id'] ?? null,
                'claim_id' => $opts['claim_id'] ?? null,
                'notes' => $opts['notes'] ?? null,
                'created_by' => $opts['created_by'] ?? null,
            ]);
        });
    }

    // ─────────────────────────────────────────────────────────────────────
    //  Reads
    // ─────────────────────────────────────────────────────────────────────

    /** Sellable batches, shortest expiry first; undated batches go last. */
    public static function sellableQuery(int $companyId, int $productId, ?int $branchId)
    {
        return ProductBatch::where('company_id', $companyId)
            ->where('product_id', $productId)
            ->where('branch_id', $branchId)
            ->where('status', ProductBatch::STATUS_ACTIVE)
            ->where('quantity', '>', 0)
            ->where(function ($q) {
                $q->whereNull('expiry_date')->orWhere('expiry_date', '>=', now()->toDateString());
            })
            // FEFO: a real date always beats an undated batch, so undated stock
            // is not quietly sold ahead of something that is about to die.
            ->orderByRaw('CASE WHEN expiry_date IS NULL THEN 1 ELSE 0 END')
            ->orderBy('expiry_date')
            ->orderBy('id');
    }

    /** Anything already past its date and still sitting on the shelf? */
    public static function hasExpiredStock(int $companyId, int $productId, ?int $branchId): bool
    {
        return ProductBatch::where('company_id', $companyId)
            ->where('product_id', $productId)
            ->where('branch_id', $branchId)
            ->where('status', '!=', ProductBatch::STATUS_WRITTEN_OFF)
            ->where('quantity', '>', 0)
            ->whereNotNull('expiry_date')
            ->where('expiry_date', '<', now()->toDateString())
            ->exists();
    }

    /**
     * The pre-batch remainder for a product/branch: aggregate minus everything
     * the batch ledger knows about. Never negative — an over-counted batch
     * ledger is a reporting problem, not a reason to hide stock.
     */
    public static function untrackedQuantity(int $companyId, int $productId, ?int $branchId): float
    {
        $aggregate = (float) (InventoryStock::where('company_id', $companyId)
            ->where('product_id', $productId)
            ->where('branch_id', $branchId)
            ->value('quantity') ?? 0);
        $tracked = (float) ProductBatch::where('company_id', $companyId)
            ->where('product_id', $productId)
            ->where('branch_id', $branchId)
            ->whereIn('status', [ProductBatch::STATUS_ACTIVE, ProductBatch::STATUS_QUARANTINED])
            ->sum('quantity');

        return round(max(0, $aggregate - $tracked), self::Q);
    }

    /**
     * Compact batch list for the sale screen's picker. Deliberately small —
     * this is fetched per product on demand, never baked into the boot payload.
     */
    public static function pickerRows(int $companyId, int $productId, ?int $branchId): array
    {
        $rows = ProductBatch::where('company_id', $companyId)
            ->where('product_id', $productId)
            ->where('branch_id', $branchId)
            ->where('quantity', '>', 0)
            ->where('status', '!=', ProductBatch::STATUS_WRITTEN_OFF)
            ->orderByRaw('CASE WHEN expiry_date IS NULL THEN 1 ELSE 0 END')
            ->orderBy('expiry_date')
            ->limit(60)
            ->get();

        return $rows->map(function (ProductBatch $b) {
            $days = $b->daysToExpiry();
            return [
                'id' => $b->id,
                'batch' => $b->batch_number,
                'expiry' => $b->expiry_date?->format('d M Y'),
                'expiry_raw' => $b->expiry_date?->toDateString(),
                'quantity' => (float) $b->quantity,
                'retail' => $b->retail_price !== null ? (float) $b->retail_price : null,
                'cost' => (float) $b->cost_price,
                'expired' => $b->isExpired(),
                'short_dated' => $days !== null && $days >= 0 && $days <= self::NEAR_EXPIRY_DAYS,
                'days' => $days,
                'status' => $b->status,
                'sellable' => $b->isSellable(),
            ];
        })->all();
    }

    // ─────────────────────────────────────────────────────────────────────
    //  Helpers
    // ─────────────────────────────────────────────────────────────────────

    private static function allocationRow(ProductBatch $batch, float $quantity): array
    {
        return [
            'batch_id' => $batch->id,
            'batch_number' => $batch->batch_number,
            'expiry' => $batch->expiry_date?->toDateString(),
            'quantity' => round($quantity, self::Q),
            'cost' => (float) $batch->cost_price,
        ];
    }

    private static function anyShortDated(array $allocations): bool
    {
        foreach ($allocations as $row) {
            if (empty($row['expiry'])) {
                continue;
            }
            try {
                $days = now()->startOfDay()->diffInDays(\Carbon\Carbon::parse($row['expiry'])->startOfDay(), false);
            } catch (\Throwable $e) {
                continue;
            }
            if ($days >= 0 && $days <= self::NEAR_EXPIRY_DAYS) {
                return true;
            }
        }

        return false;
    }

    /** Batch numbers are printed on foil — trim, upper-case, cap the length. */
    public static function cleanBatchNumber(?string $value): ?string
    {
        $value = trim((string) $value);
        if ($value === '') {
            return null;
        }

        return mb_substr(mb_strtoupper($value), 0, 60);
    }

    /**
     * A medicine strip prints "EXP 04/27", meaning usable through the END of
     * April 2027. Accept that, a full date, and the usual slash forms, and
     * always store the LAST day of the month when only a month was given —
     * storing the 1st would expire the shop's stock a month early.
     */
    public static function normalizeExpiry(?string $value): ?string
    {
        $value = trim((string) $value);
        if ($value === '') {
            return null;
        }

        // MM/YYYY, MM-YYYY, MM/YY
        if (preg_match('/^(\d{1,2})[\/\-.](\d{2}|\d{4})$/', $value, $m)) {
            $month = (int) $m[1];
            $year = (int) $m[2];
            if ($year < 100) {
                $year += 2000;
            }
            if ($month >= 1 && $month <= 12) {
                return \Carbon\Carbon::create($year, $month, 1)->endOfMonth()->toDateString();
            }
        }

        try {
            return \Carbon\Carbon::parse($value)->toDateString();
        } catch (\Throwable $e) {
            Log::warning('Pharmacy: unparseable expiry date', ['value' => $value]);
            return null;
        }
    }
}

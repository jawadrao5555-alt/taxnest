<?php

namespace App\Services;

use App\Models\HealthBatchMovement;
use App\Models\HealthMedicine;
use App\Models\HealthMedicineBatch;
use App\Models\InventoryMovement;
use App\Models\InventoryStock;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * The pharmacy's ONE stock authority (Task 1549).
 *
 * Nothing else in the module may touch a quantity. Every method here writes
 * three things inside a single transaction:
 *
 *   1. the BATCH remainder            (health_medicine_batches.quantity)
 *   2. the BRANCH truth               (InventoryService → inventory_stocks
 *                                      + inventory_movements)
 *   3. the traceability ledger        (health_batch_movements)
 *
 * Keeping them together is the whole point: a counter sale, a ward return and
 * an expiry write-off must move the same number the rest of the platform reads,
 * and must always be answerable with "which lot, who, and why".
 *
 * EXPIRY POLICY — dispensing picks FEFO (first expiry, first out), never FIFO.
 * Expired stock is refused unless the owner deliberately opened it; short-dated
 * stock sells but the counter is warned before the medicine goes out.
 */
class HealthPharmacyStockService
{
    /** Batch movement type → the shared inventory movement it maps onto. */
    private const INVENTORY_TYPE = [
        HealthBatchMovement::TYPE_PURCHASE => InventoryMovement::TYPE_PURCHASE,
        HealthBatchMovement::TYPE_OPENING => InventoryMovement::TYPE_OPENING,
        HealthBatchMovement::TYPE_DISPENSE => InventoryMovement::TYPE_SALE,
        HealthBatchMovement::TYPE_SALE_RETURN => InventoryMovement::TYPE_RETURN_IN,
        HealthBatchMovement::TYPE_PURCHASE_RETURN => InventoryMovement::TYPE_RETURN_OUT,
        HealthBatchMovement::TYPE_WASTAGE => InventoryMovement::TYPE_ADJUSTMENT_OUT,
        HealthBatchMovement::TYPE_EXPIRY_WRITEOFF => InventoryMovement::TYPE_ADJUSTMENT_OUT,
        HealthBatchMovement::TYPE_ADJUSTMENT_IN => InventoryMovement::TYPE_ADJUSTMENT_IN,
        HealthBatchMovement::TYPE_ADJUSTMENT_OUT => InventoryMovement::TYPE_ADJUSTMENT_OUT,
        HealthBatchMovement::TYPE_TRANSFER_IN => InventoryMovement::TYPE_TRANSFER_IN,
        HealthBatchMovement::TYPE_TRANSFER_OUT => InventoryMovement::TYPE_TRANSFER_OUT,
    ];

    // ═══════════════════════ Receiving ═══════════════════════

    /**
     * Put a received lot into a branch.
     *
     * A repeat delivery of the SAME batch number and expiry into the same
     * branch merges into the existing lot (weighted cost) instead of splitting
     * the shelf into look-alike rows the pharmacist cannot tell apart.
     */
    public static function receive(
        int $companyId,
        HealthMedicine $medicine,
        array $line,
        ?int $branchId,
        array $reference = [],
        ?int $userId = null,
        string $type = HealthBatchMovement::TYPE_PURCHASE
    ): HealthMedicineBatch {
        $quantity = round((float) ($line['quantity'] ?? 0), 3);
        if ($quantity <= 0) {
            throw ValidationException::withMessages(['quantity' => [__('health.ph_qty_positive')]]);
        }

        $batchNo = trim((string) ($line['batch_no'] ?? '')) ?: null;
        $expiry = self::date($line['expiry_date'] ?? null);
        $cost = round((float) ($line['cost_price'] ?? 0), 2);
        $salePrice = round((float) ($line['sale_price'] ?? $medicine->sale_price ?? 0), 2);

        return DB::transaction(function () use (
            $companyId, $medicine, $branchId, $quantity, $batchNo, $expiry, $cost,
            $salePrice, $line, $reference, $userId, $type
        ) {
            $batch = null;
            if ($batchNo !== null) {
                $batch = HealthMedicineBatch::withoutGlobalScopes()
                    ->where('company_id', $companyId)
                    ->where('medicine_id', $medicine->id)
                    ->where('branch_id', $branchId)
                    ->where('batch_no', $batchNo)
                    ->where('status', HealthMedicineBatch::STATUS_ACTIVE)
                    ->when($expiry, fn ($q) => $q->whereDate('expiry_date', $expiry))
                    ->when(!$expiry, fn ($q) => $q->whereNull('expiry_date'))
                    ->lockForUpdate()
                    ->first();
            }

            if ($batch) {
                // Weighted cost, same rule the platform uses for a product's
                // running average — a re-buy at a new rate must not erase the
                // margin history of what is still on the shelf.
                $batch->cost_price = BranchStockService::blendCost(
                    (float) $batch->quantity,
                    (float) $batch->cost_price,
                    $quantity,
                    $cost
                );
                $batch->quantity = round((float) $batch->quantity + $quantity, 3);
                $batch->received_quantity = round((float) $batch->received_quantity + $quantity, 3);
                if ($salePrice > 0) {
                    $batch->sale_price = $salePrice;
                }
                $batch->save();
            } else {
                $batch = HealthMedicineBatch::withoutGlobalScopes()->create([
                    'company_id' => $companyId,
                    'branch_id' => $branchId,
                    'medicine_id' => $medicine->id,
                    'product_id' => $medicine->product_id,
                    'batch_no' => $batchNo,
                    'expiry_date' => $expiry,
                    'manufacture_date' => self::date($line['manufacture_date'] ?? null),
                    'received_quantity' => $quantity,
                    'quantity' => $quantity,
                    'cost_price' => $cost,
                    'sale_price' => $salePrice,
                    'supplier_id' => $line['supplier_id'] ?? null,
                    'purchase_order_id' => $line['purchase_order_id'] ?? null,
                    'purchase_order_item_id' => $line['purchase_order_item_id'] ?? null,
                    'status' => HealthMedicineBatch::STATUS_ACTIVE,
                    'notes' => $line['notes'] ?? null,
                    'created_by' => $userId,
                ]);
            }

            self::applyToBranchStock($companyId, $medicine, $branchId, $quantity, $cost, $type, $reference, $userId, $line['notes'] ?? null);

            self::logMovement($batch, $type, HealthBatchMovement::DIRECTION_IN, $quantity, $cost, $salePrice, $reference, $userId, $line['reason'] ?? null, $line['notes'] ?? null);

            return $batch;
        });
    }

    // ═══════════════════════ Expiry-aware picking ═══════════════════════

    /**
     * Choose the lots a dispense should come out of, first-expiry-first-out.
     *
     * Read-only: it decides and warns, it never moves stock. The caller applies
     * the plan inside its own transaction so a half-served prescription can
     * never be committed.
     *
     * @return array{allocations: array<int, array{batch: HealthMedicineBatch, quantity: float}>, shortfall: float, warnings: array<int, array{code: string, batch_no: ?string, expiry: ?string, days: ?int}>}
     */
    public static function plan(
        int $companyId,
        HealthMedicine $medicine,
        float $quantity,
        ?int $branchId,
        array $options = []
    ): array {
        $settings = HealthPharmacyService::settings($companyId);
        $allowExpired = (bool) ($options['allow_expired'] ?? !$settings->block_expired_dispense);
        $nearDays = (int) ($options['near_expiry_days'] ?? $settings->near_expiry_days);

        $quantity = round(max(0, (float) $quantity), 3);
        $allocations = [];
        $warnings = [];

        $batches = self::sellableBatches($companyId, (int) $medicine->id, $branchId)->get();

        $remaining = $quantity;
        foreach ($batches as $batch) {
            if ($remaining <= 0) {
                break;
            }

            if ($batch->isExpired()) {
                if (!$allowExpired) {
                    $warnings[] = self::warning('expired_skipped', $batch);
                    continue;
                }
                $warnings[] = self::warning('expired_used', $batch);
            } elseif ($settings->warn_short_dated && $batch->isShortDated($nearDays)) {
                $warnings[] = self::warning('short_dated', $batch);
            }

            $take = min($remaining, (float) $batch->quantity);
            if ($take <= 0) {
                continue;
            }

            $allocations[] = ['batch' => $batch, 'quantity' => round($take, 3)];
            $remaining = round($remaining - $take, 3);
        }

        if ($remaining > 0) {
            $warnings[] = ['code' => 'short_stock', 'batch_no' => null, 'expiry' => null, 'days' => null];
        }

        return [
            'allocations' => $allocations,
            'shortfall' => max(0, $remaining),
            'warnings' => $warnings,
        ];
    }

    /**
     * FEFO ordering, applied identically everywhere a batch pool is read.
     * Undated lots go LAST: an unknown expiry must not jump the queue ahead of
     * medicine that is about to die.
     */
    public static function sellableBatches(int $companyId, int $medicineId, ?int $branchId)
    {
        return HealthMedicineBatch::withoutGlobalScopes()
            ->where('company_id', $companyId)
            ->where('medicine_id', $medicineId)
            ->where('branch_id', $branchId)
            ->where('status', HealthMedicineBatch::STATUS_ACTIVE)
            ->where('quantity', '>', 0)
            ->orderByRaw('CASE WHEN expiry_date IS NULL THEN 1 ELSE 0 END')
            ->orderBy('expiry_date')
            ->orderBy('id');
    }

    /** Sellable (active, non-expired) quantity per medicine id, for one branch. */
    public static function availability(int $companyId, array $medicineIds, ?int $branchId): array
    {
        $medicineIds = array_values(array_unique(array_map('intval', $medicineIds)));
        if (!$medicineIds) {
            return [];
        }

        $rows = HealthMedicineBatch::withoutGlobalScopes()
            ->where('company_id', $companyId)
            ->whereIn('medicine_id', $medicineIds)
            ->where('branch_id', $branchId)
            ->where('status', HealthMedicineBatch::STATUS_ACTIVE)
            ->where('quantity', '>', 0)
            ->where(function ($query) {
                $query->whereNull('expiry_date')->orWhereDate('expiry_date', '>=', now()->toDateString());
            })
            ->selectRaw('medicine_id, SUM(quantity) as qty')
            ->groupBy('medicine_id')
            ->pluck('qty', 'medicine_id');

        $out = [];
        foreach ($medicineIds as $id) {
            $out[$id] = round((float) ($rows[$id] ?? 0), 3);
        }

        return $out;
    }

    // ═══════════════════════ Moving stock out ═══════════════════════

    /**
     * Take quantity out of one specific lot.
     *
     * The batch row is locked first: two counters selling the last strip must
     * not both succeed. A dispense beyond the lot's remainder is refused unless
     * the owner allowed negative stock — a pharmacy does not sell air.
     */
    public static function deduct(
        int $companyId,
        HealthMedicineBatch $batch,
        float $quantity,
        string $type,
        array $reference = [],
        ?int $userId = null,
        ?string $reason = null,
        ?string $notes = null,
        ?float $unitPrice = null
    ): HealthMedicineBatch {
        $quantity = round((float) $quantity, 3);
        if ($quantity <= 0) {
            throw ValidationException::withMessages(['quantity' => [__('health.ph_qty_positive')]]);
        }

        return DB::transaction(function () use ($companyId, $batch, $quantity, $type, $reference, $userId, $reason, $notes, $unitPrice) {
            $locked = HealthMedicineBatch::withoutGlobalScopes()
                ->where('company_id', $companyId)
                ->whereKey($batch->id)
                ->lockForUpdate()
                ->firstOrFail();

            $allowNegative = (bool) HealthPharmacyService::settings($companyId)->allow_negative_stock;
            if (!$allowNegative && $quantity > (float) $locked->quantity + 0.0005) {
                throw ValidationException::withMessages([
                    'quantity' => [__('health.ph_batch_short', [
                        'batch' => $locked->batch_no ?: __('health.ph_no_batch'),
                        'available' => rtrim(rtrim(number_format((float) $locked->quantity, 3, '.', ''), '0'), '.'),
                    ])],
                ]);
            }

            $locked->quantity = round((float) $locked->quantity - $quantity, 3);
            $locked->save();

            $medicine = self::medicine($companyId, (int) $locked->medicine_id);
            self::applyToBranchStock(
                $companyId,
                $medicine,
                $locked->branch_id !== null ? (int) $locked->branch_id : null,
                -$quantity,
                (float) $locked->cost_price,
                $type,
                $reference,
                $userId,
                $notes
            );

            self::logMovement($locked, $type, HealthBatchMovement::DIRECTION_OUT, $quantity, (float) $locked->cost_price, $unitPrice ?? (float) $locked->sale_price, $reference, $userId, $reason, $notes);

            return $locked;
        });
    }

    /** Put quantity back onto a specific lot (customer return, cancelled issue). */
    public static function restock(
        int $companyId,
        HealthMedicineBatch $batch,
        float $quantity,
        string $type,
        array $reference = [],
        ?int $userId = null,
        ?string $reason = null,
        ?string $notes = null
    ): HealthMedicineBatch {
        $quantity = round((float) $quantity, 3);
        if ($quantity <= 0) {
            throw ValidationException::withMessages(['quantity' => [__('health.ph_qty_positive')]]);
        }

        return DB::transaction(function () use ($companyId, $batch, $quantity, $type, $reference, $userId, $reason, $notes) {
            $locked = HealthMedicineBatch::withoutGlobalScopes()
                ->where('company_id', $companyId)
                ->whereKey($batch->id)
                ->lockForUpdate()
                ->firstOrFail();

            $locked->quantity = round((float) $locked->quantity + $quantity, 3);
            $locked->save();

            $medicine = self::medicine($companyId, (int) $locked->medicine_id);
            self::applyToBranchStock(
                $companyId,
                $medicine,
                $locked->branch_id !== null ? (int) $locked->branch_id : null,
                $quantity,
                (float) $locked->cost_price,
                $type,
                $reference,
                $userId,
                $notes
            );

            self::logMovement($locked, $type, HealthBatchMovement::DIRECTION_IN, $quantity, (float) $locked->cost_price, (float) $locked->sale_price, $reference, $userId, $reason, $notes);

            return $locked;
        });
    }

    /**
     * A counted correction. `newQuantity` is what the shelf actually holds; the
     * difference is written as an in or out adjustment so the reason survives.
     */
    public static function adjust(
        int $companyId,
        HealthMedicineBatch $batch,
        float $newQuantity,
        string $reason,
        ?int $userId = null,
        ?string $notes = null
    ): HealthMedicineBatch {
        $newQuantity = round(max(0, (float) $newQuantity), 3);
        $difference = round($newQuantity - (float) $batch->quantity, 3);

        if (abs($difference) < 0.0005) {
            return $batch;
        }

        $reference = ['type' => 'health_stock_adjustment', 'id' => $batch->id, 'number' => $batch->batch_no];

        if ($difference > 0) {
            return self::restock($companyId, $batch, $difference, HealthBatchMovement::TYPE_ADJUSTMENT_IN, $reference, $userId, $reason, $notes);
        }

        return self::deduct($companyId, $batch, abs($difference), HealthBatchMovement::TYPE_ADJUSTMENT_OUT, $reference, $userId, $reason, $notes);
    }

    /**
     * Write a lot off the shelf — wastage, breakage or an expired lot.
     * Passing no quantity writes off whatever is left.
     */
    public static function writeOff(
        int $companyId,
        HealthMedicineBatch $batch,
        ?float $quantity,
        string $reason,
        ?int $userId = null,
        ?string $notes = null
    ): HealthMedicineBatch {
        $quantity = $quantity === null ? (float) $batch->quantity : round((float) $quantity, 3);
        if ($quantity <= 0) {
            throw ValidationException::withMessages(['quantity' => [__('health.ph_qty_positive')]]);
        }

        $type = $reason === 'expired' || $batch->isExpired()
            ? HealthBatchMovement::TYPE_EXPIRY_WRITEOFF
            : HealthBatchMovement::TYPE_WASTAGE;

        $updated = self::deduct(
            $companyId,
            $batch,
            $quantity,
            $type,
            ['type' => 'health_write_off', 'id' => $batch->id, 'number' => $batch->batch_no],
            $userId,
            $reason,
            $notes
        );

        // An emptied write-off closes the lot so it can never be picked again,
        // even if a later correction pushes a stray unit back onto it.
        if ((float) $updated->quantity <= 0) {
            $updated->status = HealthMedicineBatch::STATUS_WRITTEN_OFF;
            $updated->save();
        }

        return $updated;
    }

    /**
     * Hold a lot back from the counter without pretending it left the building.
     *
     * Quarantine is deliberately status-only: the medicine is still physically
     * on the premises, so the branch quantity must not change. Only a write-off
     * moves stock. The ledger still records who quarantined it and why.
     */
    public static function quarantine(int $companyId, HealthMedicineBatch $batch, string $reason, ?int $userId = null): HealthMedicineBatch
    {
        return DB::transaction(function () use ($companyId, $batch, $reason, $userId) {
            $locked = HealthMedicineBatch::withoutGlobalScopes()
                ->where('company_id', $companyId)
                ->whereKey($batch->id)
                ->lockForUpdate()
                ->firstOrFail();

            $locked->status = HealthMedicineBatch::STATUS_QUARANTINED;
            $locked->quarantine_reason = $reason;
            $locked->save();

            self::logMovement($locked, HealthBatchMovement::TYPE_QUARANTINE, HealthBatchMovement::DIRECTION_NONE, (float) $locked->quantity, (float) $locked->cost_price, (float) $locked->sale_price, ['type' => 'health_quarantine', 'id' => $locked->id, 'number' => $locked->batch_no], $userId, $reason, null);

            return $locked;
        });
    }

    public static function release(int $companyId, HealthMedicineBatch $batch, ?int $userId = null): HealthMedicineBatch
    {
        return DB::transaction(function () use ($companyId, $batch, $userId) {
            $locked = HealthMedicineBatch::withoutGlobalScopes()
                ->where('company_id', $companyId)
                ->whereKey($batch->id)
                ->lockForUpdate()
                ->firstOrFail();

            $locked->status = HealthMedicineBatch::STATUS_ACTIVE;
            $locked->quarantine_reason = null;
            $locked->save();

            self::logMovement($locked, HealthBatchMovement::TYPE_RELEASE, HealthBatchMovement::DIRECTION_NONE, (float) $locked->quantity, (float) $locked->cost_price, (float) $locked->sale_price, ['type' => 'health_quarantine', 'id' => $locked->id, 'number' => $locked->batch_no], $userId, null, null);

            return $locked;
        });
    }

    /**
     * Move a lot between branches. The batch identity travels with the goods —
     * the destination gets its OWN row carrying the same batch number, expiry
     * and cost, so a recall still finds the medicine wherever it ended up.
     */
    public static function transfer(
        int $companyId,
        HealthMedicineBatch $batch,
        ?int $toBranchId,
        float $quantity,
        ?int $userId = null,
        ?string $notes = null
    ): HealthMedicineBatch {
        if ((int) $batch->branch_id === (int) $toBranchId) {
            throw ValidationException::withMessages(['branch_id' => [__('health.ph_transfer_same_branch')]]);
        }

        return DB::transaction(function () use ($companyId, $batch, $toBranchId, $quantity, $userId, $notes) {
            $reference = ['type' => 'health_batch_transfer', 'id' => $batch->id, 'number' => $batch->batch_no];

            $source = self::deduct($companyId, $batch, $quantity, HealthBatchMovement::TYPE_TRANSFER_OUT, $reference, $userId, null, $notes);

            $medicine = self::medicine($companyId, (int) $source->medicine_id);

            self::receive(
                $companyId,
                $medicine,
                [
                    'quantity' => $quantity,
                    'batch_no' => $source->batch_no,
                    'expiry_date' => $source->expiry_date,
                    'manufacture_date' => $source->manufacture_date,
                    'cost_price' => $source->cost_price,
                    'sale_price' => $source->sale_price,
                    'supplier_id' => $source->supplier_id,
                    'notes' => $notes,
                ],
                $toBranchId,
                $reference,
                $userId,
                HealthBatchMovement::TYPE_TRANSFER_IN
            );

            return $source;
        });
    }

    // ═══════════════════════ Reconciliation ═══════════════════════

    /**
     * Prove the two levels still agree.
     *
     * Returns every (medicine, branch) pair whose batch remainders no longer
     * sum to the shared branch quantity. A non-empty answer means something
     * wrote stock without going through this service — the exact drift a
     * pharmacy audit must catch rather than discover at stock-take.
     */
    public static function reconcile(int $companyId, ?int $branchId = null, bool $allBranches = false): array
    {
        $batchTotals = HealthMedicineBatch::withoutGlobalScopes()
            ->where('company_id', $companyId)
            ->when(!$allBranches, fn ($q) => $q->where('branch_id', $branchId))
            ->where('status', '!=', HealthMedicineBatch::STATUS_WRITTEN_OFF)
            ->selectRaw('product_id, branch_id, SUM(quantity) as qty')
            ->groupBy('product_id', 'branch_id')
            ->get();

        $out = [];
        foreach ($batchTotals as $row) {
            if (!$row->product_id) {
                continue;
            }

            $stock = InventoryStock::withoutGlobalScopes()
                ->where('company_id', $companyId)
                ->where('product_id', $row->product_id)
                ->where('branch_id', $row->branch_id)
                ->value('quantity');

            $difference = round((float) $row->qty - (float) $stock, 3);
            if (abs($difference) >= 0.001) {
                $out[] = [
                    'product_id' => (int) $row->product_id,
                    'branch_id' => $row->branch_id !== null ? (int) $row->branch_id : null,
                    'batch_total' => round((float) $row->qty, 3),
                    'branch_total' => round((float) $stock, 3),
                    'difference' => $difference,
                ];
            }
        }

        return $out;
    }

    // ═══════════════════════ Internals ═══════════════════════

    /**
     * Push the same movement onto the shared branch truth, so the platform's
     * stock level, average cost and movement history stay correct for medicine
     * exactly as they are for any other product.
     */
    private static function applyToBranchStock(
        int $companyId,
        ?HealthMedicine $medicine,
        ?int $branchId,
        float $signedQuantity,
        float $unitPrice,
        string $type,
        array $reference,
        ?int $userId,
        ?string $notes
    ): void {
        $productId = $medicine?->product_id;
        if (!$productId || abs($signedQuantity) < 0.0005) {
            return;
        }

        $inventoryType = self::INVENTORY_TYPE[$type] ?? (
            $signedQuantity > 0 ? InventoryMovement::TYPE_ADJUSTMENT_IN : InventoryMovement::TYPE_ADJUSTMENT_OUT
        );

        if ($signedQuantity > 0) {
            InventoryService::addStock(
                $companyId,
                (int) $productId,
                round($signedQuantity, 3),
                $unitPrice,
                $inventoryType,
                $branchId,
                $reference,
                $notes,
                $userId
            );

            return;
        }

        InventoryService::deductStock(
            $companyId,
            (int) $productId,
            round(abs($signedQuantity), 3),
            $unitPrice,
            $inventoryType,
            $branchId,
            $reference,
            $notes,
            $userId
        );
    }

    private static function logMovement(
        HealthMedicineBatch $batch,
        string $type,
        string $direction,
        float $quantity,
        float $unitCost,
        float $unitPrice,
        array $reference,
        ?int $userId,
        ?string $reason,
        ?string $notes
    ): void {
        HealthBatchMovement::withoutGlobalScopes()->create([
            'company_id' => $batch->company_id,
            'branch_id' => $batch->branch_id,
            'batch_id' => $batch->id,
            'medicine_id' => $batch->medicine_id,
            'product_id' => $batch->product_id,
            'type' => $type,
            'direction' => $direction,
            'quantity' => round($quantity, 3),
            'balance_after' => round((float) $batch->quantity, 3),
            'unit_cost' => round($unitCost, 2),
            'unit_price' => round($unitPrice, 2),
            'reference_type' => $reference['type'] ?? null,
            'reference_id' => $reference['id'] ?? null,
            'reference_number' => $reference['number'] ?? null,
            'reason' => $reason,
            'notes' => $notes,
            'created_by' => $userId,
        ]);
    }

    private static function medicine(int $companyId, int $medicineId): ?HealthMedicine
    {
        return HealthMedicine::withoutGlobalScopes()
            ->where('company_id', $companyId)
            ->find($medicineId);
    }

    private static function warning(string $code, HealthMedicineBatch $batch): array
    {
        return [
            'code' => $code,
            'batch_no' => $batch->batch_no,
            'expiry' => $batch->expiry_date?->toDateString(),
            'days' => $batch->daysToExpiry(),
        ];
    }

    private static function date($value): ?string
    {
        if (!$value) {
            return null;
        }

        try {
            return Carbon::parse($value)->toDateString();
        } catch (\Throwable $e) {
            return null;
        }
    }
}

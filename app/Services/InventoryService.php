<?php

namespace App\Services;

use App\Models\InventoryStock;
use App\Models\InventoryMovement;
use Illuminate\Support\Facades\DB;

class InventoryService
{
    public static function addStock($companyId, $productId, $quantity, $unitPrice, $type, $branchId = null, $reference = [], $notes = null, $userId = null)
    {
        return DB::transaction(function () use ($companyId, $productId, $quantity, $unitPrice, $type, $branchId, $reference, $notes, $userId) {
            $stock = InventoryStock::lockForUpdate()->firstOrCreate(
                ['company_id' => $companyId, 'product_id' => $productId, 'branch_id' => $branchId],
                ['quantity' => 0, 'min_stock_level' => 0, 'avg_purchase_price' => 0, 'last_purchase_price' => 0]
            );

            $stock->quantity += $quantity;

            if ($type === InventoryMovement::TYPE_PURCHASE || $type === InventoryMovement::TYPE_OPENING) {
                $stock->last_purchase_price = $unitPrice;
                if ($stock->avg_purchase_price > 0 && ($stock->quantity - $quantity) > 0) {
                    $oldTotal = $stock->avg_purchase_price * ($stock->quantity - $quantity);
                    $newTotal = $unitPrice * $quantity;
                    $stock->avg_purchase_price = round(($oldTotal + $newTotal) / $stock->quantity, 2);
                } else {
                    $stock->avg_purchase_price = $unitPrice;
                }
            }

            $stock->save();

            InventoryMovement::create([
                'company_id' => $companyId,
                'product_id' => $productId,
                'branch_id' => $branchId,
                'type' => $type,
                'quantity' => $quantity,
                'unit_price' => $unitPrice,
                'total_price' => round($unitPrice * $quantity, 2),
                'balance_after' => $stock->quantity,
                'reference_type' => $reference['type'] ?? null,
                'reference_id' => $reference['id'] ?? null,
                'reference_number' => $reference['number'] ?? null,
                'notes' => $notes,
                'created_by' => $userId,
            ]);

            return $stock;
        });
    }

    /**
     * Reverse a previously received purchase line (purchase void — Task 419).
     *
     * Stock: the received quantity is deducted (negative stock allowed — some
     * of it may already be sold; sales are never blocked).
     *
     * Avg purchase price: we "un-weight" the running average — solve for the
     * average that would exist without this purchase's qty×price in the pool:
     *   prevAvg = (avg×qtyBefore − price×qty) / (qtyBefore − qty)
     * This is exact when the void happens right after the mistake (the common
     * fat-finger case). If other movements happened in between it is still the
     * most sensible estimate. When the math degenerates (remaining qty ≤ 0 or
     * a negative result), we fall back to the most recent OTHER purchase price
     * supplied by the caller (or leave the average untouched if there is none).
     * Perfect reversal of a running average is not always possible — accepted.
     *
     * Last purchase price: rolled back to $fallbackLastPrice only when the
     * current last price matches this purchase's price (i.e. this purchase set
     * it); a newer purchase's last-kharid is never overwritten.
     */
    public static function reversePurchase($companyId, $productId, $quantity, $unitPrice, $branchId = null, $reference = [], $notes = null, $userId = null, $fallbackLastPrice = null)
    {
        return DB::transaction(function () use ($companyId, $productId, $quantity, $unitPrice, $branchId, $reference, $notes, $userId, $fallbackLastPrice) {
            $stock = InventoryStock::lockForUpdate()->firstOrCreate(
                ['company_id' => $companyId, 'product_id' => $productId, 'branch_id' => $branchId],
                ['quantity' => 0, 'min_stock_level' => 0, 'avg_purchase_price' => 0, 'last_purchase_price' => 0]
            );

            $qtyBefore = (float) $stock->quantity;
            $remaining = round($qtyBefore - $quantity, 3);

            // $fallbackLastPrice === null means "no still-valid prior purchase
            // exists" — degenerate cases then RESET the rate to 0 (explicit
            // "no rate", same as a never-purchased product) rather than keep
            // a value sourced from the voided purchase.
            $prevAvg = null;
            if ($remaining > 0 && (float) $stock->avg_purchase_price > 0) {
                $prevAvg = ((float) $stock->avg_purchase_price * $qtyBefore - $unitPrice * $quantity) / $remaining;
            }
            if ($prevAvg !== null && $prevAvg > 0) {
                $stock->avg_purchase_price = round($prevAvg, 2);
            } else {
                $stock->avg_purchase_price = $fallbackLastPrice !== null ? round((float) $fallbackLastPrice, 2) : 0;
            }

            if (abs((float) $stock->last_purchase_price - (float) $unitPrice) < 0.005) {
                $stock->last_purchase_price = $fallbackLastPrice !== null ? round((float) $fallbackLastPrice, 2) : 0;
            }

            $stock->quantity = $remaining;
            $stock->save();

            InventoryMovement::create([
                'company_id' => $companyId,
                'product_id' => $productId,
                'branch_id' => $branchId,
                'type' => InventoryMovement::TYPE_RETURN_OUT,
                'quantity' => $quantity,
                'unit_price' => $unitPrice,
                'total_price' => round($unitPrice * $quantity, 2),
                'balance_after' => $stock->quantity,
                'reference_type' => $reference['type'] ?? null,
                'reference_id' => $reference['id'] ?? null,
                'reference_number' => $reference['number'] ?? null,
                'notes' => $notes,
                'created_by' => $userId,
            ]);

            return $stock;
        });
    }

    public static function deductStock($companyId, $productId, $quantity, $unitPrice, $type, $branchId = null, $reference = [], $notes = null, $userId = null)
    {
        return DB::transaction(function () use ($companyId, $productId, $quantity, $unitPrice, $type, $branchId, $reference, $notes, $userId) {
            $stock = InventoryStock::lockForUpdate()->firstOrCreate(
                ['company_id' => $companyId, 'product_id' => $productId, 'branch_id' => $branchId],
                ['quantity' => 0, 'min_stock_level' => 0, 'avg_purchase_price' => 0, 'last_purchase_price' => 0]
            );

            $stock->quantity -= $quantity;
            $stock->save();

            InventoryMovement::create([
                'company_id' => $companyId,
                'product_id' => $productId,
                'branch_id' => $branchId,
                'type' => $type,
                'quantity' => $quantity,
                'unit_price' => $unitPrice,
                'total_price' => round($unitPrice * $quantity, 2),
                'balance_after' => $stock->quantity,
                'reference_type' => $reference['type'] ?? null,
                'reference_id' => $reference['id'] ?? null,
                'reference_number' => $reference['number'] ?? null,
                'notes' => $notes,
                'created_by' => $userId,
            ]);

            return $stock;
        });
    }

    public static function getStockLevel($companyId, $productId, $branchId = null)
    {
        return InventoryStock::where('company_id', $companyId)
            ->where('product_id', $productId)
            ->where('branch_id', $branchId)
            ->value('quantity') ?? 0;
    }
}

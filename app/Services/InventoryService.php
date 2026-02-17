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

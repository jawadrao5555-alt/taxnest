<?php

namespace App\Services;

use App\Models\InventoryStock;
use App\Models\InventoryMovement;
use App\Models\Ingredient;
use App\Models\PosProduct;
use App\Models\Product;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Validation\ValidationException;

class InventoryService
{
    /**
     * Restore the stock that an FBR POS transaction actually deducted.
     *
     * FBR deals are deliberately persisted as frozen component rows (fixed and
     * selected choice components alike), rather than as a mutable deal id.  The
     * persisted rows therefore define the requested quantities while the sale
     * movement ledger defines what really left stock.  Intersecting both avoids
     * minting stock when tracking was enabled after the sale and also combines
     * repeated choices/components before writing one auditable movement.
     *
     * The caller owns the surrounding transaction. Any exception must escape so
     * deleting a bill/creating a credit note cannot commit without its stock.
     */
    public static function restoreFbrTransaction(
        int $companyId,
        iterable $items,
        int $saleTransactionId,
        string $referenceType,
        int $referenceId,
        string $referenceNumber,
        ?int $branchId = null,
        ?int $userId = null
    ): array {
        $company = \App\Models\Company::find($companyId);
        if (!$company || !$company->inventory_enabled) {
            return ['skipped' => true, 'restored' => []];
        }

        $requested = [];
        $prices = [];
        foreach ($items as $item) {
            $productId = (int) (is_array($item) ? ($item['product_id'] ?? $item['item_id'] ?? 0) : ($item->product_id ?? 0));
            $quantity = (float) (is_array($item) ? ($item['quantity'] ?? 0) : ($item->quantity ?? 0));
            if ($productId < 1 || $quantity <= 0) {
                continue;
            }
            $requested[$productId] = round(($requested[$productId] ?? 0) + $quantity, 4);
            $prices[$productId] = (float) (is_array($item) ? ($item['unit_price'] ?? 0) : ($item->unit_price ?? 0));
        }
        if (!$requested) {
            return ['skipped' => false, 'restored' => []];
        }

        $deducted = InventoryMovement::where('company_id', $companyId)
            ->where('reference_type', 'fbr_pos_transaction')
            ->where('reference_id', $saleTransactionId)
            ->where('type', InventoryMovement::TYPE_SALE)
            ->selectRaw('product_id, SUM(ABS(quantity)) as quantity')
            ->groupBy('product_id')
            ->lockForUpdate()
            ->pluck('quantity', 'product_id')
            ->map(fn ($quantity) => (float) $quantity)
            ->all();

        $restored = [];
        $resolvedBranch = BranchStockService::writeBranchId($companyId, $branchId);
        foreach ($requested as $productId => $quantity) {
            $quantity = min($quantity, max(0, (float) ($deducted[$productId] ?? 0)));
            if ($quantity <= 0) {
                continue;
            }
            // A deleted catalog row must not create an orphan movement. Existing
            // persisted stock can still be restored only while its product is
            // company-owned; otherwise fail the caller's whole transaction.
            if (!Product::where('company_id', $companyId)->whereKey($productId)->exists()) {
                throw new \RuntimeException("Cannot restore stock for missing FBR product #{$productId}.");
            }
            self::addStock(
                $companyId,
                $productId,
                $quantity,
                $prices[$productId] ?? 0,
                InventoryMovement::TYPE_RETURN_IN,
                $resolvedBranch,
                ['type' => $referenceType, 'id' => $referenceId, 'number' => $referenceNumber],
                'FBR POS transaction stock restoration',
                $userId
            );
            $restored[] = ['product_id' => $productId, 'quantity' => $quantity];
        }

        return ['skipped' => false, 'restored' => $restored];
    }

    /**
     * Validate and expand the immutable Local Core stock snapshot.  This method
     * is deliberately read-only so a malformed/cross-tenant settlement is
     * rejected before storeInvoice can create a bill or auto-create a product.
     */
    public static function canonicalConsumptions(int $companyId, array $sale): array
    {
        $products = [];
        $ingredients = [];
        $items = $sale['items'] ?? null;
        if (!is_array($items) || !$items) {
            throw ValidationException::withMessages(['payload.sale.items' => ['A non-empty immutable item snapshot is required.']]);
        }

        $expand = function (array $line, float $multiplier = 1.0) use (&$expand, &$products, &$ingredients, $companyId): void {
            $type = (string) ($line['type'] ?? 'product');
            $quantity = $line['quantity'] ?? $line['qty'] ?? null;
            if (!is_numeric($quantity) || !is_finite((float) $quantity) || (float) $quantity <= 0) {
                throw ValidationException::withMessages(['payload.sale.items' => ['Every stock quantity must be finite and positive.']]);
            }
            $quantity = (float) $quantity * $multiplier;

            if ($type === 'deal') {
                $dealId = (int) ($line['item_id'] ?? $line['deal_id'] ?? 0);
                $snapshot = $line['deal_snapshot'] ?? null;
                if ($dealId < 1 || !is_array($snapshot) || !$snapshot) {
                    throw ValidationException::withMessages(['payload.sale.items' => ['A deal requires its immutable component snapshot.']]);
                }
                foreach ($snapshot as $component) {
                    if (!is_array($component)) {
                        throw ValidationException::withMessages(['payload.sale.items' => ['A deal component snapshot is invalid.']]);
                    }
                    if ((isset($component['deal_id']) && (int) $component['deal_id'] !== $dealId)
                        || (isset($component['tax_facts']['company_id'])
                            && (int) $component['tax_facts']['company_id'] !== $companyId)
                        || (isset($component['mode'])
                            && !in_array((string) $component['mode'], ['direct', 'recipe'], true))
                        || (($component['mode'] ?? null) === 'recipe' && empty($component['recipe_snapshot']))
                        || (($component['mode'] ?? null) === 'direct' && !empty($component['recipe_snapshot']))) {
                        throw ValidationException::withMessages(['payload.sale.items' => ['A deal component snapshot was tampered with.']]);
                    }
                    $component['type'] = 'product';
                    $component['item_id'] = $component['product_id'] ?? $component['item_id'] ?? null;
                    $expand($component, $quantity);
                }
                return;
            }
            if (in_array($type, ['manual', 'service'], true)) {
                if (!empty($line['recipe_snapshot'])) {
                    throw ValidationException::withMessages(['payload.sale.items' => ['Manual/service lines cannot consume stock.']]);
                }
                return;
            }
            if ($type !== 'product') {
                throw ValidationException::withMessages(['payload.sale.items' => ['Unsupported stock line type.']]);
            }

            $productId = (int) ($line['item_id'] ?? $line['product_id'] ?? 0);
            if ($productId < 1 || !PosProduct::where('company_id', $companyId)->whereKey($productId)->exists()) {
                throw ValidationException::withMessages(['payload.sale.items' => ['A product or deal component is outside the company.']]);
            }
            $recipe = $line['recipe_snapshot'] ?? [];
            if (!is_array($recipe)) {
                throw ValidationException::withMessages(['payload.sale.items' => ['recipe_snapshot must be an array.']]);
            }
            if (($line['has_recipe'] ?? false) === true && !$recipe) {
                throw ValidationException::withMessages(['payload.sale.items' => ['A recipe product requires its immutable recipe snapshot.']]);
            }
            if ($recipe) {
                foreach ($recipe as $part) {
                    if (!is_array($part) || !is_numeric($part['quantity'] ?? null)
                        || !is_finite((float) $part['quantity']) || (float) $part['quantity'] <= 0) {
                        throw ValidationException::withMessages(['payload.sale.items' => ['A recipe component quantity is invalid.']]);
                    }
                    $stockId = (string) ($part['stock_id'] ?? '');
                    if (!preg_match('/^(?:ingredient[:\\-])?(\\d+)$/', $stockId, $match)) {
                        throw ValidationException::withMessages(['payload.sale.items' => ['A recipe component stock id is invalid.']]);
                    }
                    $ingredientId = (int) $match[1];
                    if (!Ingredient::where('company_id', $companyId)->whereKey($ingredientId)->exists()) {
                        throw ValidationException::withMessages(['payload.sale.items' => ['A recipe component is outside the company.']]);
                    }
                    $needed = round($quantity * (float) $part['quantity'], 4);
                    $ingredients[$ingredientId]['quantity'] = round(($ingredients[$ingredientId]['quantity'] ?? 0) + $needed, 4);
                    $ingredients[$ingredientId]['products'][(string) $productId] =
                        round(($ingredients[$ingredientId]['products'][(string) $productId] ?? 0) + $needed, 4);
                    $ingredients[$ingredientId]['snapshot'][] = [
                        'product_id' => $productId,
                        'sale_quantity' => $quantity,
                        'quantity_needed' => (float) $part['quantity'],
                        'ingredient_id' => $ingredientId,
                        'recipe_version' => (int) ($part['version'] ?? $part['recipe_version'] ?? 1),
                    ];
                }
                return;
            }
            $products[$productId] = round(($products[$productId] ?? 0) + $quantity, 4);
        };

        foreach ($items as $line) {
            if (!is_array($line)) {
                throw ValidationException::withMessages(['payload.sale.items' => ['A sale line is invalid.']]);
            }
            $expand($line);
        }
        ksort($products);
        ksort($ingredients);
        return ['products' => $products, 'ingredients' => $ingredients];
    }

    /**
     * Apply a canonical Local Core sale exactly once inside the caller's bill
     * transaction. Cloud stock may become negative: the sale was already
     * accepted by the authoritative offline policy.
     */
    public static function projectCanonicalSale(
        int $companyId, int $branchId, array $sale, int $transactionId,
        string $invoiceNumber, ?int $userId = null
    ): array {
        $consumptions = self::canonicalConsumptions($companyId, $sale);
        if (InventoryMovement::where('company_id', $companyId)
            ->where('reference_type', 'pos_transaction')->where('reference_id', $transactionId)
            ->whereIn('type', [InventoryMovement::TYPE_SALE, InventoryMovement::TYPE_RECIPE_SALE])->exists()) {
            return ['idempotent' => true];
        }

        $productIds = array_keys($consumptions['products']);
        if ($productIds) {
            PosProduct::where('company_id', $companyId)->whereIn('id', $productIds)
                ->orderBy('id')->lockForUpdate()->get();
        }
        foreach ($productIds as $productId) {
            $stock = InventoryStock::where('company_id', $companyId)->where('product_id', $productId)
                ->where('branch_id', $branchId)->lockForUpdate()->first();
            if (!$stock) {
                $stock = InventoryStock::create([
                    'company_id' => $companyId, 'product_id' => $productId, 'branch_id' => $branchId,
                    'quantity' => 0, 'min_stock_level' => 0, 'avg_purchase_price' => 0, 'last_purchase_price' => 0,
                ]);
            }
            $qty = $consumptions['products'][$productId];
            $after = round((float) $stock->quantity - $qty, 4);
            $stock->update(['quantity' => $after]);
            BranchStockService::syncProductMirror($companyId, (int) $productId);
            InventoryMovement::create([
                'company_id' => $companyId, 'product_id' => $productId, 'branch_id' => $branchId,
                'type' => InventoryMovement::TYPE_SALE, 'quantity' => $qty, 'unit_price' => 0,
                'total_price' => 0, 'balance_after' => $after, 'reference_type' => 'pos_transaction',
                'reference_id' => $transactionId, 'reference_number' => $invoiceNumber,
                'notes' => 'Authoritative Local Core sale deduction', 'created_by' => $userId,
            ]);
        }

        $ingredientIds = array_keys($consumptions['ingredients']);
        $lockedIngredients = $ingredientIds
            ? Ingredient::where('company_id', $companyId)->whereIn('id', $ingredientIds)
                ->orderBy('id')->lockForUpdate()->get()->keyBy('id')
            : collect();
        foreach ($ingredientIds as $ingredientId) {
            $ingredient = $lockedIngredients[$ingredientId];
            $requirement = $consumptions['ingredients'][$ingredientId];
            $qty = (float) $requirement['quantity'];
            $after = round((float) $ingredient->current_stock - $qty, 4);
            $ingredient->update(['current_stock' => $after]);
            if (Schema::hasTable('ingredient_stocks')) {
                $row = DB::table('ingredient_stocks')->where('company_id', $companyId)
                    ->where('ingredient_id', $ingredientId)->where('branch_id', $branchId)->lockForUpdate()->first();
                if ($row) {
                    DB::table('ingredient_stocks')->where('id', $row->id)
                        ->update(['quantity' => round((float) $row->quantity - $qty, 4), 'updated_at' => now()]);
                } else {
                    DB::table('ingredient_stocks')->insert([
                        'company_id' => $companyId, 'ingredient_id' => $ingredientId, 'branch_id' => $branchId,
                        'quantity' => $after, 'created_at' => now(), 'updated_at' => now(),
                    ]);
                }
            }
            InventoryMovement::create([
                'company_id' => $companyId, 'product_id' => (int) array_key_first($requirement['products']),
                'branch_id' => $branchId, 'type' => InventoryMovement::TYPE_RECIPE_SALE,
                'quantity' => $qty, 'unit_price' => (float) ($ingredient->cost_per_unit ?? 0),
                'total_price' => round($qty * (float) ($ingredient->cost_per_unit ?? 0), 2),
                'balance_after' => $after, 'reference_type' => 'pos_transaction',
                'reference_id' => $transactionId, 'reference_number' => $invoiceNumber,
                'notes' => 'Authoritative Local Core recipe consumption', 'created_by' => $userId,
            ]);
            if (Schema::hasTable('ingredient_movements')) {
                DB::table('ingredient_movements')->insert([
                    'company_id' => $companyId, 'ingredient_id' => $ingredientId,
                    'branch_id' => $branchId, 'type' => InventoryMovement::TYPE_RECIPE_SALE,
                    'quantity' => $qty, 'balance_after' => $after,
                    'reference_type' => 'pos_transaction', 'reference_id' => $transactionId,
                    'reference_number' => $invoiceNumber,
                    'snapshot' => json_encode($requirement['snapshot']), 'created_by' => $userId,
                    'created_at' => now(), 'updated_at' => now(),
                ]);
            }
            if (Schema::hasTable('recipe_consumptions')) {
                DB::table('recipe_consumptions')->insert([
                    'company_id' => $companyId, 'transaction_id' => $transactionId,
                    'ingredient_id' => $ingredientId, 'branch_id' => $branchId, 'quantity' => $qty,
                    'components' => json_encode($requirement['products']),
                    'snapshot' => json_encode($requirement['snapshot']), 'invoice_number' => $invoiceNumber,
                    'created_at' => now(), 'updated_at' => now(),
                ]);
            }
        }
        return ['idempotent' => false, 'consumptions' => $consumptions];
    }

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

<?php

namespace App\Services;

use App\Models\Company;
use App\Models\Ingredient;
use App\Models\InventoryMovement;
use App\Models\ProductRecipe;
use App\Models\PosTransaction;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * The kitchen side of POS inventory.
 *
 * Product stock and ingredient stock are deliberately kept as two ledgers:
 * a recipe dish consumes its ingredients, while a product without a recipe
 * consumes the normal product stock ledger.  All callers (universal POS,
 * held-order settlement and returns) use this service so a dish cannot be
 * counted twice or use a recipe edited after the sale.
 */
class RecipeInventoryService
{
    public const DISPOSITION_NORMAL = 'normal_restock';
    public const DISPOSITION_COOKED = 'cooked_resaleable';
    public const DISPOSITION_WASTAGE = 'wastage';

    private const MOVEMENT_SALE = 'recipe_sale';
    private const MOVEMENT_RETURN = 'recipe_return';
    private const MOVEMENT_ADJUSTMENT = 'ingredient_adjustment';

    /** Units accepted by the UI/importer. Conversions are explicit, never guessed. */
    public const UNITS = ['kg', 'g', 'ltr', 'ml', 'pcs', 'dozen', 'pack'];

    /**
     * Return the recipe requirements for a cart.  The output is deterministic
     * and combines repeated ingredients, which is important for both locking
     * and an auditable one-row-per-ingredient movement.
     */
    public static function requirementsForItems(int $companyId, array $items): array
    {
        if (!Schema::hasTable('product_recipes')) {
            return [];
        }
        $requirements = [];

        foreach ($items as $item) {
            $type = $item['type'] ?? $item['item_type'] ?? 'product';
            $productId = (int) ($item['item_id'] ?? 0);
            $saleQty = (float) ($item['quantity'] ?? 0);
            if ($type !== 'product' || $productId <= 0 || $saleQty <= 0) {
                continue;
            }

            $recipeQuery = ProductRecipe::where('company_id', $companyId)
                ->where('product_id', $productId);
            if (Schema::hasColumn('product_recipes', 'is_active')) {
                $recipeQuery->where('is_active', true);
            }
            $recipes = $recipeQuery
                ->with('ingredient')
                ->orderBy('ingredient_id')
                ->get();

            foreach ($recipes as $recipe) {
                if (!$recipe->ingredient || !$recipe->ingredient->is_active) {
                    continue;
                }
                $ingredientId = (int) $recipe->ingredient_id;
                $needed = round((float) $recipe->quantity_needed * $saleQty, 4);
                if ($needed <= 0) {
                    continue;
                }

                if (!isset($requirements[$ingredientId])) {
                    $requirements[$ingredientId] = [
                        'ingredient_id' => $ingredientId,
                        'quantity' => 0.0,
                        'unit' => (string) $recipe->ingredient->unit,
                        'cost_per_unit' => (float) ($recipe->ingredient->cost_per_unit ?? 0),
                        'components' => [],
                        'snapshot' => [],
                    ];
                }

                $requirements[$ingredientId]['quantity'] = round(
                    $requirements[$ingredientId]['quantity'] + $needed,
                    4
                );
                $requirements[$ingredientId]['components'][(string) $productId] =
                    round(($requirements[$ingredientId]['components'][(string) $productId] ?? 0) + $needed, 4);
                $requirements[$ingredientId]['snapshot'][] = [
                    'product_id' => $productId,
                    'sale_quantity' => $saleQty,
                    'recipe_id' => (int) $recipe->id,
                    'recipe_version' => (int) ($recipe->recipe_version ?? 1),
                    'quantity_needed' => (float) $recipe->quantity_needed,
                    'ingredient_id' => $ingredientId,
                    'ingredient_unit' => (string) $recipe->ingredient->unit,
                ];
            }
        }

        ksort($requirements);
        return $requirements;
    }

    /**
     * Consume ingredients exactly once for a completed invoice.
     *
     * Prepared cooked units are consumed before fresh ingredients.  The
     * remaining quantity is then recipe-consumed.  Existing deployments which
     * have not run the kitchen migration still get the legacy current_stock +
     * inventory_movements behavior through the schema guards.
     */
    public static function consumeForInvoice(
        int $companyId,
        array $items,
        int $transactionId,
        string $invoiceNumber,
        ?int $userId = null,
        ?int $branchId = null
    ): array {
        $company = Company::find($companyId);
        if (!$company || !$company->inventory_enabled) {
            return ['skipped' => true, 'consumed' => [], 'prepared' => []];
        }

        return DB::transaction(function () use (
            $companyId, $items, $transactionId, $invoiceNumber, $userId, $branchId
        ) {
            // A lost HTTP response or a retry must not consume the same kitchen
            // stock twice.  This is intentionally based on the transaction
            // reference, not on the current recipe.
            if (self::hasSaleMovement($companyId, $transactionId)) {
                return ['skipped' => false, 'idempotent' => true, 'consumed' => [], 'prepared' => []];
            }

            $resolvedBranch = BranchStockService::writeBranchId($companyId, $branchId);
            $remainingItems = [];
            $prepared = [];
            foreach ($items as $item) {
                $type = $item['type'] ?? $item['item_type'] ?? 'product';
                $productId = (int) ($item['item_id'] ?? 0);
                $qty = (float) ($item['quantity'] ?? 0);
                if ($type !== 'product' || $productId <= 0 || $qty <= 0) {
                    continue;
                }
                $used = self::consumePrepared(
                    $companyId, $productId, $qty, $transactionId, $resolvedBranch
                );
                if ($used > 0) {
                    $prepared[] = ['product_id' => $productId, 'quantity' => $used];
                }
                $freshQty = round($qty - $used, 4);
                if ($freshQty > 0) {
                    $copy = $item;
                    $copy['quantity'] = $freshQty;
                    $remainingItems[] = $copy;
                }
            }

            $requirements = self::requirementsForItems($companyId, $remainingItems);
            $consumed = [];
            foreach ($requirements as $requirement) {
                $ingredient = Ingredient::where('company_id', $companyId)
                    ->where('id', $requirement['ingredient_id'])
                    ->lockForUpdate()
                    ->first();
                if (!$ingredient) {
                    continue;
                }

                $qty = (float) $requirement['quantity'];
                $before = (float) $ingredient->current_stock;
                $after = round($before - $qty, 4);
                if ($after < -0.0001) {
                    throw new \RuntimeException(
                        "Insufficient kitchen stock: {$ingredient->name} needs {$qty} {$ingredient->unit}, have {$before}"
                    );
                }
                $ingredient->update(['current_stock' => $after]);
                self::changeIngredientBranchStock($companyId, (int) $ingredient->id, $resolvedBranch, -$qty, $before);

                $movement = InventoryMovement::create([
                    'company_id' => $companyId,
                    // Keep the legacy ledger's required product_id meaningful:
                    // the first product in this combined ingredient movement.
                    'product_id' => (int) array_key_first($requirement['components']),
                    'branch_id' => $resolvedBranch,
                    'type' => self::MOVEMENT_SALE,
                    'quantity' => $qty,
                    'unit_price' => (float) ($ingredient->cost_per_unit ?? 0),
                    'total_price' => round($qty * (float) ($ingredient->cost_per_unit ?? 0), 2),
                    'balance_after' => $after,
                    'reference_type' => 'pos_transaction',
                    'reference_id' => $transactionId,
                    'reference_number' => $invoiceNumber,
                    'notes' => 'Recipe ingredients consumed for POS sale',
                    'created_by' => $userId,
                ]);

                self::writeIngredientLedger(
                    $companyId, (int) $ingredient->id, $resolvedBranch, self::MOVEMENT_SALE,
                    $qty, $after, $transactionId, $invoiceNumber, $requirement, $userId
                );

                $consumed[] = [
                    'ingredient_id' => (int) $ingredient->id,
                    'quantity' => $qty,
                    'movement_id' => $movement->id,
                ];
                self::writeConsumptionSnapshot(
                    $companyId, $transactionId, $resolvedBranch, $requirement, $invoiceNumber
                );
            }

            // Prepared-only sales still need a durable marker for idempotency.
            if (empty($requirements) && !empty($prepared)) {
                self::writePreparedSaleMarker(
                    $companyId, $transactionId, $invoiceNumber, $resolvedBranch, $userId
                );
            }
            if (!empty($prepared) && Schema::hasColumn('pos_transactions', 'pra_dependency_transaction_id')
                && Schema::hasTable('prepared_returns')) {
                $dependency = DB::table('prepared_returns')
                    ->where('company_id', $companyId)
                    ->where('consumed_by_transaction_id', $transactionId)
                    ->orderBy('return_transaction_id')
                    ->value('return_transaction_id');
                if ($dependency) {
                    DB::table('pos_transactions')
                        ->where('company_id', $companyId)
                        ->where('id', $transactionId)
                        ->update(['pra_dependency_transaction_id' => $dependency]);
                }
            }
            if (!empty($prepared) && Schema::hasTable('pos_transaction_items')
                && Schema::hasColumn('pos_transaction_items', 'prepared_return_id')) {
                foreach ($prepared as $preparedItem) {
                    $preparedId = DB::table('prepared_returns')
                        ->where('company_id', $companyId)
                        ->where('product_id', $preparedItem['product_id'])
                        ->where('consumed_by_transaction_id', $transactionId)
                        ->orderBy('id')->value('id');
                    if ($preparedId) {
                        DB::table('pos_transaction_items')
                            ->where('transaction_id', $transactionId)
                            ->where('item_id', $preparedItem['product_id'])
                            ->whereNull('prepared_return_id')
                            ->orderBy('id')
                            ->limit(1)
                            ->update(['prepared_return_id' => $preparedId]);
                    }
                }
            }

            return ['skipped' => false, 'consumed' => $consumed, 'prepared' => $prepared];
        });
    }

    /**
     * Restore recipe inputs for normal-restock return lines.  A cooked resaleable
     * return deliberately does not restore inputs: the prepared dish is put in
     * the one-time resale pool instead.  Wastage restores nothing.
     */
    public static function restoreForReturn(
        int $companyId,
        int $parentTransactionId,
        int $returnTransactionId,
        array $returnItems,
        ?int $branchId = null,
        ?int $userId = null,
        string $returnNumber = ''
    ): array {
        $company = Company::find($companyId);
        if (!$company || !$company->inventory_enabled) {
            return ['skipped' => true, 'restored' => []];
        }

        $normalByProduct = [];
        foreach ($returnItems as $item) {
            $disposition = self::normalizeDisposition($item['disposition'] ?? self::DISPOSITION_NORMAL);
            if ($disposition !== self::DISPOSITION_NORMAL) {
                continue;
            }
            $productId = (int) ($item['item_id'] ?? 0);
            $qty = (float) ($item['quantity'] ?? 0);
            if ($productId > 0 && $qty > 0) {
                $normalByProduct[$productId] = round(($normalByProduct[$productId] ?? 0) + $qty, 4);
            }
        }
        if (!$normalByProduct) {
            return ['skipped' => false, 'restored' => []];
        }

        return DB::transaction(function () use (
            $companyId, $parentTransactionId, $returnTransactionId, $normalByProduct,
            $branchId, $userId, $returnNumber
        ) {
            if (Schema::hasTable('recipe_consumptions')) {
                $rows = DB::table('recipe_consumptions')
                    ->where('company_id', $companyId)
                    ->where('transaction_id', $parentTransactionId)
                    ->orderBy('ingredient_id')
                    ->lockForUpdate()
                    ->get();
            } else {
                $rows = collect();
            }

            $restore = [];
            foreach ($rows as $row) {
                $components = json_decode((string) ($row->components ?? '{}'), true);
                if (!is_array($components)) {
                    $components = [];
                }
                $factor = 0.0;
                foreach ($normalByProduct as $productId => $qty) {
                    $componentQty = (float) ($components[(string) $productId] ?? 0);
                    if ($componentQty > 0) {
                        $factor += self::returnQuantityForProduct(
                            $companyId, $parentTransactionId, (int) $productId, $qty, $row
                        );
                    }
                }
                if ($factor > 0) {
                    $id = (int) $row->ingredient_id;
                    $restore[$id] = round(($restore[$id] ?? 0) + $factor, 4);
                }
            }

            // Lean/old schemas have no snapshot table.  Only use the current
            // recipe as a compatibility fallback, and only where the parent
            // demonstrably had a recipe_sale movement.
            if (!$rows->count()) {
                $parent = PosTransaction::withoutGlobalScope('hide_archived')
                    ->with('items')->where('company_id', $companyId)->find($parentTransactionId);
                if ($parent && self::hasSaleMovement($companyId, $parentTransactionId)) {
                    foreach ($normalByProduct as $productId => $qty) {
                        $fallbackRecipeQuery = ProductRecipe::where('company_id', $companyId)
                            ->where('product_id', $productId);
                        if (Schema::hasColumn('product_recipes', 'is_active')) {
                            $fallbackRecipeQuery->where('is_active', true);
                        }
                        foreach ($fallbackRecipeQuery->get() as $recipe) {
                            $restore[(int) $recipe->ingredient_id] =
                                round(($restore[(int) $recipe->ingredient_id] ?? 0)
                                    + (float) $recipe->quantity_needed * $qty, 4);
                        }
                    }
                }
            }

            if (!$restore) {
                return ['skipped' => false, 'restored' => []];
            }

            $resolvedBranch = BranchStockService::writeBranchId($companyId, $branchId);
            $out = [];
            foreach ($restore as $ingredientId => $qty) {
                $ingredient = Ingredient::where('company_id', $companyId)
                    ->where('id', $ingredientId)->lockForUpdate()->first();
                if (!$ingredient) {
                    continue;
                }
                $after = round((float) $ingredient->current_stock + $qty, 4);
                $ingredient->update(['current_stock' => $after]);
                self::changeIngredientBranchStock($companyId, (int) $ingredient->id, $resolvedBranch, $qty, (float) $after - $qty);
                $movement = InventoryMovement::create([
                    'company_id' => $companyId,
                    'product_id' => $ingredientId,
                    'branch_id' => $resolvedBranch,
                    'type' => self::MOVEMENT_RETURN,
                    'quantity' => $qty,
                    'unit_price' => (float) ($ingredient->cost_per_unit ?? 0),
                    'total_price' => round($qty * (float) ($ingredient->cost_per_unit ?? 0), 2),
                    'balance_after' => $after,
                    'reference_type' => 'pos_return',
                    'reference_id' => $returnTransactionId,
                    'reference_number' => $returnNumber,
                    'notes' => 'Recipe ingredients restored for normal return',
                    'created_by' => $userId,
                ]);
                self::writeIngredientLedger(
                    $companyId, $ingredientId, $resolvedBranch, self::MOVEMENT_RETURN,
                    $qty, $after, $returnTransactionId, $returnNumber, null, $userId
                );
                $out[] = ['ingredient_id' => $ingredientId, 'quantity' => $qty, 'movement_id' => $movement->id];
            }

            return ['skipped' => false, 'restored' => $out];
        });
    }

    /** Add a cooked returned quantity to the prepared pool. */
    public static function recordPreparedReturns(
        int $companyId,
        int $returnTransactionId,
        ?int $branchId,
        array $returnItems,
        ?int $userId = null,
        ?int $expiryHours = null
    ): void {
        if (!Schema::hasTable('prepared_returns')) {
            return;
        }
        $resolvedBranch = BranchStockService::writeBranchId($companyId, $branchId);
        $hours = max(1, min(168, (int) ($expiryHours ?? 24)));
        foreach ($returnItems as $item) {
            if (self::normalizeDisposition($item['disposition'] ?? '') !== self::DISPOSITION_COOKED) {
                continue;
            }
            $qty = (float) ($item['quantity'] ?? 0);
            $productId = (int) ($item['item_id'] ?? 0);
            if ($productId <= 0 || $qty <= 0) {
                continue;
            }
            DB::table('prepared_returns')->insert([
                'company_id' => $companyId,
                'branch_id' => $resolvedBranch,
                'product_id' => $productId,
                'return_transaction_id' => $returnTransactionId,
                'quantity' => $qty,
                'remaining_quantity' => $qty,
                'expires_at' => now()->addHours($hours),
                'status' => 'available',
                'created_by' => $userId,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }
    }

    public static function normalizeDisposition(?string $value): string
    {
        $value = strtolower(trim((string) $value));
        return match ($value) {
            'cooked', 'cooked_resale', 'cooked_resaleable' => self::DISPOSITION_COOKED,
            'wastage', 'waste', 'spoiled' => self::DISPOSITION_WASTAGE,
            default => self::DISPOSITION_NORMAL,
        };
    }

    public static function hasSaleMovement(int $companyId, int $transactionId): bool
    {
        $movement = InventoryMovement::where('company_id', $companyId)
            ->where('reference_type', 'pos_transaction')
            ->where('reference_id', $transactionId)
            ->where('type', self::MOVEMENT_SALE)->exists();
        if ($movement) return true;
        if (!Schema::hasTable('recipe_consumptions')) return false;
        $query = DB::table('recipe_consumptions')
            ->where('company_id', $companyId)->where('transaction_id', $transactionId);
        if (Schema::hasColumn('recipe_consumptions', 'reversed_at')) {
            $query->whereNull('reversed_at');
        }
        return $query->exists();
    }

    public static function hasRecipe(int $companyId, int $productId): bool
    {
        if (!Schema::hasTable('product_recipes')) {
            return false;
        }
        $query = ProductRecipe::where('company_id', $companyId)->where('product_id', $productId);
        if (Schema::hasColumn('product_recipes', 'is_active')) {
            $query->where('is_active', true);
        }
        return $query->exists();
    }

    public static function stockErrors(int $companyId, array $items, ?int $branchId = null): array
    {
        if (!Schema::hasTable('product_recipes')) {
            return [];
        }
        $errors = [];
        $freshItems = [];
        foreach ($items as $item) {
            $type = $item['type'] ?? $item['item_type'] ?? 'product';
            $productId = (int) ($item['item_id'] ?? 0);
            $quantity = (float) ($item['quantity'] ?? 0);
            if ($type === 'product' && $productId > 0 && $quantity > 0) {
                $prepared = min($quantity, self::availablePrepared($companyId, $productId, $branchId));
                $quantity = round($quantity - $prepared, 4);
            }
            if ($quantity > 0) {
                $copy = $item;
                $copy['quantity'] = $quantity;
                $freshItems[] = $copy;
            }
        }
        foreach (self::requirementsForItems($companyId, $freshItems) as $requirement) {
            $ingredient = Ingredient::where('company_id', $companyId)->find($requirement['ingredient_id']);
            if ($ingredient && (float) $ingredient->current_stock + 0.0001 < (float) $requirement['quantity']) {
                $errors[] = "{$ingredient->name} (need {$requirement['quantity']} {$ingredient->unit}, have {$ingredient->current_stock})";
            }
        }
        return $errors;
    }

    private static function availablePrepared(int $companyId, int $productId, ?int $branchId): float
    {
        if (!Schema::hasTable('prepared_returns')) {
            return 0.0;
        }
        $query = DB::table('prepared_returns')
            ->where('company_id', $companyId)
            ->where('product_id', $productId)
            ->where('status', 'available')
            ->where('remaining_quantity', '>', 0)
            ->where(function ($q) use ($branchId) {
                $branchId ? $q->where('branch_id', $branchId) : $q->whereNull('branch_id');
            })
            ->where(function ($q) {
                $q->whereNull('expires_at')->orWhere('expires_at', '>', now());
            });
        return (float) $query->sum('remaining_quantity');
    }

    public static function adjustIngredientStock(
        int $companyId, int $ingredientId, float $delta, ?int $userId = null,
        ?int $branchId = null, string $reason = 'Manual kitchen stock adjustment'
    ): array {
        return DB::transaction(function () use (
            $companyId, $ingredientId, $delta, $userId, $branchId, $reason
        ) {
            $ingredient = Ingredient::where('company_id', $companyId)
                ->where('id', $ingredientId)->lockForUpdate()->firstOrFail();
            $before = (float) $ingredient->current_stock;
            $after = round($before + $delta, 4);
            if ($after < -0.0001) {
                throw new \RuntimeException('Kitchen stock cannot go below zero.');
            }
            $ingredient->update(['current_stock' => $after]);
            $resolvedBranch = BranchStockService::writeBranchId($companyId, $branchId);
            self::changeIngredientBranchStock(
                $companyId, $ingredientId, $resolvedBranch, $delta,
                $delta >= 0 ? $before : null
            );
            self::writeIngredientLedger(
                $companyId, $ingredientId, $resolvedBranch, self::MOVEMENT_ADJUSTMENT,
                abs($delta), $after, $ingredientId, '', ['reason' => $reason, 'delta' => $delta], $userId
            );
            return ['before' => $before, 'after' => $after];
        });
    }

    /**
     * Reverse the frozen recipe consumption before a bill edit/void is
     * re-deducted.  Consumption rows are retained as audit history but marked
     * reversed, and their sale movements are relabelled rather than deleted.
     */
    public static function reverseForInvoice(
        int $companyId, int $transactionId, ?int $branchId = null, ?int $userId = null, string $referenceType = 'pos_edit'
    ): array {
        if (!Schema::hasTable('recipe_consumptions')) return ['restored' => []];

        return DB::transaction(function () use ($companyId, $transactionId, $branchId, $userId, $referenceType) {
            $query = DB::table('recipe_consumptions')
                ->where('company_id', $companyId)
                ->where('transaction_id', $transactionId);
            if (Schema::hasColumn('recipe_consumptions', 'reversed_at')) {
                $query->whereNull('reversed_at');
            }
            $rows = $query->where('ingredient_id', '>', 0)->lockForUpdate()->get();
            $restored = [];
            $resolvedBranch = BranchStockService::writeBranchId($companyId, $branchId);
            foreach ($rows as $row) {
                $ingredient = Ingredient::where('company_id', $companyId)
                    ->where('id', $row->ingredient_id)->lockForUpdate()->first();
                if (!$ingredient) continue;
                $qty = (float) $row->quantity;
                $after = round((float) $ingredient->current_stock + $qty, 4);
                $ingredient->update(['current_stock' => $after]);
                self::changeIngredientBranchStock(
                    $companyId, (int) $ingredient->id, $resolvedBranch, $qty, (float) $after - $qty
                );
                self::writeIngredientLedger(
                    $companyId, (int) $ingredient->id, $resolvedBranch, self::MOVEMENT_RETURN,
                    $qty, $after, $transactionId, (string) $transactionId, ['reason' => $referenceType], $userId
                );
                if (Schema::hasColumn('recipe_consumptions', 'reversed_at')) {
                    DB::table('recipe_consumptions')->where('id', $row->id)->update([
                        'reversed_at' => now(), 'updated_at' => now(),
                    ]);
                }
                $restored[] = ['ingredient_id' => (int) $ingredient->id, 'quantity' => $qty];
            }
            // An edited/voided sale must release any cooked unit it consumed.
            // Keep the prepared-return row and its audit identity, but make the
            // consumed quantity available again exactly once.
            if (Schema::hasTable('prepared_returns')) {
                $preparedRows = DB::table('prepared_returns')
                    ->where('company_id', $companyId)
                    ->where('consumed_by_transaction_id', $transactionId)
                    ->lockForUpdate()->get();
                foreach ($preparedRows as $preparedRow) {
                    $consumed = (float) ($preparedRow->consumed_quantity ?? 0);
                    if ($consumed <= 0) {
                        continue;
                    }
                    DB::table('prepared_returns')->where('id', $preparedRow->id)->update([
                        'remaining_quantity' => round((float) $preparedRow->remaining_quantity + $consumed, 4),
                        'consumed_quantity' => max(0, round($consumed - $consumed, 4)),
                        'consumed_by_transaction_id' => null,
                        'consumed_at' => null,
                        'status' => 'available',
                        'updated_at' => now(),
                    ]);
                }
            }
            if (Schema::hasColumn('recipe_consumptions', 'reversed_at')) {
                DB::table('recipe_consumptions')
                    ->where('company_id', $companyId)
                    ->where('transaction_id', $transactionId)
                    ->whereNull('reversed_at')
                    ->update(['reversed_at' => now(), 'updated_at' => now()]);
            }
            // The original movement remains searchable in history, but no
            // longer acts as the active idempotency marker for the re-save.
            InventoryMovement::where('company_id', $companyId)
                ->where('reference_type', 'pos_transaction')
                ->where('reference_id', $transactionId)
                ->where('type', self::MOVEMENT_SALE)
                ->update(['reference_type' => $referenceType]);
            return ['restored' => $restored];
        });
    }

    private static function consumePrepared(int $companyId, int $productId, float $quantity, int $transactionId, ?int $branchId): float
    {
        if (!Schema::hasTable('prepared_returns')) {
            return 0.0;
        }
        $left = $quantity;
        $used = 0.0;
        $rows = DB::table('prepared_returns')
            ->where('company_id', $companyId)
            ->where('product_id', $productId)
            ->where('status', 'available')
            ->where('remaining_quantity', '>', 0)
            ->where(function ($q) use ($branchId) {
                $branchId ? $q->where('branch_id', $branchId) : $q->whereNull('branch_id');
            })
            ->where(function ($q) {
                $q->whereNull('expires_at')->orWhere('expires_at', '>', now());
            })
            ->orderBy('id')
            ->lockForUpdate()
            ->get();

        foreach ($rows as $row) {
            if ($left <= 0) break;
            $take = min($left, (float) $row->remaining_quantity);
            $remaining = round((float) $row->remaining_quantity - $take, 4);
            DB::table('prepared_returns')->where('id', $row->id)->update([
                'remaining_quantity' => 0,
                'consumed_quantity' => $take,
                'consumed_by_transaction_id' => $transactionId,
                'consumed_at' => now(),
                'status' => 'consumed',
                'updated_at' => now(),
            ]);
            // Keep each sale's prepared-return ownership independent. A
            // partial take leaves a fresh available row, while the consumed
            // row remains the immutable link for this transaction.
            if ($remaining > 0) {
                DB::table('prepared_returns')->insert([
                    'company_id' => $companyId,
                    'branch_id' => $row->branch_id,
                    'product_id' => $productId,
                    'return_transaction_id' => $row->return_transaction_id,
                    'quantity' => $remaining,
                    'remaining_quantity' => $remaining,
                    'consumed_quantity' => 0,
                    'status' => 'available',
                    'expires_at' => $row->expires_at,
                    'created_by' => $row->created_by,
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);
            }
            $left = round($left - $take, 4);
            $used = round($used + $take, 4);
        }
        return $used;
    }

    private static function writeConsumptionSnapshot(
        int $companyId,
        int $transactionId,
        ?int $branchId,
        array $requirement,
        string $invoiceNumber
    ): void {
        if (!Schema::hasTable('recipe_consumptions')) return;
        $data = [
            'company_id' => $companyId,
            'transaction_id' => $transactionId,
            'branch_id' => $branchId,
            'ingredient_id' => $requirement['ingredient_id'],
            'quantity' => $requirement['quantity'],
            'components' => json_encode($requirement['components']),
            'snapshot' => json_encode($requirement['snapshot']),
            'invoice_number' => $invoiceNumber,
            'created_at' => now(),
            'updated_at' => now(),
        ];
        if (Schema::hasColumn('recipe_consumptions', 'reversed_at')) {
            $data['reversed_at'] = null;
        }
        DB::table('recipe_consumptions')->insert($data);
    }

    private static function writePreparedSaleMarker(
        int $companyId, int $transactionId, string $invoiceNumber, ?int $branchId, ?int $userId
    ): void {
        if (!Schema::hasTable('recipe_consumptions')) return;
        DB::table('recipe_consumptions')->insert([
            'company_id' => $companyId,
            'transaction_id' => $transactionId,
            'branch_id' => $branchId,
            'ingredient_id' => 0,
            'quantity' => 0,
            'components' => json_encode([]),
            'snapshot' => json_encode([['prepared_return' => true, 'created_by' => $userId]]),
            'invoice_number' => $invoiceNumber,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    private static function writeIngredientLedger(
        int $companyId,
        int $ingredientId,
        ?int $branchId,
        string $type,
        float $quantity,
        float $balance,
        int $referenceId,
        string $referenceNumber,
        ?array $meta,
        ?int $userId
    ): void {
        if (!Schema::hasTable('ingredient_movements')) return;
        DB::table('ingredient_movements')->insert([
            'company_id' => $companyId,
            'ingredient_id' => $ingredientId,
            'branch_id' => $branchId,
            'type' => $type,
            'quantity' => $quantity,
            'balance_after' => $balance,
            'reference_type' => str_contains($type, 'return') ? 'pos_return' : 'pos_transaction',
            'reference_id' => $referenceId,
            'reference_number' => $referenceNumber,
            'snapshot' => $meta ? json_encode($meta) : null,
            'created_by' => $userId,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    private static function returnQuantityForProduct(
        int $companyId, int $parentTransactionId, int $productId, float $returnQty, object $row
    ): float {
        $snapshot = json_decode((string) ($row->snapshot ?? '{}'), true);
        $perUnit = 0.0;
        foreach ((array) $snapshot as $component) {
            if ((int) ($component['product_id'] ?? 0) !== $productId) continue;
            $saleQty = (float) ($component['sale_quantity'] ?? 0);
            if ($saleQty > 0) {
                $perUnit += (float) ($component['quantity_needed'] ?? 0) * $returnQty;
            }
        }
        // Old snapshots may only contain a components map. In that case the
        // parent line quantity is needed to calculate a proportional return.
        if ($perUnit <= 0) {
            $parentQty = (float) PosTransaction::withoutGlobalScope('hide_archived')
                ->where('id', $parentTransactionId)->with(['items' => function ($q) use ($productId) {
                    $q->where('item_type', 'product')->where('item_id', $productId);
                }])->first()?->items?->sum('quantity');
            $componentMap = json_decode((string) ($row->components ?? '{}'), true);
            $componentTotal = (float) (($componentMap[$productId] ?? 0));
            $perUnit = $parentQty > 0 ? ($componentTotal / $parentQty) * $returnQty : 0;
        }
        return round($perUnit, 4);
    }

    private static function changeIngredientBranchStock(
        int $companyId, int $ingredientId, ?int $branchId, float $delta, ?float $initialQuantity = null
    ): void {
        if (!$branchId || !Schema::hasTable('ingredient_stocks')) {
            return;
        }
        $row = DB::table('ingredient_stocks')
            ->where('company_id', $companyId)
            ->where('ingredient_id', $ingredientId)
            ->where('branch_id', $branchId)
            ->lockForUpdate()
            ->first();
        if (!$row) {
            DB::table('ingredient_stocks')->insert([
                'company_id' => $companyId,
                'ingredient_id' => $ingredientId,
                'branch_id' => $branchId,
                'quantity' => $initialQuantity ?? 0,
                'min_stock_level' => 0,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
            $row = DB::table('ingredient_stocks')
                ->where('company_id', $companyId)
                ->where('ingredient_id', $ingredientId)
                ->where('branch_id', $branchId)
                ->lockForUpdate()->first();
        }
        DB::table('ingredient_stocks')->where('id', $row->id)->update([
            'quantity' => round((float) $row->quantity + $delta, 4),
            'updated_at' => now(),
        ]);
    }
}
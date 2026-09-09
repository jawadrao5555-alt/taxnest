<?php

namespace App\Services;

use App\Models\Company;
use App\Models\Ingredient;
use App\Models\IngredientMovement;
use App\Models\InventoryMovement;
use App\Models\PosProduct;
use App\Models\PosStockInBatch;
use App\Models\PosStockInLine;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Validation\ValidationException;

/**
 * NestPOS Stock-In Phase 2a — persistent staging + posting.
 *
 * Matching lives in PosStockInExcelService. Posting reuses
 * RecipeInventoryService::adjustIngredientStock and the PosInventoryController
 * ADD path (inventory_stocks + TYPE_ADJUSTMENT_IN). No second ledger.
 */
class PosStockInService
{
    public function __construct(protected PosStockInExcelService $excel)
    {
    }

    public function latestOpenBatch(int $companyId): ?PosStockInBatch
    {
        return PosStockInBatch::query()
            ->where('company_id', $companyId)
            ->where('status', PosStockInBatch::STATUS_OPEN)
            ->orderByDesc('id')
            ->first();
    }

    public function stageUpload(
        Company $company,
        UploadedFile $file,
        int $userId,
        string $reference,
        bool $updateCost = false,
        bool $saveSupplierCode = false
    ): PosStockInBatch {
        $this->assertSingleShop($company);

        $reference = trim($reference);
        if ($reference === '') {
            throw ValidationException::withMessages([
                'reference' => __('pos.stock_in_reference_required'),
            ]);
        }

        $parsed = $this->excel->parseFile($file->getRealPath());
        if (! ($parsed['ok'] ?? false)) {
            throw ValidationException::withMessages([
                'excel_file' => $parsed['fatal'] ?? __('pos.stock_in_file_unreadable'),
            ]);
        }

        $inventoryOn = $this->inventoryOn($company);
        $recipesOn = $this->recipesOn($company);

        return DB::transaction(function () use (
            $company, $file, $userId, $reference, $updateCost, $saveSupplierCode,
            $parsed, $inventoryOn, $recipesOn
        ) {
            $batch = PosStockInBatch::query()->create([
                'company_id' => $company->id,
                'created_by' => $userId,
                'branch_id' => null,
                'reference' => $reference,
                'update_cost' => $updateCost,
                'save_supplier_code' => $saveSupplierCode,
                'status' => PosStockInBatch::STATUS_OPEN,
                'original_filename' => $file->getClientOriginalName(),
                'line_count' => 0,
            ]);

            foreach ($parsed['rows'] as $row) {
                $match = $this->excel->matchRow($row, (int) $company->id, $inventoryOn, $recipesOn);
                PosStockInLine::query()->create([
                    'batch_id' => $batch->id,
                    'source_row_no' => (int) $row['source_row_no'],
                    'line_type' => (string) ($row['line_type'] ?? PosStockInLine::TYPE_ITEM),
                    'supplier_item_code' => $row['supplier_item_code'],
                    'supplier_item_name' => $row['supplier_item_name'] !== '' ? $row['supplier_item_name'] : null,
                    'nestpos_code_raw' => $row['nestpos_code'],
                    'qty' => (float) ($row['qty'] ?? 0),
                    'unit' => $row['unit'] !== '' ? $row['unit'] : null,
                    'rate' => $row['rate'],
                    'branch_id' => null,
                    'reference_override' => $row['reference_override'] !== '' ? $row['reference_override'] : null,
                    'notes' => trim((string) $row['notes']) !== '' ? trim((string) $row['notes']) : null,
                    'match_status' => $match['match_status'],
                    'matched_ingredient_id' => $match['matched_ingredient_id'],
                    'matched_product_id' => $match['matched_product_id'],
                    'invalid_reason' => $match['invalid_reason'],
                    'selected' => (bool) $match['selected'],
                ]);
            }

            $batch->line_count = $batch->lines()->count();
            $batch->save();

            return $batch->fresh(['lines']);
        });
    }

    public function rematch(PosStockInBatch $batch, Company $company): PosStockInBatch
    {
        $this->assertSingleShop($company);
        $this->assertBatchOpen($batch, $company);

        $inventoryOn = $this->inventoryOn($company);
        $recipesOn = $this->recipesOn($company);

        foreach ($batch->lines as $line) {
            if ($line->posted_at !== null) {
                continue;
            }
            if ($this->keepHumanClearance($line, $company)) {
                continue;
            }

            $parsed = $this->lineToParsed($line);
            $match = $this->excel->matchRow($parsed, (int) $company->id, $inventoryOn, $recipesOn);
            $line->match_status = $match['match_status'];
            $line->matched_ingredient_id = $match['matched_ingredient_id'];
            $line->matched_product_id = $match['matched_product_id'];
            $line->invalid_reason = $match['invalid_reason'];
            $line->selected = (bool) $match['selected'];
            $line->save();
        }

        return $batch->fresh(['lines']);
    }

    public function mapClearance(
        PosStockInBatch $batch,
        Company $company,
        int $lineId,
        ?int $ingredientId,
        ?int $productId
    ): PosStockInLine {
        $this->assertSingleShop($company);
        $this->assertBatchOpen($batch, $company);

        $line = $batch->lines()->where('id', $lineId)->firstOrFail();
        if ($line->posted_at !== null) {
            throw ValidationException::withMessages(['line' => __('pos.stock_in_already_posted_line')]);
        }

        if ($line->line_type === PosStockInLine::TYPE_INGREDIENT) {
            if (! $ingredientId) {
                throw ValidationException::withMessages(['ingredient_id' => __('pos.stock_in_pick_ingredient')]);
            }
            $ing = Ingredient::query()
                ->where('company_id', $company->id)
                ->where('id', $ingredientId)
                ->first();
            if (! $ing) {
                throw ValidationException::withMessages(['ingredient_id' => __('pos.stock_in_pick_ingredient')]);
            }
            $line->matched_ingredient_id = (int) $ing->id;
            $line->matched_product_id = null;
        } else {
            if (! $productId) {
                throw ValidationException::withMessages(['product_id' => __('pos.stock_in_pick_item')]);
            }
            $product = PosProduct::query()
                ->where('company_id', $company->id)
                ->where('id', $productId)
                ->first();
            if (! $product) {
                throw ValidationException::withMessages(['product_id' => __('pos.stock_in_pick_item')]);
            }
            $line->matched_product_id = (int) $product->id;
            $line->matched_ingredient_id = null;
        }

        $line->match_status = PosStockInLine::MATCHED;
        $line->invalid_reason = PosStockInLine::REASON_MAPPED;
        $line->selected = true;
        $line->save();

        return $line;
    }

    /**
     * @param  list<int>  $selectedIds
     */
    public function setSelected(PosStockInBatch $batch, Company $company, array $selectedIds): void
    {
        $this->assertBatchOpen($batch, $company);
        $want = array_map('intval', $selectedIds);
        foreach ($batch->lines as $line) {
            if ($line->posted_at !== null || $line->match_status !== PosStockInLine::MATCHED) {
                $line->selected = false;
                $line->save();
                continue;
            }
            $line->selected = in_array((int) $line->id, $want, true);
            $line->save();
        }
    }

    public function cancelBatch(PosStockInBatch $batch, Company $company): PosStockInBatch
    {
        $this->assertSingleShop($company);
        $this->assertBatchOpen($batch, $company);
        $batch->status = PosStockInBatch::STATUS_CANCELLED;
        $batch->save();

        return $batch;
    }

    /**
     * Post selected MATCHED rows. One DB transaction. Failure rolls inventory
     * back and leaves staging posted_at null for retry.
     *
     * @return array{posted:int,skipped:int,already:int}
     */
    public function post(PosStockInBatch $batch, Company $company, int $userId): array
    {
        $this->assertSingleShop($company);
        $this->assertBatchOpen($batch, $company);

        $batch->load('lines');

        $groups = $this->collapseReadyGroups($batch);
        if ($groups === []) {
            throw ValidationException::withMessages([
                'stock_in' => __('pos.stock_in_nothing_to_post'),
            ]);
        }

        $posted = 0;
        $already = 0;

        DB::transaction(function () use ($batch, $company, $userId, $groups, &$posted, &$already) {
            foreach ($groups as $group) {
                if ($this->alreadyPosted($company, $group)) {
                    $this->markAlreadyReceived($group);
                    $already += count($group['line_ids']);
                    continue;
                }

                if ($group['kind'] === PosStockInLine::TYPE_INGREDIENT) {
                    $movementId = $this->postIngredientGroup($company, $userId, $batch, $group);
                    $table = 'ingredient_movements';
                } else {
                    $movementId = $this->postItemGroup($company, $userId, $batch, $group);
                    $table = 'inventory_movements';
                }

                $this->markPosted($group, $movementId, $table);
                $posted += count($group['line_ids']);
            }

            $remaining = PosStockInLine::query()
                ->where('batch_id', $batch->id)
                ->whereNull('posted_at')
                ->where('match_status', PosStockInLine::MATCHED)
                ->exists();
            if (! $remaining) {
                $batch->status = PosStockInBatch::STATUS_POSTED;
                $batch->posted_at = now();
                $batch->save();
            }
        });

        return [
            'posted' => $posted,
            'skipped' => 0,
            'already' => $already,
        ];
    }

    public function assertSingleShop(Company $company): void
    {
        if (BranchStockService::isMultiBranch((int) $company->id)) {
            throw ValidationException::withMessages([
                'stock_in' => __('pos.stock_in_multi_branch_blocked'),
            ]);
        }
    }

    protected function assertBatchOpen(PosStockInBatch $batch, Company $company): void
    {
        if ((int) $batch->company_id !== (int) $company->id) {
            abort(404);
        }
        if ($batch->status !== PosStockInBatch::STATUS_OPEN) {
            throw ValidationException::withMessages([
                'stock_in' => __('pos.stock_in_batch_closed'),
            ]);
        }
    }

    protected function inventoryOn(Company $company): bool
    {
        return (bool) ($company->inventory_enabled ?? false)
            && PosFeatureService::moduleAvailable($company, 'inventory');
    }

    protected function recipesOn(Company $company): bool
    {
        return PosFeatureService::moduleAvailable($company, 'recipes');
    }

    protected function keepHumanClearance(PosStockInLine $line, Company $company): bool
    {
        if ($line->invalid_reason !== PosStockInLine::REASON_MAPPED) {
            return false;
        }
        if ($line->line_type === PosStockInLine::TYPE_INGREDIENT) {
            return Ingredient::query()
                ->where('company_id', $company->id)
                ->where('id', (int) $line->matched_ingredient_id)
                ->exists();
        }

        return PosProduct::query()
            ->where('company_id', $company->id)
            ->where('id', (int) $line->matched_product_id)
            ->exists();
    }

    /**
     * Same ingredient/item + branch + reference → SUM qty, post once.
     *
     * @return list<array<string,mixed>>
     */
    protected function collapseReadyGroups(PosStockInBatch $batch): array
    {
        $groups = [];
        foreach ($batch->lines as $line) {
            if (! $line->isPostable() || ! $line->selected) {
                continue;
            }
            $ref = $this->effectiveReference($batch, $line);
            $matchedId = $line->line_type === PosStockInLine::TYPE_INGREDIENT
                ? (int) $line->matched_ingredient_id
                : (int) $line->matched_product_id;
            $key = implode('|', [
                $line->line_type,
                (string) $matchedId,
                (string) ($line->branch_id ?? ''),
                $ref,
            ]);
            if (! isset($groups[$key])) {
                $groups[$key] = [
                    'kind' => $line->line_type,
                    'ingredient_id' => $line->line_type === PosStockInLine::TYPE_INGREDIENT ? $matchedId : null,
                    'product_id' => $line->line_type === PosStockInLine::TYPE_ITEM ? $matchedId : null,
                    'reference_number' => $ref,
                    'quantity' => 0.0,
                    'rate_num' => 0.0,
                    'rate_den' => 0.0,
                    'supplier_item_code' => $line->supplier_item_code,
                    'notes' => [],
                    'source_row_nos' => [],
                    'line_ids' => [],
                    'keeper_id' => (int) $line->id,
                ];
            }
            $groups[$key]['quantity'] += (float) $line->qty;
            if ($line->rate !== null) {
                $groups[$key]['rate_num'] += (float) $line->rate * (float) $line->qty;
                $groups[$key]['rate_den'] += (float) $line->qty;
            }
            if ($line->notes) {
                $groups[$key]['notes'][] = $line->notes;
            }
            $groups[$key]['source_row_nos'][] = (int) $line->source_row_no;
            $groups[$key]['line_ids'][] = (int) $line->id;
        }

        foreach ($groups as &$group) {
            $group['rate'] = $group['rate_den'] > 0
                ? $group['rate_num'] / $group['rate_den']
                : 0.0;
        }
        unset($group);

        return array_values($groups);
    }

    protected function effectiveReference(PosStockInBatch $batch, PosStockInLine $line): string
    {
        $override = trim((string) ($line->reference_override ?? ''));

        return $override !== '' ? $override : (string) $batch->reference;
    }

    /**
     * @param  array<string,mixed>  $group
     */
    protected function alreadyPosted(Company $company, array $group): bool
    {
        $ref = trim((string) $group['reference_number']);
        if ($ref === '') {
            return false;
        }

        if ($group['kind'] === PosStockInLine::TYPE_INGREDIENT) {
            if (! Schema::hasTable('ingredient_movements')) {
                return false;
            }

            return IngredientMovement::query()
                ->where('company_id', $company->id)
                ->where('reference_type', PosStockInBatch::REFERENCE_TYPE)
                ->where('reference_number', $ref)
                ->where('ingredient_id', (int) $group['ingredient_id'])
                ->exists();
        }

        return InventoryMovement::query()
            ->where('company_id', $company->id)
            ->where('reference_type', PosStockInBatch::REFERENCE_TYPE)
            ->where('reference_number', $ref)
            ->where('product_id', (int) $group['product_id'])
            ->exists();
    }

    /**
     * @param  array<string,mixed>  $group
     */
    protected function postIngredientGroup(Company $company, int $userId, PosStockInBatch $batch, array $group): int
    {
        $qty = (float) $group['quantity'];
        $rate = (float) $group['rate'];
        $snapshot = [
            'reason' => 'Stock-In',
            'delta' => $qty,
            'rate' => $rate,
            'supplier_item_code' => $group['supplier_item_code'],
            'source_row_nos' => $group['source_row_nos'],
            'collapsed_qty' => $qty,
        ];

        RecipeInventoryService::adjustIngredientStock(
            (int) $company->id,
            (int) $group['ingredient_id'],
            $qty,
            $userId,
            null,
            'Stock-In',
            [
                'reference_type' => PosStockInBatch::REFERENCE_TYPE,
                'reference_id' => (int) $batch->id,
                'reference_number' => (string) $group['reference_number'],
                'snapshot' => $snapshot,
            ]
        );

        if ($batch->update_cost && $rate > 0) {
            Ingredient::query()
                ->where('company_id', $company->id)
                ->where('id', (int) $group['ingredient_id'])
                ->update(['cost_per_unit' => $rate]);
        }

        if ($batch->save_supplier_code) {
            $this->maybeSaveSupplierCode($company, (int) $group['ingredient_id'], (string) ($group['supplier_item_code'] ?? ''));
        }

        $movementId = (int) (DB::table('ingredient_movements')
            ->where('company_id', $company->id)
            ->where('ingredient_id', (int) $group['ingredient_id'])
            ->where('reference_type', PosStockInBatch::REFERENCE_TYPE)
            ->where('reference_id', (int) $batch->id)
            ->orderByDesc('id')
            ->value('id') ?? 0);

        return $movementId;
    }

    /**
     * Finished-item ADD path: BranchStockService::stockRow + TYPE_ADJUSTMENT_IN.
     * Never TYPE_PURCHASE, never type=set.
     *
     * @param  array<string,mixed>  $group
     */
    protected function postItemGroup(Company $company, int $userId, PosStockInBatch $batch, array $group): int
    {
        $product = PosProduct::query()
            ->where('company_id', $company->id)
            ->where('id', (int) $group['product_id'])
            ->firstOrFail();

        $qty = (float) $group['quantity'];
        if ($qty <= 0) {
            throw new \RuntimeException('Stock-In item quantity must be greater than zero.');
        }

        $branchId = BranchStockService::writeBranchId((int) $company->id, null);
        $stock = BranchStockService::stockRow((int) $company->id, (int) $product->id, $branchId);
        $previousQty = (float) $stock->quantity;
        $newQty = $previousQty + $qty;

        $stockUpdate = ['quantity' => $newQty];
        $rate = (float) $group['rate'];
        if ($batch->update_cost && $rate > 0) {
            if ($previousQty > 0 && (float) $stock->avg_purchase_price > 0) {
                $totalOldValue = $previousQty * (float) $stock->avg_purchase_price;
                $totalNewValue = $qty * $rate;
                $stockUpdate['avg_purchase_price'] = round(($totalOldValue + $totalNewValue) / $newQty, 2);
            } else {
                $stockUpdate['avg_purchase_price'] = $rate;
            }
            $stockUpdate['last_purchase_price'] = $rate;
        }
        $stock->update($stockUpdate);
        BranchStockService::syncProductMirror((int) $company->id, (int) $product->id);

        $notes = implode('; ', array_unique($group['notes']));
        $movement = InventoryMovement::query()->create([
            'company_id' => $company->id,
            'product_id' => $product->id,
            'branch_id' => $branchId,
            'type' => InventoryMovement::TYPE_ADJUSTMENT_IN,
            'quantity' => $qty,
            'unit_price' => $rate > 0 ? $rate : 0,
            'balance_after' => $newQty,
            'reference_type' => PosStockInBatch::REFERENCE_TYPE,
            'reference_id' => (int) $batch->id,
            'reference_number' => (string) $group['reference_number'],
            'notes' => $notes !== '' ? $notes : 'Stock-In',
            'created_by' => $userId,
        ]);

        return (int) $movement->id;
    }

    protected function maybeSaveSupplierCode(Company $company, int $ingredientId, string $code): void
    {
        $code = trim($code);
        if ($code === '' || ! Schema::hasColumn('ingredients', 'code')) {
            return;
        }
        $ingredient = Ingredient::query()
            ->where('company_id', $company->id)
            ->where('id', $ingredientId)
            ->first();
        if (! $ingredient || trim((string) $ingredient->code) !== '') {
            return;
        }
        $taken = Ingredient::query()
            ->where('company_id', $company->id)
            ->where('id', '!=', $ingredientId)
            ->whereRaw('LOWER(code) = ?', [strtolower($code)])
            ->exists();
        if ($taken) {
            return;
        }
        $ingredient->code = $code;
        $ingredient->save();
    }

    /**
     * @param  array<string,mixed>  $group
     */
    protected function markPosted(array $group, int $movementId, string $table): void
    {
        $keeper = (int) $group['keeper_id'];
        foreach ($group['line_ids'] as $id) {
            PosStockInLine::query()->where('id', $id)->update([
                'posted_at' => now(),
                'movement_id' => $movementId > 0 ? $movementId : null,
                'movement_table' => $table,
                'collapsed_into_line_id' => ((int) $id === $keeper) ? null : $keeper,
            ]);
        }
    }

    /**
     * @param  array<string,mixed>  $group
     */
    protected function markAlreadyReceived(array $group): void
    {
        PosStockInLine::query()->whereIn('id', $group['line_ids'])->update([
            'posted_at' => now(),
            'invalid_reason' => __('pos.stock_in_already_received'),
            'selected' => false,
        ]);
    }

    /**
     * @return array<string,mixed>
     */
    protected function lineToParsed(PosStockInLine $line): array
    {
        return [
            'source_row_no' => (int) $line->source_row_no,
            'line_type' => (string) $line->line_type,
            'nestpos_code' => $line->nestpos_code_raw,
            'supplier_item_code' => $line->supplier_item_code,
            'supplier_item_name' => (string) ($line->supplier_item_name ?? ''),
            'qty' => (float) $line->qty,
            'unit' => (string) ($line->unit ?? ''),
            'rate' => $line->rate !== null ? (float) $line->rate : null,
            'reference_override' => (string) ($line->reference_override ?? ''),
            'notes' => (string) ($line->notes ?? ''),
        ];
    }
}

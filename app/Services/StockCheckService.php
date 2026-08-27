<?php

namespace App\Services;

use App\Models\Ingredient;
use App\Models\IngredientMovement;
use App\Models\IngredientStock;
use App\Models\InventoryMovement;
use App\Models\PosProduct;
use App\Models\StockCheck;
use App\Models\StockCheckLine;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Physical Stock Check — the whole expected-vs-counted lifecycle in one place.
 *
 * Three rules this service exists to protect:
 *
 * 1. EXPECTED IS A SNAPSHOT. The number the counter is arguing with is frozen
 *    the moment the sheet is opened. If sales keep running while the kitchen
 *    counts, the sheet must not silently move under their feet.
 *
 * 2. POSTING APPLIES THE DIFFERENCE, NOT THE COUNT. At post time the live
 *    quantity may have moved on (a late bill). Setting stock to the counted
 *    number would erase those sales. We apply `variance` as a delta instead, so
 *    "system said 20, we found 15" always means "take 5 off whatever is there
 *    now" — which is exactly how the shop thinks about it.
 *
 * 3. EVERY CORRECTION LEAVES A LEDGER ROW. A stock check is an audit, so each
 *    adjustment writes an inventory/ingredient movement pointing back at the
 *    check code. Nothing changes quantity invisibly.
 */
class StockCheckService
{
    public const REFERENCE_TYPE = 'stock_check';

    /** Ingredient movement type used when a count corrects raw-material stock. */
    private const ING_MOVEMENT_IN = 'stock_check_in';
    private const ING_MOVEMENT_OUT = 'stock_check_out';

    /* ------------------------------------------------------------------ */
    /* Availability                                                        */
    /* ------------------------------------------------------------------ */

    /**
     * The owner's PROD schema has drifted before (migration "Ran" without the
     * table). Every entry point asks this first and degrades to a clear
     * message instead of a 500.
     */
    private static ?bool $ready = null;
    public static function ready(): bool
    {
        if (self::$ready === null) {
            try {
                // Tables alone are not enough: a migration marked "Ran" against
                // a half-built table would sail past a hasTable() check and then
                // 500 on the first insert. Name the columns the code actually
                // depends on, including the inventory table it reads from.
                self::$ready = Schema::hasTable('stock_checks')
                    && Schema::hasTable('stock_check_lines')
                    && Schema::hasColumns('stock_checks', [
                        'company_id', 'branch_id', 'code', 'scope', 'status',
                        'total_lines', 'counted_lines', 'variance_lines',
                        'short_value', 'excess_value', 'started_at', 'posted_at',
                    ])
                    && Schema::hasColumns('stock_check_lines', [
                        'company_id', 'stock_check_id', 'item_type', 'item_id', 'branch_id',
                        'item_name', 'item_code', 'unit', 'expected_quantity',
                        'counted_quantity', 'variance', 'unit_cost', 'variance_value',
                        'reason', 'notes', 'counted_by', 'counted_at',
                    ])
                    && Schema::hasTable('inventory_stocks')
                    && Schema::hasColumns('inventory_stocks', ['company_id', 'product_id', 'branch_id', 'quantity']);
            } catch (\Throwable $e) {
                self::$ready = false;
            }
        }
        return self::$ready;
    }

    /** Raw materials only exist for shops running the kitchen/recipe module. */
    public static function ingredientsAvailable(int $companyId): bool
    {
        try {
            if (!Schema::hasTable('ingredients')) return false;
            return Ingredient::where('company_id', $companyId)->where('is_active', true)->exists();
        } catch (\Throwable $e) {
            return false;
        }
    }

    /* ------------------------------------------------------------------ */
    /* Creating a sheet                                                    */
    /* ------------------------------------------------------------------ */

    /**
     * Insert the header, retrying if a concurrent open stole the number.
     *
     * The running number is derived from existing rows rather than an identity
     * column, so two simultaneous opens can compute the same one and the unique
     * (company, code) index will reject the loser. That is the index doing its
     * job — recompute and try again instead of handing the shop a 500.
     */
    private static function createHeader(int $companyId, ?int $branchId, string $scope, int $userId, array $options): StockCheck
    {
        $attributes = [
            'company_id' => $companyId,
            'branch_id' => $branchId,
            'scope' => $scope,
            'status' => StockCheck::STATUS_COUNTING,
            'notes' => $options['notes'] ?? null,
            'started_at' => now(),
            'created_by' => $userId,
        ];

        for ($attempt = 0; $attempt < 5; $attempt++) {
            try {
                return StockCheck::create($attributes + ['code' => self::nextCode($companyId)]);
            } catch (\Illuminate\Database\UniqueConstraintViolationException $e) {
                continue;
            } catch (\Illuminate\Database\QueryException $e) {
                // Older drivers do not raise the dedicated class.
                if (!str_contains(strtolower($e->getMessage()), 'duplicate')) throw $e;
            }
        }

        // Last resort: a suffix nobody else can be computing at the same time.
        return StockCheck::create($attributes + ['code' => 'SC-' . now()->format('ymdHis')]);
    }

    /** Per-company running number: SC-0001, SC-0002 … never reused. */
    public static function nextCode(int $companyId): string
    {
        $last = StockCheck::where('company_id', $companyId)->max('id');
        // id-based rather than count-based so a cancelled check never hands its
        // number to the next one.
        $seq = (int) StockCheck::where('company_id', $companyId)->count() + 1;
        if ($last) {
            $lastCode = StockCheck::where('company_id', $companyId)->orderByDesc('id')->value('code');
            if ($lastCode && preg_match('/(\d+)$/', $lastCode, $m)) {
                $seq = max($seq, ((int) $m[1]) + 1);
            }
        }
        return 'SC-' . str_pad((string) $seq, 4, '0', STR_PAD_LEFT);
    }

    /**
     * Open a new count sheet and freeze the expected quantities onto it.
     *
     * @param  array{category?:?string, only_in_stock?:bool, item_ids?:array}  $options
     */
    public static function open(int $companyId, ?int $branchId, string $scope, int $userId, array $options = []): StockCheck
    {
        $scope = in_array($scope, [StockCheck::SCOPE_PRODUCTS, StockCheck::SCOPE_INGREDIENTS, StockCheck::SCOPE_BOTH], true)
            ? $scope
            : StockCheck::SCOPE_PRODUCTS;

        return DB::transaction(function () use ($companyId, $branchId, $scope, $userId, $options) {
            // One open sheet per branch, enforced HERE and not just in the UI:
            // two managers opening sheets a second apart would each freeze their
            // own snapshot, and posting both would apply the same shortage twice.
            $openQuery = StockCheck::where('company_id', $companyId)
                ->where('status', StockCheck::STATUS_COUNTING);
            $branchId === null
                ? $openQuery->whereNull('branch_id')
                : $openQuery->where('branch_id', $branchId);
            if ($openQuery->lockForUpdate()->exists()) {
                throw new StockCheckAlreadyOpenException();
            }

            $check = self::createHeader($companyId, $branchId, $scope, $userId, $options);

            $rows = [];
            if ($scope !== StockCheck::SCOPE_INGREDIENTS) {
                $rows = array_merge($rows, self::productLines($companyId, $branchId, $check->id, $options));
            }
            if ($scope !== StockCheck::SCOPE_PRODUCTS) {
                $rows = array_merge($rows, self::ingredientLines($companyId, $branchId, $check->id, $options));
            }

            foreach (array_chunk($rows, 500) as $chunk) {
                StockCheckLine::insert($chunk);
            }

            $check->update(['total_lines' => count($rows)]);
            return $check->fresh();
        });
    }

    /** Snapshot every sellable/menu item's expected quantity for this branch. */
    private static function productLines(int $companyId, ?int $branchId, int $checkId, array $options): array
    {
        $query = PosProduct::where('company_id', $companyId)->where('is_active', true);

        if (!empty($options['category'])) {
            $query->where('category', $options['category']);
        }
        if (!empty($options['item_ids']) && is_array($options['item_ids'])) {
            $query->whereIn('id', $options['item_ids']);
        }

        $products = $query->orderBy('name')->get(['id', 'name', 'sku', 'barcode', 'unit_type', 'uom', 'cost_price']);
        if ($products->isEmpty()) return [];

        // One query for the whole branch's stock rather than N lookups.
        $stockQuery = DB::table('inventory_stocks')
            ->where('company_id', $companyId)
            ->whereIn('product_id', $products->pluck('id')->all());
        $branchId === null
            ? $stockQuery->whereNull('branch_id')
            : $stockQuery->where('branch_id', $branchId);
        $stocks = $stockQuery->get()->keyBy('product_id');

        $now = now();
        $rows = [];
        foreach ($products as $p) {
            $stock = $stocks->get($p->id);
            $expected = (float) ($stock->quantity ?? 0);

            // "Only items that should have stock" keeps a 900-item menu from
            // producing a 900-row sheet nobody will ever fill in.
            if (!empty($options['only_in_stock']) && $expected <= 0) continue;

            $cost = (float) ($stock->avg_purchase_price ?? 0);
            if ($cost <= 0) $cost = (float) ($p->cost_price ?? 0);

            $rows[] = [
                'company_id' => $companyId,
                'stock_check_id' => $checkId,
                'item_type' => StockCheckLine::TYPE_PRODUCT,
                'item_id' => $p->id,
                'branch_id' => $branchId,
                'item_name' => (string) $p->name,
                'item_code' => $p->sku ?: ($p->barcode ?: null),
                'unit' => $p->unit_type ?: ($p->uom ?: null),
                'expected_quantity' => round($expected, 4),
                'counted_quantity' => null,
                'variance' => 0,
                'unit_cost' => round($cost, 2),
                'variance_value' => 0,
                'created_at' => $now,
                'updated_at' => $now,
            ];
        }
        return $rows;
    }

    /** Snapshot every raw material's expected quantity for this branch. */
    private static function ingredientLines(int $companyId, ?int $branchId, int $checkId, array $options): array
    {
        if (!self::ingredientsAvailable($companyId)) return [];

        $ingredients = Ingredient::where('company_id', $companyId)
            ->where('is_active', true)
            ->orderBy('name')
            ->get();
        if ($ingredients->isEmpty()) return [];

        $branchRows = collect();
        if (Schema::hasTable('ingredient_stocks')) {
            $q = DB::table('ingredient_stocks')
                ->where('company_id', $companyId)
                ->whereIn('ingredient_id', $ingredients->pluck('id')->all());
            $branchId === null ? $q->whereNull('branch_id') : $q->where('branch_id', $branchId);
            $branchRows = $q->get()->keyBy('ingredient_id');
        }

        $now = now();
        $rows = [];
        foreach ($ingredients as $ing) {
            $row = $branchRows->get($ing->id);
            // Shops that never ran the per-branch kitchen migration still have
            // their quantity on the legacy company-level mirror.
            $expected = $row !== null ? (float) $row->quantity : (float) $ing->current_stock;

            if (!empty($options['only_in_stock']) && $expected <= 0) continue;

            $rows[] = [
                'company_id' => $companyId,
                'stock_check_id' => $checkId,
                'item_type' => StockCheckLine::TYPE_INGREDIENT,
                'item_id' => $ing->id,
                'branch_id' => $branchId,
                'item_name' => (string) $ing->name,
                'item_code' => $ing->code ?: null,
                'unit' => $ing->unit ?: $ing->base_unit,
                'expected_quantity' => round($expected, 4),
                'counted_quantity' => null,
                'variance' => 0,
                'unit_cost' => round((float) $ing->cost_per_unit, 2),
                'variance_value' => 0,
                'created_at' => $now,
                'updated_at' => $now,
            ];
        }
        return $rows;
    }

    /* ------------------------------------------------------------------ */
    /* Entering counts                                                     */
    /* ------------------------------------------------------------------ */

    /**
     * Write physical counts onto an open sheet.
     *
     * @param  array<int|string, array{counted?:mixed, reason?:?string, notes?:?string}>  $input  keyed by line id
     * @return int  how many lines actually changed
     */
    public static function saveCounts(StockCheck $check, array $input, ?int $userId): int
    {
        if (!$check->isOpen()) return 0;

        $lines = StockCheckLine::where('stock_check_id', $check->id)
            ->whereIn('id', array_map('intval', array_keys($input)))
            ->get()
            ->keyBy('id');

        $changed = 0;
        foreach ($input as $lineId => $payload) {
            $line = $lines->get((int) $lineId);
            if (!$line) continue;

            $raw = $payload['counted'] ?? null;
            // An empty box means "not counted yet" — that is different from a
            // counted zero, and the sheet must be able to say both.
            $counted = ($raw === null || $raw === '') ? null : round((float) $raw, 4);
            if ($counted !== null && $counted < 0) $counted = 0.0;

            $reason = isset($payload['reason']) && in_array($payload['reason'], StockCheckLine::REASONS, true)
                ? $payload['reason'] : null;
            $notes = isset($payload['notes']) && $payload['notes'] !== ''
                ? mb_substr((string) $payload['notes'], 0, 255) : null;

            $variance = $counted === null ? 0.0 : round($counted - (float) $line->expected_quantity, 4);
            $varianceValue = round($variance * (float) $line->unit_cost, 2);

            $isSame = ($line->counted_quantity === null && $counted === null)
                || ($line->counted_quantity !== null && $counted !== null
                    && abs((float) $line->counted_quantity - $counted) < 0.00005);
            if ($isSame && $line->reason === $reason && $line->notes === $notes) continue;

            $line->update([
                'counted_quantity' => $counted,
                'variance' => $variance,
                'variance_value' => $varianceValue,
                'reason' => $reason,
                'notes' => $notes,
                'counted_by' => $counted === null ? null : $userId,
                'counted_at' => $counted === null ? null : now(),
            ]);
            $changed++;
        }

        if ($changed > 0) self::recalculate($check);
        return $changed;
    }

    /** Roll the line totals up onto the header. */
    public static function recalculate(StockCheck $check): void
    {
        $agg = DB::table('stock_check_lines')
            ->where('stock_check_id', $check->id)
            ->selectRaw('COUNT(*) as total')
            ->selectRaw('SUM(CASE WHEN counted_quantity IS NULL THEN 0 ELSE 1 END) as counted')
            ->selectRaw('SUM(CASE WHEN counted_quantity IS NOT NULL AND variance <> 0 THEN 1 ELSE 0 END) as varied')
            ->selectRaw('SUM(CASE WHEN variance < 0 THEN -variance_value ELSE 0 END) as short_value')
            ->selectRaw('SUM(CASE WHEN variance > 0 THEN variance_value ELSE 0 END) as excess_value')
            ->first();

        $check->update([
            'total_lines' => (int) ($agg->total ?? 0),
            'counted_lines' => (int) ($agg->counted ?? 0),
            'variance_lines' => (int) ($agg->varied ?? 0),
            'short_value' => round((float) ($agg->short_value ?? 0), 2),
            'excess_value' => round((float) ($agg->excess_value ?? 0), 2),
        ]);
    }

    /* ------------------------------------------------------------------ */
    /* Posting                                                             */
    /* ------------------------------------------------------------------ */

    /**
     * Apply the counted differences to live stock and close the check.
     *
     * @return array{applied:int, skipped:int}
     */
    public static function post(StockCheck $check, int $userId): array
    {
        if (!$check->isOpen()) {
            return ['applied' => 0, 'skipped' => 0];
        }

        $applied = 0;
        $skipped = 0;

        DB::transaction(function () use ($check, $userId, &$applied, &$skipped) {
            // Re-read under the transaction: two managers pressing Post at the
            // same moment must not both apply the same variance.
            $fresh = StockCheck::where('id', $check->id)->lockForUpdate()->first();
            if (!$fresh || !$fresh->isOpen()) return;

            // Only lines that actually MOVE stock are loaded — a 10,000-item
            // sheet where forty things were short must cost forty corrections,
            // not ten thousand no-ops. Chunked so the row set never has to fit
            // in memory all at once.
            StockCheckLine::where('stock_check_id', $fresh->id)
                ->whereNotNull('counted_quantity')
                ->where('variance', '!=', 0)
                ->orderBy('id')
                ->chunkById(200, function ($lines) use ($fresh, $userId, &$applied, &$skipped) {
                    $products = $lines->where('item_type', StockCheckLine::TYPE_PRODUCT);
                    $ingredients = $lines->where('item_type', StockCheckLine::TYPE_INGREDIENT);

                    if ($products->isNotEmpty()) {
                        $r = self::applyProductBatch($fresh, $products, $userId);
                        $applied += $r['applied'];
                        $skipped += $r['skipped'];
                    }
                    foreach ($ingredients as $line) {
                        $variance = round((float) $line->variance, 4);
                        self::applyIngredient($fresh, $line, $variance, $userId)
                            ? $applied++ : $skipped++;
                    }
                });

            $fresh->update([
                'status' => StockCheck::STATUS_COMPLETED,
                'posted_at' => now(),
                'posted_by' => $userId,
            ]);
        });

        self::recalculate($check->refresh());
        return ['applied' => $applied, 'skipped' => $skipped];
    }

    /**
     * Move a batch of sellable items' branch stock by their counted differences.
     *
     * Written as a batch on purpose: the per-line version issued six queries per
     * correction (product lookup, locked stock read, stock write, mirror SUM,
     * mirror write, movement insert). On a large sheet that is tens of thousands
     * of round-trips inside one transaction, holding stock-row locks the whole
     * time — the counter's own sales would block behind it.
     *
     * @param  \Illuminate\Support\Collection<int, StockCheckLine>  $lines
     * @return array{applied:int, skipped:int}
     */
    private static function applyProductBatch(StockCheck $check, $lines, int $userId): array
    {
        $companyId = (int) $check->company_id;
        $productIds = $lines->pluck('item_id')->map('intval')->unique()->values()->all();

        // A product deleted between counting and posting must be skipped, not
        // resurrected as a stock row.
        $liveIds = PosProduct::where('company_id', $companyId)
            ->whereIn('id', $productIds)
            ->pluck('id')
            ->map('intval')
            ->flip();

        $applied = 0;
        $skipped = 0;
        $movements = [];
        $now = now();

        foreach ($lines as $line) {
            $productId = (int) $line->item_id;
            $variance = round((float) $line->variance, 4);

            if (!$liveIds->has($productId) || abs($variance) < 0.00005) { $skipped++; continue; }

            // stockRow() row-locks and creates on demand; it stays per-line
            // because the lock is the whole point of it.
            $stock = BranchStockService::stockRow($companyId, $productId, $line->branch_id);
            if (!$stock) { $skipped++; continue; }

            $current = (float) $stock->quantity;
            $new = round(max(0, $current + $variance), 4);
            $stock->update(['quantity' => $new]);

            $movements[] = [
                'company_id' => $companyId,
                'product_id' => $productId,
                'branch_id' => $line->branch_id,
                'type' => $variance < 0 ? InventoryMovement::TYPE_ADJUSTMENT_OUT : InventoryMovement::TYPE_ADJUSTMENT_IN,
                'quantity' => abs(round($new - $current, 4)),
                'balance_after' => $new,
                'reference_type' => self::REFERENCE_TYPE,
                'reference_id' => $check->id,
                'reference_number' => $check->code,
                'notes' => self::movementNote($check, $line),
                'created_by' => $userId,
                'created_at' => $now,
                'updated_at' => $now,
            ];
            $applied++;
        }

        if (!empty($movements)) {
            foreach (array_chunk($movements, 200) as $chunk) {
                InventoryMovement::insert($chunk);
            }
        }

        // One mirror pass for the batch instead of one per corrected line.
        foreach (array_unique(array_column($movements, 'product_id')) as $pid) {
            BranchStockService::syncProductMirror($companyId, (int) $pid);
        }

        return ['applied' => $applied, 'skipped' => $skipped];
    }

    /** Move a raw material's branch stock by the counted difference. */
    private static function applyIngredient(StockCheck $check, StockCheckLine $line, float $variance, int $userId): bool
    {
        $ingredient = Ingredient::where('company_id', $check->company_id)->find($line->item_id);
        if (!$ingredient) return false;

        $current = (float) $ingredient->current_stock;
        $stockRow = null;

        if (Schema::hasTable('ingredient_stocks')) {
            $query = IngredientStock::where('company_id', $check->company_id)
                ->where('ingredient_id', $line->item_id);
            $line->branch_id === null
                ? $query->whereNull('branch_id')
                : $query->where('branch_id', $line->branch_id);
            $stockRow = $query->lockForUpdate()->first();

            if (!$stockRow) {
                $stockRow = IngredientStock::create([
                    'company_id' => $check->company_id,
                    'ingredient_id' => $line->item_id,
                    'branch_id' => $line->branch_id,
                    'quantity' => 0,
                    'min_stock_level' => 0,
                ]);
            }
            $current = (float) $stockRow->quantity;
        }

        $new = round(max(0, $current + $variance), 4);
        $delta = round($new - $current, 4);

        if ($stockRow) {
            $stockRow->update(['quantity' => $new]);
            // Keep the company-level mirror in step — shortage checks and the
            // ingredients list still read `current_stock`.
            $ingredient->update([
                'current_stock' => round(max(0, (float) $ingredient->current_stock + $delta), 4),
            ]);
        } else {
            $ingredient->update(['current_stock' => $new]);
        }

        if (Schema::hasTable('ingredient_movements')) {
            IngredientMovement::create([
                'company_id' => $check->company_id,
                'ingredient_id' => $line->item_id,
                'branch_id' => $line->branch_id,
                'type' => $variance < 0 ? self::ING_MOVEMENT_OUT : self::ING_MOVEMENT_IN,
                'quantity' => abs($delta),
                'balance_after' => $new,
                'reference_type' => self::REFERENCE_TYPE,
                'reference_id' => $check->id,
                'reference_number' => $check->code,
                'snapshot' => [
                    'expected' => (float) $line->expected_quantity,
                    'counted' => (float) $line->counted_quantity,
                    'reason' => $line->reason,
                ],
                'created_by' => $userId,
            ]);
        }

        return true;
    }

    /**
     * The check is passed in rather than read off the line: live runs with
     * strict lazy loading, so touching $line->stockCheck here would throw.
     */
    private static function movementNote(StockCheck $check, StockCheckLine $line): string
    {
        $note = 'Stock check ' . $check->code
            . ': expected ' . rtrim(rtrim(number_format((float) $line->expected_quantity, 4, '.', ''), '0'), '.')
            . ', counted ' . rtrim(rtrim(number_format((float) $line->counted_quantity, 4, '.', ''), '0'), '.');
        if ($line->reason) $note .= ' — ' . $line->reason;
        if ($line->notes) $note .= ' (' . $line->notes . ')';
        return mb_substr($note, 0, 500);
    }
}

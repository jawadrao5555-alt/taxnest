<?php

namespace App\Services;

use App\Models\InventoryStock;
use App\Models\PosProduct;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * BranchStockService — single source of truth for PER-BRANCH stock (Task 1354).
 *
 * Until now `inventory_stocks` rows were always written with `branch_id = NULL`,
 * i.e. one shared pile of goods for the whole company. A two-shop owner saw
 * Gulberg's sale eat Main Shop's stock and could never answer "kis branch mein
 * kitna maal para hai".
 *
 * The keying rule (the ONLY rule every write path must obey):
 *
 *   - Company with NO branches  → `branch_id = NULL`  (byte-for-byte today's
 *     behaviour; single-shop users must not notice this feature exists).
 *   - Company WITH branches     → `branch_id = <the branch that owns the goods>`.
 *     For a sale that is the branch stamped on the BILL, not the session — an
 *     owner browsing from another branch must never move the wrong shop's stock.
 *
 * Legacy safety: pre-branch rows (branch_id NULL) belonging to a company that
 * now HAS branches are adopted onto its head office — "purana maal ghayab na
 * ho". That happens in three places so a skipped migration can never lose
 * stock: the backfill migration, the moment the first branch is created, and
 * lazily via healLegacyRows() on every inventory read/write path.
 *
 * Mirror rule is unchanged (see pos-inventory-mirror): `pos_products.
 * stock_quantity` shadows `inventory_stocks.quantity` — with branches it holds
 * the COMPANY TOTAL (sum of all branches). NULL still means "untracked" and is
 * never forced to 0.
 */
class BranchStockService
{
    /** companyId => branch rows (id, name, is_head_office, is_active), per request. */
    private static array $branchMemo = [];

    /** companyId => true once healLegacyRows() has run this request. */
    private static array $healed = [];

    /** Memo of the `branches` table check (lean test schemas / drifted deployments). */
    private static ?bool $tableMemo = null;

    /** "companyId:userId" => branches that user may touch, per request. */
    private static array $actorMemo = [];

    /** True when the `branches` table actually exists. Mirrors BranchContextService::branchesReady(). */
    public static function ready(): bool
    {
        if (self::$tableMemo === null) {
            try {
                self::$tableMemo = Schema::hasTable('branches');
            } catch (\Throwable $e) {
                self::$tableMemo = false;
            }
        }
        return self::$tableMemo;
    }

    /** Test helper — schema rebuilds between tests must not see a stale memo. */
    public static function flushMemo(): void
    {
        self::$branchMemo = [];
        self::$healed = [];
        self::$actorMemo = [];
        self::$tableMemo = null;
    }

    /**
     * Every branch of the company, head office first. Raw rows via the query
     * builder on purpose: Branch carries a CompanyScope that is unbound in
     * console/queue context, and this service is also used from migrations.
     */
    public static function branches(int $companyId)
    {
        if (isset(self::$branchMemo[$companyId])) {
            return self::$branchMemo[$companyId];
        }
        if (!self::ready()) {
            return self::$branchMemo[$companyId] = collect();
        }
        try {
            $rows = DB::table('branches')
                ->where('company_id', $companyId)
                ->orderByDesc('is_head_office')
                ->orderBy('id')
                ->get();
        } catch (\Throwable $e) {
            $rows = collect();
        }
        return self::$branchMemo[$companyId] = $rows;
    }

    /** Branches a stock view may be scoped to — inactive ones still hold goods. */
    public static function isMultiBranch(int $companyId): bool
    {
        return self::branches($companyId)->isNotEmpty();
    }

    /**
     * Branches the CURRENT USER is allowed to touch.
     *
     * branches() above is company-wide on purpose (a bill's branch must resolve
     * no matter who is looking). Anything a human can PICK — the adjust-stock
     * branch selector, both ends of a transfer, the transfer stock map — must
     * go through this instead: a manager confined to Gulberg has no business
     * reading, adjusting or moving Main Shop's maal.
     *
     * Empty accessible list = no auth context at all (console, queue, migration),
     * which falls back to the whole company rather than locking those out.
     */
    public static function actorBranches(int $companyId)
    {
        if (!self::isMultiBranch($companyId)) {
            return collect();
        }

        try {
            $ctx = app(BranchContextService::class);
            $userId = 0;
            foreach (['pos', 'fbrpos', 'web'] as $guard) {
                if ($id = \Illuminate\Support\Facades\Auth::guard($guard)->id()) {
                    $userId = (int) $id;
                    break;
                }
            }
            $key = $companyId . ':' . $userId;
            if (isset(self::$actorMemo[$key])) {
                return self::$actorMemo[$key];
            }
            $ids = $ctx->accessibleBranches()->pluck('id')->map(fn ($id) => (int) $id)->all();
        } catch (\Throwable $e) {
            return self::branches($companyId);
        }

        if (empty($ids)) {
            return self::$actorMemo[$key] = self::branches($companyId);
        }

        return self::$actorMemo[$key] = self::branches($companyId)
            ->filter(fn ($b) => in_array((int) $b->id, $ids, true))
            ->values();
    }

    /** True only when $branchId is one the current user may read AND write. */
    public static function actorCanUse(int $companyId, ?int $branchId): bool
    {
        if (!$branchId) {
            return false;
        }

        return self::actorBranches($companyId)->contains(fn ($b) => (int) $b->id === (int) $branchId);
    }

    /** A transfer needs two shops the same person is allowed to move stock between. */
    public static function canTransfer(int $companyId): bool
    {
        return self::actorBranches($companyId)->count() > 1;
    }

    public static function branchBelongs(int $companyId, ?int $branchId): bool
    {
        return $branchId ? self::branches($companyId)->contains('id', $branchId) : false;
    }

    public static function branchName(int $companyId, ?int $branchId): ?string
    {
        if (!$branchId) return null;
        $row = self::branches($companyId)->firstWhere('id', $branchId);
        return $row->name ?? null;
    }

    /** Head office (the "main shop"), else the first branch created. */
    public static function headOfficeBranchId(int $companyId): ?int
    {
        $rows = self::branches($companyId);
        if ($rows->isEmpty()) return null;
        $hq = $rows->first(fn ($b) => (int) ($b->is_head_office ?? 0) === 1);
        return (int) (($hq->id ?? null) ?: $rows->first()->id);
    }

    /**
     * Branch a stock row must be keyed by when WRITING.
     *
     * $preferred wins when it is a real branch of the company — sale/void paths
     * pass the BILL's branch_id so goods always move where the bill was made,
     * even if the owner has since switched the branch selector.
     * NULL is returned only for companies that have no branches at all.
     */
    public static function writeBranchId(int $companyId, ?int $preferred = null): ?int
    {
        if (!self::isMultiBranch($companyId)) {
            return null;
        }
        // Any write path that resolves a branch also heals stranded pre-branch
        // rows first, so no path can leave the old stock behind.
        self::healLegacyRows($companyId);

        if (self::branchBelongs($companyId, $preferred)) {
            return (int) $preferred;
        }
        try {
            $active = app(BranchContextService::class)->stampBranchId();
        } catch (\Throwable $e) {
            $active = null;
        }
        if (self::branchBelongs($companyId, $active)) {
            return (int) $active;
        }
        return self::headOfficeBranchId($companyId);
    }

    /**
     * Branch the current user is LOOKING at. NULL = show everything, which
     * means either "company has no branches" or the OWNER's all-branches view.
     *
     * A non-owner never gets NULL while the company has branches: falling
     * through to "no filter" would hand a confined manager the whole company's
     * stock. If their active branch is not one of theirs (auto-selection can
     * land on head office), they are pinned to their first accessible branch.
     */
    public static function viewBranchId(int $companyId): ?int
    {
        if (!self::isMultiBranch($companyId)) {
            return null;
        }
        try {
            $ctx = app(BranchContextService::class);
            if ($ctx->isAllBranches()) {
                return null;
            }
            $active = $ctx->getActiveBranchId();
            $isOwner = $ctx->isOwner();
        } catch (\Throwable $e) {
            return null;
        }

        if (!$isOwner) {
            if (self::actorCanUse($companyId, $active)) {
                return (int) $active;
            }
            $first = self::actorBranches($companyId)->first();
            return $first ? (int) $first->id : self::headOfficeBranchId($companyId);
        }

        return self::branchBelongs($companyId, $active) ? (int) $active : null;
    }

    /** True only for the owner's company-wide view of a company that HAS branches. */
    public static function viewingAllBranches(int $companyId): bool
    {
        return self::isMultiBranch($companyId) && self::viewBranchId($companyId) === null;
    }

    /**
     * Scope an inventory query to the branch being viewed.
     *
     * Deliberately STRICT (no `orWhereNull` the way BranchContextService does
     * for bills): a branch-less stock row shown inside every branch would be
     * counted twice in the all-branches total. Legacy rows are adopted onto
     * head office instead — see healLegacyRows().
     */
    public static function applyViewFilter($query, int $companyId, string $column = 'branch_id')
    {
        $branchId = self::viewBranchId($companyId);
        if ($branchId) {
            $query->where($column, $branchId);
        }
        return $query;
    }

    /**
     * Lazy self-heal: move any pre-branch (NULL) inventory rows of a
     * branch-enabled company onto its head office. Runs at most once per
     * request per company and can never break the caller.
     */
    public static function healLegacyRows(int $companyId): void
    {
        if (isset(self::$healed[$companyId])) {
            return;
        }
        self::$healed[$companyId] = true;

        if (!self::isMultiBranch($companyId)) {
            return;
        }
        try {
            $hasLegacy = DB::table('inventory_stocks')
                ->where('company_id', $companyId)
                ->whereNull('branch_id')
                ->exists();
            if ($hasLegacy) {
                self::adoptLegacyRows($companyId);
            } else {
                // Stock is clean but history may still be branch-less.
                DB::table('inventory_movements')
                    ->where('company_id', $companyId)
                    ->whereNull('branch_id')
                    ->update(['branch_id' => self::headOfficeBranchId($companyId)]);
            }
        } catch (\Throwable $e) {
            // Never let a housekeeping query fail a sale or a page load.
        }
    }

    /**
     * Assign every branch-less stock + movement row of the company to $target
     * (default: head office). Merges instead of colliding when the target
     * already has a row for that product — the unique index
     * (company_id, product_id, branch_id) does not tolerate a blind update.
     *
     * Returns the number of stock rows moved.
     */
    public static function adoptLegacyRows(int $companyId, ?int $targetBranchId = null): int
    {
        if (!self::ready()) {
            return 0;
        }
        $target = $targetBranchId && self::branchBelongs($companyId, $targetBranchId)
            ? (int) $targetBranchId
            : self::headOfficeBranchId($companyId);
        if (!$target) {
            return 0;
        }

        $moved = 0;
        $legacy = DB::table('inventory_stocks')
            ->where('company_id', $companyId)
            ->whereNull('branch_id')
            ->get();

        foreach ($legacy as $row) {
            $existing = DB::table('inventory_stocks')
                ->where('company_id', $companyId)
                ->where('product_id', $row->product_id)
                ->where('branch_id', $target)
                ->first();

            if ($existing) {
                DB::table('inventory_stocks')->where('id', $existing->id)->update([
                    'quantity' => (float) $existing->quantity + (float) $row->quantity,
                    'min_stock_level' => max((float) $existing->min_stock_level, (float) $row->min_stock_level),
                    'avg_purchase_price' => (float) $existing->avg_purchase_price ?: (float) $row->avg_purchase_price,
                    'last_purchase_price' => (float) $existing->last_purchase_price ?: (float) $row->last_purchase_price,
                    'updated_at' => now(),
                ]);
                DB::table('inventory_stocks')->where('id', $row->id)->delete();
            } else {
                DB::table('inventory_stocks')->where('id', $row->id)->update([
                    'branch_id' => $target,
                    'updated_at' => now(),
                ]);
            }
            $moved++;
        }

        // History follows the goods, otherwise a branch ledger opens empty.
        DB::table('inventory_movements')
            ->where('company_id', $companyId)
            ->whereNull('branch_id')
            ->update(['branch_id' => $target]);

        return $moved;
    }

    /**
     * The stock row a write path must touch, locked for update.
     *
     * Legacy rows are healed onto head office FIRST so the outcome never
     * depends on which branch happens to sell first.
     */
    public static function stockRow(int $companyId, int $productId, ?int $branchId, bool $create = true): ?InventoryStock
    {
        if ($branchId) {
            self::healLegacyRows($companyId);
        }

        $query = InventoryStock::where('company_id', $companyId)->where('product_id', $productId);
        $branchId ? $query->where('branch_id', $branchId) : $query->whereNull('branch_id');

        $row = $query->lockForUpdate()->first();
        if ($row || !$create) {
            return $row;
        }

        return InventoryStock::create([
            'company_id' => $companyId,
            'product_id' => $productId,
            'branch_id' => $branchId,
            'quantity' => 0,
            'min_stock_level' => 0,
            'avg_purchase_price' => 0,
            'last_purchase_price' => 0,
        ]);
    }

    /**
     * Re-point `pos_products.stock_quantity` at the company TOTAL across
     * branches. Only for products that already track stock — a NULL there
     * means "untracked" and must never become 0.
     */
    public static function syncProductMirror(int $companyId, int $productId): void
    {
        try {
            $sum = (float) DB::table('inventory_stocks')
                ->where('company_id', $companyId)
                ->where('product_id', $productId)
                ->sum('quantity');

            PosProduct::where('company_id', $companyId)
                ->where('id', $productId)
                ->whereNotNull('stock_quantity')
                ->update(['stock_quantity' => (int) round($sum)]);
        } catch (\Throwable $e) {
            // Mirror drift is recoverable; never fail the caller for it.
        }
    }

    /**
     * product_id => quantity for one branch (or summed company-wide when
     * $branchId is NULL). Used to overlay the products page with the figures
     * of the branch the user is actually standing in.
     */
    /**
     * Weighted-average cost for goods ARRIVING on a shelf that may already
     * hold some of the same product.
     *
     * Transferring 10 units worth Rs.200 into a shop already holding 10 units
     * worth Rs.100 must leave that shelf at Rs.150 — keeping the destination's
     * old Rs.100 would undervalue the maal and every later sale's cost
     * snapshot (and therefore that branch's munafa) would be wrong.
     *
     * A rate of 0 means "no rate recorded" everywhere in this codebase, not
     * "free", so it never dilutes a known one:
     *   - unknown incoming rate  → the shelf keeps the rate it had
     *   - shelf empty or rateless → it simply takes the incoming rate
     */
    public static function blendCost(float $destQty, float $destCost, float $inQty, float $inCost): float
    {
        if ($inQty <= 0 || $inCost <= 0) {
            return round($destCost, 2);
        }
        if ($destQty <= 0 || $destCost <= 0) {
            return round($inCost, 2);
        }

        return round((($destQty * $destCost) + ($inQty * $inCost)) / ($destQty + $inQty), 2);
    }

    public static function quantities(int $companyId, ?int $branchId, array $productIds): array
    {
        if (empty($productIds)) {
            return [];
        }
        try {
            $query = DB::table('inventory_stocks')
                ->where('company_id', $companyId)
                ->whereIn('product_id', $productIds);
            if ($branchId) {
                $query->where('branch_id', $branchId);
            }
            return $query->groupBy('product_id')
                ->selectRaw('product_id, SUM(quantity) as qty')
                ->pluck('qty', 'product_id')
                ->map(fn ($v) => (float) $v)
                ->all();
        } catch (\Throwable $e) {
            return [];
        }
    }
}

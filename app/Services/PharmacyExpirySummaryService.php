<?php

namespace App\Services;

use App\Models\Company;
use App\Models\ProductBatch;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Schema;

/**
 * Near-expiry and expired-on-shelf totals for a pharmacy — ONE resolver.
 *
 * The near-expiry data always existed, but only as a report nobody opened.
 * The dashboard tile, the daily layout alert and the report itself now all
 * read from this one place, so a batch can never be "expiring" on one surface
 * and fine on another. Branch-scoped exactly like the pharmacy reports (the
 * actor's view branch; head-office / single-branch shops see everything).
 *
 * Cached briefly per company + branch + day: the dashboard and the layout run
 * on every page load, and a medical store's batch table is the biggest table
 * it owns. Every batch write path calls forget() so a fresh receive or a
 * write-off shows up at once.
 */
class PharmacyExpirySummaryService
{
    /** Seconds the summary may be served from cache. */
    public const TTL = 600;

    /** Longest window a shop may configure (a year is already "not near"). */
    public const MAX_WINDOW_DAYS = 365;

    /** Shortest window that still means something at a counter. */
    public const MIN_WINDOW_DAYS = 7;

    /**
     * The shop's near-expiry window in days.
     *
     * The pharmacy feature map may carry near_expiry_days; anything absent or
     * out of range falls back to the module-wide default so a mistyped value
     * can never silence the alert or flag the whole shelf.
     */
    public static function windowDays(?Company $company): int
    {
        $flags = is_array($company?->feature_flags ?? null) ? $company->feature_flags : [];
        $days = (int) ($flags['near_expiry_days'] ?? 0);
        if ($days < self::MIN_WINDOW_DAYS || $days > self::MAX_WINDOW_DAYS) {
            return PharmacyBatchService::NEAR_EXPIRY_DAYS;
        }

        return $days;
    }

    /**
     * Does this shop get the expiry surfaces at all? Pharmacy live AND batch
     * tracking on — without batches there is nothing to expire.
     */
    public static function enabledFor(?Company $company): bool
    {
        return $company !== null
            && PharmacyBatchService::trackingEnabled($company)
            && Schema::hasTable('product_batches');
    }

    /**
     * @return array{
     *   window_days:int, near_count:int, near_qty:float, near_cost:float, near_retail:float,
     *   expired_count:int, expired_qty:float, expired_cost:float, expired_retail:float,
     *   products_near:int, products_expired:int
     * }
     */
    public static function summary(Company $company, ?int $branchId): array
    {
        $key = self::cacheKey($company->id, $branchId);

        return Cache::remember($key, self::TTL, function () use ($company, $branchId) {
            return self::compute($company, $branchId);
        });
    }

    /** Forget the cached summary for a company (every branch view). */
    public static function forget(int $companyId): void
    {
        // Branch views are few; a version bump is cheaper than tracking keys.
        // Deliberately get+put, NOT Cache::increment(): the database/file
        // stores return false on a key that does not exist yet (array store
        // creates it), so an increment-only bump was a silent no-op on live
        // and the tile kept showing a stale window after a settings change.
        $key = self::versionKey($companyId);
        $next = (int) Cache::get($key, 0) + 1;
        Cache::put($key, $next, 86400 * 7);
    }

    public static function compute(Company $company, ?int $branchId): array
    {
        $window = self::windowDays($company);
        $empty = [
            'window_days' => $window,
            'near_count' => 0, 'near_qty' => 0.0, 'near_cost' => 0.0, 'near_retail' => 0.0,
            'expired_count' => 0, 'expired_qty' => 0.0, 'expired_cost' => 0.0, 'expired_retail' => 0.0,
            'products_near' => 0, 'products_expired' => 0,
        ];
        if (!Schema::hasTable('product_batches')) {
            return $empty;
        }

        $today = now()->toDateString();
        $soon = now()->addDays($window)->toDateString();

        $base = ProductBatch::query()
            ->where('company_id', $company->id)
            ->where('quantity', '>', 0)
            ->where('status', '!=', ProductBatch::STATUS_WRITTEN_OFF)
            ->whereNotNull('expiry_date');
        if ($branchId) {
            $base->where('branch_id', $branchId);
        }

        $agg = static function ($query): array {
            $row = $query->selectRaw(
                'COUNT(*) AS batches, COUNT(DISTINCT product_id) AS products, '
                . 'COALESCE(SUM(quantity), 0) AS qty, '
                . 'COALESCE(SUM(quantity * cost_price), 0) AS cost, '
                . 'COALESCE(SUM(quantity * COALESCE(retail_price, 0)), 0) AS retail'
            )->first();

            return [
                (int) ($row->batches ?? 0),
                (int) ($row->products ?? 0),
                round((float) ($row->qty ?? 0), 3),
                round((float) ($row->cost ?? 0), 2),
                round((float) ($row->retail ?? 0), 2),
            ];
        };

        [$nb, $np, $nq, $nc, $nr] = $agg((clone $base)->whereBetween('expiry_date', [$today, $soon]));
        [$eb, $ep, $eq, $ec, $er] = $agg((clone $base)->where('expiry_date', '<', $today));

        return [
            'window_days' => $window,
            'near_count' => $nb, 'near_qty' => $nq, 'near_cost' => $nc, 'near_retail' => $nr,
            'expired_count' => $eb, 'expired_qty' => $eq, 'expired_cost' => $ec, 'expired_retail' => $er,
            'products_near' => $np, 'products_expired' => $ep,
        ];
    }

    private static function cacheKey(int $companyId, ?int $branchId): string
    {
        $version = (int) Cache::get(self::versionKey($companyId), 0);

        return 'ph_expiry_summary:' . $companyId . ':' . ($branchId ?: 'all') . ':' . now()->toDateString() . ':' . $version;
    }

    private static function versionKey(int $companyId): string
    {
        return 'ph_expiry_summary_ver:' . $companyId;
    }
}

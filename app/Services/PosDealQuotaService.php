<?php

namespace App\Services;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use RuntimeException;

/**
 * Shared availability and quota rules for PRA and FBR combo deals.
 *
 * The caller must lock the deal row before reserve() (the billing paths do
 * this). That lock serializes total-limit checks across all usage dates.
 */
class PosDealQuotaService
{
    public static function isSpecial(Model $deal): bool
    {
        return strtolower((string) ($deal->deal_type ?? 'regular')) === 'special';
    }

    public static function usageTable(Model $deal): string
    {
        return $deal->getTable() === 'fbr_pos_deals'
            ? 'fbr_pos_deal_usages'
            : 'pos_deal_usages';
    }

    public static function isAvailable(Model $deal, ?Carbon $at = null): bool
    {
        $at = ($at ?: now())->copy()->setTimezone(config('app.timezone'));
        // Regular deals retain their existing weekday + optional date-range
        // recurrence. A Special deal is a one-off date/time window, so an
        // old active_days value must not unexpectedly hide it on one day in
        // that explicitly selected range.
        if (!self::isSpecial($deal) && !$deal->isActiveOn($at)) {
            return false;
        }

        if (!self::isSpecial($deal)) {
            return true;
        }

        if (!$deal->is_active) {
            return false;
        }
        if ($deal->starts_on && $at->lt($deal->starts_on->startOfDay())) {
            return false;
        }
        if ($deal->ends_on && $at->gt($deal->ends_on->endOfDay())) {
            return false;
        }

        $start = self::timeValue($deal->special_start_time ?? null);
        $end = self::timeValue($deal->special_end_time ?? null);
        if ($start === null || $end === null || $start > $end) {
            return false;
        }

        $time = $at->format('H:i:s');
        if ($time < $start || $time > $end) {
            return false;
        }

        return self::remainingTotal($deal) !== 0
            && self::remainingDaily($deal, $at->toDateString()) !== 0;
    }

    public static function remainingTotal(Model $deal): ?int
    {
        $limit = self::positiveLimit($deal->total_deal_units_limit ?? null);
        if ($limit === null || !self::usageReady($deal)) {
            // If a limited Special exists during a partial migration, fail
            // closed rather than silently selling beyond its quota.
            return $limit === null ? null : 0;
        }

        $used = (int) DB::table(self::usageTable($deal))
            ->where('company_id', (int) $deal->company_id)
            ->where('deal_id', (int) $deal->id)
            ->sum('units_used');
        return max(0, $limit - $used);
    }

    public static function remainingDaily(Model $deal, ?string $date = null): ?int
    {
        $limit = self::positiveLimit($deal->daily_deal_units_limit ?? null);
        if ($limit === null || !self::usageReady($deal)) {
            return $limit === null ? null : 0;
        }

        $used = (int) DB::table(self::usageTable($deal))
            ->where('company_id', (int) $deal->company_id)
            ->where('deal_id', (int) $deal->id)
            ->where('usage_date', $date ?: now()->setTimezone(config('app.timezone'))->toDateString())
            ->value('units_used');
        return max(0, $limit - $used);
    }

    /**
     * Consume units in the surrounding billing transaction.
     *
     * Deal rows are locked by callers. The usage row is also locked before it
     * is changed, and a unique key makes a duplicate first-day row impossible.
     */
    public static function reserve(Model $deal, int $units, ?Carbon $at = null): void
    {
        if ($units < 1 || !self::isSpecial($deal)) {
            return;
        }
        if (!self::usageReady($deal)) {
            if (self::positiveLimit($deal->total_deal_units_limit ?? null) !== null
                || self::positiveLimit($deal->daily_deal_units_limit ?? null) !== null) {
                throw new RuntimeException('This special deal is temporarily unavailable.');
            }
            return;
        }

        $at = ($at ?: now())->copy()->setTimezone(config('app.timezone'));
        $date = $at->toDateString();
        $table = self::usageTable($deal);
        $totalLimit = self::positiveLimit($deal->total_deal_units_limit ?? null);
        $dailyLimit = self::positiveLimit($deal->daily_deal_units_limit ?? null);

        $usage = DB::table($table)
            ->where('company_id', (int) $deal->company_id)
            ->where('deal_id', (int) $deal->id)
            ->where('usage_date', $date)
            ->lockForUpdate()
            ->first();
        $dailyUsed = (int) ($usage->units_used ?? 0);
        $totalUsed = (int) DB::table($table)
            ->where('company_id', (int) $deal->company_id)
            ->where('deal_id', (int) $deal->id)
            ->sum('units_used');

        if ($totalLimit !== null && $totalUsed + $units > $totalLimit) {
            throw new RuntimeException('This special deal has no remaining total quantity.');
        }
        if ($dailyLimit !== null && $dailyUsed + $units > $dailyLimit) {
            throw new RuntimeException('This special deal has no remaining quantity for today.');
        }

        if ($usage) {
            DB::table($table)->where('id', $usage->id)->update([
                'units_used' => $dailyUsed + $units,
                'updated_at' => now(),
            ]);
        } else {
            $attributes = [
                'company_id' => (int) $deal->company_id,
                'deal_id' => (int) $deal->id,
                'usage_date' => $date,
                'units_used' => $units,
            ];
            if ($table === 'pos_deal_usages') {
                \App\Models\PosDealUsage::create($attributes);
            } else {
                DB::table($table)->insert($attributes + ['created_at' => now(), 'updated_at' => now()]);
            }
        }
    }

    public static function metadata(Model $deal, ?Carbon $at = null): array
    {
        $at = ($at ?: now())->copy()->setTimezone(config('app.timezone'));
        $special = self::isSpecial($deal);
        return [
            'deal_type' => $special ? 'special' : 'regular',
            'special_start_time' => $special ? ($deal->special_start_time ?? null) : null,
            'special_end_time' => $special ? ($deal->special_end_time ?? null) : null,
            'total_limit' => $special ? self::positiveLimit($deal->total_deal_units_limit ?? null) : null,
            'daily_limit' => $special ? self::positiveLimit($deal->daily_deal_units_limit ?? null) : null,
            'remaining_total' => $special ? self::remainingTotal($deal) : null,
            'remaining_daily' => $special ? self::remainingDaily($deal, $at->toDateString()) : null,
            'available' => self::isAvailable($deal, $at),
        ];
    }

    private static function positiveLimit($value): ?int
    {
        if ($value === null || $value === '') {
            return null;
        }
        $int = filter_var($value, FILTER_VALIDATE_INT);
        return ($int !== false && $int > 0) ? (int) $int : null;
    }

    private static function timeValue($value): ?string
    {
        if (!$value) {
            return null;
        }
        $value = substr((string) $value, 0, 8);
        return preg_match('/^(?:[01]\d|2[0-3]):[0-5]\d(?::[0-5]\d)?$/', $value)
            ? (strlen($value) === 5 ? $value . ':00' : $value)
            : null;
    }

    private static function usageReady(Model $deal): bool
    {
        return Schema::hasTable(self::usageTable($deal))
            && Schema::hasColumn($deal->getTable(), 'deal_type')
            && Schema::hasColumn($deal->getTable(), 'total_deal_units_limit')
            && Schema::hasColumn($deal->getTable(), 'daily_deal_units_limit');
    }
}
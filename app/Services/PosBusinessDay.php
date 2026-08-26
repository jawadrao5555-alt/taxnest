<?php

namespace App\Services;

use App\Models\PosDayCloseReport;
use Carbon\CarbonInterface;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * PRA POS "business day" (owner rule 26 Jul 2026; custom cutoff 30 Jul 2026).
 *
 * Restaurants that stay open past midnight close their trading day in the
 * early morning. A bill created between 00:00 and the company's day-close
 * cutoff (default 06:00) belongs to the PREVIOUS day's business as long as
 * that day has not been day-closed yet. The separate auto-close time controls
 * when the hourly auto-close sweep is allowed to run.
 *
 * The cutoff is per-company: companies.pos_business_day_cutoff ('HH:MM',
 * default '06:00' — set on the Day Close page). PRA / tax reporting ALWAYS
 * keeps the real timestamp — business_date only drives shop-facing grouping:
 * day-close & Z-report, sales reports, dashboard "today", local-bills portal.
 *
 * Both the WRITE side (PosTransaction creating hook) and every READ side
 * ("what is today's trading day?") must go through this class so they can
 * never diverge.
 */
class PosBusinessDay
{
    public const DEFAULT_CUTOFF = '06:00';
    public const DEFAULT_AUTO_CLOSE_TIME = '06:00';

    /** @var array<int,string> per-request cache: company_id => 'HH:MM' */
    protected static array $cutoffCache = [];

    /**
     * The company's day-close cutoff time ('HH:MM'). Sales strictly before
     * this time count in the previous business day. hasColumn-guarded so a
     * tar-deploy window before `migrate --force` falls back to 06:00.
     */
    public static function cutoffFor(int $companyId): string
    {
        if (isset(self::$cutoffCache[$companyId])) {
            return self::$cutoffCache[$companyId];
        }
        $cutoff = self::DEFAULT_CUTOFF;
        try {
            if (Schema::hasColumn('companies', 'pos_business_day_cutoff')) {
                $raw = DB::table('companies')->where('id', $companyId)->value('pos_business_day_cutoff');
                if (is_string($raw) && preg_match('/^([01]\d):([0-5]\d)$/', $raw) && $raw < '12:00') {
                    $cutoff = $raw;
                }
            }
        } catch (\Throwable $e) {
            // fall back to the default — never break a sale over a setting.
        }

        return self::$cutoffCache[$companyId] = $cutoff;
    }

    /** Drop the cached cutoff after a settings save (same-request reads). */
    public static function forgetCutoff(int $companyId): void
    {
        unset(self::$cutoffCache[$companyId]);
    }

    /**
     * Company's independent auto day-close time ('HH:MM'). The same
     * 00:00–11:30 choices as the business-day cutoff are accepted.
     */
    public static function autoCloseTimeFor(int $companyId): string
    {
        $time = self::DEFAULT_AUTO_CLOSE_TIME;
        try {
            if (Schema::hasColumn('companies', 'pos_auto_dayclose_time')) {
                $raw = DB::table('companies')->where('id', $companyId)->value('pos_auto_dayclose_time');
                if (is_string($raw) && preg_match('/^([01]\d):([0-5]\d)$/', $raw) && $raw < '12:00') {
                    $time = $raw;
                }
            }
        } catch (\Throwable $e) {
            // Fall back during the migration/deploy window.
        }

        return $time;
    }

    public static function forgetAutoCloseTime(int $companyId): void
    {
        // Kept as the write-side counterpart to forgetCutoff(). Auto-close
        // time intentionally reads fresh from the database: the hourly worker
        // must honour a just-saved choice without a cache grace period.
    }

    /**
     * Trading day for a PRA POS company at a given moment (app tz =
     * Asia/Karachi). "Already closed" check reads pos_day_close_reports.
     */
    public static function forMoment(int $companyId, CarbonInterface $at): string
    {
        return self::resolve($companyId, $at, PosDayCloseReport::class);
    }

    /**
     * Trading day for an FBR POS company at a given moment (Task 492 — FBR
     * mirror). Same per-company cutoff rule, but the "already closed" check
     * reads fbr_day_close_reports (the two products close independently).
     */
    public static function forMomentFbr(int $companyId, CarbonInterface $at): string
    {
        return self::resolve($companyId, $at, \App\Models\FbrDayCloseReport::class);
    }

    /**
     * Shared cutoff rule: before the cutoff, yesterday stays the open trading
     * day as long as it has not been day-closed in $reportModel's table.
     */
    protected static function resolve(int $companyId, CarbonInterface $at, string $reportModel): string
    {
        try {
            $local = $at->copy()->setTimezone(config('app.timezone'));
            if ($local->format('H:i') < self::cutoffFor($companyId)) {
                $yesterday = $local->copy()->subDay()->toDateString();
                $closed = $reportModel::where('company_id', $companyId)
                    ->where('report_date', $yesterday)
                    ->exists();
                if (!$closed) {
                    return $yesterday;
                }
            }
            return $local->toDateString();
        } catch (\Throwable $e) {
            // Never let the business-day lookup break a sale or a page —
            // fall back to the calendar date.
            return $at->toDateString();
        }
    }

    /**
     * The company's CURRENT open trading day (PRA POS).
     */
    public static function current(int $companyId): string
    {
        return self::forMoment($companyId, now());
    }

    /**
     * The company's CURRENT open trading day (FBR POS).
     */
    public static function currentFbr(int $companyId): string
    {
        return self::forMomentFbr($companyId, now());
    }
}

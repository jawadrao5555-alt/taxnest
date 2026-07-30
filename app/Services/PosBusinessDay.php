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
 * that day has not been day-closed yet. The cutoff mirrors the auto-dayclose
 * grace rule (before the cutoff yesterday stays open; from the cutoff on it
 * is swept).
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
     * Trading day for a company at a given moment (app tz = Asia/Karachi).
     */
    public static function forMoment(int $companyId, CarbonInterface $at): string
    {
        try {
            $local = $at->copy()->setTimezone(config('app.timezone'));
            if ($local->format('H:i') < self::cutoffFor($companyId)) {
                $yesterday = $local->copy()->subDay()->toDateString();
                $closed = PosDayCloseReport::where('company_id', $companyId)
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
     * The company's CURRENT open trading day.
     */
    public static function current(int $companyId): string
    {
        return self::forMoment($companyId, now());
    }
}

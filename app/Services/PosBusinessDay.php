<?php

namespace App\Services;

use App\Models\PosDayCloseReport;
use Carbon\CarbonInterface;

/**
 * PRA POS "business day" (owner rule 26 Jul 2026).
 *
 * Restaurants that stay open past midnight close their trading day at
 * 00:00–02:00 AM. A bill created between 00:00 and 05:59 belongs to the
 * PREVIOUS day's business as long as that day has not been day-closed yet.
 * The 6 AM cap mirrors the existing auto-dayclose grace rule (between
 * 00:00–05:59 yesterday stays open; from 06:00 it is swept).
 *
 * PRA / tax reporting ALWAYS keeps the real timestamp — business_date only
 * drives shop-facing grouping: day-close & Z-report, sales reports,
 * dashboard "today", local-bills portal.
 *
 * Both the WRITE side (PosTransaction creating hook) and every READ side
 * ("what is today's trading day?") must go through this class so they can
 * never diverge.
 */
class PosBusinessDay
{
    /**
     * Trading day for a company at a given moment (app tz = Asia/Karachi).
     */
    public static function forMoment(int $companyId, CarbonInterface $at): string
    {
        try {
            $local = $at->copy()->setTimezone(config('app.timezone'));
            if ($local->hour < 6) {
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

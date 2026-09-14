<?php

namespace App\Services;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Operational stay numbers "HS001" — never a fiscal invoice serial.
 *
 * Distinct from PosFinalSeries (P…) and PosLocalSeries (L…). Restaurant
 * table/token prefixes are not reused.
 *
 * Dialects: MySQL/MariaDB (production + local) and sqlite :memory: (PHPUnit).
 * Avoid MySQL-only ON DUPLICATE KEY — use the same company-row lock + counter
 * bump pattern as PosFinalSeries so both dialects stay correct under concurrency.
 */
class HotelStaySeries
{
    public const PREFIX = 'HS';

    public const PAD = 3;

    public static function issueNext(int $companyId): string
    {
        if (!Schema::hasTable('hotel_stay_series_counters')) {
            $last = 0;
            if (Schema::hasTable('hotel_stays')) {
                $last = (int) DB::table('hotel_stays')->where('company_id', $companyId)->count();
            }

            return self::format($last + 1);
        }

        // Serialize issuers on the company row (same pattern as PosFinalSeries).
        DB::table('companies')->where('id', $companyId)->lockForUpdate()->value('id');

        $now = now();
        $row = DB::table('hotel_stay_series_counters')
            ->where('company_id', $companyId)
            ->lockForUpdate()
            ->first();

        if (!$row) {
            DB::table('hotel_stay_series_counters')->insert([
                'company_id' => $companyId,
                'last_number' => 1,
                'created_at' => $now,
                'updated_at' => $now,
            ]);

            return self::format(1);
        }

        $next = (int) $row->last_number + 1;
        DB::table('hotel_stay_series_counters')
            ->where('company_id', $companyId)
            ->update([
                'last_number' => $next,
                'updated_at' => $now,
            ]);

        return self::format(max(1, $next));
    }

    public static function format(int $n): string
    {
        return self::PREFIX . str_pad((string) $n, self::PAD, '0', STR_PAD_LEFT);
    }
}

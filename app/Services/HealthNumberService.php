<?php

namespace App\Services;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Monotonic per-company counters for the healthcare panel.
 *
 * Why a counter table and not COUNT(*) + 1:
 *
 *  - A medical record number is how a hospital finds the same human being again
 *    years later. It must NEVER be reused or rewound. Deriving it from a row
 *    count hands a deleted or archived patient's number to the next arrival,
 *    which is how two people end up sharing one file.
 *  - Two receptionists registering at the same second must get two numbers. The
 *    value is therefore read under a row lock, inside the same transaction as
 *    the insert that consumes it, so a rolled-back registration is the only
 *    thing that can leave a gap — and a gap is harmless where a collision is not.
 *
 * `period` is '' for counters that never reset and a date-scoped string for the
 * OPD token, which starts again at 1 for each doctor each day.
 */
class HealthNumberService
{
    public const KEY_MRN = 'mrn';
    public const KEY_VISIT = 'visit';
    public const KEY_PRESCRIPTION = 'prescription';
    public const KEY_TOKEN = 'token';

    /**
     * Consume the next value of a counter and return it.
     *
     * Safe to call inside an outer transaction — it joins that transaction
     * rather than opening a nested one, so the number and the row that uses it
     * commit or roll back together.
     */
    public static function next(int $companyId, string $key, string $period = ''): int
    {
        if (!Schema::hasTable('health_number_sequences')) {
            // Nothing to lock against. Fall back to a wall-clock-derived value
            // rather than 1: on a box mid-deploy, a duplicate is worse than an
            // ugly number, and the unique index would reject the row anyway.
            return (int) (time() % 1000000);
        }

        $run = function () use ($companyId, $key, $period) {
            $row = DB::table('health_number_sequences')
                ->where('company_id', $companyId)
                ->where('key', $key)
                ->where('period', $period)
                ->lockForUpdate()
                ->first();

            if (!$row) {
                try {
                    DB::table('health_number_sequences')->insert([
                        'company_id' => $companyId,
                        'key' => $key,
                        'period' => $period,
                        'next_value' => 1,
                        'created_at' => now(),
                        'updated_at' => now(),
                    ]);
                } catch (\Throwable $e) {
                    // A concurrent request created it first. That is fine — the
                    // unique index is doing its job; re-read below.
                }

                $row = DB::table('health_number_sequences')
                    ->where('company_id', $companyId)
                    ->where('key', $key)
                    ->where('period', $period)
                    ->lockForUpdate()
                    ->first();
            }

            if (!$row) {
                return (int) (time() % 1000000);
            }

            $value = max(1, (int) $row->next_value);

            DB::table('health_number_sequences')
                ->where('id', $row->id)
                ->update(['next_value' => $value + 1, 'updated_at' => now()]);

            return $value;
        };

        return DB::transactionLevel() > 0 ? $run() : DB::transaction($run);
    }

    /** MR000123 — the patient's permanent medical record number. */
    public static function medicalRecordNumber(int $companyId): string
    {
        return 'MR' . str_pad((string) self::next($companyId, self::KEY_MRN), 6, '0', STR_PAD_LEFT);
    }

    /** V000123 — one encounter. */
    public static function visitNumber(int $companyId): string
    {
        return 'V' . str_pad((string) self::next($companyId, self::KEY_VISIT), 6, '0', STR_PAD_LEFT);
    }

    /** RX000123 — one prescription. */
    public static function prescriptionNumber(int $companyId): string
    {
        return 'RX' . str_pad((string) self::next($companyId, self::KEY_PRESCRIPTION), 6, '0', STR_PAD_LEFT);
    }

    /**
     * The next queue token for one doctor on one day.
     *
     * Scoped per doctor rather than per clinic: a patient waiting for Dr A does
     * not care how many people Dr B has seen, and "token 4" has to mean "fourth
     * in this queue" or it means nothing at all.
     */
    public static function tokenNumber(int $companyId, int $doctorId, string $date): int
    {
        return self::next($companyId, self::KEY_TOKEN, $date . ':d' . $doctorId);
    }
}

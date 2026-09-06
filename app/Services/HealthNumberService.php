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
    public const KEY_ADMISSION = 'admission';
    public const KEY_OPERATION = 'operation';
    // Unified billing (Task 1551). Charges, bills, estimates and receipts each
    // get their own never-reused series, for the same reason the MRN does: a
    // reused receipt number turns "show me receipt R000412" into two answers.
    public const KEY_CHARGE = 'charge';
    public const KEY_BILL = 'bill';
    public const KEY_ESTIMATE = 'estimate';
    public const KEY_RECEIPT = 'receipt';
    // Accounting (Task 1552). A journal number is what an auditor asks for by
    // name, so it obeys exactly the same never-reused rule as a receipt.
    public const KEY_JOURNAL = 'journal';
    public const KEY_EXPENSE = 'expense';
    public const KEY_TRANSFER = 'transfer';
    public const KEY_SETTLEMENT = 'settlement';

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

    /** IPD000123 — one inpatient stay, for the lifetime of that stay. */
    public static function admissionNumber(int $companyId): string
    {
        return 'IPD' . str_pad((string) self::next($companyId, self::KEY_ADMISSION), 6, '0', STR_PAD_LEFT);
    }

    /** OT000123 — one scheduled or performed operation. */
    public static function operationNumber(int $companyId): string
    {
        return 'OT' . str_pad((string) self::next($companyId, self::KEY_OPERATION), 6, '0', STR_PAD_LEFT);
    }

    /** C000123 — one line on the unified charge ledger. */
    public static function chargeNumber(int $companyId): string
    {
        return 'C' . str_pad((string) self::next($companyId, self::KEY_CHARGE), 6, '0', STR_PAD_LEFT);
    }

    /** B000123 — one patient bill (invoice / combined statement / final bill). */
    public static function billNumber(int $companyId): string
    {
        return 'B' . str_pad((string) self::next($companyId, self::KEY_BILL), 6, '0', STR_PAD_LEFT);
    }

    /**
     * E000123 — one estimate.
     *
     * A separate series from the invoice on purpose: an estimate is a quote, not
     * money owed, and a patient holding "B000412" must never be able to mistake
     * it for a bill that was actually raised.
     */
    public static function estimateNumber(int $companyId): string
    {
        return 'E' . str_pad((string) self::next($companyId, self::KEY_ESTIMATE), 6, '0', STR_PAD_LEFT);
    }

    /** R000123 — one money receipt (deposit, payment or refund). */
    public static function receiptNumber(int $companyId): string
    {
        return 'R' . str_pad((string) self::next($companyId, self::KEY_RECEIPT), 6, '0', STR_PAD_LEFT);
    }

    /** J000123 — one balanced entry in the books. */
    public static function journalNumber(int $companyId): string
    {
        return 'J' . str_pad((string) self::next($companyId, self::KEY_JOURNAL), 6, '0', STR_PAD_LEFT);
    }

    /** EX000123 — one recorded expense. */
    public static function expenseNumber(int $companyId): string
    {
        return 'EX' . str_pad((string) self::next($companyId, self::KEY_EXPENSE), 6, '0', STR_PAD_LEFT);
    }

    /** TR000123 — one cash/bank movement between our own accounts. */
    public static function transferNumber(int $companyId): string
    {
        return 'TR' . str_pad((string) self::next($companyId, self::KEY_TRANSFER), 6, '0', STR_PAD_LEFT);
    }

    /** DS000123 — one doctor's payout for one period. */
    public static function settlementNumber(int $companyId): string
    {
        return 'DS' . str_pad((string) self::next($companyId, self::KEY_SETTLEMENT), 6, '0', STR_PAD_LEFT);
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

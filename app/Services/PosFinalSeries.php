<?php

namespace App\Services;

use App\Helpers\DbCompat;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * PRA POS FINAL bill series "P-NNN" — the ONE place the fiscal serial rule lives.
 *
 * Owner rule (25 Aug 2026): the old "POS-2026-00035" serial was too long to read
 * out or type when matching an order at the counter. New finals are issued as the
 * short "P036" (same shape as the local "L015" series, 3-digit pad that grows
 * naturally past 999) so a cashier can say and search the number in one breath.
 * The shop asked for the dash to go too — nothing between the letter and the
 * digits — so an already-issued "P-036" stays valid input everywhere it is read
 * or searched, but is never MINTED again.
 *
 * Existing bills are NEVER renumbered: legacy "POS-YYYY-NNNNN" rows keep their
 * serial, stay searchable, and still RESERVE their number — the short series
 * continues from the company's highest number in EITHER format, so a shop sitting
 * on POS-2026-00035 gets P-036 next. Both formats are final serials everywhere
 * (isFinalSerial), which is what the PRA/local stream split keys off.
 *
 * MONOTONIC, like the local series: the durable high-water mark in
 * pos_final_series_counters means a number is never re-issued after its bill is
 * deleted at day close (reporting-OFF finals are deletable) — otherwise two
 * different sales could answer to the same short number in customer history.
 *
 * Two callers must agree byte-for-byte:
 *   - the retail sale path     (PosController::generateInvoiceNumber)
 *   - the restaurant pay path  (RestaurantPosController::generateInvoiceNumber)
 * Both call issueNext() inside their sale transaction, so a failed bill rolls the
 * counter advance back with it.
 */
class PosFinalSeries
{
    /** Short serial prefix for every NEW final bill. */
    public const PREFIX = 'P';

    /** Dashed spelling issued between 25 Aug 2026 and the dash removal. */
    public const LEGACY_DASHED_PREFIX = 'P-';

    /** Zero-pad width; longer numbers simply grow past it (P1000). */
    public const PAD = 3;

    /**
     * Issue and reserve the next monotonic final number. Call INSIDE the sale
     * transaction.
     */
    public static function issueNext(int $companyId): string
    {
        $last = self::lockAndSyncHighWater($companyId);

        $next = $last + 1;
        if (Schema::hasTable('pos_final_series_counters')) {
            DB::table('pos_final_series_counters')
                ->where('company_id', $companyId)
                ->update(['last_number' => $next, 'updated_at' => now()]);
        }

        return self::format($next);
    }

    /** Read-only preview of the next final number (never advances the counter). */
    public static function previewNext(int $companyId): string
    {
        $last = self::highestRecordedNumber($companyId);

        if (Schema::hasTable('pos_final_series_counters')) {
            $last = max(
                $last,
                (int) (DB::table('pos_final_series_counters')
                    ->where('company_id', $companyId)
                    ->value('last_number') ?? 0)
            );
        }

        return self::format($last + 1);
    }

    /**
     * The number an invoice reserves in the final series, or null when it is not
     * a final serial at all. BOTH the short format and the legacy long format
     * count — a legacy bill still owns its number.
     */
    public static function serialOf($invoiceNumber): ?int
    {
        $serial = (string) $invoiceNumber;

        // "P036" (current) and "P-036" (issued before the dash was dropped) are
        // the same slot in the series — both reserve their number.
        if (preg_match('/^' . preg_quote(self::PREFIX, '/') . '-?(\d+)$/', $serial, $m)) {
            return (int) $m[1];
        }

        if (preg_match('/^POS-\d{4}-(\d+)$/', $serial, $m)) {
            return (int) $m[1];
        }

        return null;
    }

    /**
     * Is this invoice number a PRA-stream fiscal serial (short or legacy)?
     * The reporting split uses this: a PRA-bound bill must carry one, a local
     * bill must not.
     */
    public static function isFinalSerial($invoiceNumber): bool
    {
        return self::serialOf($invoiceNumber) !== null;
    }

    /** Render a number in the short series format (P001, P1000). */
    public static function format(int $number): string
    {
        return self::PREFIX . str_pad((string) $number, self::PAD, '0', STR_PAD_LEFT);
    }

    /**
     * Highest final number still present on a bill of this company — short and
     * legacy formats, archived rows included (they still occupy
     * UNIQUE(company_id, invoice_number)).
     *
     * Deliberately aggregated in SQL: a busy shop can hold tens of thousands of
     * finals and this runs on every sale.
     */
    private static function highestRecordedNumber(int $companyId): int
    {
        if (!Schema::hasTable('pos_transactions')) {
            return 0;
        }

        return max(
            // Current dashless "P036". The LIKE also sees "P-036" and "POS-…",
            // so both are excluded here and counted by their own queries below.
            self::maxSerialFor(
                $companyId,
                self::PREFIX . '%',
                strlen(self::PREFIX) + 1,
                [self::LEGACY_DASHED_PREFIX . '%', 'POS-%']
            ),
            // Dashed "P-036" bills issued before the dash was dropped.
            self::maxSerialFor($companyId, self::LEGACY_DASHED_PREFIX . '%', strlen(self::LEGACY_DASHED_PREFIX) + 1),
            // "POS-YYYY-" is 9 characters, so the serial starts at position 10.
            self::maxSerialFor($companyId, 'POS-%', 10)
        );
    }

    /**
     * MAX(serial) for one prefix family. Rows whose tail is not numeric cast to
     * 0 on both MySQL and sqlite, so stray text can never lower the sequence.
     */
    private static function maxSerialFor(int $companyId, string $like, int $offset, array $notLike = []): int
    {
        $query = DB::table('pos_transactions')
            ->where('company_id', $companyId)
            ->where('invoice_number', 'like', $like);

        foreach ($notLike as $pattern) {
            $query->where('invoice_number', 'not like', $pattern);
        }

        return (int) ($query
            ->selectRaw('MAX(' . DbCompat::cast("SUBSTR(invoice_number, {$offset})", 'int') . ') as max_serial')
            ->value('max_serial') ?? 0);
    }

    /**
     * Lock the company sequence and raise its durable high-water mark to every
     * final serial currently present. Returns the locked current maximum.
     */
    private static function lockAndSyncHighWater(int $companyId): int
    {
        // Serialize first allocation as well as later increments — locking only
        // the counter row is insufficient when that row does not exist yet.
        DB::table('companies')->where('id', $companyId)->lockForUpdate()->value('id');

        $floor = self::highestRecordedNumber($companyId);

        // Deployment compatibility: code can briefly run before the migration
        // creates the counter table. Still use max+1 (never gap-fill) meanwhile.
        if (!Schema::hasTable('pos_final_series_counters')) {
            return $floor;
        }

        $counter = DB::table('pos_final_series_counters')
            ->where('company_id', $companyId)
            ->lockForUpdate()
            ->first();

        if (!$counter) {
            DB::table('pos_final_series_counters')->insert([
                'company_id' => $companyId,
                'last_number' => $floor,
                'created_at' => now(),
                'updated_at' => now(),
            ]);

            return $floor;
        }

        $last = max((int) $counter->last_number, $floor);
        if ($last !== (int) $counter->last_number) {
            DB::table('pos_final_series_counters')
                ->where('company_id', $companyId)
                ->update(['last_number' => $last, 'updated_at' => now()]);
        }

        return $last;
    }
}

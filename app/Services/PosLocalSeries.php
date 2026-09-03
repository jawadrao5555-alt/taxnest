<?php

namespace App\Services;

use App\Models\PosTransaction;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * PRA POS local bill series "L-NNN" — the ONE place the numbering rule lives
 * (Task 1373).
 *
 * Vendor-requested short format: L001 (per-company, 3-digit pad, grows
 * naturally past 999). Distinct from the fiscal serials so cashiers can spot
 * non-PRA bills at a glance in lists / receipts / PDFs.
 *
 * The shop asked for the dash to go (25 Aug 2026): new bills are minted without
 * it, while the "L-001" spelling issued earlier stays a valid serial everywhere
 * it is read, searched or counted — it still reserves its number.
 *
 * Owner rule (22 Aug 2026) — MONOTONIC, never smallest-free: once a company has
 * reached L-832, every later local bill is L-833 or higher even if older local
 * rows are archived, promoted to fiscal, or permanently cleared. Existing bills
 * are NEVER renumbered.
 *
 * The durable high-water mark lives in pos_local_series_counters. Existing rows
 * (including archived ones) are also inspected as a safety floor so imported or
 * legacy data can only move the sequence forward, never backward.
 *   - legacy "LOCAL-YYYY-NNNNN" bills are NOT part of this series (the coarse
 *     `like 'L%'` prefilter would otherwise match them and corrupt the
 *     counter), and neither is any other stray "L-…" text: ONLY an exact
 *     /^L-\d+$/ serial reserves a number. preg_match keeps that contract
 *     identical on MySQL and sqlite, where REGEXP support differs.
 *
 * Three callers must agree byte-for-byte or the screen promises one number and
 * the printer prints another:
 *   - the retail sale path        (PosController::generateLocalInvoiceNumber)
 *   - the restaurant pay path     (RestaurantPosController::generateLocalInvoiceNumber)
 *   - the read-only preview shown on the Customize POS → Local Billing card and
 *     its clear-confirmation modal (PosController::previewNextLocalNumber)
 * Sale paths call issueNext() inside their transaction. It locks the company's
 * row before advancing the counter, serialising concurrent cashiers even when
 * the counter has not yet been initialized. Preview never advances the counter.
 */
class PosLocalSeries
{
    /**
     * Is any L-series number still occupied by a bill (archived rows included)?
     * A reset is permitted only when this is false.
     */
    public static function hasIssuedRows(int $companyId): bool
    {
        return self::highestRecordedNumber($companyId) > 0;
    }

    /**
     * Explicitly restart an empty local reference series. This is never used by
     * automatic or manual day close / record clearing.
     */
    public static function resetToStart(int $companyId): bool
    {
        DB::table('companies')->where('id', $companyId)->lockForUpdate()->value('id');

        if (self::highestRecordedNumber($companyId) > 0) {
            return false;
        }

        if (!Schema::hasTable('pos_local_series_counters')) {
            return true;
        }

        DB::table('pos_local_series_counters')
            ->where('company_id', $companyId)
            ->update(['last_number' => 0, 'updated_at' => now()]);

        return true;
    }

    /** Serial prefix. Any other prefix (e.g. legacy "LOCAL-") is not this series. */
    public const PREFIX = 'L';

    /** Dashed spelling issued before the dash was dropped. */
    public const LEGACY_DASHED_PREFIX = 'L-';

    /** Zero-pad width; longer numbers simply grow past it (L1000). */
    public const PAD = 3;

    /**
     * Issue and reserve the next monotonic number. Call INSIDE the sale
     * transaction so a failed bill rolls the counter advance back with it.
     */
    public static function issueNext(int $companyId): string
    {
        $last = self::lockAndSyncHighWater($companyId);

        $next = $last + 1;
        if (Schema::hasTable('pos_local_series_counters')) {
            DB::table('pos_local_series_counters')
                ->where('company_id', $companyId)
                ->update(['last_number' => $next, 'updated_at' => now()]);
        }

        return self::format($next);
    }

    /**
     * Persist every currently visible L-reference before a destructive cleanup.
     * This protects imported/pre-migration rows even if they are deleted before
     * the first new sale has a chance to advance the counter.
     */
    public static function preserveHighWaterMark(int $companyId): void
    {
        self::lockAndSyncHighWater($companyId);
    }

    /**
     * Read-only preview of the next number. $excludeIds remains in the signature
     * for compatibility with the Customize screen, but deletion can no longer
     * lower the sequence so exclusions intentionally do not affect the result.
     *
     * @param  array<int,int>  $excludeIds
     */
    public static function previewNext(int $companyId, array $excludeIds = []): string
    {
        $last = self::highestRecordedNumber($companyId);

        if (Schema::hasTable('pos_local_series_counters')) {
            $last = max(
                $last,
                (int) (DB::table('pos_local_series_counters')
                    ->where('company_id', $companyId)
                    ->value('last_number') ?? 0)
            );
        }

        return self::format($last + 1);
    }

    /**
     * Every row of the company that could hold an L-series number: archived
     * rows included, legacy "LOCAL-YYYY-NNNNN" rows excluded.
     */
    public static function query(int $companyId)
    {
        return self::filter(
            PosTransaction::withoutGlobalScope('hide_archived')->where('company_id', $companyId)
        );
    }

    /**
     * The series prefix filter on an existing builder, for selectors that carry
     * their own extra conditions (e.g. the archived-blockers query). This is a
     * COARSE prefilter — narrow the result with isSeriesSerial() before treating
     * a row as holding a number.
     *
     * @template T of \Illuminate\Database\Eloquent\Builder|\Illuminate\Database\Query\Builder
     *
     * @param  T  $query
     * @return T
     */
    public static function filter($query)
    {
        return $query
            ->where('invoice_number', 'like', self::PREFIX . '%')
            ->where('invoice_number', 'not like', 'LOCAL-%');
    }

    /** The number an invoice reserves, or null when it is not an L-series serial. */
    public static function serialOf($invoiceNumber): ?int
    {
        // "L001" (current) and "L-001" (issued before the dash was dropped) are
        // the same slot; "LOCAL-2026-00001" and any other stray L-text are not.
        return preg_match('/^' . preg_quote(self::PREFIX, '/') . '-?(\d+)$/', (string) $invoiceNumber, $m)
            ? (int) $m[1]
            : null;
    }

    /** Does this invoice number occupy a slot in the series? */
    public static function isSeriesSerial($invoiceNumber): bool
    {
        return self::serialOf($invoiceNumber) !== null;
    }

    /** Render a number in the series format (L001, L1000). */
    public static function format(int $number): string
    {
        return self::PREFIX . str_pad((string) $number, self::PAD, '0', STR_PAD_LEFT);
    }

    /** Highest exact L-NNN number still present, archived rows included. */
    private static function highestRecordedNumber(int $companyId): int
    {
        $highest = 0;
        foreach (self::query($companyId)->pluck('invoice_number') as $serial) {
            $number = self::serialOf($serial);
            if ($number !== null) {
                $highest = max($highest, $number);
            }
        }

        return $highest;
    }

    /**
     * Lock the company sequence and raise its durable high-water mark to every
     * exact L-reference currently present. Returns the locked current maximum.
     */
    private static function lockAndSyncHighWater(int $companyId): int
    {
        // Serialize first allocation as well as later increments. Locking only
        // the counter row is insufficient when that row does not exist yet.
        DB::table('companies')->where('id', $companyId)->lockForUpdate()->value('id');

        $floor = self::highestRecordedNumber($companyId);

        // Deployment compatibility: code can briefly run before the migration
        // creates the counter table. Still use max+1 (never gap-fill) meanwhile.
        if (!Schema::hasTable('pos_local_series_counters')) {
            return $floor;
        }

        $counter = DB::table('pos_local_series_counters')
            ->where('company_id', $companyId)
            ->lockForUpdate()
            ->first();

        if (!$counter) {
            DB::table('pos_local_series_counters')->insert([
                'company_id' => $companyId,
                'last_number' => $floor,
                'created_at' => now(),
                'updated_at' => now(),
            ]);

            return $floor;
        }

        $last = max((int) $counter->last_number, $floor);
        if ($last !== (int) $counter->last_number) {
            DB::table('pos_local_series_counters')
                ->where('company_id', $companyId)
                ->update(['last_number' => $last, 'updated_at' => now()]);
        }

        return $last;
    }
}

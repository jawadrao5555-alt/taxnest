<?php

namespace App\Services;

use App\Models\PosTransaction;

/**
 * PRA POS local bill series "L-NNN" — the ONE place the numbering rule lives
 * (Task 1373).
 *
 * Vendor-requested short format: L-001 (per-company, 3-digit pad, grows
 * naturally past 999). Distinct from the "POS-{year}-NNNNN" fiscal serials so
 * cashiers can spot non-PRA bills at a glance in lists / receipts / PDFs.
 *
 * Owner rule (22 Jul 2026) — SMALLEST FREE NUMBER, not max+1: a new local bill
 * takes the lowest L-number not held by ANY existing row. Two effects the owner
 * asked for: (a) when day-close DELETES local bills, the series restarts from
 * L-001 the next day; (b) when bills are kept, a mid-series deletion frees its
 * number and the next new bill fills that gap, then the series continues
 * upward. Existing bills are NEVER renumbered — only NEW bills take free
 * numbers.
 *
 * Which rows hold a number:
 *   - the company's own rows only, INCLUDING day-close ARCHIVED ones
 *     (withoutGlobalScope('hide_archived')) — they stay in the table and in the
 *     UNIQUE(company_id, invoice_number) index, so their numbers are NOT free;
 *     re-issuing one 500s the next sale on insert.
 *   - legacy "LOCAL-YYYY-NNNNN" bills are NOT part of this series (the coarse
 *     `like 'L-%'` prefilter would otherwise match them and corrupt the
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
 * They differ in ONE thing only, and that difference is deliberate: the sale
 * paths issueNext() (FOR UPDATE row lock inside their own transaction,
 * serialising concurrent cashiers — UNIQUE(company_id, invoice_number) is the
 * final guard), while the preview previewNext() takes no lock because nothing
 * is being issued.
 */
class PosLocalSeries
{
    /** Serial prefix. Any other prefix (e.g. legacy "LOCAL-") is not this series. */
    public const PREFIX = 'L-';

    /** Zero-pad width; longer numbers simply grow past it (L-1000). */
    public const PAD = 3;

    /**
     * Issue the next number for a bill being created — SMALLEST FREE, with the
     * candidate rows locked FOR UPDATE. Call INSIDE the sale transaction.
     */
    public static function issueNext(int $companyId): string
    {
        return self::resolveNext($companyId, true, []);
    }

    /**
     * Read-only preview of the next number — same rule, no lock (nothing is
     * being issued). $excludeIds lets the UI answer "and what will it be AFTER
     * the archived bills are cleared?".
     *
     * @param  array<int,int>  $excludeIds
     */
    public static function previewNext(int $companyId, array $excludeIds = []): string
    {
        return self::resolveNext($companyId, false, $excludeIds);
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
        return preg_match('/^' . preg_quote(self::PREFIX, '/') . '(\d+)$/', (string) $invoiceNumber, $m)
            ? (int) $m[1]
            : null;
    }

    /** Does this invoice number occupy a slot in the series? */
    public static function isSeriesSerial($invoiceNumber): bool
    {
        return self::serialOf($invoiceNumber) !== null;
    }

    /** Render a number in the series format (L-001, L-1000). */
    public static function format(int $number): string
    {
        return self::PREFIX . str_pad((string) $number, self::PAD, '0', STR_PAD_LEFT);
    }

    /**
     * The rule itself — kept private so the lock decision can only be made via
     * issueNext() / previewNext().
     *
     * @param  array<int,int>  $excludeIds
     */
    private static function resolveNext(int $companyId, bool $lock, array $excludeIds): string
    {
        $taken = self::takenQuery($companyId, $lock, $excludeIds)->pluck('invoice_number');

        $used = [];
        foreach ($taken as $serial) {
            $number = self::serialOf($serial);
            if ($number !== null) {
                $used[$number] = true;
            }
        }

        $next = 1;
        while (isset($used[$next])) {
            $next++;
        }

        return self::format($next);
    }

    /**
     * The rows to read the taken numbers from. The ONLY difference between
     * issuing and previewing: issuing locks them FOR UPDATE (inside the sale
     * transaction), previewing does not touch them.
     *
     * @param  array<int,int>  $excludeIds
     */
    private static function takenQuery(int $companyId, bool $lock, array $excludeIds)
    {
        return self::query($companyId)
            ->when(!empty($excludeIds), fn ($q) => $q->whereNotIn('id', $excludeIds))
            ->when($lock, fn ($q) => $q->lockForUpdate());
    }
}

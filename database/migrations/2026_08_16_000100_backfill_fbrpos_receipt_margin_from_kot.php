<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Task 828 (16 Aug 2026): Decouple FBR receipt print position from KOT columns.
 *
 * receipt_align_center / receipt_left_margin_mm were added Aug 14 for PRA receipts.
 * The Aug-14 kot_align_default_center_pizza_master migration already froze
 * receipt_align_center for ALL companies (by copying kot_align_center → receipt_align_center
 * where NULL), so receipt_align_center is non-NULL for all existing companies.
 * receipt_left_margin_mm however was NEVER backfilled by that migration and remains
 * NULL for every company that had a configured kot_left_margin_mm > 0.
 *
 * This backfill handles each column independently:
 *   - receipt_align_center  where NULL → copy from kot_align_center   (new companies only)
 *   - receipt_left_margin_mm where NULL → copy from kot_left_margin_mm (ALL existing companies)
 *
 * After this runs, FBR receipt templates that read receipt_* will produce the
 * same output as before the column split — no existing shop's print position moves.
 *
 * Idempotent: each UPDATE only targets rows where the receipt_* column is still NULL.
 */
return new class extends Migration
{
    public function up(): void
    {
        // Guard: all four columns must exist.
        if (
            !Schema::hasColumn('companies', 'receipt_align_center') ||
            !Schema::hasColumn('companies', 'receipt_left_margin_mm') ||
            !Schema::hasColumn('companies', 'kot_align_center') ||
            !Schema::hasColumn('companies', 'kot_left_margin_mm')
        ) {
            return;
        }

        // Backfill receipt_align_center independently — covers new fbrpos companies
        // created after Aug-14 but before this migration runs.
        DB::statement("
            UPDATE companies
               SET receipt_align_center = COALESCE(kot_align_center, 0)
             WHERE product_type = 'fbrpos'
               AND receipt_align_center IS NULL
        ");

        // Backfill receipt_left_margin_mm independently — this is the critical one:
        // the Aug-14 migration never touched receipt_left_margin_mm, so every
        // existing company that configured kot_left_margin_mm > 0 still has
        // receipt_left_margin_mm = NULL, which renders as 0 (no margin) on receipt.
        DB::statement("
            UPDATE companies
               SET receipt_left_margin_mm = COALESCE(kot_left_margin_mm, 0)
             WHERE product_type = 'fbrpos'
               AND receipt_left_margin_mm IS NULL
        ");
    }

    public function down(): void
    {
        // Irreversible data backfill — nothing to undo.
    }
};

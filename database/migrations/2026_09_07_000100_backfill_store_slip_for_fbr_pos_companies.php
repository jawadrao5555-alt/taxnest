<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Task 1403 — give existing FBR POS shops the Store Slip feature.
 *
 * `companies.kitchen_printer_enabled` is the single gate for the whole
 * store-slip path (Customize's Auto Store Slip + reprint toggles, the sale
 * screen's store-slip controls, the auto-store-slip endpoint). Until this task
 * the ONLY writer of that column was PRA's restaurant Kitchen Settings page —
 * a page FBR shops do not have — so every fbrpos company sat at the 0 default
 * forever. A shop could pick a Store Printer on Printer Settings and still
 * never get a slip, with nothing on screen explaining why.
 *
 * FBR now owns the switch (Customize → Features), and the owner's decision is
 * that it starts ON so nobody has to hunt for it. New fbrpos registrations get
 * it at Company::create; this backfill covers the shops that already exist.
 *
 * This does NOT start printing anything on its own: auto printing still needs
 * `auto_print_kot`, and the package gate (`kot_enabled`) still decides whether
 * the shop sees the store-slip UI at all. Turning the column on only means the
 * switch is available and pre-set.
 *
 * ONE-SHOT, not merely idempotent. A plain "flip every row still at 0" would
 * re-run happily — and on PROD this file can genuinely run twice (schema-drift
 * self-heal replays a migration whose row went missing). By then some owners
 * will have deliberately switched Store Slip OFF, and a replay would silently
 * switch it back on for them. So the pass is recorded in system_settings and
 * skipped for good afterwards; hasColumn/hasTable guards cover schema drift.
 */
return new class extends Migration
{
    /** system_settings key that marks the one-time pass as already done. */
    private const DONE_KEY = 'fbr_store_slip_backfill_done';

    public function up(): void
    {
        if (!Schema::hasTable('companies')
            || !Schema::hasColumn('companies', 'kitchen_printer_enabled')
            || !Schema::hasColumn('companies', 'product_type')) {
            return;
        }

        // No marker table (very early/minimal schema) → run the flip but do not
        // pretend it was recorded; a later run with the table present is still
        // the first one shop owners could have reacted to.
        $canMark = Schema::hasTable('system_settings')
            && Schema::hasColumn('system_settings', 'key')
            && Schema::hasColumn('system_settings', 'value');

        if ($canMark && DB::table('system_settings')->where('key', self::DONE_KEY)->exists()) {
            return;
        }

        DB::table('companies')
            ->where('product_type', 'fbrpos')
            ->where(function ($w) {
                $w->where('kitchen_printer_enabled', false)
                  ->orWhereNull('kitchen_printer_enabled');
            })
            ->update(['kitchen_printer_enabled' => true]);

        if ($canMark) {
            $row = ['value' => '1'];
            foreach (['created_at', 'updated_at'] as $ts) {
                if (Schema::hasColumn('system_settings', $ts)) {
                    $row[$ts] = now();
                }
            }
            // updateOrInsert, not insert: `key` is unique, and two migrators
            // racing on a multi-node deploy would both see no marker — a plain
            // insert would then blow up the second one's whole migration run.
            DB::table('system_settings')->updateOrInsert(['key' => self::DONE_KEY], $row);
        }
    }

    public function down(): void
    {
        // Non-reversible: we cannot tell which fbrpos rows this backfill flipped
        // from the ones a shop owner switched on deliberately afterwards.
    }
};

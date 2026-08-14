<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Pizza Master KOT default (Task 718, owner-approved Aug 2026):
 * every company's kitchen ticket should print in the Pizza Master style
 * (center-aligned + bold) BY DEFAULT — kot_align_center's old default(false)
 * meant a shop that never opened KOT settings got the left-edge plain look.
 *
 * Approach — NULL = "no explicit choice", each read path applies ITS OWN
 * default (this is what keeps receipts unchanged):
 *   1. FREEZE RECEIPTS FIRST: receipt_align_center (nullable, Aug 14 2026)
 *      falls back to kot_align_center on receipt_80mm/58mm + proof-bill.
 *      Backfill it from the CURRENT kot value for every existing company so
 *      no receipt/proof-bill print position ever moves because of this flip.
 *   2. Make kot_align_center NULLABLE (default NULL for new companies).
 *   3. Convert 0 → NULL, EXCEPT deliberate left-layout shops (kot_compact=1
 *      or kot_left_margin_mm>0 — they configured a left-edge layout on
 *      purpose; centering would break their compact/margin setup).
 *
 * Read-time defaults after this migration:
 *   - pos/restaurant/kitchen-ticket: NULL → CENTER (Pizza Master).
 *   - fbr-pos receipt + day-close-thermal (`?? false`): NULL → LEFT — for
 *     fbrpos companies kot_align_center is the RECEIPT position by design
 *     (receipt-kot-margin-split); their output must not move.
 *   - PRA receipt fallback tail (`?? false`): NULL → LEFT (new companies).
 *
 * Deliberate-left shops flipped by step 3 opt back out via the Khula preset
 * card (writes explicit false, which now sticks — Task 712 no-op rule only
 * guards re-saves of the SAME preset).
 *
 * Idempotent (prod drift self-heal): step 1 only fills NULLs; steps 2-3 run
 * ONLY when the column is still NOT NULL (a re-run after the flip must never
 * wipe post-718 deliberate "left" saves back to NULL).
 */
return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasColumn('companies', 'kot_align_center')) {
            return; // drift: base column missing — nothing to flip (self-heal migration will add it with its old default; harmless).
        }

        // 1. Freeze every existing company's receipt print position.
        if (Schema::hasColumn('companies', 'receipt_align_center')) {
            DB::table('companies')->whereNull('receipt_align_center')
                ->where('kot_align_center', true)
                ->update(['receipt_align_center' => true]);
            DB::table('companies')->whereNull('receipt_align_center')
                ->update(['receipt_align_center' => false]);
        }

        // 2 + 3. Nullable flip + one-time 0 → NULL conversion, guarded on the
        // column still being NOT NULL so a re-run is a no-op.
        $col = collect(Schema::getColumns('companies'))->firstWhere('name', 'kot_align_center');
        if ($col && empty($col['nullable'])) {
            Schema::table('companies', function (Blueprint $table) {
                $table->boolean('kot_align_center')->nullable()->default(null)->change();
            });

            $q = DB::table('companies')->where('kot_align_center', false);
            if (Schema::hasColumn('companies', 'kot_compact')) {
                $q->where(function ($w) {
                    $w->where('kot_compact', false)->orWhereNull('kot_compact');
                });
            }
            if (Schema::hasColumn('companies', 'kot_left_margin_mm')) {
                $q->where(function ($w) {
                    $w->where('kot_left_margin_mm', 0)->orWhereNull('kot_left_margin_mm');
                });
            }
            $q->update(['kot_align_center' => null]);
        }
    }

    public function down(): void
    {
        if (!Schema::hasColumn('companies', 'kot_align_center')) {
            return;
        }
        // NULL meant "center by default" — restore the old explicit-false world.
        DB::table('companies')->whereNull('kot_align_center')->update(['kot_align_center' => false]);
        Schema::table('companies', function (Blueprint $table) {
            $table->boolean('kot_align_center')->default(false)->nullable(false)->change();
        });
    }
};

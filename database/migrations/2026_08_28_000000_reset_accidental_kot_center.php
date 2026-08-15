<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Task 761 — Reset accidental KOT centering.
 *
 * Before Task 757 fixed the Kitchen Settings UI, companies with a NULL
 * kot_align_center saw "Center" pre-selected in the dropdown (because the
 * Task 718 UI picked the Pizza Master default). Any owner who opened the
 * page and hit Save without changing anything received explicit true — even
 * though they never consciously opted in to centering. On A4-default Windows
 * print queues this shifts the 72mm body off the thermal head → blank KOTs
 * (the exact regression Task 756 fixed for NEW companies).
 *
 * Safe-rollback criterion — companies that have:
 *   • kot_align_center = true (explicitly written)
 *   • kot_compact false or NULL (not using compact mode — a deliberate choice)
 *   • kot_left_margin_mm 0 or NULL (no margin customisation)
 *
 * These two columns are the only ones that a shop owner would have had to
 * consciously touch to configure a non-default KOT layout. If both are still
 * at their "never touched" state, the kot_align_center = true is almost
 * certainly a ghost from the pre-757 Save.
 *
 * We reset those rows to NULL — which Task 756/757 renders as LEFT EDGE,
 * matching what the UI now pre-selects and what the print CSS has always
 * produced safely on unconfigured printers.
 *
 * Companies that intentionally chose centering (AND also tweaked compact or
 * margin) are left untouched. The kitchen-settings page now shows them a
 * prominent warning to verify their print output (Task 761 blade change).
 *
 * Idempotent: the WHERE clause only matches rows still at true — a re-run
 * after the first pass touches nothing.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasColumn('companies', 'kot_align_center')) {
            return; // column missing — schema drift, nothing to reset.
        }

        $q = DB::table('companies')->where('kot_align_center', true);

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

    public function down(): void
    {
        // Non-reversible: we cannot tell which NULLs were reset by this
        // migration vs which were always NULL.
    }
};

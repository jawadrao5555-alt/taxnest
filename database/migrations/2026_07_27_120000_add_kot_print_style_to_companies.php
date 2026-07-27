<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * KOT Print Style (customer feedback 27 Jul 2026, Pizza Master video):
 * (1) KOT uses too much paper — compact mode + per-element show/hide toggles;
 * (2) print sits to one side on some printers — opt-in center alignment or a
 *     user-set left margin (default stays margin:0, the v6 printable-width fix).
 *
 * Idempotent per prod-schema-drift-selfheal: per-column hasColumn guards so a
 * re-run (or a prod row marked "Ran" without the columns) self-heals.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('companies', function (Blueprint $table) {
            if (!Schema::hasColumn('companies', 'kot_compact')) {
                $table->boolean('kot_compact')->default(false);
            }
            if (!Schema::hasColumn('companies', 'kot_show_customer')) {
                $table->boolean('kot_show_customer')->default(true);
            }
            if (!Schema::hasColumn('companies', 'kot_show_orderby')) {
                $table->boolean('kot_show_orderby')->default(true);
            }
            if (!Schema::hasColumn('companies', 'kot_show_barcode')) {
                $table->boolean('kot_show_barcode')->default(true);
            }
            if (!Schema::hasColumn('companies', 'kot_show_footer')) {
                $table->boolean('kot_show_footer')->default(true);
            }
            if (!Schema::hasColumn('companies', 'kot_align_center')) {
                $table->boolean('kot_align_center')->default(false);
            }
            if (!Schema::hasColumn('companies', 'kot_left_margin_mm')) {
                $table->unsignedTinyInteger('kot_left_margin_mm')->default(0);
            }
        });
    }

    public function down(): void
    {
        Schema::table('companies', function (Blueprint $table) {
            foreach (['kot_compact', 'kot_show_customer', 'kot_show_orderby', 'kot_show_barcode', 'kot_show_footer', 'kot_align_center', 'kot_left_margin_mm'] as $col) {
                if (Schema::hasColumn('companies', $col)) {
                    $table->dropColumn($col);
                }
            }
        });
    }
};

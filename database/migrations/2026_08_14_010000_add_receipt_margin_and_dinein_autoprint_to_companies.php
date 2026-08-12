<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Pizza Master (11 Aug 2026, owner-approved for ALL PRA POS companies):
 *
 * 1. Separate RECEIPT margin from KOT margin. Until now kot_align_center /
 *    kot_left_margin_mm steered receipts (80/58mm), proof bill AND the kitchen
 *    ticket together — fixing one printer cut off the other. New receipt_*
 *    columns are NULLABLE: NULL = fall back to the old shared kot_* values, so
 *    every existing shop keeps its exact current layout until it saves a
 *    separate receipt value. KOT keeps kot_*.
 *
 * 2. print_on_pay_dinein (default ON): when OFF, the dine-in FINAL bill stops
 *    auto-printing at payment (the customer already got the proof bill at the
 *    table). Takeaway/Delivery auto-print and all KOT logic untouched.
 *
 * Idempotent: hasColumn guards per column (PROD drift self-heal convention).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('companies', function (Blueprint $table) {
            if (!Schema::hasColumn('companies', 'receipt_align_center')) {
                $table->boolean('receipt_align_center')->nullable()->default(null);
            }
            if (!Schema::hasColumn('companies', 'receipt_left_margin_mm')) {
                $table->unsignedSmallInteger('receipt_left_margin_mm')->nullable()->default(null);
            }
            if (!Schema::hasColumn('companies', 'print_on_pay_dinein')) {
                $table->boolean('print_on_pay_dinein')->default(true);
            }
        });
    }

    public function down(): void
    {
        Schema::table('companies', function (Blueprint $table) {
            foreach (['receipt_align_center', 'receipt_left_margin_mm', 'print_on_pay_dinein'] as $col) {
                if (Schema::hasColumn('companies', $col)) {
                    $table->dropColumn($col);
                }
            }
        });
    }
};

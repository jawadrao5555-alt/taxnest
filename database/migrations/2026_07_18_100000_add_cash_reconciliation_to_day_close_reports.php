<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Cash reconciliation (Z-report style) for POS day-close reports:
 * cashier enters opening float + physically-counted cash at close;
 * system stores expected cash (float + cash sales) and the variance.
 * All nullable — auto midnight close & legacy reports simply have none.
 * Per-column hasColumn guards: prod schema drift self-heal pattern.
 */
return new class extends Migration
{
    public function up(): void
    {
        foreach (['pos_day_close_reports', 'fbr_day_close_reports'] as $table) {
            if (!Schema::hasTable($table)) {
                continue;
            }
            Schema::table($table, function (Blueprint $t) use ($table) {
                if (!Schema::hasColumn($table, 'opening_float')) {
                    $t->decimal('opening_float', 15, 2)->nullable()->after('other_amount');
                }
                if (!Schema::hasColumn($table, 'counted_cash')) {
                    $t->decimal('counted_cash', 15, 2)->nullable()->after('opening_float');
                }
                if (!Schema::hasColumn($table, 'expected_cash')) {
                    $t->decimal('expected_cash', 15, 2)->nullable()->after('counted_cash');
                }
                if (!Schema::hasColumn($table, 'cash_variance')) {
                    $t->decimal('cash_variance', 15, 2)->nullable()->after('expected_cash');
                }
            });
        }
    }

    public function down(): void
    {
        foreach (['pos_day_close_reports', 'fbr_day_close_reports'] as $table) {
            if (!Schema::hasTable($table)) {
                continue;
            }
            Schema::table($table, function (Blueprint $t) use ($table) {
                foreach (['cash_variance', 'expected_cash', 'counted_cash', 'opening_float'] as $col) {
                    if (Schema::hasColumn($table, $col)) {
                        $t->dropColumn($col);
                    }
                }
            });
        }
    }
};

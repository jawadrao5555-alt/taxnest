<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Strict plan-feature binding (owner, 9 Aug 2026): "jo company jo plan buy kare
 * usko wohi features milein".
 *
 * 1. NEW gate column excel_enabled — Product Excel import/export was promised
 *    Business+ on the plan cards but had NO gate. Business/Pro/Pro Max/Unlimited = 1.
 * 2. Starter reports_enabled = 0 — reports_enabled gates ONLY the CSV/PDF export
 *    endpoints (report PAGES are ungated basic reports); Starter card promises
 *    basic reports only, exports are a Business+ promise ("Advanced reports with
 *    CSV & PDF export"). Starter keeps its report pages either way.
 *
 * Idempotent, name+product_type matched (prod runs migrate --force).
 * scripts/plan-gate-check.php matrix updated in the same commit.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasTable('pricing_plans')) {
            return;
        }

        if (!Schema::hasColumn('pricing_plans', 'excel_enabled')) {
            Schema::table('pricing_plans', function (Blueprint $table) {
                $table->boolean('excel_enabled')->default(false);
            });
        }

        DB::table('pricing_plans')
            ->where('product_type', 'pos')
            ->whereIn('name', ['Business', 'Pro', 'Pro Max', 'Unlimited'])
            ->update(['excel_enabled' => 1, 'updated_at' => now()]);

        DB::table('pricing_plans')
            ->where('product_type', 'pos')->where('name', 'Starter')
            ->update(['reports_enabled' => 0, 'updated_at' => now()]);
    }

    public function down(): void
    {
        // Data/gate ladder change — restore via a fresh migration if ever needed.
    }
};

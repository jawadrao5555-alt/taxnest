<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Task 1197 — Per-cashier complete sales isolation (owner-approved STRICT rule,
 * Sepro's shop: two cashiers were seeing each other's sales). Company switch
 * "Cashier sirf apni sale dekhe" — DEFAULT ON for every company, existing and
 * new (default true + readers treat missing/NULL column as ON, so no backfill
 * is needed and pre-migration PROD schemas behave identically). Owner-only
 * Team-page toggle turns it OFF for shops that explicitly want shared
 * visibility. Single verdict: User::posSalesIsolated().
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('companies', function (Blueprint $table) {
            if (!Schema::hasColumn('companies', 'pos_cashier_own_sales_only')) {
                $table->boolean('pos_cashier_own_sales_only')->default(true);
            }
        });
    }

    public function down(): void
    {
        Schema::table('companies', function (Blueprint $table) {
            if (Schema::hasColumn('companies', 'pos_cashier_own_sales_only')) {
                $table->dropColumn('pos_cashier_own_sales_only');
            }
        });
    }
};

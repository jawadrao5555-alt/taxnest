<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Task 1356 — "Bill final ho to KOT zaroor jaye" safety net.
 *
 * Owner video (dine-in, Table 02): the cashier settled a dine-in cart with CASH
 * without ever pressing "Send to Kitchen". The customer receipt printed, but the
 * kitchen got nothing — the sale screen's auto-print chain deliberately skipped
 * KOT on dine-in finals, so the order was cooked by nobody.
 *
 * companies.kot_on_final_if_unsent = the shop-level off-switch for the new
 * safety net (fire a delta kitchen ticket for lines the kitchen has NEVER
 * seen when a bill is finalised). DEFAULT ON for every company, existing and
 * new — readers treat a missing/NULL column as ON, so a PROD schema that has
 * not migrated yet behaves identically and no backfill is required.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('companies', function (Blueprint $table) {
            if (!Schema::hasColumn('companies', 'kot_on_final_if_unsent')) {
                $table->boolean('kot_on_final_if_unsent')->default(true);
            }
        });
    }

    public function down(): void
    {
        Schema::table('companies', function (Blueprint $table) {
            if (Schema::hasColumn('companies', 'kot_on_final_if_unsent')) {
                $table->dropColumn('kot_on_final_if_unsent');
            }
        });
    }
};

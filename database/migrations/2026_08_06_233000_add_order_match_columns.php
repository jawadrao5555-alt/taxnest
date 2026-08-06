<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Order Matching feature (owner request, 06 Aug 2026 — customer voice notes):
 * receipt aur kitchen KOT par aik jaisa pehchan number, per-company style:
 *   'off'   — kuch nahi chapta (default, existing behaviour)
 *   'token' — roz ka Daily Token # (1,2,3… business-day 6AM par reset)
 *   'code'  — order number ka unique short code (misal 91C4F), random —
 *             bahar wala banda daily order volume trace nahi kar sakta.
 *
 * Idempotent per-column guards (PROD schema drift self-heal convention).
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('companies')) {
            Schema::table('companies', function (Blueprint $table) {
                if (!Schema::hasColumn('companies', 'order_match_style')) {
                    $table->string('order_match_style', 10)->default('off');
                }
                if (!Schema::hasColumn('companies', 'pos_token_counter')) {
                    $table->integer('pos_token_counter')->default(0);
                }
                if (!Schema::hasColumn('companies', 'pos_token_date')) {
                    $table->date('pos_token_date')->nullable();
                }
            });
        }

        if (Schema::hasTable('restaurant_orders')) {
            Schema::table('restaurant_orders', function (Blueprint $table) {
                if (!Schema::hasColumn('restaurant_orders', 'token_no')) {
                    $table->integer('token_no')->nullable();
                }
            });
        }
    }

    public function down(): void
    {
        // Intentionally no column drops — self-heal migrations never destroy data.
    }
};

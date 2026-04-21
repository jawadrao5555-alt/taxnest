<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Phase 5 — KOT (Kitchen Order Ticket) tracking columns.
 *
 * Adds two NULLABLE / DEFAULT-safe columns to restaurant_orders so we can
 * (a) timestamp when the order was last sent to the kitchen, and
 * (b) count how many times the same order has been sent so the printed
 *     ticket can be marked "UPDATED" on re-prints.
 *
 * Strictly additive — no existing column or primary key is touched.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('restaurant_orders', function (Blueprint $table) {
            if (!Schema::hasColumn('restaurant_orders', 'kot_sent_at')) {
                $table->timestamp('kot_sent_at')->nullable()->after('kitchen_notes');
            }
            if (!Schema::hasColumn('restaurant_orders', 'kot_print_count')) {
                $table->unsignedSmallInteger('kot_print_count')->default(0)->after('kot_sent_at');
            }
        });
    }

    public function down(): void
    {
        Schema::table('restaurant_orders', function (Blueprint $table) {
            if (Schema::hasColumn('restaurant_orders', 'kot_print_count')) {
                $table->dropColumn('kot_print_count');
            }
            if (Schema::hasColumn('restaurant_orders', 'kot_sent_at')) {
                $table->dropColumn('kot_sent_at');
            }
        });
    }
};

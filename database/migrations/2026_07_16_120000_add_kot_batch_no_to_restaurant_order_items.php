<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// Item #6 (owner, Jul 2026): each KOT print batch gets its own stable number.
// Stamped in RestaurantPosController::kitchenTicket in the SAME update that
// stamps kot_printed_at — deterministic and reprint-stable (render-time
// kot_print_count+1 would renumber on races/reprints).
return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasColumn('restaurant_order_items', 'kot_batch_no')) {
            Schema::table('restaurant_order_items', function (Blueprint $table) {
                $table->unsignedSmallInteger('kot_batch_no')->nullable()->after('kot_printed_at');
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasColumn('restaurant_order_items', 'kot_batch_no')) {
            Schema::table('restaurant_order_items', function (Blueprint $table) {
                $table->dropColumn('kot_batch_no');
            });
        }
    }
};

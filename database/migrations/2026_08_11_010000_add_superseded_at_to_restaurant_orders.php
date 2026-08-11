<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Task 506 (11 Aug 2026): recall + re-hold ka supersede write purane held order
 * ko status='cancelled' kar deta tha BINA cancelled_at/cancelled_by ke — yeh
 * "ghost" rows Cancelled Orders report aur dashboard tile mein asli cancels
 * ki tarah gin rahi thin (customer complaint: 9 cancels jo usne kabhi kiye nahi).
 *
 * superseded_at = system-supersede marker (status 'cancelled' hi rehta hai
 * taake `status != 'cancelled'` blacklist queries mein leak na ho).
 * Backfill: legacy ghosts = NULL stamps + zero items signature.
 * Idempotent + per-column hasColumn guards (prod schema-drift memory).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('restaurant_orders', function (Blueprint $table) {
            if (!Schema::hasColumn('restaurant_orders', 'superseded_at')) {
                $table->timestamp('superseded_at')->nullable();
            }
        });

        // Backfill legacy ghosts: system-supersede rows have NULL cancel stamps
        // AND zero items (genuine cancels always stamp cancelled_at+cancelled_by
        // and keep their items). Idempotent: whereNull('superseded_at').
        if (Schema::hasColumn('restaurant_orders', 'superseded_at')
            && Schema::hasColumn('restaurant_orders', 'cancelled_at')
            && Schema::hasColumn('restaurant_orders', 'cancelled_by')) {
            DB::table('restaurant_orders')
                ->where('status', 'cancelled')
                ->whereNull('cancelled_at')
                ->whereNull('cancelled_by')
                ->whereNull('superseded_at')
                ->whereNotExists(function ($q) {
                    $q->select(DB::raw(1))
                        ->from('restaurant_order_items')
                        ->whereColumn('restaurant_order_items.order_id', 'restaurant_orders.id');
                })
                ->update(['superseded_at' => DB::raw('updated_at')]);
        }
    }

    public function down(): void
    {
        Schema::table('restaurant_orders', function (Blueprint $table) {
            if (Schema::hasColumn('restaurant_orders', 'superseded_at')) {
                $table->dropColumn('superseded_at');
            }
        });
    }
};

<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Owner, 23 Aug 2026: "bill adhoora reh jaye — customer dair laga raha ho — to
 * usay wahin chhor kar doosre customer ka bill kaise banayein?"
 *
 * PRA retail had NO answer. The sale screen's HOLD (F5) button posted to the
 * restaurant hold endpoint, which happily created a RestaurantOrder for a plain
 * retail shop — but the screen only loads held orders when the KOT feature is
 * on, so a retail cashier's parked cart became unreachable and the bill was
 * simply lost.
 *
 * This table is the retail answer, deliberately shaped like the FBR one
 * (fbr_pos_held_sales): a parked cart is JSON, never a PosTransaction row, so
 * invoice numbering, PRA reporting and day-close totals cannot see it.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('pos_held_sales')) {
            return;
        }

        Schema::create('pos_held_sales', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('company_id')->index();
            $table->unsignedBigInteger('user_id')->nullable()->index();
            // Cashier's own label — "neeli shirt wala", "gaari wala", a name.
            $table->string('hold_name', 60);
            $table->unsignedBigInteger('customer_id')->nullable();
            $table->string('customer_name')->nullable();
            $table->string('customer_phone', 30)->nullable();
            $table->decimal('total_amount', 15, 2)->default(0);
            $table->unsignedInteger('item_count')->default(0);
            $table->json('cart_data');
            // Idempotency for a lost response and for offline holds that sync
            // later — the same parked cart must never land twice.
            $table->string('hold_uuid', 64)->nullable();
            $table->timestamps();

            $table->unique(['company_id', 'hold_uuid'], 'pos_held_sales_uuid_unique');
            $table->index(['company_id', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('pos_held_sales');
    }
};

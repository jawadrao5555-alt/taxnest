<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Task 1271 — FBR POS cart drafts (PRA sale-screen parity, law-neutral).
 *
 * DESIGN: drafts are JSON carts in their OWN table — NEVER FbrPosTransaction
 * rows with status='draft' like PRA. Two hard reasons:
 *  1. FBR invoice numbering must not be consumed by drafts (compliance —
 *     serials are fiscal artifacts; PRA's "draft mints a real number" pattern
 *     is unacceptable on the FBR panel).
 *  2. fbr_pos_transactions rows with fbr_status defaults could be picked up
 *     by the offline-sync / retry schedulers and SUBMITTED to FBR.
 * Same philosophy as fbr_pos_held_sales (Phase2 JSON carts), plus lock
 * columns for the multi-cashier edit lock (PRA lock parity, user-keyed —
 * the FBR sale screen has no terminal picker).
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('fbr_pos_drafts')) {
            return; // idempotent — prod drift safe
        }
        Schema::create('fbr_pos_drafts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            // Draft keeps the selected customer reference (task requirement).
            $table->unsignedBigInteger('customer_id')->nullable();
            $table->string('customer_name')->nullable();
            $table->string('customer_phone', 30)->nullable();
            $table->json('cart_data');
            // List-display snapshot — avoids decoding every JSON cart to render the modal.
            $table->decimal('total_amount', 12, 2)->default(0);
            $table->unsignedInteger('items_count')->default(0);
            // Edit lock (5-min expiry, PRA parity but user-keyed).
            $table->unsignedBigInteger('locked_by_user_id')->nullable();
            $table->timestamp('lock_time')->nullable();
            $table->timestamps();
            $table->index(['company_id', 'updated_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('fbr_pos_drafts');
    }
};

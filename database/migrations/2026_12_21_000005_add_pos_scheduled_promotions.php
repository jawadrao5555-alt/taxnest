<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        if (!Schema::hasTable('pos_promotions')) Schema::create('pos_promotions', function (Blueprint $t) {
            $t->id(); $t->unsignedBigInteger('company_id'); $t->string('title', 120);
            $t->decimal('percent', 5, 2); $t->string('applies_to', 16); $t->json('product_ids')->nullable();
            $t->unsignedSmallInteger('priority')->default(0); $t->string('timezone', 64);
            $t->dateTime('starts_at'); $t->dateTime('ends_at'); $t->boolean('is_active')->default(false); $t->timestamps();
            $t->index(['company_id', 'is_active', 'starts_at'], 'pos_promotion_schedule');
        });
        foreach (['pos_transaction_items', 'restaurant_order_items'] as $table) {
            if (Schema::hasTable($table) && !Schema::hasColumn($table, 'promotion_snapshot')) {
                Schema::table($table, function (Blueprint $t) {
                    $t->json('promotion_snapshot')->nullable(); $t->decimal('promotion_discount', 18, 2)->nullable();
                });
            }
        }
    }
    public function down(): void
    {
        // Existing sale/held-order snapshots and campaign history survive application rollback.
    }
};

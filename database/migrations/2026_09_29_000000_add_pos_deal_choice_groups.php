<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Deal Choice Groups (Task 1531) — lets an owner require the cashier to pick
 * a REAL product per named group (e.g. "Pizza Flavor", "Drink") instead of a
 * deal always billing one hardcoded component. Fixed components
 * (pos_deal_items / fbr_pos_deal_items) are untouched and keep selling
 * unchanged — a choice group is an ADDITIONAL, optional layer on top.
 *
 * A group carries a label + required quantity; its options list which of the
 * company's own products are eligible. The cashier's actual pick is frozen
 * into the SAME snapshot mechanisms the fixed items already use
 * (pos_transaction_items.deal_snapshot / fbr_pos_transaction_items component
 * rows), so this migration adds no new snapshot columns.
 *
 * PRA and FBR twins, same shape as the fixed-item tables (deliberately NO FK
 * — shared-table convention already used by pos_deal_items/fbr_pos_deal_items).
 *
 * Idempotent guards throughout (prod-schema-drift convention: cPanel PROD may
 * mark migrations "Ran" without applying — every op checks before acting).
 */
return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasTable('pos_deal_choice_groups')) {
            Schema::create('pos_deal_choice_groups', function (Blueprint $table) {
                $table->id();
                $table->unsignedBigInteger('deal_id')->index();
                $table->string('label');
                $table->unsignedInteger('quantity')->default(1);
                $table->unsignedInteger('sort_order')->default(0);
                $table->timestamps();
            });
        }

        if (!Schema::hasTable('pos_deal_choice_options')) {
            Schema::create('pos_deal_choice_options', function (Blueprint $table) {
                $table->id();
                $table->unsignedBigInteger('group_id')->index();
                // No FK — pos_deal_items precedent (shared-table rule).
                $table->unsignedBigInteger('pos_product_id');
                $table->timestamps();
            });
        }

        if (!Schema::hasTable('fbr_pos_deal_choice_groups')) {
            Schema::create('fbr_pos_deal_choice_groups', function (Blueprint $table) {
                $table->id();
                $table->unsignedBigInteger('deal_id')->index();
                $table->string('label');
                $table->unsignedInteger('quantity')->default(1);
                $table->unsignedInteger('sort_order')->default(0);
                $table->timestamps();
            });
        }

        if (!Schema::hasTable('fbr_pos_deal_choice_options')) {
            Schema::create('fbr_pos_deal_choice_options', function (Blueprint $table) {
                $table->id();
                $table->unsignedBigInteger('group_id')->index();
                // FBR POS products live in the shared `products` table.
                $table->unsignedBigInteger('product_id');
                $table->timestamps();
            });
        }
    }

    public function down(): void
    {
        foreach ([
            'pos_deal_choice_options', 'pos_deal_choice_groups',
            'fbr_pos_deal_choice_options', 'fbr_pos_deal_choice_groups',
        ] as $table) {
            Schema::dropIfExists($table);
        }
    }
};

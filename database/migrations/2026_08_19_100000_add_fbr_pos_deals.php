<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * FBR POS "Deals" (Task 1273 — FBR twin of pos_deals, Jul 2026): fixed-price
 * combos (burger + fries + drink) sold as ONE tap on the FBR sale screen.
 *
 * FBR compliance difference vs PRA: FBR IMS reporting is ITEM-LEVEL, so a
 * sold deal is stored as REAL fbr_pos_transaction_items component rows (each
 * with its own allocated subtotal/tax at the product's own FBR rate) plus
 * deal-grouping metadata columns — FbrService, stock deduction and returns
 * then need ZERO changes. The receipt groups rows by deal_group to show the
 * deal line the way the customer sees it.
 *
 * Idempotent guards throughout (prod-schema-drift convention: cPanel PROD may
 * mark migrations "Ran" without applying — every op checks before acting).
 */
return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasTable('fbr_pos_deals')) {
            Schema::create('fbr_pos_deals', function (Blueprint $table) {
                $table->id();
                $table->unsignedBigInteger('company_id')->index();
                $table->string('name');
                $table->string('description')->nullable();
                $table->decimal('price', 10, 2)->default(0);
                $table->boolean('is_active')->default(true);
                // ISO weekday ints 1(Mon)..7(Sun); null/empty = every day.
                $table->json('active_days')->nullable();
                $table->date('starts_on')->nullable();
                $table->date('ends_on')->nullable();
                $table->timestamps();
            });
        }

        if (!Schema::hasTable('fbr_pos_deal_items')) {
            Schema::create('fbr_pos_deal_items', function (Blueprint $table) {
                $table->id();
                $table->unsignedBigInteger('deal_id')->index();
                // Deliberately NO FK (pos_deal_items precedent — shared-table rule).
                // FBR POS products live in the shared `products` table.
                $table->unsignedBigInteger('product_id');
                $table->unsignedInteger('quantity')->default(1);
                $table->timestamps();
            });
        }

        if (Schema::hasTable('fbr_pos_transaction_items')) {
            Schema::table('fbr_pos_transaction_items', function (Blueprint $table) {
                // Deal-grouping metadata on component rows. deal_group = one uuid
                // per deal CART LINE (receipt groups rows under a single deal
                // header); deal_* snapshot fields survive later deal edits/deletes.
                if (!Schema::hasColumn('fbr_pos_transaction_items', 'deal_group')) {
                    $table->string('deal_group', 40)->nullable()->index();
                }
                if (!Schema::hasColumn('fbr_pos_transaction_items', 'deal_id')) {
                    $table->unsignedBigInteger('deal_id')->nullable();
                }
                if (!Schema::hasColumn('fbr_pos_transaction_items', 'deal_name')) {
                    $table->string('deal_name')->nullable();
                }
                if (!Schema::hasColumn('fbr_pos_transaction_items', 'deal_quantity')) {
                    // Number of DEALS sold on this cart line (component row
                    // quantity = component qty-per-deal × deal_quantity).
                    $table->unsignedInteger('deal_quantity')->nullable();
                }
                if (!Schema::hasColumn('fbr_pos_transaction_items', 'deal_unit_price')) {
                    // Customer-facing fixed price per deal (tax-inclusive gross).
                    $table->decimal('deal_unit_price', 10, 2)->nullable();
                }
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasTable('fbr_pos_transaction_items')) {
            Schema::table('fbr_pos_transaction_items', function (Blueprint $table) {
                foreach (['deal_group', 'deal_id', 'deal_name', 'deal_quantity', 'deal_unit_price'] as $col) {
                    if (Schema::hasColumn('fbr_pos_transaction_items', $col)) {
                        $table->dropColumn($col);
                    }
                }
            });
        }
        Schema::dropIfExists('fbr_pos_deal_items');
        Schema::dropIfExists('fbr_pos_deals');
    }
};

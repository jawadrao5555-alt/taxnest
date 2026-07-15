<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * PRA POS "Deals" (Jul 2026) — fast-food style combo promos (Sunday Deal etc.):
 * a deal bundles existing pos_products at one promo price and auto-appears on
 * the universal sale screen only on its configured weekdays / date range.
 *
 * Idempotent guards throughout (prod-schema-drift convention: cPanel PROD may
 * mark migrations "Ran" without applying — every op checks before acting).
 */
return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasTable('pos_deals')) {
            Schema::create('pos_deals', function (Blueprint $table) {
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

        if (!Schema::hasTable('pos_deal_items')) {
            Schema::create('pos_deal_items', function (Blueprint $table) {
                $table->id();
                $table->unsignedBigInteger('deal_id')->index();
                // Deliberately NO FK (pos_menu_items precedent — shared-table rule).
                $table->unsignedBigInteger('pos_product_id');
                $table->unsignedInteger('quantity')->default(1);
                $table->timestamps();
            });
        }

        if (Schema::hasTable('pos_transaction_items') && !Schema::hasColumn('pos_transaction_items', 'deal_snapshot')) {
            Schema::table('pos_transaction_items', function (Blueprint $table) {
                // Frozen [{product_id, name, qty}] captured at sale time for deal
                // lines — drives receipt component display + inventory restore,
                // immune to later deal edits/deletes.
                $table->json('deal_snapshot')->nullable()->after('special_notes');
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasTable('pos_transaction_items') && Schema::hasColumn('pos_transaction_items', 'deal_snapshot')) {
            Schema::table('pos_transaction_items', function (Blueprint $table) {
                $table->dropColumn('deal_snapshot');
            });
        }
        Schema::dropIfExists('pos_deal_items');
        Schema::dropIfExists('pos_deals');
    }
};

<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * FBR POS "Peti (Wholesale) Rate" (Task 1414).
 *
 * WHY three columns, all idempotent hasColumn-guarded (PROD schema has drifted
 * before, and this migration ships to live FBR shops mid-flight):
 *
 *  companies.fbr_peti_rate_enabled  — the ONE per-company switch (default OFF).
 *      Deliberately a SINGLE source of truth — the inventory dual-switch trap
 *      (pos-inventory-dual-switch.md) is not repeated: no feature_flags mirror,
 *      gates read this column only.
 *  companies.fbr_peti_margin_pct    — "peti par munafa %" (default 3.00). The
 *      peti rate is derived SERVER-SIDE from avg purchase cost + this margin;
 *      the shop never fills a second per-product rate that goes stale.
 *  products.pack_size               — "peti mein kitne piece" (nullable). Empty
 *      ⇒ the product stays out of the feature entirely.
 *  fbr_pos_transaction_items.is_peti_rate — receipt/report marker: this line
 *      billed at the peti rate. Stock still cuts in pieces; only a small badge
 *      rides the receipt. Without $fillable + this column the write is dropped.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('companies', function (Blueprint $table) {
            if (!Schema::hasColumn('companies', 'fbr_peti_rate_enabled')) {
                $table->boolean('fbr_peti_rate_enabled')->default(false);
            }
            if (!Schema::hasColumn('companies', 'fbr_peti_margin_pct')) {
                $table->decimal('fbr_peti_margin_pct', 6, 2)->default(3.00);
            }
        });

        Schema::table('products', function (Blueprint $table) {
            if (!Schema::hasColumn('products', 'pack_size')) {
                // Unsigned int, nullable — "kitne piece per peti". Nullable is
                // load-bearing: NULL/empty means "not a peti product", never 0.
                $table->unsignedInteger('pack_size')->nullable();
            }
        });

        Schema::table('fbr_pos_transaction_items', function (Blueprint $table) {
            if (!Schema::hasColumn('fbr_pos_transaction_items', 'is_peti_rate')) {
                $table->boolean('is_peti_rate')->default(false);
            }
        });
    }

    public function down(): void
    {
        Schema::table('companies', function (Blueprint $table) {
            if (Schema::hasColumn('companies', 'fbr_peti_rate_enabled')) {
                $table->dropColumn('fbr_peti_rate_enabled');
            }
            if (Schema::hasColumn('companies', 'fbr_peti_margin_pct')) {
                $table->dropColumn('fbr_peti_margin_pct');
            }
        });
        Schema::table('products', function (Blueprint $table) {
            if (Schema::hasColumn('products', 'pack_size')) {
                $table->dropColumn('pack_size');
            }
        });
        Schema::table('fbr_pos_transaction_items', function (Blueprint $table) {
            if (Schema::hasColumn('fbr_pos_transaction_items', 'is_peti_rate')) {
                $table->dropColumn('is_peti_rate');
            }
        });
    }
};

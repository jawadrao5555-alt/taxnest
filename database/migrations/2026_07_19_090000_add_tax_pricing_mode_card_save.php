<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Owner feature (Jul 2026): THIRD tax pricing mode "inclusive_card_save"
 * (Menu Rate Final — Card Bachat) for PRA POS.
 *
 * - companies.pos_tax_pricing_mode: 'exclusive' | 'inclusive' | 'inclusive_card_save'.
 *   NULL = derive from legacy pos_tax_inclusive boolean (back-compat).
 *   pos_tax_inclusive stays SYNCED (1 for both inclusive variants) so every
 *   existing snapshot branch keeps working untouched.
 * - pos_transactions.tax_menu_rate: per-bill SNAPSHOT of the MENU (cash) rate the
 *   base was derived from. NULL = classic inclusive/exclusive bill. Set on ALL
 *   mode-3 bills (cash too, where it equals tax_rate). Card-save math triggers
 *   only when tax_menu_rate is set AND differs from the bill's tax_rate.
 *
 * Idempotent per PROD schema-drift rules (hasTable/hasColumn guards).
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('companies') && !Schema::hasColumn('companies', 'pos_tax_pricing_mode')) {
            Schema::table('companies', function (Blueprint $table) {
                $table->string('pos_tax_pricing_mode', 30)->nullable()->default(null);
            });
        }

        if (Schema::hasTable('pos_transactions') && !Schema::hasColumn('pos_transactions', 'tax_menu_rate')) {
            Schema::table('pos_transactions', function (Blueprint $table) {
                $table->decimal('tax_menu_rate', 5, 2)->nullable()->default(null);
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasTable('companies') && Schema::hasColumn('companies', 'pos_tax_pricing_mode')) {
            Schema::table('companies', function (Blueprint $table) {
                $table->dropColumn('pos_tax_pricing_mode');
            });
        }
        if (Schema::hasTable('pos_transactions') && Schema::hasColumn('pos_transactions', 'tax_menu_rate')) {
            Schema::table('pos_transactions', function (Blueprint $table) {
                $table->dropColumn('tax_menu_rate');
            });
        }
    }
};

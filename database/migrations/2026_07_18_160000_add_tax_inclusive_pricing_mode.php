<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Owner feature (Jul 2026): per-company "Tax-Inclusive Pricing" (Menu-Rate-Final)
 * mode for PRA POS. When ON, the menu price IS the grand total the customer pays;
 * base + tax are back-calculated per payment method at billing time.
 *
 * - companies.pos_tax_inclusive: the company-level setting (admin toggles on
 *   Customize POS). Applies to NEW bills only.
 * - pos_transactions.tax_inclusive: per-bill SNAPSHOT of the mode the bill was
 *   created under. Edit / promote / PRA payload / receipts read the SNAPSHOT so
 *   a bill keeps its semantics even if the company toggles later.
 *
 * Idempotent per PROD schema-drift rules (hasTable/hasColumn guards).
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('companies') && !Schema::hasColumn('companies', 'pos_tax_inclusive')) {
            Schema::table('companies', function (Blueprint $table) {
                $table->boolean('pos_tax_inclusive')->default(false);
            });
        }

        if (Schema::hasTable('pos_transactions') && !Schema::hasColumn('pos_transactions', 'tax_inclusive')) {
            Schema::table('pos_transactions', function (Blueprint $table) {
                $table->boolean('tax_inclusive')->default(false);
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasTable('companies') && Schema::hasColumn('companies', 'pos_tax_inclusive')) {
            Schema::table('companies', function (Blueprint $table) {
                $table->dropColumn('pos_tax_inclusive');
            });
        }
        if (Schema::hasTable('pos_transactions') && Schema::hasColumn('pos_transactions', 'tax_inclusive')) {
            Schema::table('pos_transactions', function (Blueprint $table) {
                $table->dropColumn('tax_inclusive');
            });
        }
    }
};

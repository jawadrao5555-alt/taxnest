<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Per-company POS tax-rate overrides (PRA rates are company-editable now).
     * NULL = use the global default from pos_tax_rules.
     * Also updates the global card/digital-channel rate 5% -> 8% (PRA change, July 2026).
     * Idempotent: safe to re-run on PROD (per-column hasColumn guards).
     */
    public function up(): void
    {
        if (!Schema::hasColumn('companies', 'pos_tax_rate_cash')) {
            Schema::table('companies', function (Blueprint $table) {
                $table->decimal('pos_tax_rate_cash', 5, 2)->nullable();
            });
        }
        if (!Schema::hasColumn('companies', 'pos_tax_rate_card')) {
            Schema::table('companies', function (Blueprint $table) {
                $table->decimal('pos_tax_rate_card', 5, 2)->nullable();
            });
        }

        // PRA changed the reduced rate for card / digital-channel payments from 5% to 8%.
        // Only touch rows still at the old default so a manually-edited value is never clobbered.
        DB::table('pos_tax_rules')
            ->whereIn('payment_method', ['debit_card', 'credit_card', 'qr_payment'])
            ->where('tax_rate', 5.00)
            ->update(['tax_rate' => 8.00, 'updated_at' => now()]);
    }

    public function down(): void
    {
        if (Schema::hasColumn('companies', 'pos_tax_rate_cash')) {
            Schema::table('companies', function (Blueprint $table) {
                $table->dropColumn('pos_tax_rate_cash');
            });
        }
        if (Schema::hasColumn('companies', 'pos_tax_rate_card')) {
            Schema::table('companies', function (Blueprint $table) {
                $table->dropColumn('pos_tax_rate_card');
            });
        }
    }
};

<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Opening balance on a supplier (Task 1555 — pilot data onboarding).
 *
 * A hospital that moves onto the panel mid-year already owes its distributors
 * money. Until now the only way a supplier balance could exist was a purchase
 * order raised INSIDE the panel, so day-one payables were invisible: the
 * pharmacy's payables screen showed zero against a distributor the hospital
 * owed 400,000, and the first payment recorded against them went negative.
 *
 * The figure lives on the supplier rather than as a synthetic purchase order on
 * purpose. A fake PO would have to carry fake batches to be counted, and those
 * batches would then be dispensable stock that never physically arrived.
 *
 * `suppliers` is shared with the POS/DI inventory module. A nullable column is
 * inert there — only the healthcare payables report reads it.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasTable('suppliers')) {
            return;
        }

        Schema::table('suppliers', function (Blueprint $table) {
            if (!Schema::hasColumn('suppliers', 'opening_balance')) {
                $table->decimal('opening_balance', 14, 2)->default(0)->after('is_active');
            }
            if (!Schema::hasColumn('suppliers', 'opening_balance_date')) {
                $table->date('opening_balance_date')->nullable()->after('opening_balance');
            }
        });
    }

    public function down(): void
    {
        if (!Schema::hasTable('suppliers')) {
            return;
        }

        Schema::table('suppliers', function (Blueprint $table) {
            foreach (['opening_balance_date', 'opening_balance'] as $column) {
                if (Schema::hasColumn('suppliers', $column)) {
                    $table->dropColumn($column);
                }
            }
        });
    }
};

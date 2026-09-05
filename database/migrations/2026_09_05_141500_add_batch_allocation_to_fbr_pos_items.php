<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Task 1558 — the exact batch split behind a sale line.
 *
 * A line of 3 boxes can legitimately come off two batches when the
 * shortest-dated one only holds 2. batch_id/batch_number on the item row name
 * the PRIMARY batch (what the cashier saw and what prints), but a refund has to
 * put every unit back where it came from — "never on a blind aggregate" — so
 * the full allocation is stored beside it as
 *   [{"batch_id":12,"batch_number":"A21","expiry":"2027-04-30","quantity":2}, ...]
 *
 * Null on every non-pharmacy line, and on a pharmacy line that came entirely
 * off untracked legacy stock.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('fbr_pos_transaction_items')
            && !Schema::hasColumn('fbr_pos_transaction_items', 'batch_allocation')) {
            Schema::table('fbr_pos_transaction_items', function (Blueprint $table) {
                $table->json('batch_allocation')->nullable();
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasTable('fbr_pos_transaction_items')
            && Schema::hasColumn('fbr_pos_transaction_items', 'batch_allocation')) {
            Schema::table('fbr_pos_transaction_items', function (Blueprint $table) {
                $table->dropColumn('batch_allocation');
            });
        }
    }
};

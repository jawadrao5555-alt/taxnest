<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Task 1403 — persist the per-item Store note on FBR POS bills.
 *
 * The FBR sale screen already lets the cashier type a short note per line
 * (`special_notes` in the cart) and `fbr-pos/kitchen-ticket.blade.php` renders
 * it, but the bill lines had nowhere to store it: the note lived only in the
 * cart and `kotReprint()` hardcoded null. Result — the store slip printed at
 * pay time carried the note, and the SAME slip reprinted a minute later came
 * out blank.
 *
 * Idempotent: guarded on hasColumn so a re-run (or a PROD row already marked
 * "Ran" against a drifted schema) is a no-op.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasTable('fbr_pos_transaction_items')) {
            return;
        }
        if (Schema::hasColumn('fbr_pos_transaction_items', 'special_notes')) {
            return;
        }

        Schema::table('fbr_pos_transaction_items', function (Blueprint $table) {
            $table->string('special_notes', 190)->nullable()->after('item_name');
        });
    }

    public function down(): void
    {
        if (Schema::hasTable('fbr_pos_transaction_items') && Schema::hasColumn('fbr_pos_transaction_items', 'special_notes')) {
            Schema::table('fbr_pos_transaction_items', function (Blueprint $table) {
                $table->dropColumn('special_notes');
            });
        }
    }
};

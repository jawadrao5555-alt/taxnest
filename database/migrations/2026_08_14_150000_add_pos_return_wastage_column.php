<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Rider auto-return wastage flag (Task 586).
 *
 * When a delivery comes back and the food is spoiled, the auto-created return
 * bill carries is_wastage=1 — stock is NOT restored and future wastage
 * reporting can read the flag. Default (0) = goods restocked.
 *
 * Idempotent per-column hasColumn guard (prod schema-drift self-heal rule).
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('pos_transactions')) {
            Schema::table('pos_transactions', function (Blueprint $table) {
                if (!Schema::hasColumn('pos_transactions', 'is_wastage')) {
                    $table->boolean('is_wastage')->default(false)->after('parent_transaction_id');
                }
            });
        }
    }

    public function down(): void
    {
        // Additive, guarded column — intentionally not dropped (data-bearing).
    }
};

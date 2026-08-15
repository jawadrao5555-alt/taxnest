<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Task 773 one-time backfill: settled delivery bills stuck at
 * assigned/dispatched become "delivered" so they leave the Pending tab.
 * delivered_at is deliberately left NULL (settle time ≠ actual delivery
 * time — the "Delivered in X min" chip only renders with delivered_at).
 * Idempotent + Schema::hasColumn guarded (PROD schema drift).
 */
return new class extends Migration
{
    public function up(): void
    {
        foreach (['pos_transactions', 'fbr_pos_transactions'] as $table) {
            if (!Schema::hasTable($table)
                || !Schema::hasColumn($table, 'rider_settlement_id')
                || !Schema::hasColumn($table, 'delivery_status')) {
                continue;
            }
            DB::table($table)
                ->whereNotNull('rider_settlement_id')
                ->whereIn('delivery_status', ['assigned', 'dispatched'])
                ->update(['delivery_status' => 'delivered']);
        }
    }

    public function down(): void
    {
        // Data backfill — irreversible by design (original statuses not kept).
    }
};

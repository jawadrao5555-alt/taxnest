<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * In-transit branch transfers (Task 1434... — actually Task 1434 is the race;
 * this is the "maal raste mein" feature). We stop treating a branch transfer as
 * instantaneous: the goods leave the source at once but only land in the
 * destination's sellable stock when the receiving branch clicks "wasool ho
 * gaya". Between the two, the transfer is IN TRANSIT.
 *
 * Rather than a whole new table, the existing TRANSFER_OUT movement row IS the
 * transfer record (fits the paired-ledger design already in place). Two columns
 * carry the extra state on that one row:
 *   - transfer_status: 'in_transit' | 'received' | 'cancelled' (NULL for every
 *     other movement type — sales, adjustments, the paired TRANSFER_IN, etc.)
 *   - received_quantity: how much actually arrived when the branch received it,
 *     which may be LESS than what was sent (kam ya kharab maal raste mein).
 */
return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasTable('inventory_movements')) {
            return;
        }
        Schema::table('inventory_movements', function (Blueprint $table) {
            if (!Schema::hasColumn('inventory_movements', 'transfer_status')) {
                // Only ever set on the TRANSFER_OUT row of a branch_transfer.
                $table->string('transfer_status', 20)->nullable()->after('reference_number');
            }
            if (!Schema::hasColumn('inventory_movements', 'received_quantity')) {
                // Actual quantity confirmed by the receiving branch.
                $table->decimal('received_quantity', 15, 2)->nullable()->after('transfer_status');
            }
        });

        // Cheap lookup for "raste mein kaunse transfers pare hain".
        try {
            Schema::table('inventory_movements', function (Blueprint $table) {
                $table->index(['company_id', 'transfer_status'], 'inv_mov_transfer_status_idx');
            });
        } catch (\Throwable $e) {
            // Index already present on a re-run — harmless.
        }
    }

    public function down(): void
    {
        if (!Schema::hasTable('inventory_movements')) {
            return;
        }
        Schema::table('inventory_movements', function (Blueprint $table) {
            try {
                $table->dropIndex('inv_mov_transfer_status_idx');
            } catch (\Throwable $e) {
                // Index may not exist — ignore.
            }
            if (Schema::hasColumn('inventory_movements', 'received_quantity')) {
                $table->dropColumn('received_quantity');
            }
            if (Schema::hasColumn('inventory_movements', 'transfer_status')) {
                $table->dropColumn('transfer_status');
            }
        });
    }
};

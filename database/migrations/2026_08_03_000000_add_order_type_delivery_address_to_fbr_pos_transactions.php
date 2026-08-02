<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// Task 156: FBR POS delivery bills remember their order type. Adds the
// order_type + delivery_address snapshot columns the Pending Deliveries panel
// (Task 122) already knows how to read (apiProvisionalBills hasColumn guards
// pick them up automatically). Idempotent + prod-safe: per-column hasColumn
// guards so a partially-applied cPanel PROD schema self-heals on re-run.
return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasTable('fbr_pos_transactions')) {
            return;
        }
        if (!Schema::hasColumn('fbr_pos_transactions', 'order_type')) {
            Schema::table('fbr_pos_transactions', function (Blueprint $table) {
                // dine_in / takeaway / delivery snapshot (nullable — older bills
                // and non-restaurant sales carry no type).
                $table->string('order_type', 20)->nullable()->after('fbr_status');
            });
        }
        if (!Schema::hasColumn('fbr_pos_transactions', 'delivery_address')) {
            Schema::table('fbr_pos_transactions', function (Blueprint $table) {
                // Address SNAPSHOT frozen on the bill — later edits to the
                // customer's saved address never rewrite receipts (PRA parity).
                $table->string('delivery_address', 500)->nullable()->after('order_type');
            });
        }
    }

    public function down(): void
    {
        if (!Schema::hasTable('fbr_pos_transactions')) {
            return;
        }
        foreach (['delivery_address', 'order_type'] as $col) {
            if (Schema::hasColumn('fbr_pos_transactions', $col)) {
                Schema::table('fbr_pos_transactions', function (Blueprint $table) use ($col) {
                    $table->dropColumn($col);
                });
            }
        }
    }
};

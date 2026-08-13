<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * PRA POS Return / Credit-Note flow (Task 570).
 *
 * Mirrors the FBR Phase-2 return schema on the PRA tables:
 *  - pos_transactions.transaction_type  'sale' | 'return'
 *  - pos_transactions.parent_transaction_id  link to the returned bill
 *  - pos_transaction_items.parent_item_id + returned_quantity
 *  - pos_day_close_reports.returns_count / returns_amount (Z-report detail;
 *    written via forceFill try/catch like rider_summary — never blocks a close)
 *
 * Idempotent per-column hasColumn guards (prod schema-drift self-heal rule).
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('pos_transactions')) {
            Schema::table('pos_transactions', function (Blueprint $table) {
                if (!Schema::hasColumn('pos_transactions', 'transaction_type')) {
                    $table->string('transaction_type', 10)->default('sale')->index()->after('status');
                }
                if (!Schema::hasColumn('pos_transactions', 'parent_transaction_id')) {
                    $table->unsignedBigInteger('parent_transaction_id')->nullable()->index()->after('transaction_type');
                }
            });
        }

        if (Schema::hasTable('pos_transaction_items')) {
            Schema::table('pos_transaction_items', function (Blueprint $table) {
                if (!Schema::hasColumn('pos_transaction_items', 'parent_item_id')) {
                    $table->unsignedBigInteger('parent_item_id')->nullable()->after('item_id');
                }
                if (!Schema::hasColumn('pos_transaction_items', 'returned_quantity')) {
                    $table->decimal('returned_quantity', 10, 3)->default(0)->after('quantity');
                }
            });
        }

        if (Schema::hasTable('pos_day_close_reports')) {
            Schema::table('pos_day_close_reports', function (Blueprint $table) {
                if (!Schema::hasColumn('pos_day_close_reports', 'returns_count')) {
                    $table->unsignedInteger('returns_count')->nullable()->after('offline_invoices');
                }
                if (!Schema::hasColumn('pos_day_close_reports', 'returns_amount')) {
                    $table->decimal('returns_amount', 12, 2)->nullable()->after('returns_count');
                }
            });
        }
    }

    public function down(): void
    {
        // Additive, guarded columns — intentionally not dropped (data-bearing).
    }
};

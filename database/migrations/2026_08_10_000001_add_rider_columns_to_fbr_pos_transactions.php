<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Add rider/delivery-lifecycle columns to fbr_pos_transactions.
 * Idempotent hasColumn guards — safe to run on cPanel PROD with schema drift.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('fbr_pos_transactions', function (Blueprint $table) {
            if (!Schema::hasColumn('fbr_pos_transactions', 'rider_id')) {
                $table->unsignedBigInteger('rider_id')->nullable()->after('delivery_address');
            }
            if (!Schema::hasColumn('fbr_pos_transactions', 'delivery_status')) {
                // assigned → dispatched → delivered | returned
                $table->string('delivery_status', 20)->nullable()->after('rider_id');
            }
            if (!Schema::hasColumn('fbr_pos_transactions', 'rider_assigned_at')) {
                $table->timestamp('rider_assigned_at')->nullable()->after('delivery_status');
            }
            if (!Schema::hasColumn('fbr_pos_transactions', 'delivered_at')) {
                $table->timestamp('delivered_at')->nullable()->after('rider_assigned_at');
            }
            if (!Schema::hasColumn('fbr_pos_transactions', 'rider_settlement_id')) {
                $table->unsignedBigInteger('rider_settlement_id')->nullable()->after('delivered_at');
            }
            if (!Schema::hasColumn('fbr_pos_transactions', 'rider_settled_at')) {
                $table->timestamp('rider_settled_at')->nullable()->after('rider_settlement_id');
            }
            if (!Schema::hasColumn('fbr_pos_transactions', 'prepaid_converted_at')) {
                $table->timestamp('prepaid_converted_at')->nullable()->after('rider_settled_at');
            }
            if (!Schema::hasColumn('fbr_pos_transactions', 'prepaid_converted_by')) {
                $table->unsignedBigInteger('prepaid_converted_by')->nullable()->after('prepaid_converted_at');
            }
        });
    }

    public function down(): void
    {
        Schema::table('fbr_pos_transactions', function (Blueprint $table) {
            $cols = [
                'rider_id', 'delivery_status', 'rider_assigned_at',
                'delivered_at', 'rider_settlement_id', 'rider_settled_at',
                'prepaid_converted_at', 'prepaid_converted_by',
            ];
            foreach ($cols as $col) {
                if (Schema::hasColumn('fbr_pos_transactions', $col)) {
                    $table->dropColumn($col);
                }
            }
        });
    }
};

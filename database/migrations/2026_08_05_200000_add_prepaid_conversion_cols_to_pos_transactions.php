<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Task 285 — prepaid conversion audit columns (Aug 2026).
 *
 * When a delivery bill is marked Prepaid (cash → qr_payment) on the
 * Deliveries board, these two columns record who did it and when.
 * Idempotent: guarded with hasColumn so re-running on PROD is safe.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasColumn('pos_transactions', 'prepaid_converted_at')) {
            Schema::table('pos_transactions', function (Blueprint $table) {
                $table->timestamp('prepaid_converted_at')->nullable()->after('delivered_at');
            });
        }

        if (!Schema::hasColumn('pos_transactions', 'prepaid_converted_by')) {
            Schema::table('pos_transactions', function (Blueprint $table) {
                $table->unsignedBigInteger('prepaid_converted_by')->nullable()->after('prepaid_converted_at');
            });
        }
    }

    public function down(): void
    {
        Schema::table('pos_transactions', function (Blueprint $table) {
            if (Schema::hasColumn('pos_transactions', 'prepaid_converted_by')) {
                $table->dropColumn('prepaid_converted_by');
            }
            if (Schema::hasColumn('pos_transactions', 'prepaid_converted_at')) {
                $table->dropColumn('prepaid_converted_at');
            }
        });
    }
};

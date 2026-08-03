<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// Delivery duration tracking (owner batch, 3 Aug 2026): stamp WHEN a rider was
// put on the bill and WHEN it was marked delivered, so the Deliveries board and
// rider report can show "kitni der mein deliver hui". Idempotent per-column
// hasColumn guards — cPanel PROD schema-drift self-heal convention.
return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasTable('pos_transactions')) {
            return;
        }
        Schema::table('pos_transactions', function (Blueprint $table) {
            if (!Schema::hasColumn('pos_transactions', 'rider_assigned_at')) {
                $table->timestamp('rider_assigned_at')->nullable();
            }
            if (!Schema::hasColumn('pos_transactions', 'delivered_at')) {
                $table->timestamp('delivered_at')->nullable();
            }
        });
    }

    public function down(): void
    {
        if (!Schema::hasTable('pos_transactions')) {
            return;
        }
        Schema::table('pos_transactions', function (Blueprint $table) {
            foreach (['rider_assigned_at', 'delivered_at'] as $col) {
                if (Schema::hasColumn('pos_transactions', $col)) {
                    $table->dropColumn($col);
                }
            }
        });
    }
};

<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Cancelled orders ab DELETE nahi hote — status='cancelled' ke sath mehfooz
 * rehte hain taake Cancelled Orders report ban sake (ZFC, 2 Aug 2026).
 * Per-column hasColumn guards (prod schema-drift memory).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('restaurant_orders', function (Blueprint $table) {
            if (!Schema::hasColumn('restaurant_orders', 'cancelled_at')) {
                $table->timestamp('cancelled_at')->nullable();
            }
            if (!Schema::hasColumn('restaurant_orders', 'cancelled_by')) {
                $table->unsignedBigInteger('cancelled_by')->nullable();
            }
        });
    }

    public function down(): void
    {
        Schema::table('restaurant_orders', function (Blueprint $table) {
            if (Schema::hasColumn('restaurant_orders', 'cancelled_at')) {
                $table->dropColumn('cancelled_at');
            }
            if (Schema::hasColumn('restaurant_orders', 'cancelled_by')) {
                $table->dropColumn('cancelled_by');
            }
        });
    }
};

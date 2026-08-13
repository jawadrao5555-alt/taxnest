<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Owner rule (13 Aug 2026, voice note): Order Cancel (restaurant board / bell
 * panel / claimed-cart) is admin/manager work by DEFAULT — cashiers can no
 * longer soft-cancel orders. This company-level switch ("Cashier bhi order
 * cancel kar sake") lets ANY plan re-open it for cashiers (Team Custom Access
 * remains the per-member override on Unlimited). Default OFF everywhere.
 * Mirrors the pos_cashier_dayclose pattern exactly.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('companies', function (Blueprint $table) {
            if (!Schema::hasColumn('companies', 'pos_cashier_order_cancel')) {
                $table->boolean('pos_cashier_order_cancel')->default(false)->after('pos_cashier_dayclose');
            }
        });
    }

    public function down(): void
    {
        Schema::table('companies', function (Blueprint $table) {
            if (Schema::hasColumn('companies', 'pos_cashier_order_cancel')) {
                $table->dropColumn('pos_cashier_order_cancel');
            }
        });
    }
};

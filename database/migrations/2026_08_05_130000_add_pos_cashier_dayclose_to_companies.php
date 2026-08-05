<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Owner rule (5 Aug 2026): Day Close is admin/manager work by DEFAULT — cashiers
 * no longer see or reach it. This company-level switch ("Cashier bhi Day Close
 * kar sake") lets ANY plan re-open it for cashiers (Team Custom Access remains
 * the per-member override on Unlimited). Default OFF everywhere.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('companies', function (Blueprint $table) {
            if (!Schema::hasColumn('companies', 'pos_cashier_dayclose')) {
                $table->boolean('pos_cashier_dayclose')->default(false)->after('pos_auto_dayclose_24h');
            }
        });
    }

    public function down(): void
    {
        Schema::table('companies', function (Blueprint $table) {
            if (Schema::hasColumn('companies', 'pos_cashier_dayclose')) {
                $table->dropColumn('pos_cashier_dayclose');
            }
        });
    }
};

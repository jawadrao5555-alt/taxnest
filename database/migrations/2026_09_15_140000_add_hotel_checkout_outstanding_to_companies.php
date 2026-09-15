<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Hotel outstanding-balance check-out policy.
 *
 * NULL / missing / 'allow' = Hotel V1 (check-out with due permitted).
 * 'block' = refuse check-out until fiscal outstanding is 0.
 *
 * Additive only: existing rows stay NULL so saved hotel behaviour is unchanged.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasColumn('companies', 'hotel_checkout_outstanding')) {
            Schema::table('companies', function (Blueprint $table) {
                $table->string('hotel_checkout_outstanding', 16)->nullable();
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasColumn('companies', 'hotel_checkout_outstanding')) {
            Schema::table('companies', function (Blueprint $table) {
                $table->dropColumn('hotel_checkout_outstanding');
            });
        }
    }
};

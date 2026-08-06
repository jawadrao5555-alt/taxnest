<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Task #320 (ZFC request, Aug 2026): shop's own location for the rider
 * live-tracking map — admin drops a pin once, map opens centered on it and a
 * distinct shop marker shows where riders start from.
 * Idempotent per-column guards (prod-schema-drift convention).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('companies', function (Blueprint $table) {
            if (!Schema::hasColumn('companies', 'shop_lat')) {
                $table->decimal('shop_lat', 10, 7)->nullable();
            }
            if (!Schema::hasColumn('companies', 'shop_lng')) {
                $table->decimal('shop_lng', 10, 7)->nullable();
            }
        });
    }

    public function down(): void
    {
        Schema::table('companies', function (Blueprint $table) {
            if (Schema::hasColumn('companies', 'shop_lat')) {
                $table->dropColumn('shop_lat');
            }
            if (Schema::hasColumn('companies', 'shop_lng')) {
                $table->dropColumn('shop_lng');
            }
        });
    }
};

<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Task 1585: category-wise elaan.
 *
 * NULL / empty list = every shop of the chosen panel audience (unchanged
 * behaviour). A non-empty list narrows the elaan to shops on those business
 * categories. Fresh idempotent migration per the PROD schema-drift convention.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasTable('app_updates')) {
            return;
        }
        if (!Schema::hasColumn('app_updates', 'target_categories')) {
            Schema::table('app_updates', function (Blueprint $table) {
                $table->json('target_categories')->nullable()->after('audience');
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasTable('app_updates') && Schema::hasColumn('app_updates', 'target_categories')) {
            Schema::table('app_updates', function (Blueprint $table) {
                $table->dropColumn('target_categories');
            });
        }
    }
};

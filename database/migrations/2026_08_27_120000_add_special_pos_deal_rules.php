<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Regular/Special deal scheduling and transaction-safe unit counters.
 * Every operation is guarded because some cPanel databases report migrations
 * as ran while their schema is still behind.
 */
return new class extends Migration
{
    public function up(): void
    {
        foreach (['pos_deals', 'fbr_pos_deals'] as $tableName) {
            if (!Schema::hasTable($tableName)) {
                continue;
            }
            Schema::table($tableName, function (Blueprint $table) use ($tableName) {
                if (!Schema::hasColumn($tableName, 'deal_type')) {
                    $table->string('deal_type', 20)->default('regular')->index();
                }
                if (!Schema::hasColumn($tableName, 'special_start_time')) {
                    $table->time('special_start_time')->nullable();
                }
                if (!Schema::hasColumn($tableName, 'special_end_time')) {
                    $table->time('special_end_time')->nullable();
                }
                if (!Schema::hasColumn($tableName, 'total_deal_units_limit')) {
                    $table->unsignedInteger('total_deal_units_limit')->nullable();
                }
                if (!Schema::hasColumn($tableName, 'daily_deal_units_limit')) {
                    $table->unsignedInteger('daily_deal_units_limit')->nullable();
                }
            });
        }

        foreach (['pos_deal_usages', 'fbr_pos_deal_usages'] as $tableName) {
            if (Schema::hasTable($tableName)) {
                continue;
            }
            Schema::create($tableName, function (Blueprint $table) use ($tableName) {
                $table->id();
                $table->unsignedBigInteger('company_id')->index();
                $table->unsignedBigInteger('deal_id')->index();
                $table->date('usage_date');
                $table->unsignedInteger('units_used')->default(0);
                $table->timestamps();
                // Index names are per-table in MySQL but GLOBAL in SQLite, so one
                // shared name for both usage tables blows up the second create on
                // the test database ("index deal_usage_day_unique already exists").
                $table->unique(['company_id', 'deal_id', 'usage_date'], $tableName . '_day_unique');
            });
        }
    }

    public function down(): void
    {
        foreach (['pos_deal_usages', 'fbr_pos_deal_usages'] as $tableName) {
            Schema::dropIfExists($tableName);
        }
        foreach (['pos_deals', 'fbr_pos_deals'] as $tableName) {
            if (!Schema::hasTable($tableName)) {
                continue;
            }
            Schema::table($tableName, function (Blueprint $table) use ($tableName) {
                foreach (['deal_type', 'special_start_time', 'special_end_time', 'total_deal_units_limit', 'daily_deal_units_limit'] as $column) {
                    if (Schema::hasColumn($tableName, $column)) {
                        $table->dropColumn($column);
                    }
                }
            });
        }
    }
};
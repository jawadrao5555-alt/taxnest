<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Task 1582 — What's New announcements and tutorial videos reach only the
 * shops whose category FAMILY they are for (food_service / goods_retail /
 * pharmacy / services / all). Existing rows default to 'all' — nothing a
 * shop sees today disappears.
 */
return new class extends Migration
{
    public function up(): void
    {
        foreach (['app_updates', 'tutorial_videos'] as $table) {
            if (! Schema::hasTable($table) || Schema::hasColumn($table, 'audience_family')) {
                continue;
            }
            Schema::table($table, function (Blueprint $t) {
                $t->string('audience_family', 20)->default('all');
            });
        }
    }

    public function down(): void
    {
        foreach (['app_updates', 'tutorial_videos'] as $table) {
            if (Schema::hasTable($table) && Schema::hasColumn($table, 'audience_family')) {
                Schema::table($table, function (Blueprint $t) {
                    $t->dropColumn('audience_family');
                });
            }
        }
    }
};

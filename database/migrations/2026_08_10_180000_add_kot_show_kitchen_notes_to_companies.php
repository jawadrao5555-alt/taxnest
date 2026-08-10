<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\Schema;
use Illuminate\Database\Schema\Blueprint;

/**
 * KOT kitchen-notes box toggle (10 Aug 2026, Pizza Master feedback):
 * owner decided item-level notes (special_notes per item) are enough for their
 * kitchen; the order-level kitchen_notes bordered box wastes paper. Per-company
 * toggle defaults TRUE (backward compatible — existing restaurants unchanged).
 * Idempotent per prod-schema-drift-selfheal convention.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('companies', function (Blueprint $table) {
            if (!Schema::hasColumn('companies', 'kot_show_kitchen_notes')) {
                $table->boolean('kot_show_kitchen_notes')->default(true);
            }
        });
    }

    public function down(): void
    {
        Schema::table('companies', function (Blueprint $table) {
            if (Schema::hasColumn('companies', 'kot_show_kitchen_notes')) {
                $table->dropColumn('kot_show_kitchen_notes');
            }
        });
    }
};

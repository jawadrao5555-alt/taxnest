<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Database\Schema\Blueprint;

/**
 * Owner decision (10 Aug 2026): kitchen notes box should be OFF for everyone
 * by default — item-level special_notes are sufficient for most kitchens.
 * Companies can re-enable via Kitchen Settings or Receipt Settings.
 *
 * Sets all existing companies to 0, then changes column default to false
 * so any new company also starts with it off.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasColumn('companies', 'kot_show_kitchen_notes')) {
            // Turn off for every existing company.
            DB::table('companies')->update(['kot_show_kitchen_notes' => 0]);

            // Change column default to false for new companies.
            Schema::table('companies', function (Blueprint $table) {
                $table->boolean('kot_show_kitchen_notes')->default(false)->change();
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasColumn('companies', 'kot_show_kitchen_notes')) {
            DB::table('companies')->update(['kot_show_kitchen_notes' => 1]);
            Schema::table('companies', function (Blueprint $table) {
                $table->boolean('kot_show_kitchen_notes')->default(true)->change();
            });
        }
    }
};

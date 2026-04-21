<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Phase 5+ — Auto-KOT toggle on companies.
 * Strictly additive nullable/default column. No primary key change.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('companies', function (Blueprint $table) {
            if (!Schema::hasColumn('companies', 'auto_print_kot')) {
                $table->boolean('auto_print_kot')->default(false)->after('print_on_pay');
            }
        });
    }

    public function down(): void
    {
        Schema::table('companies', function (Blueprint $table) {
            if (Schema::hasColumn('companies', 'auto_print_kot')) {
                $table->dropColumn('auto_print_kot');
            }
        });
    }
};

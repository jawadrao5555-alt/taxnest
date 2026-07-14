<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// P6 (F5): KDS auto-print — when ON, the Kitchen Display device itself
// auto-prints the KOT ticket for every NEW incoming order it sees via the
// poller. Distinct from companies.auto_print_kot (cashier-side print after
// sale) — different device, different moment.
return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasColumn('companies', 'pos_kds_auto_print')) {
            Schema::table('companies', function (Blueprint $table) {
                $table->boolean('pos_kds_auto_print')->default(false)->after('pos_auto_dayclose_24h');
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasColumn('companies', 'pos_kds_auto_print')) {
            Schema::table('companies', function (Blueprint $table) {
                $table->dropColumn('pos_kds_auto_print');
            });
        }
    }
};

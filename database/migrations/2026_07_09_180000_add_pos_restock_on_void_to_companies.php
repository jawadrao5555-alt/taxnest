<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Per-company toggle: when a POS bill is deleted/voided or edited, should the
 * previously-deducted stock be added back to inventory? Default ON (the correct
 * accounting behaviour — a reversed sale returns goods to the shelf). Owners who
 * treat a deleted bill as consumed/damaged stock can turn it OFF.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasColumn('companies', 'pos_restock_on_void')) {
            Schema::table('companies', function (Blueprint $table) {
                $table->boolean('pos_restock_on_void')->default(true)->after('inventory_enabled');
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasColumn('companies', 'pos_restock_on_void')) {
            Schema::table('companies', function (Blueprint $table) {
                $table->dropColumn('pos_restock_on_void');
            });
        }
    }
};

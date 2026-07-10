<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('companies', function (Blueprint $table) {
            if (!Schema::hasColumn('companies', 'pos_auto_purge_local_on_dayclose')) {
                $table->boolean('pos_auto_purge_local_on_dayclose')->default(false)->after('pos_restock_on_void');
            }
            if (!Schema::hasColumn('companies', 'pos_auto_dayclose_24h')) {
                $table->boolean('pos_auto_dayclose_24h')->default(false)->after('pos_auto_purge_local_on_dayclose');
            }
        });

        // NTN optional at POS registration — companies add it later in the profile
        // when they enable PRA integration. Raw MODIFY preserves the column type and
        // the existing UNIQUE index (MySQL allows multiple NULLs under a unique key).
        if (Schema::hasColumn('companies', 'ntn')) {
            try {
                DB::statement('ALTER TABLE companies MODIFY ntn VARCHAR(255) NULL');
            } catch (\Throwable $e) {
                // Ignore — column may already be nullable or the driver differs.
            }
        }
    }

    public function down(): void
    {
        Schema::table('companies', function (Blueprint $table) {
            foreach (['pos_auto_purge_local_on_dayclose', 'pos_auto_dayclose_24h'] as $col) {
                if (Schema::hasColumn('companies', $col)) {
                    $table->dropColumn($col);
                }
            }
        });
    }
};

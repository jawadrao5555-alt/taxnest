<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('global_hs_master') && !Schema::hasColumn('global_hs_master', 'st_withheld_applicable')) {
            Schema::table('global_hs_master', function (Blueprint $table) {
                $table->boolean('st_withheld_applicable')->default(false);
                $table->boolean('petroleum_levy_applicable')->default(false);
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasColumn('global_hs_master', 'st_withheld_applicable')) {
            Schema::table('global_hs_master', function (Blueprint $table) {
                $table->dropColumn(['st_withheld_applicable', 'petroleum_levy_applicable']);
            });
        }
    }
};

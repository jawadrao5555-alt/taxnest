<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasTable('global_hs_master')) {
            return;
        }

        if (Schema::hasColumn('global_hs_master', 'st_withheld_applicable')) {
            return;
        }

        Schema::table('global_hs_master', function (Blueprint $table) {
            $table->boolean('st_withheld_applicable')->default(false);
            $table->boolean('petroleum_levy_applicable')->default(false);
        });

        $stPrefixes = ['2523', '7213', '7214', '7216', '7228', '7308', '8544'];
        $petPrefixes = ['2709', '2710', '2711', '2713'];

        foreach ($stPrefixes as $prefix) {
            DB::table('global_hs_master')
                ->where('hs_code', 'like', $prefix . '%')
                ->update(['st_withheld_applicable' => true]);
        }

        foreach ($petPrefixes as $prefix) {
            DB::table('global_hs_master')
                ->where('hs_code', 'like', $prefix . '%')
                ->update(['petroleum_levy_applicable' => true]);
        }
    }

    public function down(): void
    {
        Schema::table('global_hs_master', function (Blueprint $table) {
            $table->dropColumn(['st_withheld_applicable', 'petroleum_levy_applicable']);
        });
    }
};

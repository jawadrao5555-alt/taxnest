<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('companies', function (Blueprint $table) {
            $table->string('pos_type', 20)->default('general')->after('product_type');
        });

        DB::table('companies')
            ->where('restaurant_mode', true)
            ->update(['pos_type' => 'restaurant']);
    }

    public function down(): void
    {
        Schema::table('companies', function (Blueprint $table) {
            $table->dropColumn('pos_type');
        });
    }
};

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
            if (!Schema::hasColumn('companies', 'business_category')) {
                $table->string('business_category', 60)->nullable()->after('business_activity');
            }
            if (!Schema::hasColumn('companies', 'feature_flags')) {
                $table->json('feature_flags')->nullable()->after('business_category');
            }
            if (!Schema::hasColumn('companies', 'use_universal_pos')) {
                $table->boolean('use_universal_pos')->default(false)->after('feature_flags');
            }
            if (!Schema::hasColumn('companies', 'pos_ui_density')) {
                $table->string('pos_ui_density', 20)->default('standard')->after('use_universal_pos');
            }
        });

        DB::table('companies')->whereNull('business_category')->where('restaurant_mode', true)->update([
            'business_category' => 'restaurant',
        ]);
        DB::table('companies')->whereNull('business_category')->where(function ($q) {
            $q->where('restaurant_mode', false)->orWhereNull('restaurant_mode');
        })->update(['business_category' => 'retail']);
    }

    public function down(): void
    {
        Schema::table('companies', function (Blueprint $table) {
            foreach (['pos_ui_density', 'use_universal_pos', 'feature_flags', 'business_category'] as $col) {
                if (Schema::hasColumn('companies', $col)) {
                    $table->dropColumn($col);
                }
            }
        });
    }
};

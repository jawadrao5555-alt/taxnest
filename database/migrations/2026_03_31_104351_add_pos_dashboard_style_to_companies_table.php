<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('companies', function (Blueprint $table) {
            if (!Schema::hasColumn('companies', 'pos_dashboard_style')) {
                $table->string('pos_dashboard_style', 30)->default('default')->after('pos_theme');
            }
        });
    }

    public function down(): void
    {
        Schema::table('companies', function (Blueprint $table) {
            if (Schema::hasColumn('companies', 'pos_dashboard_style')) {
                $table->dropColumn('pos_dashboard_style');
            }
        });
    }
};

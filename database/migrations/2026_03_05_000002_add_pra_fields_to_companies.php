<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('companies', function (Blueprint $table) {
            if (!Schema::hasColumn('companies', 'pra_environment')) {
                $table->string('pra_environment')->default('sandbox')->after('pra_reporting_enabled');
            }
            if (!Schema::hasColumn('companies', 'pra_pos_id')) {
                $table->string('pra_pos_id')->nullable()->after('pra_environment');
            }
            if (!Schema::hasColumn('companies', 'pra_production_token')) {
                $table->string('pra_production_token')->nullable()->after('pra_pos_id');
            }
        });
    }

    public function down(): void
    {
        Schema::table('companies', function (Blueprint $table) {
            $table->dropColumn(['pra_environment', 'pra_pos_id', 'pra_production_token']);
        });
    }
};

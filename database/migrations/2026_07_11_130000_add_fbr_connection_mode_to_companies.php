<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasColumn('companies', 'fbr_connection_mode')) {
            Schema::table('companies', function (Blueprint $table) {
                $table->string('fbr_connection_mode')->default('cloud')->after('fbr_pos_environment');
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasColumn('companies', 'fbr_connection_mode')) {
            Schema::table('companies', function (Blueprint $table) {
                $table->dropColumn('fbr_connection_mode');
            });
        }
    }
};

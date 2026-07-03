<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasColumn('companies', 'pra_connection_mode')) {
            Schema::table('companies', function (Blueprint $table) {
                $table->string('pra_connection_mode', 20)->default('cloud')->after('pra_proxy_url');
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasColumn('companies', 'pra_connection_mode')) {
            Schema::table('companies', function (Blueprint $table) {
                $table->dropColumn('pra_connection_mode');
            });
        }
    }
};

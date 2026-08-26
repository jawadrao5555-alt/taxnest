<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('companies', function (Blueprint $table) {
            if (! Schema::hasColumn('companies', 'pos_auto_dayclose_time')) {
                $table->string('pos_auto_dayclose_time', 5)
                    ->default('06:00')
                    ->after('pos_auto_dayclose_24h');
            }
        });
    }

    public function down(): void
    {
        Schema::table('companies', function (Blueprint $table) {
            if (Schema::hasColumn('companies', 'pos_auto_dayclose_time')) {
                $table->dropColumn('pos_auto_dayclose_time');
            }
        });
    }
};
<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('fbr_pos_logs') && !Schema::hasColumn('fbr_pos_logs', 'environment')) {
            Schema::table('fbr_pos_logs', function (Blueprint $table) {
                $table->string('environment', 20)->nullable()->after('status')->index();
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasTable('fbr_pos_logs') && Schema::hasColumn('fbr_pos_logs', 'environment')) {
            Schema::table('fbr_pos_logs', function (Blueprint $table) {
                $table->dropIndex(['environment']);
                $table->dropColumn('environment');
            });
        }
    }
};
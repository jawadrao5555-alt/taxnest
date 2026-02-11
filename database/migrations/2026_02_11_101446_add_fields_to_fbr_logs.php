<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('fbr_logs', function (Blueprint $table) {
            $table->string('failure_type')->nullable()->after('status');
            $table->integer('response_time_ms')->nullable()->after('failure_type');
            $table->integer('retry_count')->default(0)->after('response_time_ms');
        });
    }

    public function down(): void
    {
        Schema::table('fbr_logs', function (Blueprint $table) {
            $table->dropColumn(['failure_type', 'response_time_ms', 'retry_count']);
        });
    }
};

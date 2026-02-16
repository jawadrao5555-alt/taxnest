<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('compliance_reports', function (Blueprint $table) {
            $table->boolean('is_fbr_validated')->default(false)->after('risk_level');
            $table->json('pre_validation_flags')->nullable()->after('is_fbr_validated');
        });
    }

    public function down(): void
    {
        Schema::table('compliance_reports', function (Blueprint $table) {
            $table->dropColumn(['is_fbr_validated', 'pre_validation_flags']);
        });
    }
};

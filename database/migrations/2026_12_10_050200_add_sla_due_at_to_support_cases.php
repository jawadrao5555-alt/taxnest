<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('support_cases', function (Blueprint $table) {
            $table->dateTime('sla_due_at')->nullable()->after('priority');
            $table->index(['sla_due_at', 'status'], 'support_cases_sla_status');
        });
    }

    public function down(): void
    {
        Schema::table('support_cases', function (Blueprint $table) {
            $table->dropIndex('support_cases_sla_status');
            $table->dropColumn('sla_due_at');
        });
    }
};

<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('support_cases', function (Blueprint $table) {
            $table->string('priority', 16)->default('normal')->after('status');
            $table->index(['priority', 'status'], 'support_cases_priority_status');
        });
    }

    public function down(): void
    {
        Schema::table('support_cases', function (Blueprint $table) {
            $table->dropIndex('support_cases_priority_status');
            $table->dropColumn('priority');
        });
    }
};

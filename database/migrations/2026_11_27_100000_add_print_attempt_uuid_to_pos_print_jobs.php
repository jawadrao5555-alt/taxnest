<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasTable('pos_print_jobs') || Schema::hasColumn('pos_print_jobs', 'print_attempt_uuid')) {
            return;
        }

        Schema::table('pos_print_jobs', function (Blueprint $table) {
            $table->string('print_attempt_uuid', 64)->nullable()->after('transaction_id');
            $table->unique(
                ['company_id', 'print_attempt_uuid'],
                'pos_print_jobs_company_attempt_unique'
            );
        });
    }

    public function down(): void
    {
        if (!Schema::hasColumn('pos_print_jobs', 'print_attempt_uuid')) {
            return;
        }

        Schema::table('pos_print_jobs', function (Blueprint $table) {
            $table->dropUnique('pos_print_jobs_company_attempt_unique');
            $table->dropColumn('print_attempt_uuid');
        });
    }
};
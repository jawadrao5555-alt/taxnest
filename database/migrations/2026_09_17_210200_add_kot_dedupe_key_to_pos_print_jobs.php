<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasColumn('pos_print_jobs', 'dedupe_key')) {
            Schema::table('pos_print_jobs', function (Blueprint $table) {
                $table->string('dedupe_key', 191)->nullable()->after('render_query');
                $table->unique(['company_id', 'type', 'dedupe_key'], 'pos_print_jobs_dedupe_unique');
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasColumn('pos_print_jobs', 'dedupe_key')) {
            Schema::table('pos_print_jobs', function (Blueprint $table) {
                $table->dropUnique('pos_print_jobs_dedupe_unique');
                $table->dropColumn('dedupe_key');
            });
        }
    }
};
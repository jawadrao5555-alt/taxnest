<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Task 1238: AI assist for DI Excel import — stores the AI fix suggestions
 * generated on the validation preview so the downloadable error report can
 * include them as a suggestion column.
 *
 * Idempotent (per-column hasColumn guard) so it self-heals installs where
 * migrations were marked "Ran" without the column landing.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasTable('invoice_import_batches')) {
            return;
        }
        if (!Schema::hasColumn('invoice_import_batches', 'ai_suggestions_json')) {
            Schema::table('invoice_import_batches', function (Blueprint $table) {
                $table->longText('ai_suggestions_json')->nullable()->after('result_json');
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasTable('invoice_import_batches') && Schema::hasColumn('invoice_import_batches', 'ai_suggestions_json')) {
            Schema::table('invoice_import_batches', function (Blueprint $table) {
                $table->dropColumn('ai_suggestions_json');
            });
        }
    }
};

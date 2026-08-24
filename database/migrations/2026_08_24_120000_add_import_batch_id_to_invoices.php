<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Batch review needs to fetch every draft an Excel/CSV import produced.
 *
 * The AI-photo path already has an exact link (bulk_ai_image_items.invoice_id),
 * but the Excel path had none — invoice_import_batches.result_json only keeps
 * a capped summary of the first 300 created invoices, so a large batch could
 * never be reviewed in full. This adds the missing FK-ish column.
 *
 * Idempotent on purpose: PROD has drifted before (columns marked "Ran" that
 * never landed), so the guard lets a re-run heal a drifted install.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasTable('invoices')) {
            return;
        }

        if (!Schema::hasColumn('invoices', 'import_batch_id')) {
            Schema::table('invoices', function (Blueprint $table) {
                $table->unsignedBigInteger('import_batch_id')->nullable()->after('source')
                    ->comment('invoice_import_batches.id when this draft came from an Excel/CSV bulk import');
                $table->index(['company_id', 'import_batch_id'], 'invoices_company_import_batch_idx');
            });
        }
    }

    public function down(): void
    {
        if (!Schema::hasTable('invoices') || !Schema::hasColumn('invoices', 'import_batch_id')) {
            return;
        }

        Schema::table('invoices', function (Blueprint $table) {
            $table->dropIndex('invoices_company_import_batch_idx');
            $table->dropColumn('import_batch_id');
        });
    }
};

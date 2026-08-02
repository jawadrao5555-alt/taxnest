<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Task 142: AI Invoice Reader — one row per AI extraction attempt
 * (success or failed). Monthly quota = COUNT(status='success') rows in the
 * current calendar month (AiInvoiceReaderService). The uploaded FILE is
 * never stored — only the normalized draft payload JSON.
 *
 * company_id FK cascade covers the admin hard-delete purge rule
 * (see AdminCompanyController::forceDelete purge coverage).
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('ai_invoice_parses')) {
            return;
        }

        Schema::create('ai_invoice_parses', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            $table->unsignedBigInteger('user_id')->nullable();
            $table->string('status', 20)->default('failed'); // success | failed
            $table->string('source_type', 10)->nullable();   // pdf | image | xlsx | csv
            $table->string('original_filename')->nullable();
            $table->longText('payload_json')->nullable();    // normalized draft prefill (success rows)
            $table->text('error')->nullable();               // friendly failure reason (failed rows)
            $table->string('model', 60)->nullable();
            $table->unsignedInteger('total_tokens')->nullable();
            $table->unsignedBigInteger('invoice_id')->nullable()->index(); // set when the draft is saved
            $table->timestamps();
            $table->index(['company_id', 'status', 'created_at'], 'ai_parses_quota_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('ai_invoice_parses');
    }
};

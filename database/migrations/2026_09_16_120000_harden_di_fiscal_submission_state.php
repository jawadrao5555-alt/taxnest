<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('invoices')) {
            Schema::table('invoices', function (Blueprint $table) {
                if (!Schema::hasColumn('invoices', 'fiscal_submission_state')) {
                    $table->string('fiscal_submission_state', 32)->nullable()->index();
                }
                if (!Schema::hasColumn('invoices', 'fiscal_submission_environment')) {
                    $table->string('fiscal_submission_environment', 16)->nullable();
                }
                if (!Schema::hasColumn('invoices', 'fiscal_submission_provenance')) {
                    $table->string('fiscal_submission_provenance', 80)->nullable();
                }
                if (!Schema::hasColumn('invoices', 'fiscal_submission_batch_id')) {
                    $table->unsignedBigInteger('fiscal_submission_batch_id')->nullable()->index();
                }
                if (!Schema::hasColumn('invoices', 'fiscal_payload_hash')) {
                    $table->string('fiscal_payload_hash', 64)->nullable()->index();
                }
                if (!Schema::hasColumn('invoices', 'fiscal_lease_expires_at')) {
                    $table->timestamp('fiscal_lease_expires_at')->nullable()->index();
                }
                if (!Schema::hasColumn('invoices', 'fiscal_acknowledged_at')) {
                    $table->timestamp('fiscal_acknowledged_at')->nullable();
                }
            });
        }

        if (!Schema::hasTable('invoice_bulk_submission_results')) {
            Schema::create('invoice_bulk_submission_results', function (Blueprint $table) {
                $table->id();
                $table->unsignedBigInteger('batch_id');
                $table->unsignedBigInteger('invoice_id');
                $table->string('outcome', 20);
                $table->string('message', 300)->nullable();
                $table->timestamps();
                $table->unique(['batch_id', 'invoice_id'], 'invoice_bulk_result_once');
                $table->index(['batch_id', 'outcome']);
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('invoice_bulk_submission_results');
        // Fiscal evidence is intentionally retained on rollback.
    }
};
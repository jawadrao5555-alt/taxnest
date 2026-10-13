<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('invoice_import_verified_mappings', function (Blueprint $t) {
            $t->id();
            $t->unsignedBigInteger('company_id')->index();
            $t->string('source_system', 80);
            $t->string('kind', 10);
            $t->string('source_code', 120);
            $t->unsignedInteger('version');
            $t->json('details');
            $t->text('evidence');
            $t->unsignedBigInteger('verified_by');
            $t->timestamp('verified_at');
            $t->timestamps();
            $t->unique(['company_id', 'source_system', 'kind', 'source_code', 'version'], 'di_mapping_version_unique');
        });
        Schema::create('invoice_import_source_receipts', function (Blueprint $t) {
            $t->id();
            $t->unsignedBigInteger('company_id')->index();
            $t->string('source_system', 80);
            $t->string('source_invoice_number', 255);
            $t->date('supply_date');
            $t->string('fingerprint', 64)->unique();
            $t->string('content_hash', 64);
            $t->unsignedBigInteger('import_batch_id')->index();
            // No cascading delete: deleting a draft must never permit a repeat import.
            $t->unsignedBigInteger('invoice_id')->nullable()->index();
            $t->json('audit');
            $t->timestamps();
        });
        Schema::table('invoice_import_batches', function (Blueprint $t) {
            $t->string('smart_source_system', 80)->nullable();
            $t->string('source_hash', 64)->nullable()->index();
            $t->json('smart_review')->nullable();
            $t->unsignedBigInteger('approved_by')->nullable();
            $t->timestamp('approved_at')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('invoice_import_batches', function (Blueprint $t) {
            $t->dropColumn(['smart_source_system', 'source_hash', 'smart_review', 'approved_by', 'approved_at']);
        });
        Schema::dropIfExists('invoice_import_source_receipts');
        Schema::dropIfExists('invoice_import_verified_mappings');
    }
};

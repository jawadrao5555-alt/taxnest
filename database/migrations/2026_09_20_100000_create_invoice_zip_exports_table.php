<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Background ZIP exports of invoice PDFs.
 *
 * The old bulk download built the whole ZIP inside one HTTP request and so had
 * to cap itself at 500 invoices. A shop that wants to eyeball every draft it
 * ever imported needs tens of thousands, which only works as a resumable
 * background build with a progress bar — hence this table.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('invoice_zip_exports')) {
            return;
        }

        Schema::create('invoice_zip_exports', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('company_id')->index();
            $table->unsignedBigInteger('user_id')->nullable();

            // The exact filter set the user asked for, replayed on every chunk
            // so a resumed build can never silently change its own scope.
            $table->json('filters')->nullable();
            $table->string('scope_label')->nullable();

            $table->string('status', 20)->default('pending'); // pending | processing | ready | failed
            $table->unsignedInteger('total_invoices')->default(0);
            $table->unsignedInteger('processed_invoices')->default(0);
            $table->unsignedInteger('failed_invoices')->default(0);
            $table->unsignedTinyInteger('progress')->default(0);

            // The build walks a FIXED id range by keyset cursor rather than by
            // offset. Invoices created or deleted while a 50,000-invoice export
            // is running would otherwise shift the pages under it and quietly
            // drop or duplicate documents in an archive meant for verification.
            $table->unsignedBigInteger('max_invoice_id')->default(0);
            $table->unsignedBigInteger('cursor_id')->default(0);

            // Invoices that could not be rendered — excluded from the manifest
            // so the index never lists a PDF the archive does not contain.
            $table->json('failed_ids')->nullable();

            // Set when the build stops early on the disk ceiling rather than
            // on the invoice count, so the UI can say so instead of implying
            // the ZIP is complete.
            $table->boolean('size_capped')->default(false);

            $table->string('file_path')->nullable();
            $table->unsignedBigInteger('file_size')->nullable();
            $table->text('error_message')->nullable();
            // A claim is a lease: whoever holds this exact token owns the build.
            // Without it, a chunk that outlives the stale window can be taken
            // over while the original worker is still writing to the archive.
            $table->timestamp('locked_at')->nullable();
            $table->string('lock_token', 64)->nullable();
            $table->timestamp('completed_at')->nullable();
            $table->timestamps();

            $table->index(['company_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('invoice_zip_exports');
    }
};

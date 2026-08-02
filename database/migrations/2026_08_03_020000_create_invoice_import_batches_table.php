<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('invoice_import_batches')) {
            return;
        }

        Schema::create('invoice_import_batches', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('company_id')->index();
            $table->unsignedBigInteger('user_id')->nullable();
            $table->string('original_filename')->nullable();
            $table->string('source_format', 10)->default('xlsx'); // xlsx | csv
            // validated -> queued -> processing -> completed | failed
            $table->string('status', 30)->default('validated')->index();
            $table->unsignedInteger('total_rows')->default(0);
            $table->unsignedInteger('valid_rows')->default(0);
            $table->unsignedInteger('invalid_rows')->default(0);
            $table->unsignedInteger('processed_rows')->default(0);
            $table->unsignedInteger('created_invoices')->default(0);
            $table->unsignedInteger('failed_rows')->default(0);
            $table->longText('rows_json')->nullable();
            $table->longText('result_json')->nullable();
            $table->text('error_message')->nullable();
            $table->timestamp('started_at')->nullable();
            $table->timestamp('finished_at')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('invoice_import_batches');
    }
};

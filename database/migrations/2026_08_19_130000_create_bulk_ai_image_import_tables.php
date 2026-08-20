<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasTable('bulk_ai_image_batches')) {
            Schema::create('bulk_ai_image_batches', function (Blueprint $table) {
                $table->id();
                $table->foreignId('company_id')->constrained()->cascadeOnDelete();
                $table->unsignedBigInteger('user_id')->nullable();
                $table->uuid('batch_uuid')->unique();
                $table->string('status', 30)->default('uploading')->index();
                $table->unsignedInteger('total_images')->default(0);
                $table->unsignedInteger('processed_images')->default(0);
                $table->unsignedInteger('ready_images')->default(0);
                $table->unsignedInteger('needs_review_images')->default(0);
                $table->unsignedInteger('duplicate_images')->default(0);
                $table->unsignedInteger('failed_images')->default(0);
                $table->unsignedInteger('reserved_credits')->default(0);
                $table->timestamp('finished_at')->nullable();
                $table->timestamp('retention_until')->nullable();
                $table->timestamps();
            });
        }

        if (!Schema::hasTable('bulk_ai_image_items')) {
            Schema::create('bulk_ai_image_items', function (Blueprint $table) {
                $table->id();
                $table->foreignId('batch_id')->constrained('bulk_ai_image_batches')->cascadeOnDelete();
                $table->foreignId('company_id')->constrained()->cascadeOnDelete();
                $table->uuid('source_uuid')->unique();
                $table->unsignedInteger('position');
                $table->string('original_filename', 255);
                $table->string('mime_type', 100)->nullable();
                $table->unsignedBigInteger('expected_bytes')->default(0);
                $table->unsignedBigInteger('uploaded_bytes')->default(0);
                $table->unsignedInteger('total_chunks')->default(0);
                $table->string('content_hash', 64)->nullable()->index();
                $table->string('storage_path')->nullable();
                $table->string('status', 30)->default('not_started')->index();
                $table->string('reservation_status', 20)->default('reserved')->index();
                $table->unsignedInteger('parse_id')->nullable();
                $table->unsignedBigInteger('invoice_id')->nullable();
                $table->longText('warnings_json')->nullable();
                $table->longText('details_json')->nullable();
                $table->text('error')->nullable();
                $table->unsignedInteger('retry_count')->default(0);
                $table->timestamp('processed_at')->nullable();
                $table->timestamp('source_deleted_at')->nullable();
                $table->timestamps();
                $table->index(['company_id', 'reservation_status', 'created_at'], 'bulk_ai_credit_idx');
                $table->index(['batch_id', 'position']);
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('bulk_ai_image_items');
        Schema::dropIfExists('bulk_ai_image_batches');
    }
};
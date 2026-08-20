<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasTable('bulk_ai_image_batches')) {
            return;
        }

        Schema::table('bulk_ai_image_batches', function (Blueprint $table) {
            foreach ([
                'annexure_filename' => fn () => $table->string('annexure_filename', 255)->nullable(),
                'annexure_storage_path' => fn () => $table->string('annexure_storage_path')->nullable(),
                'annexure_status' => fn () => $table->string('annexure_status', 30)->default('none')->index(),
                'annexure_headers_json' => fn () => $table->longText('annexure_headers_json')->nullable(),
                'annexure_samples_json' => fn () => $table->longText('annexure_samples_json')->nullable(),
                'annexure_rows_json' => fn () => $table->longText('annexure_rows_json')->nullable(),
                'annexure_mapping_json' => fn () => $table->longText('annexure_mapping_json')->nullable(),
                'annexure_uploaded_at' => fn () => $table->timestamp('annexure_uploaded_at')->nullable(),
            ] as $column => $definition) {
                if (!Schema::hasColumn('bulk_ai_image_batches', $column)) {
                    $definition();
                }
            }
        });
    }

    public function down(): void
    {
        if (!Schema::hasTable('bulk_ai_image_batches')) {
            return;
        }
        Schema::table('bulk_ai_image_batches', function (Blueprint $table) {
            foreach ([
                'annexure_filename', 'annexure_storage_path', 'annexure_status',
                'annexure_headers_json', 'annexure_samples_json', 'annexure_rows_json',
                'annexure_mapping_json', 'annexure_uploaded_at',
            ] as $column) {
                if (Schema::hasColumn('bulk_ai_image_batches', $column)) {
                    $table->dropColumn($column);
                }
            }
        });
    }
};
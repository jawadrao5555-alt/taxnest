<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('annexure_product_audits')) {
            return;
        }

        Schema::create('annexure_product_audits', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            $table->foreignId('batch_id')->constrained('bulk_ai_image_batches')->cascadeOnDelete();
            $table->foreignId('product_id')->nullable()->constrained('products')->nullOnDelete();
            $table->unsignedBigInteger('user_id')->nullable();
            $table->string('action', 40);
            $table->string('decision', 30)->nullable();
            $table->unsignedInteger('annexure_row')->nullable();
            $table->string('idempotency_key', 100);
            $table->longText('previous_values_json')->nullable();
            $table->longText('approved_values_json')->nullable();
            $table->timestamps();
            $table->unique(['company_id', 'batch_id', 'idempotency_key'], 'annexure_audit_idempotency');
            $table->index(['company_id', 'product_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('annexure_product_audits');
    }
};
<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * NestPOS Stock-In Phase 2a — persistent staging (not session).
 * Master Excel remains catalog-only; this table holds receiving Excel until Post.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasTable('pos_stock_in_batches')) {
            Schema::create('pos_stock_in_batches', function (Blueprint $table) {
                $table->id();
                $table->unsignedBigInteger('company_id');
                $table->unsignedBigInteger('created_by')->nullable();
                $table->unsignedBigInteger('branch_id')->nullable();
                $table->string('reference');
                $table->boolean('update_cost')->default(false);
                $table->boolean('save_supplier_code')->default(false);
                $table->string('status', 20)->default('open');
                $table->string('original_filename')->nullable();
                $table->unsignedInteger('line_count')->default(0);
                $table->timestamp('posted_at')->nullable();
                $table->timestamps();
                $table->index(['company_id', 'status']);
                $table->index(['company_id', 'reference']);
            });
        }

        if (!Schema::hasTable('pos_stock_in_lines')) {
            Schema::create('pos_stock_in_lines', function (Blueprint $table) {
                $table->id();
                $table->unsignedBigInteger('batch_id');
                $table->unsignedInteger('source_row_no');
                $table->string('line_type', 20);
                $table->string('supplier_item_code')->nullable();
                $table->string('supplier_item_name')->nullable();
                $table->string('nestpos_code_raw')->nullable();
                $table->decimal('qty', 15, 4)->default(0);
                $table->string('unit', 30)->nullable();
                $table->decimal('rate', 15, 4)->nullable();
                $table->unsignedBigInteger('branch_id')->nullable();
                $table->string('reference_override')->nullable();
                $table->string('notes')->nullable();
                $table->string('match_status', 30);
                $table->unsignedBigInteger('matched_ingredient_id')->nullable();
                $table->unsignedBigInteger('matched_product_id')->nullable();
                $table->string('invalid_reason')->nullable();
                $table->boolean('selected')->default(false);
                $table->unsignedBigInteger('collapsed_into_line_id')->nullable();
                $table->timestamp('posted_at')->nullable();
                $table->unsignedBigInteger('movement_id')->nullable();
                $table->string('movement_table', 40)->nullable();
                $table->timestamps();
                $table->index(['batch_id', 'match_status']);
                $table->index(['batch_id', 'posted_at']);
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('pos_stock_in_lines');
        Schema::dropIfExists('pos_stock_in_batches');
    }
};

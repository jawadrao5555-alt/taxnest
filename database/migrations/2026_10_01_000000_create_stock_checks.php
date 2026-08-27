<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Physical Stock Check (jismani stock ginti).
 *
 * The shop issues goods to a branch, sells through the day, and at close the
 * kitchen/counter physically counts what is left. The system already knows what
 * SHOULD be left; this pair of tables records what was ACTUALLY counted so the
 * gap (chori / wastage / galat entry) is visible instead of silently absorbed.
 *
 * Idempotent by design: the owner's cPanel PROD schema has drifted before
 * (migrations marked "Ran" without the table existing), so every create and
 * column add is guarded.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasTable('stock_checks')) {
            Schema::create('stock_checks', function (Blueprint $table) {
                $table->id();
                $table->unsignedBigInteger('company_id');
                // NULL = single-branch company (matches inventory_stocks).
                $table->unsignedBigInteger('branch_id')->nullable();
                $table->string('code', 30);
                // products | ingredients | both
                $table->string('scope', 20)->default('products');
                // counting | completed | cancelled
                $table->string('status', 20)->default('counting');
                $table->text('notes')->nullable();
                $table->unsignedInteger('total_lines')->default(0);
                $table->unsignedInteger('counted_lines')->default(0);
                $table->unsignedInteger('variance_lines')->default(0);
                // Rupee value of what is missing / extra, at cost.
                $table->decimal('short_value', 15, 2)->default(0);
                $table->decimal('excess_value', 15, 2)->default(0);
                $table->timestamp('started_at')->nullable();
                $table->timestamp('posted_at')->nullable();
                $table->unsignedBigInteger('created_by')->nullable();
                $table->unsignedBigInteger('posted_by')->nullable();
                $table->timestamps();
                $table->unique(['company_id', 'code'], 'stock_checks_company_code_unique');
                $table->index(['company_id', 'status']);
                $table->index(['company_id', 'branch_id']);
            });
        }

        if (!Schema::hasTable('stock_check_lines')) {
            Schema::create('stock_check_lines', function (Blueprint $table) {
                $table->id();
                $table->unsignedBigInteger('company_id');
                $table->unsignedBigInteger('stock_check_id');
                // product = sellable/menu item (pos_products)
                // ingredient = raw material (ingredients)
                $table->string('item_type', 20)->default('product');
                $table->unsignedBigInteger('item_id');
                $table->unsignedBigInteger('branch_id')->nullable();
                // Snapshots — a renamed or deleted item must not rewrite history.
                $table->string('item_name');
                $table->string('item_code', 80)->nullable();
                $table->string('unit', 30)->nullable();
                // What the system said should be there when the count STARTED.
                $table->decimal('expected_quantity', 15, 4)->default(0);
                // What the human actually counted. NULL = not counted yet.
                $table->decimal('counted_quantity', 15, 4)->nullable();
                $table->decimal('variance', 15, 4)->default(0);
                $table->decimal('unit_cost', 15, 2)->default(0);
                $table->decimal('variance_value', 15, 2)->default(0);
                $table->string('reason', 40)->nullable();
                $table->string('notes', 255)->nullable();
                $table->unsignedBigInteger('counted_by')->nullable();
                $table->timestamp('counted_at')->nullable();
                $table->timestamps();
                $table->unique(['stock_check_id', 'item_type', 'item_id'], 'stock_check_line_item_unique');
                $table->index(['company_id', 'stock_check_id']);
            });
        }
    }

    public function down(): void
    {
        // Counted variance rows are an audit trail — never dropped automatically.
    }
};

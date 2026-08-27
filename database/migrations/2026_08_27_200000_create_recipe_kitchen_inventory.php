<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('ingredients')) {
            Schema::table('ingredients', function (Blueprint $table) {
                if (!Schema::hasColumn('ingredients', 'code')) $table->string('code', 50)->nullable();
                if (!Schema::hasColumn('ingredients', 'base_unit')) $table->string('base_unit', 20)->nullable();
                if (!Schema::hasColumn('ingredients', 'conversion_factor')) $table->decimal('conversion_factor', 15, 4)->default(1);
            });
            try {
                Schema::table('ingredients', function (Blueprint $table) {
                    $table->decimal('current_stock', 15, 4)->change();
                    $table->decimal('min_stock_level', 15, 4)->change();
                });
            } catch (\Throwable $e) {
                // Older SQLite/production schema variants can retain the
                // legacy mirror precision; branch stock remains precise.
            }
        }

        if (Schema::hasTable('product_recipes')) {
            Schema::table('product_recipes', function (Blueprint $table) {
                if (!Schema::hasColumn('product_recipes', 'recipe_version')) $table->unsignedInteger('recipe_version')->default(1);
                if (!Schema::hasColumn('product_recipes', 'is_active')) $table->boolean('is_active')->default(true);
            });
        }

        if (Schema::hasTable('pos_transaction_items')) {
            Schema::table('pos_transaction_items', function (Blueprint $table) {
                if (!Schema::hasColumn('pos_transaction_items', 'return_disposition')) $table->string('return_disposition', 30)->nullable();
                if (!Schema::hasColumn('pos_transaction_items', 'prepared_return_id')) $table->unsignedBigInteger('prepared_return_id')->nullable();
            });
        }

        if (Schema::hasTable('pos_transactions')) {
            Schema::table('pos_transactions', function (Blueprint $table) {
                if (!Schema::hasColumn('pos_transactions', 'pra_dependency_transaction_id')) {
                    $table->unsignedBigInteger('pra_dependency_transaction_id')->nullable()->index();
                }
            });
        }

        if (!Schema::hasTable('ingredient_stocks')) {
            Schema::create('ingredient_stocks', function (Blueprint $table) {
                $table->id();
                $table->unsignedBigInteger('company_id');
                $table->unsignedBigInteger('ingredient_id');
                $table->unsignedBigInteger('branch_id')->nullable();
                $table->decimal('quantity', 15, 4)->default(0);
                $table->decimal('min_stock_level', 15, 4)->default(0);
                $table->timestamps();
                $table->unique(['company_id', 'ingredient_id', 'branch_id'], 'ingredient_stocks_scope_unique');
                $table->index(['company_id', 'branch_id']);
            });
        }

        if (!Schema::hasTable('ingredient_movements')) {
            Schema::create('ingredient_movements', function (Blueprint $table) {
                $table->id();
                $table->unsignedBigInteger('company_id');
                $table->unsignedBigInteger('ingredient_id');
                $table->unsignedBigInteger('branch_id')->nullable();
                $table->string('type', 30);
                $table->decimal('quantity', 15, 4)->default(0);
                $table->decimal('balance_after', 15, 4)->default(0);
                $table->string('reference_type')->nullable();
                $table->unsignedBigInteger('reference_id')->nullable();
                $table->string('reference_number')->nullable();
                $table->json('snapshot')->nullable();
                $table->timestamp('reversed_at')->nullable();
                $table->unsignedBigInteger('created_by')->nullable();
                $table->timestamps();
                $table->index(['company_id', 'ingredient_id', 'branch_id']);
            });
        }

        if (!Schema::hasTable('recipe_consumptions')) {
            Schema::create('recipe_consumptions', function (Blueprint $table) {
                $table->id();
                $table->unsignedBigInteger('company_id');
                $table->unsignedBigInteger('transaction_id');
                $table->unsignedBigInteger('ingredient_id');
                $table->unsignedBigInteger('branch_id')->nullable();
                $table->decimal('quantity', 15, 4)->default(0);
                $table->json('components')->nullable();
                $table->json('snapshot')->nullable();
                $table->string('invoice_number')->nullable();
                 $table->timestamp('reversed_at')->nullable();
                $table->timestamps();
                 // A bill edit/void keeps the old snapshot and writes a new
                 // version for the same transaction; this must not be unique.
                 $table->index(['company_id', 'transaction_id', 'ingredient_id'], 'recipe_consumptions_lookup_idx');
                $table->index(['company_id', 'transaction_id']);
            });
        }

        if (!Schema::hasTable('prepared_returns')) {
            Schema::create('prepared_returns', function (Blueprint $table) {
                $table->id();
                $table->unsignedBigInteger('company_id');
                $table->unsignedBigInteger('branch_id')->nullable();
                $table->unsignedBigInteger('product_id');
                $table->unsignedBigInteger('return_transaction_id');
                $table->decimal('quantity', 15, 4);
                $table->decimal('remaining_quantity', 15, 4);
                $table->decimal('consumed_quantity', 15, 4)->default(0);
                $table->string('status', 20)->default('available');
                $table->timestamp('expires_at')->nullable();
                $table->unsignedBigInteger('consumed_by_transaction_id')->nullable();
                $table->timestamp('consumed_at')->nullable();
                $table->unsignedBigInteger('created_by')->nullable();
                $table->timestamps();
                $table->index(['company_id', 'product_id', 'branch_id', 'status']);
                $table->index('return_transaction_id');
            });
        }

        // If an earlier deployment created the initial unique snapshot index,
        // remove it without touching the audit rows. Bill edits need multiple
        // immutable recipe snapshots for one transaction id.
        if (Schema::hasTable('recipe_consumptions')) {
            try {
                if (!Schema::hasColumn('recipe_consumptions', 'reversed_at')) {
                    Schema::table('recipe_consumptions', fn (Blueprint $table) =>
                        $table->timestamp('reversed_at')->nullable()
                    );
                }
                $indexes = collect(Schema::getIndexes('recipe_consumptions'))->pluck('name')->all();
                if (in_array('recipe_consumptions_once_unique', $indexes, true)) {
                    Schema::table('recipe_consumptions', fn (Blueprint $table) =>
                        $table->dropUnique('recipe_consumptions_once_unique')
                    );
                }
                $indexes = collect(Schema::getIndexes('recipe_consumptions'))->pluck('name')->all();
                if (!in_array('recipe_consumptions_lookup_idx', $indexes, true)) {
                    Schema::table('recipe_consumptions', fn (Blueprint $table) =>
                        $table->index(['company_id', 'transaction_id', 'ingredient_id'], 'recipe_consumptions_lookup_idx')
                    );
                }
            } catch (\Throwable $e) {
                // Index introspection varies between older SQLite and MySQL
                // drivers; the data tables remain usable without this hint.
            }
        }
    }

    public function down(): void
    {
        // Data-bearing kitchen ledgers are intentionally not dropped.
    }
};
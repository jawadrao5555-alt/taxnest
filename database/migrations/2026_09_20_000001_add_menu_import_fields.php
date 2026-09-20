<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('ingredients')) {
            Schema::table('ingredients', function (Blueprint $table): void {
                if (!Schema::hasColumn('ingredients', 'category')) $table->string('category')->nullable();
                if (!Schema::hasColumn('ingredients', 'supplier')) $table->string('supplier')->nullable();
            });
        }
        if (Schema::hasTable('product_recipes')) {
            Schema::table('product_recipes', function (Blueprint $table): void {
                if (!Schema::hasColumn('product_recipes', 'waste_percent')) $table->decimal('waste_percent', 6, 2)->default(0);
                if (!Schema::hasColumn('product_recipes', 'notes')) $table->text('notes')->nullable();
            });
        }
    }

    public function down(): void
    {
        foreach (['category', 'supplier'] as $column) {
            if (Schema::hasColumn('ingredients', $column)) Schema::table('ingredients', fn (Blueprint $table) => $table->dropColumn($column));
        }
        foreach (['waste_percent', 'notes'] as $column) {
            if (Schema::hasColumn('product_recipes', $column)) Schema::table('product_recipes', fn (Blueprint $table) => $table->dropColumn($column));
        }
    }
};
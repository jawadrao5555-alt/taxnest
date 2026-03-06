<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasTable('inventory_adjustments')) {
            Schema::create('inventory_adjustments', function (Blueprint $table) {
                $table->id();
                $table->foreignId('company_id')->constrained('companies')->onDelete('cascade');
                $table->foreignId('product_id')->constrained('products')->onDelete('cascade');
                $table->string('type', 30);
                $table->decimal('quantity', 15, 2);
                $table->decimal('previous_quantity', 15, 2)->default(0);
                $table->decimal('new_quantity', 15, 2)->default(0);
                $table->string('reason')->nullable();
                $table->text('notes')->nullable();
                $table->foreignId('created_by')->nullable()->constrained('users')->onDelete('set null');
                $table->timestamps();
                $table->index(['company_id', 'product_id']);
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('inventory_adjustments');
    }
};

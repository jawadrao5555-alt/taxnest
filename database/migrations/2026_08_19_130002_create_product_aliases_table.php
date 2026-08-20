<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('product_aliases')) {
            return;
        }
        Schema::create('product_aliases', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            $table->foreignId('product_id')->constrained('products')->cascadeOnDelete();
            $table->string('alias', 255);
            $table->boolean('is_active')->default(true);
            $table->timestamps();
            $table->unique(['company_id', 'product_id', 'alias']);
            $table->index(['company_id', 'alias']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('product_aliases');
    }
};
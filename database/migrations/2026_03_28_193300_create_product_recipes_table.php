<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('product_recipes', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained('companies')->onDelete('cascade');
            $table->unsignedBigInteger('product_id');
            $table->foreignId('ingredient_id')->constrained('ingredients')->onDelete('cascade');
            $table->decimal('quantity_needed', 10, 4);
            $table->timestamps();
            $table->unique(['product_id', 'ingredient_id']);
            $table->index('company_id');
            $table->foreign('product_id')->references('id')->on('pos_products')->onDelete('cascade');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('product_recipes');
    }
};

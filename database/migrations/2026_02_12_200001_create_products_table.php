<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('products', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained('companies')->onDelete('cascade');
            $table->string('name');
            $table->string('hs_code');
            $table->string('pct_code')->nullable();
            $table->decimal('default_tax_rate', 5, 2)->default(18.00);
            $table->string('uom')->default('PCS');
            $table->string('schedule_type')->nullable();
            $table->string('sro_reference')->nullable();
            $table->decimal('default_price', 15, 2)->default(0);
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('products');
    }
};

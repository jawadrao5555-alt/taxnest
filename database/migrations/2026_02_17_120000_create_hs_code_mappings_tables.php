<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('hs_code_mappings', function (Blueprint $table) {
            $table->id();
            $table->string('hs_code', 20)->index();
            $table->string('label')->nullable();
            $table->string('sale_type')->default('standard');
            $table->decimal('tax_rate', 8, 2)->default(0);
            $table->boolean('sro_applicable')->default(false);
            $table->string('sro_number')->nullable();
            $table->boolean('serial_number_applicable')->default(false);
            $table->string('serial_number_value')->nullable();
            $table->boolean('mrp_required')->default(false);
            $table->string('pct_code')->nullable();
            $table->string('default_uom')->nullable();
            $table->string('buyer_type')->nullable();
            $table->text('notes')->nullable();
            $table->integer('priority')->default(10);
            $table->boolean('is_active')->default(true);
            $table->unsignedBigInteger('created_by')->nullable();
            $table->unsignedBigInteger('updated_by')->nullable();
            $table->timestamps();

            $table->index(['hs_code', 'is_active', 'priority']);
        });

        Schema::create('hs_mapping_responses', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('hs_code_mapping_id');
            $table->unsignedBigInteger('company_id');
            $table->unsignedBigInteger('user_id');
            $table->unsignedBigInteger('invoice_id')->nullable();
            $table->string('hs_code', 20)->index();
            $table->string('action');
            $table->json('custom_values')->nullable();
            $table->timestamps();

            $table->foreign('hs_code_mapping_id')->references('id')->on('hs_code_mappings')->onDelete('cascade');
            $table->index(['company_id', 'hs_code']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('hs_mapping_responses');
        Schema::dropIfExists('hs_code_mappings');
    }
};

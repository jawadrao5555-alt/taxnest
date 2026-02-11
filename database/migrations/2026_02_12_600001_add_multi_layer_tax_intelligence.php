<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('companies', function (Blueprint $table) {
            $table->decimal('standard_tax_rate', 5, 2)->default(18.00);
            $table->string('sector_type')->default('Retail');
            $table->string('province')->nullable();
        });

        Schema::create('sector_tax_rules', function (Blueprint $table) {
            $table->id();
            $table->string('sector_type');
            $table->string('hs_code');
            $table->decimal('override_tax_rate', 5, 2)->nullable();
            $table->string('override_schedule_type')->nullable();
            $table->boolean('override_sro_required')->nullable();
            $table->boolean('override_mrp_required')->nullable();
            $table->text('description')->nullable();
            $table->boolean('is_active')->default(true);
            $table->timestamps();

            $table->unique(['sector_type', 'hs_code']);
            $table->index('sector_type');
        });

        Schema::create('province_tax_rules', function (Blueprint $table) {
            $table->id();
            $table->string('province');
            $table->string('hs_code');
            $table->decimal('override_tax_rate', 5, 2)->nullable();
            $table->string('override_schedule_type')->nullable();
            $table->boolean('override_sro_required')->nullable();
            $table->boolean('override_mrp_required')->nullable();
            $table->text('description')->nullable();
            $table->boolean('is_active')->default(true);
            $table->timestamps();

            $table->unique(['province', 'hs_code']);
            $table->index('province');
        });

        Schema::create('customer_tax_rules', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained()->onDelete('cascade');
            $table->string('customer_ntn');
            $table->string('hs_code');
            $table->decimal('override_tax_rate', 5, 2)->nullable();
            $table->string('override_schedule_type')->nullable();
            $table->boolean('override_sro_required')->nullable();
            $table->boolean('override_mrp_required')->nullable();
            $table->text('description')->nullable();
            $table->boolean('is_active')->default(true);
            $table->timestamps();

            $table->unique(['company_id', 'customer_ntn', 'hs_code']);
            $table->index(['company_id', 'customer_ntn']);
        });

        Schema::create('special_sro_rules', function (Blueprint $table) {
            $table->id();
            $table->string('hs_code');
            $table->string('schedule_type');
            $table->string('sro_number');
            $table->string('serial_no')->nullable();
            $table->string('applicable_sector')->nullable();
            $table->string('applicable_province')->nullable();
            $table->decimal('concessionary_rate', 5, 2)->nullable();
            $table->text('description')->nullable();
            $table->date('effective_from')->nullable();
            $table->date('effective_until')->nullable();
            $table->boolean('is_active')->default(true);
            $table->timestamps();

            $table->index(['hs_code', 'schedule_type']);
        });

        Schema::create('override_usage_logs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained()->onDelete('cascade');
            $table->foreignId('invoice_id')->nullable()->constrained()->onDelete('set null');
            $table->string('hs_code');
            $table->string('override_layer');
            $table->string('override_source_id')->nullable();
            $table->json('original_values')->nullable();
            $table->json('overridden_values')->nullable();
            $table->timestamps();

            $table->index(['company_id', 'override_layer']);
            $table->index('created_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('override_usage_logs');
        Schema::dropIfExists('special_sro_rules');
        Schema::dropIfExists('customer_tax_rules');
        Schema::dropIfExists('province_tax_rules');
        Schema::dropIfExists('sector_tax_rules');

        Schema::table('companies', function (Blueprint $table) {
            $table->dropColumn(['standard_tax_rate', 'sector_type', 'province']);
        });
    }
};

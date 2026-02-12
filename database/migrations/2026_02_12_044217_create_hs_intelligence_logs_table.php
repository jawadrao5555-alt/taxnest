<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('hs_intelligence_logs', function (Blueprint $table) {
            $table->id();
            $table->string('hs_code', 20);
            $table->string('suggested_schedule_type', 50)->nullable();
            $table->decimal('suggested_tax_rate', 5, 2)->nullable();
            $table->boolean('suggested_sro_required')->default(false);
            $table->boolean('suggested_serial_required')->default(false);
            $table->boolean('suggested_mrp_required')->default(false);
            $table->integer('confidence_score')->default(0);
            $table->json('weight_breakdown')->nullable();
            $table->integer('based_on_records_count')->default(0);
            $table->integer('rejection_factor')->default(0);
            $table->integer('industry_factor')->default(0);
            $table->timestamp('created_at')->useCurrent();

            $table->index('hs_code');
            $table->index('confidence_score');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('hs_intelligence_logs');
    }
};

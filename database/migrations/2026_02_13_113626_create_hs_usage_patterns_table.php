<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('hs_usage_patterns', function (Blueprint $table) {
            $table->id();
            $table->string('hs_code', 20)->index();
            $table->string('schedule_type', 50)->nullable();
            $table->decimal('tax_rate', 5, 2)->nullable();
            $table->string('sro_schedule_no', 100)->nullable();
            $table->string('sro_item_serial_no', 100)->nullable();
            $table->boolean('mrp_required')->default(false);
            $table->string('sale_type', 100)->nullable();
            $table->integer('success_count')->default(0);
            $table->integer('rejection_count')->default(0);
            $table->decimal('confidence_score', 5, 2)->default(0);
            $table->string('admin_status', 20)->default('auto');
            $table->timestamp('last_used_at')->nullable();
            $table->string('integrity_hash', 64)->nullable();
            $table->timestamps();
            $table->unique(['hs_code', 'schedule_type', 'tax_rate'], 'hs_usage_unique');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('hs_usage_patterns');
    }
};

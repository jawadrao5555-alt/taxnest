<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('hs_master_global', function (Blueprint $table) {
            $table->id();
            $table->string('hs_code')->unique();
            $table->text('description')->nullable();
            $table->string('schedule_type')->nullable();
            $table->decimal('default_tax_rate', 5, 2)->nullable();
            $table->boolean('sro_required')->default(false);
            $table->string('default_sro_number')->nullable();
            $table->boolean('serial_required')->default(false);
            $table->string('default_serial_no')->nullable();
            $table->boolean('mrp_required')->default(false);
            $table->boolean('st_withheld_applicable')->default(false);
            $table->boolean('petroleum_levy_applicable')->default(false);
            $table->string('default_uom')->nullable();
            $table->integer('confidence_score')->default(100);
            $table->string('last_source')->default('manual');
            $table->boolean('is_active')->default(true);
            $table->timestamps();

            $table->index('hs_code');
            $table->index('schedule_type');
            $table->index('default_tax_rate');
            $table->index('is_active');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('hs_master_global');
    }
};

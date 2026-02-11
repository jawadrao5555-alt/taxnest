<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('global_hs_master', function (Blueprint $table) {
            $table->id();
            $table->string('hs_code', 20)->unique();
            $table->string('description', 500)->nullable();
            $table->string('pct_code', 30)->nullable();
            $table->string('schedule_type', 30)->default('standard');
            $table->decimal('tax_rate', 5, 2)->default(18.00);
            $table->string('default_uom', 100)->nullable();
            $table->boolean('sro_required')->default(false);
            $table->string('sro_number', 100)->nullable();
            $table->string('sro_item_serial_no', 100)->nullable();
            $table->boolean('mrp_required')->default(false);
            $table->string('sector_tag', 100)->nullable();
            $table->decimal('risk_weight', 5, 2)->default(0);
            $table->string('mapping_status', 20)->default('Mapped');
            $table->unsignedBigInteger('created_by')->nullable();
            $table->unsignedBigInteger('updated_by')->nullable();
            $table->timestamps();

            $table->index('schedule_type');
            $table->index('sector_tag');
            $table->index('mapping_status');
            $table->index('tax_rate');
        });

        Schema::create('hs_unmapped_log', function (Blueprint $table) {
            $table->id();
            $table->string('hs_code', 20);
            $table->unsignedBigInteger('company_id');
            $table->unsignedBigInteger('invoice_id')->nullable();
            $table->integer('frequency_count')->default(1);
            $table->timestamp('first_seen_at')->useCurrent();
            $table->timestamp('last_seen_at')->useCurrent();
            $table->timestamps();

            $table->unique(['hs_code', 'company_id']);
            $table->index('hs_code');
            $table->index('company_id');
            $table->index('frequency_count');
            $table->index('last_seen_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('hs_unmapped_log');
        Schema::dropIfExists('global_hs_master');
    }
};

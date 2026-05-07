<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('fbr_hs_rate_links', function (Blueprint $table) {
            $table->id();
            $table->string('hs_code', 20)->index();
            $table->string('schedule_type', 30)->nullable()->comment('3rd_schedule, 8th_schedule, exempt, zero_rated, reduced, standard');
            $table->decimal('tax_rate', 5, 2)->nullable()->comment('Percentage e.g. 5.00, 17.00');
            $table->string('rate_label', 50)->nullable()->comment('FBR rate label e.g. "5%", "17%", "Rs.2"');
            $table->string('sale_type', 100)->nullable();
            $table->string('sro_number', 100)->nullable();
            $table->string('sr_no', 50)->nullable()->comment('3rd Schedule serial number');
            $table->string('uom', 50)->nullable();
            $table->string('notes', 500)->nullable();
            $table->boolean('is_active')->default(true);
            $table->timestamps();

            $table->unique(['hs_code', 'schedule_type'], 'fbr_hs_rate_unique');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('fbr_hs_rate_links');
    }
};

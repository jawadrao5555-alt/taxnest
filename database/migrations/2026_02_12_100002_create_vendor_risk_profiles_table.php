<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('vendor_risk_profiles', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained()->onDelete('cascade');
            $table->string('vendor_ntn');
            $table->string('vendor_name')->nullable();
            $table->integer('vendor_score')->default(100);
            $table->integer('total_invoices')->default(0);
            $table->integer('rejected_invoices')->default(0);
            $table->integer('tax_mismatches')->default(0);
            $table->integer('anomaly_count')->default(0);
            $table->timestamp('last_flagged_at')->nullable();
            $table->timestamps();

            $table->unique(['company_id', 'vendor_ntn']);
            $table->index(['vendor_score']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('vendor_risk_profiles');
    }
};

<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('compliance_reports', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained()->onDelete('cascade');
            $table->foreignId('invoice_id')->nullable()->constrained()->onDelete('set null');
            $table->json('rule_flags')->nullable();
            $table->json('anomaly_flags')->nullable();
            $table->integer('final_score')->default(100);
            $table->string('risk_level')->default('LOW');
            $table->timestamps();

            $table->index(['company_id', 'created_at']);
            $table->index(['risk_level']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('compliance_reports');
    }
};

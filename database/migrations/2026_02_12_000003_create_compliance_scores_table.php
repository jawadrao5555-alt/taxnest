<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('compliance_scores', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained()->onDelete('cascade');
            $table->integer('score')->default(100);
            $table->float('success_rate')->default(100);
            $table->float('retry_ratio')->default(0);
            $table->float('draft_aging')->default(0);
            $table->float('failure_ratio')->default(0);
            $table->string('category')->default('SAFE');
            $table->date('calculated_date');
            $table->timestamps();

            $table->index(['company_id', 'calculated_date']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('compliance_scores');
    }
};

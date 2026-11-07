<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('diagnostic_source_observations', function (Blueprint $t) {
            $t->id(); $t->unsignedBigInteger('company_id'); $t->uuid('run_id');
            $t->string('source', 64); $t->unsignedSmallInteger('registry_version')->default(1);
            $t->date('business_date'); $t->dateTime('collected_at');
            $t->string('status', 32); $t->unsignedBigInteger('retained_rows')->nullable();
            $t->dateTime('first_source_at')->nullable(); $t->dateTime('last_source_at')->nullable();
            $t->unique(['company_id', 'source', 'business_date', 'run_id'], 'diag_observation_run');
            $t->index(['company_id', 'source', 'business_date', 'collected_at'], 'diag_observation_lookup');
            $t->index('collected_at', 'diag_observation_retention');
        });
    }
    public function down(): void { Schema::dropIfExists('diagnostic_source_observations'); }
};

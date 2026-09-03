<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('agent_core_aggregate_mappings', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('company_id');
            $table->unsignedBigInteger('branch_id');
            $table->string('local_type', 32);
            $table->string('local_aggregate_id', 128);
            $table->string('cloud_type', 64);
            $table->unsignedBigInteger('cloud_id');
            $table->json('metadata')->nullable();
            $table->timestamps();
            $table->unique(
                ['company_id', 'branch_id', 'local_type', 'local_aggregate_id'],
                'agent_core_aggregate_local_unique'
            );
            $table->index(
                ['company_id', 'branch_id', 'cloud_type', 'cloud_id'],
                'agent_core_aggregate_cloud_index'
            );
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('agent_core_aggregate_mappings');
    }
};
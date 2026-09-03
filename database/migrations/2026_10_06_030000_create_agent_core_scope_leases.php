<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('agent_core_scope_leases', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('company_id')->index();
            $table->string('device_uid', 64);
            $table->unsignedBigInteger('branch_id');
            $table->unsignedBigInteger('user_id');
            $table->string('token_hash', 64)->unique();
            $table->uuid('nonce')->unique();
            $table->json('allowed_actions');
            $table->string('permission_version', 64);
            $table->timestamp('expires_at')->index();
            $table->timestamp('revoked_at')->nullable();
            $table->timestamps();
            $table->index(['company_id', 'device_uid', 'user_id'], 'agent_core_lease_scope');
        });
    }
    public function down(): void { Schema::dropIfExists('agent_core_scope_leases'); }
};
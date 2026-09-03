<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::table('agent_core_scope_leases', function (Blueprint $table): void {
            $table->text('signing_secret')->nullable();
            $table->unsignedBigInteger('last_sequence')->default(0);
            $table->string('last_chain_hash', 64)->nullable();
        });
        Schema::table('agent_core_events', function (Blueprint $table): void {
            $table->unsignedBigInteger('lease_id')->nullable()->index();
            $table->unsignedBigInteger('lease_sequence')->nullable();
            $table->string('lease_chain_hash', 64)->nullable();
            $table->unique(['lease_id', 'lease_sequence'], 'agent_core_lease_sequence_unique');
        });
    }
    public function down(): void
    {
        Schema::table('agent_core_events', function (Blueprint $table): void {
            $table->dropUnique('agent_core_lease_sequence_unique');
            $table->dropColumn(['lease_id', 'lease_sequence', 'lease_chain_hash']);
        });
        Schema::table('agent_core_scope_leases', fn (Blueprint $table) =>
            $table->dropColumn(['signing_secret', 'last_sequence', 'last_chain_hash']));
    }
};
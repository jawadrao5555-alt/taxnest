<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Live Ops diagnostics / remediation / agent-command audit tables.
 * No foreign keys (ops tables follow pos_print_jobs pattern) for deploy safety.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasTable('live_ops_audit_events')) {
            Schema::create('live_ops_audit_events', function (Blueprint $table) {
                $table->id();
                $table->string('event_type', 64);
                $table->string('requester', 120)->nullable();
                $table->unsignedBigInteger('admin_id')->nullable()->index();
                $table->unsignedBigInteger('company_id')->nullable()->index();
                $table->string('operation', 64)->nullable();
                $table->string('action_id', 64)->nullable()->index();
                $table->string('report_id', 64)->nullable()->index();
                $table->string('params_hash', 64)->nullable();
                $table->string('artifact_digest', 64)->nullable();
                $table->string('result_status', 32)->nullable();
                $table->json('metadata')->nullable();
                $table->timestamps();
                $table->index(['event_type', 'created_at']);
            });
        }

        if (!Schema::hasTable('live_ops_diagnostic_reports')) {
            Schema::create('live_ops_diagnostic_reports', function (Blueprint $table) {
                $table->id();
                $table->string('report_id', 64)->unique();
                $table->string('operation', 64);
                $table->unsignedBigInteger('company_id')->nullable()->index();
                $table->date('date_from')->nullable();
                $table->date('date_to')->nullable();
                $table->string('requester', 120)->nullable();
                $table->string('artifact_digest', 64);
                $table->unsignedInteger('result_count')->default(0);
                $table->json('summary')->nullable();
                $table->json('payload')->nullable();
                $table->timestamps();
            });
        }

        if (!Schema::hasTable('live_ops_remediation_requests')) {
            Schema::create('live_ops_remediation_requests', function (Blueprint $table) {
                $table->id();
                $table->string('action_id', 64)->unique();
                $table->string('action', 64);
                $table->string('risk', 16);
                $table->unsignedBigInteger('company_id')->index();
                $table->json('parameters');
                $table->string('parameters_hash', 64);
                $table->string('idempotency_key', 80)->unique();
                $table->string('requester', 120)->nullable();
                $table->text('proposal')->nullable();
                $table->json('evidence')->nullable();
                $table->string('status', 32)->default('proposed'); // proposed|approved|rejected|executed|failed|expired
                $table->string('approved_by', 120)->nullable();
                $table->timestamp('approved_at')->nullable();
                $table->string('owner_approval_phrase')->nullable();
                $table->json('execution_result')->nullable();
                $table->json('verification_result')->nullable();
                $table->timestamp('executed_at')->nullable();
                $table->timestamp('expires_at')->nullable();
                $table->timestamps();
                $table->index(['company_id', 'status']);
            });
        }

        if (!Schema::hasTable('live_ops_agent_commands')) {
            Schema::create('live_ops_agent_commands', function (Blueprint $table) {
                $table->id();
                $table->string('command_id', 64)->unique();
                $table->string('command_type', 40);
                $table->unsignedBigInteger('company_id')->index();
                $table->string('device_uid', 64)->nullable()->index();
                $table->json('payload')->nullable();
                $table->string('idempotency_key', 80)->unique();
                $table->string('status', 32)->default('pending'); // pending|acked|succeeded|failed|expired
                $table->timestamp('expires_at');
                $table->timestamp('acked_at')->nullable();
                $table->timestamp('completed_at')->nullable();
                $table->json('result')->nullable();
                $table->string('requested_by', 120)->nullable();
                $table->string('remediation_action_id', 64)->nullable()->index();
                $table->timestamps();
                $table->index(['company_id', 'status', 'expires_at']);
            });
        }

        // Optional force-update advertise stamp (heartbeat reads this).
        if (Schema::hasTable('companies') && !Schema::hasColumn('companies', 'agent_force_update_at')) {
            Schema::table('companies', function (Blueprint $table) {
                $table->timestamp('agent_force_update_at')->nullable()->after('agent_update_at');
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('live_ops_agent_commands');
        Schema::dropIfExists('live_ops_remediation_requests');
        Schema::dropIfExists('live_ops_diagnostic_reports');
        Schema::dropIfExists('live_ops_audit_events');

        if (Schema::hasTable('companies') && Schema::hasColumn('companies', 'agent_force_update_at')) {
            Schema::table('companies', function (Blueprint $table) {
                $table->dropColumn('agent_force_update_at');
            });
        }
    }
};

<?php

namespace Tests\Feature\LiveOps;

use Tests\TestCase;
use App\Models\AdminUser;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Schema;
use Illuminate\Database\Schema\Blueprint;

/**
 * Minimal schema harness for Live Ops unit/feature tests.
 */
abstract class LiveOpsTestCase extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        Schema::dropAllTables();
        $this->createLiveOpsSchema();
    }

    protected function createLiveOpsSchema(): void
    {
        Schema::create('admin_users', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('email')->unique();
            $table->string('password');
            $table->string('role')->default('super_admin');
            $table->rememberToken();
            $table->timestamps();
        });

        Schema::create('companies', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('owner_name')->nullable();
            $table->string('email')->nullable();
            $table->string('ntn')->nullable();
            $table->string('account_code')->nullable();
            $table->string('product_type')->nullable();
            $table->string('status')->default('approved');
            $table->string('company_status')->default('active');
            $table->boolean('agent_enabled')->default(true);
            $table->boolean('agent_submits_pra')->default(true);
            $table->boolean('pra_reporting_enabled')->default(true);
            $table->string('pra_environment')->nullable();
            $table->string('pra_pos_id')->nullable();
            $table->string('pra_connection_mode')->nullable();
            $table->string('agent_api_key')->nullable();
            $table->timestamp('agent_last_seen')->nullable();
            $table->string('agent_version')->nullable();
            $table->string('agent_update_target')->nullable();
            $table->string('agent_update_stage')->nullable();
            $table->text('agent_update_error')->nullable();
            $table->timestamp('agent_update_at')->nullable();
            $table->timestamp('agent_force_update_at')->nullable();
            $table->boolean('agent_offline_mode')->nullable();
            $table->text('pos_printer_settings')->nullable();
            $table->softDeletes();
            $table->timestamps();
        });

        Schema::create('pos_transactions', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('company_id');
            $table->string('status')->default('completed');
            $table->decimal('subtotal', 12, 2)->default(0);
            $table->decimal('tax_amount', 12, 2)->default(0);
            $table->decimal('total_amount', 12, 2)->default(0);
            $table->string('pra_status')->nullable();
            $table->string('pra_invoice_number')->nullable();
            $table->string('pra_response_code')->nullable();
            $table->text('pra_error_message')->nullable();
            $table->string('invoice_mode')->nullable();
            $table->date('business_date')->nullable();
            $table->string('transaction_type')->default('sale');
            $table->timestamps();
        });

        Schema::create('pos_print_jobs', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('company_id');
            $table->string('type')->default('test');
            $table->string('target_printer')->nullable();
            $table->string('status')->default('pending');
            $table->string('device_uid')->nullable();
            $table->string('claim_token')->nullable();
            $table->unsignedBigInteger('transaction_id')->nullable();
            $table->unsignedBigInteger('created_by')->nullable();
            $table->text('error')->nullable();
            $table->integer('attempts')->default(0);
            $table->timestamps();
        });

        Schema::create('pos_agent_devices', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('company_id');
            $table->string('device_uid');
            $table->string('hostname')->nullable();
            $table->string('name')->nullable();
            $table->string('agent_version')->nullable();
            $table->timestamp('last_seen_at')->nullable();
            $table->text('printers')->nullable();
            $table->timestamp('printers_reported_at')->nullable();
            $table->string('receipt_printer')->nullable();
            $table->timestamps();
        });

        Schema::create('pra_logs', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('company_id');
            $table->unsignedBigInteger('transaction_id')->nullable();
            $table->text('request_payload')->nullable();
            $table->text('response_payload')->nullable();
            $table->string('response_code')->nullable();
            $table->string('status')->nullable();
            $table->timestamps();
        });

        Schema::create('live_ops_audit_events', function (Blueprint $table) {
            $table->id();
            $table->string('event_type', 64);
            $table->string('requester', 120)->nullable();
            $table->unsignedBigInteger('admin_id')->nullable();
            $table->unsignedBigInteger('company_id')->nullable();
            $table->string('operation', 64)->nullable();
            $table->string('action_id', 64)->nullable();
            $table->string('report_id', 64)->nullable();
            $table->string('params_hash', 64)->nullable();
            $table->string('artifact_digest', 64)->nullable();
            $table->string('result_status', 32)->nullable();
            $table->json('metadata')->nullable();
            $table->timestamps();
        });

        Schema::create('live_ops_diagnostic_reports', function (Blueprint $table) {
            $table->id();
            $table->string('report_id', 64)->unique();
            $table->string('operation', 64);
            $table->unsignedBigInteger('company_id')->nullable();
            $table->date('date_from')->nullable();
            $table->date('date_to')->nullable();
            $table->string('requester', 120)->nullable();
            $table->string('artifact_digest', 64);
            $table->unsignedInteger('result_count')->default(0);
            $table->json('summary')->nullable();
            $table->json('payload')->nullable();
            $table->timestamps();
        });

        Schema::create('live_ops_remediation_requests', function (Blueprint $table) {
            $table->id();
            $table->string('action_id', 64)->unique();
            $table->string('action', 64);
            $table->string('risk', 16);
            $table->unsignedBigInteger('company_id');
            $table->json('parameters');
            $table->string('parameters_hash', 64);
            $table->string('idempotency_key', 80)->unique();
            $table->string('requester', 120)->nullable();
            $table->text('proposal')->nullable();
            $table->json('evidence')->nullable();
            $table->string('status', 32)->default('proposed');
            $table->string('approved_by', 120)->nullable();
            $table->timestamp('approved_at')->nullable();
            $table->string('owner_approval_phrase')->nullable();
            $table->json('execution_result')->nullable();
            $table->json('verification_result')->nullable();
            $table->timestamp('executed_at')->nullable();
            $table->timestamp('expires_at')->nullable();
            $table->timestamps();
        });

        Schema::create('live_ops_agent_commands', function (Blueprint $table) {
            $table->id();
            $table->string('command_id', 64)->unique();
            $table->string('command_type', 40);
            $table->unsignedBigInteger('company_id');
            $table->string('device_uid', 64)->nullable();
            $table->json('payload')->nullable();
            $table->string('idempotency_key', 80)->unique();
            $table->string('status', 32)->default('pending');
            $table->timestamp('expires_at');
            $table->timestamp('acked_at')->nullable();
            $table->timestamp('completed_at')->nullable();
            $table->json('result')->nullable();
            $table->string('requested_by', 120)->nullable();
            $table->string('remediation_action_id', 64)->nullable();
            $table->timestamps();
        });

        Schema::create('admin_audit_logs', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('admin_id')->nullable();
            $table->string('action');
            $table->string('target_type')->nullable();
            $table->unsignedBigInteger('target_id')->nullable();
            $table->json('metadata')->nullable();
            $table->timestamps();
        });

        DB::table('admin_users')->insert([
            'name' => 'Live Ops Admin',
            'email' => 'liveops-admin@taxnest.test',
            'password' => Hash::make('LiveOps@12345'),
            'role' => 'super_admin',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    protected function actingAsAdmin(): self
    {
        return $this->actingAs(AdminUser::first(), 'admin');
    }

    protected function makeCompany(string $name, array $attrs = []): int
    {
        return (int) DB::table('companies')->insertGetId(array_merge([
            'name' => $name,
            'product_type' => 'pos',
            'status' => 'approved',
            'company_status' => 'active',
            'agent_enabled' => true,
            'agent_last_seen' => now()->subSeconds(30),
            'agent_version' => '1.13.2',
            'agent_api_key' => 'test-agent-key-'.uniqid(),
            'pos_printer_settings' => json_encode([
                'silent_print_enabled' => true,
                'receipt_printer' => 'XP-80',
                'available_printers' => [
                    ['name' => 'XP-80', 'displayName' => 'XP-80', 'isDefault' => true],
                ],
                'printers_reported_at' => now()->toIso8601String(),
            ]),
            'created_at' => now(),
            'updated_at' => now(),
        ], $attrs));
    }

    protected function makeTxn(int $companyId, array $attrs = []): int
    {
        return (int) DB::table('pos_transactions')->insertGetId(array_merge([
            'company_id' => $companyId,
            'status' => 'completed',
            'subtotal' => 100,
            'tax_amount' => 16,
            'total_amount' => 116,
            'pra_status' => 'submitted',
            'pra_invoice_number' => 'PRA-'.uniqid(),
            'business_date' => now()->toDateString(),
            'created_at' => now(),
            'updated_at' => now(),
        ], $attrs));
    }
}

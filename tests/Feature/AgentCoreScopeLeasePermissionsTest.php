<?php

namespace Tests\Feature;

use App\Models\Company;
use App\Models\User;
use App\Services\AgentCoreScopeLeaseService;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class AgentCoreScopeLeasePermissionsTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        Schema::dropAllTables();
        Schema::create('companies', function (Blueprint $t): void {
            $t->id(); $t->string('name'); $t->boolean('is_internal_account')->default(true);
            $t->boolean('pos_cashier_dayclose')->default(false);
            $t->boolean('pos_cashier_order_cancel')->default(false);
            $t->softDeletes(); $t->timestamps();
        });
        Schema::create('users', function (Blueprint $t): void {
            $t->id(); $t->unsignedBigInteger('company_id'); $t->string('name');
            $t->string('role')->nullable(); $t->string('pos_role')->nullable();
            $t->json('pos_custom_access')->nullable(); $t->boolean('is_active')->default(true);
            $t->timestamps();
        });
        Schema::create('pos_agent_devices', function (Blueprint $t): void {
            $t->id(); $t->unsignedBigInteger('company_id'); $t->string('device_uid'); $t->timestamps();
        });
        Schema::create('agent_core_scope_leases', function (Blueprint $t): void {
            $t->id(); $t->unsignedBigInteger('company_id'); $t->string('device_uid');
            $t->unsignedBigInteger('branch_id'); $t->unsignedBigInteger('user_id');
            $t->string('token_hash'); $t->uuid('nonce'); $t->json('allowed_actions');
            $t->string('permission_version'); $t->timestamp('expires_at');
            $t->text('signing_secret'); $t->unsignedBigInteger('last_sequence')->default(0);
            $t->timestamps();
        });

        DB::table('companies')->insert(['id' => 1, 'name' => 'Shop', 'is_internal_account' => true,
            'created_at' => now(), 'updated_at' => now()]);
        DB::table('pos_agent_devices')->insert(['company_id' => 1, 'device_uid' => 'desk-1',
            'created_at' => now(), 'updated_at' => now()]);
    }

    public function test_restricted_cashier_lease_excludes_forbidden_privileged_actions(): void
    {
        $user = User::create(['company_id' => 1, 'name' => 'Cashier', 'role' => 'user',
            'pos_role' => 'pos_cashier', 'is_active' => true]);
        $user->forceFill(['pos_custom_access' => ['orders']])->save();

        $lease = app(AgentCoreScopeLeaseService::class)->issue(Company::findOrFail(1), $user, 1, 'desk-1');

        foreach (['cash.close', 'cash.expense', 'refund.record', 'khata.debit', 'wasooli.record',
            'order.cancel', 'stock.set', 'stock.adjust', 'customer.upsert', 'table.shift'] as $action) {
            $this->assertNotContains($action, $lease['allowed_actions'], $action);
        }
        $this->assertContains('order.open', $lease['allowed_actions']);
        $this->assertContains('order.settle', $lease['allowed_actions']);
    }

    public function test_company_admin_lease_retains_all_supported_actions(): void
    {
        $user = User::create(['company_id' => 1, 'name' => 'Owner', 'role' => 'company_admin',
            'pos_role' => 'pos_admin', 'is_active' => true]);

        $lease = app(AgentCoreScopeLeaseService::class)->issue(Company::findOrFail(1), $user, 1, 'desk-1');

        $this->assertSame(AgentCoreScopeLeaseService::SUPPORTED_ACTIONS, $lease['allowed_actions']);
    }
}
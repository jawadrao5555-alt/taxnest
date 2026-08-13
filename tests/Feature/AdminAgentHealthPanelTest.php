<?php

namespace Tests\Feature;

use Tests\TestCase;
use App\Models\AdminUser;
use App\Models\Company;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Schema;
use Illuminate\Database\Schema\Blueprint;

/**
 * Task #629 — Admin Agent Health panel on /admin/companies.
 *
 * Silent-print shops whose Desktop Agent has been offline for hours must
 * surface in the red "Agent Health" panel (and get the row badge); shops
 * without silent print, with a recent heartbeat, or with the agent disabled
 * must NOT appear. Uses the minimal-schema pattern (AdminPagesSmokeTest).
 */
class AdminAgentHealthPanelTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        Schema::dropAllTables();

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
            $table->string('ntn')->nullable();
            $table->string('product_type')->nullable();
            $table->string('status')->default('approved');
            $table->string('company_status')->default('active');
            $table->unsignedBigInteger('franchise_id')->nullable();
            $table->unsignedBigInteger('requested_plan_id')->nullable();
            $table->boolean('agent_enabled')->default(false);
            $table->timestamp('agent_last_seen')->nullable();
            $table->string('agent_version')->nullable();
            $table->text('pos_printer_settings')->nullable();
            $table->softDeletes();
            $table->timestamps();
        });

        Schema::create('franchises', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->timestamps();
        });

        Schema::create('pricing_plans', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('product_type')->default('di');
            $table->decimal('price', 12, 2)->default(0);
            $table->timestamps();
        });

        Schema::create('subscriptions', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('company_id');
            $table->unsignedBigInteger('pricing_plan_id')->nullable();
            $table->date('end_date')->nullable();
            $table->timestamp('trial_ends_at')->nullable();
            $table->boolean('active')->default(true);
            $table->string('override_type')->nullable();
            $table->timestamp('override_until')->nullable();
            $table->timestamp('override_granted_at')->nullable();
            $table->integer('free_invoice_limit')->nullable();
            $table->text('override_reason')->nullable();
            $table->unsignedBigInteger('override_by')->nullable();
            $table->timestamps();
        });

        DB::table('admin_users')->insert([
            'name' => 'Panel Smoke Admin',
            'email' => 'panel-smoke-admin@taxnest.test',
            'password' => Hash::make('Smoke@12345'),
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
        return DB::table('companies')->insertGetId(array_merge([
            'name' => $name,
            'product_type' => 'pos',
            'status' => 'approved',
            'company_status' => 'active',
            'created_at' => now(),
            'updated_at' => now(),
        ], $attrs));
    }

    protected function silentPrintJson(bool $on = true): string
    {
        return json_encode(['silent_print_enabled' => $on]);
    }

    /** Stale silent-print agent (>2h) shows in the panel + row badge. */
    public function test_long_offline_silent_print_company_shows_in_panel(): void
    {
        $this->makeCompany('Frost and Brew', [
            'agent_enabled' => true,
            'agent_last_seen' => now()->subHours(6),
            'pos_printer_settings' => $this->silentPrintJson(),
        ]);

        $response = $this->actingAsAdmin()->get('/admin/companies');

        $response->assertStatus(200);
        $response->assertSee('Agent Health');
        $response->assertSee('Frost and Brew');
        $response->assertSee('Agent Offline');
    }

    /** Recent heartbeat (within 2h) — no panel, no badge. */
    public function test_recently_seen_agent_not_flagged(): void
    {
        $this->makeCompany('Fresh Beat Cafe', [
            'agent_enabled' => true,
            'agent_last_seen' => now()->subMinutes(30),
            'pos_printer_settings' => $this->silentPrintJson(),
        ]);

        $response = $this->actingAsAdmin()->get('/admin/companies');

        $response->assertStatus(200);
        $response->assertDontSee('Agent Health');
        $response->assertDontSee('Agent Offline');
    }

    /** Silent print OFF — an offline agent has no cashier impact; never flagged. */
    public function test_silent_print_disabled_company_not_flagged(): void
    {
        $this->makeCompany('Popup Printer Shop', [
            'agent_enabled' => true,
            'agent_last_seen' => now()->subDays(3),
            'pos_printer_settings' => $this->silentPrintJson(false),
        ]);

        $response = $this->actingAsAdmin()->get('/admin/companies');

        $response->assertStatus(200);
        $response->assertDontSee('Agent Health');
        $response->assertDontSee('Agent Offline');
    }

    /** Agent disabled entirely — never flagged regardless of last seen. */
    public function test_agent_disabled_company_not_flagged(): void
    {
        $this->makeCompany('No Agent Mart', [
            'agent_enabled' => false,
            'agent_last_seen' => now()->subDays(5),
            'pos_printer_settings' => $this->silentPrintJson(),
        ]);

        $response = $this->actingAsAdmin()->get('/admin/companies');

        $response->assertStatus(200);
        $response->assertDontSee('Agent Health');
        $response->assertDontSee('Agent Offline');
    }

    /** Silent print ON but agent NEVER connected — counts as long-offline. */
    public function test_never_seen_agent_with_silent_print_flagged(): void
    {
        $this->makeCompany('Ghost Agent Foods', [
            'agent_enabled' => true,
            'agent_last_seen' => null,
            'pos_printer_settings' => $this->silentPrintJson(),
        ]);

        $response = $this->actingAsAdmin()->get('/admin/companies');

        $response->assertStatus(200);
        $response->assertSee('Agent Health');
        $response->assertSee('Agent never connected');
    }

    /** Model helper edge: exactly-at / just-inside the 2h threshold. */
    public function test_agent_long_offline_threshold_boundaries(): void
    {
        $company = new Company([
            'agent_enabled' => true,
            'pos_printer_settings' => ['silent_print_enabled' => true],
        ]);

        $company->agent_last_seen = now()->subHours(2)->addMinute(); // just inside
        $this->assertFalse($company->agentLongOffline());

        $company->agent_last_seen = now()->subHours(2)->subMinute(); // just past
        $this->assertTrue($company->agentLongOffline());
    }
}

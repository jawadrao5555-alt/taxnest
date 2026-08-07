<?php

namespace Tests\Feature;

use App\Http\Controllers\PosController;
use App\Models\AuditLog;
use App\Models\Company;
use App\Models\User;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

/**
 * POS Billing-Scope Audit Log invariants (Task #328 / #329).
 *
 * Guards that AuditLogService::log() is always called for billing-scope
 * mutations so a future refactor cannot silently drop the audit trail:
 *
 *   1. storeCashier  — creating a cashier WITH a scope writes an audit row
 *                       (action = pos_billing_scope_set, old = null).
 *   2. updateCashier — scope CHANGE writes a row; a no-change update does NOT.
 *   3. setBillingScopePermission — toggle always writes a row with old/new.
 *
 * Pattern: sqlite :memory: + minimal Schema::create + controller invoked
 * directly with a crafted Request (no HTTP routing overhead — same approach
 * as PosRiderSettleInvariantTest / PosDayCloseAutoFinalizeTest).
 *
 * Run with:
 *   APP_ENV=testing DB_CONNECTION=sqlite DB_DATABASE=:memory: \
 *     php vendor/bin/phpunit --filter=PosBillingScopeAuditTest
 */
class PosBillingScopeAuditTest extends TestCase
{
    // ── Constants ─────────────────────────────────────────────────────────

    private const COMPANY_ID = 42;

    // ── setUp ─────────────────────────────────────────────────────────────

    protected function setUp(): void
    {
        parent::setUp();

        Schema::dropAllTables();

        // companies — keep softDeletes (companies model uses it)
        Schema::create('companies', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('email')->nullable();
            $table->string('status')->default('approved');
            $table->string('company_status')->default('active');
            $table->boolean('is_internal_account')->default(false);
            $table->string('product_type')->nullable();
            // Billing scope permission switch (07 Aug 2026)
            $table->boolean('billing_scope_admin_enabled')->default(false);
            $table->softDeletes();
            $table->timestamps();
        });

        // users — minimal + every billing-scope column
        Schema::create('users', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('email')->unique();
            $table->string('password');
            $table->unsignedBigInteger('company_id')->nullable();
            $table->string('role')->default('user');
            $table->string('pos_role')->nullable();
            $table->boolean('is_active')->default(true);
            $table->boolean('pra_reporting_enabled')->default(true);
            // Billing scope stream lock
            $table->string('pos_billing_scope')->nullable();
            // Admin-viewable encrypted password copy
            $table->text('pos_team_password_enc')->nullable();
            // Custom-access JSON (needed by isPosCashier / posCustomAllows checks)
            $table->text('pos_custom_access')->nullable();
            $table->string('phone')->nullable();
            $table->rememberToken();
            $table->timestamps();
        });

        // audit_logs — exact production schema
        Schema::create('audit_logs', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('company_id')->nullable();
            $table->unsignedBigInteger('user_id')->nullable();
            $table->string('action');
            $table->string('entity_type');
            $table->unsignedBigInteger('entity_id')->nullable();
            $table->json('old_values')->nullable();
            $table->json('new_values')->nullable();
            $table->string('ip_address')->nullable();
            $table->string('sha256_hash');
            $table->timestamp('created_at')->nullable();
        });

        // Flush User's static pos_billing_scope column-existence cache so the
        // freshly-created in-memory schema is re-detected correctly.
        User::flushScopeColumnCache();
    }

    // ── Fixture helpers ───────────────────────────────────────────────────

    /** Create a company that bypasses plan-limit checks (is_internal_account). */
    private function makeCompany(array $attrs = []): Company
    {
        return Company::forceCreate(array_merge([
            'id'                  => self::COMPANY_ID,
            'name'                => 'Test Shop',
            'is_internal_account' => true,   // skips PlanLimitService quota
        ], $attrs));
    }

    /** Create a User and bind it as the active pos-guard principal. */
    private function loginAs(array $attrs): User
    {
        $user = User::forceCreate(array_merge([
            'name'       => 'Test User',
            'email'      => 'user_' . uniqid() . '@test.test',
            'password'   => bcrypt('secret'),
            'company_id' => self::COMPANY_ID,
            'role'       => 'company_admin',
            'pos_role'   => 'pos_admin',
            'is_active'  => true,
        ], $attrs));

        auth('pos')->setUser($user);
        return $user;
    }

    /** Bind the test company into the IoC container (middleware normally does this). */
    private function bindCompany(): void
    {
        app()->bind('currentCompanyId', fn () => self::COMPANY_ID);
    }

    /** Build a Request with form-data, headers, and server info needed by the controller. */
    private function makeRequest(string $method, array $data = []): Request
    {
        $request = Request::create('/test', $method, $data);
        // AuditLogService calls request()->ip(); satisfy it.
        $request->server->set('REMOTE_ADDR', '127.0.0.1');
        // back() calls url()->previous(); set a referrer so redirect()->back() works.
        $request->headers->set('referer', 'http://localhost/pos/team');
        app()->instance('request', $request);

        return $request;
    }

    // ══════════════════════════════════════════════════════════════════════
    // 1. storeCashier — billing scope set at creation writes an audit row
    // ══════════════════════════════════════════════════════════════════════

    public function test_storeCashier_writes_audit_log_when_billing_scope_set(): void
    {
        $this->makeCompany();
        $owner = $this->loginAs(['role' => 'company_admin', 'pos_role' => 'pos_admin']);
        $this->bindCompany();

        $this->assertSame(0, AuditLog::count(), 'No audit rows before the request');

        $request = $this->makeRequest('POST', [
            'name'               => 'New Cashier',
            'email'              => 'cashier_' . uniqid() . '@shop.test',
            'phone'              => '',
            'password'           => 'secret123',
            'pos_role'           => 'pos_cashier',
            'pos_billing_scope'  => 'pra',
        ]);

        $controller = new PosController();
        $controller->storeCashier($request);

        $this->assertSame(1, AuditLog::count(), 'Expected exactly 1 audit_logs row after storeCashier');

        $log = AuditLog::first();
        $this->assertSame('pos_billing_scope_set', $log->action);
        $this->assertSame('User', $log->entity_type);
        $this->assertNull($log->old_values, 'old_values must be null (scope set at creation, no prior value)');
        $this->assertSame('pra', $log->new_values['pos_billing_scope'] ?? null);
        $this->assertSame(self::COMPANY_ID, (int) $log->company_id);
        $this->assertSame($owner->id, (int) $log->user_id);
    }

    public function test_storeCashier_does_NOT_write_audit_log_when_no_scope_provided(): void
    {
        $this->makeCompany();
        $this->loginAs(['role' => 'company_admin', 'pos_role' => 'pos_admin']);
        $this->bindCompany();

        $request = $this->makeRequest('POST', [
            'name'     => 'Plain Cashier',
            'email'    => 'plain_' . uniqid() . '@shop.test',
            'phone'    => '',
            'password' => 'secret123',
            'pos_role' => 'pos_cashier',
            // pos_billing_scope intentionally absent
        ]);

        $controller = new PosController();
        $controller->storeCashier($request);

        $this->assertSame(0, AuditLog::count(), 'No audit row when no scope is passed');
    }

    // ══════════════════════════════════════════════════════════════════════
    // 2. updateCashier — scope change writes an audit row; no-change does not
    // ══════════════════════════════════════════════════════════════════════

    public function test_updateCashier_writes_audit_log_when_scope_changes(): void
    {
        $this->makeCompany();
        $owner = $this->loginAs(['role' => 'company_admin', 'pos_role' => 'pos_admin']);
        $this->bindCompany();

        // Existing cashier starting on 'local' scope
        $cashier = User::forceCreate([
            'name'               => 'Cashier One',
            'email'              => 'c1_' . uniqid() . '@shop.test',
            'password'           => bcrypt('pass'),
            'company_id'         => self::COMPANY_ID,
            'role'               => 'user',
            'pos_role'           => 'pos_cashier',
            'pos_billing_scope'  => 'local',
            'is_active'          => true,
        ]);

        $request = $this->makeRequest('PUT', [
            'name'              => $cashier->name,
            'email'             => $cashier->email,
            'pos_billing_scope' => 'pra',   // changed from 'local'
        ]);

        $controller = new PosController();
        $controller->updateCashier($request, $cashier->id);

        $this->assertSame(1, AuditLog::count(), 'Expected 1 audit row for scope change');

        $log = AuditLog::first();
        $this->assertSame('pos_billing_scope_changed', $log->action);
        $this->assertSame('User', $log->entity_type);
        $this->assertSame($cashier->id, (int) $log->entity_id);
        $this->assertSame('local', $log->old_values['pos_billing_scope'] ?? null);
        $this->assertSame('pra', $log->new_values['pos_billing_scope'] ?? null);
        $this->assertSame(self::COMPANY_ID, (int) $log->company_id);
        $this->assertSame($owner->id, (int) $log->user_id);
    }

    public function test_updateCashier_does_NOT_write_audit_log_when_scope_unchanged(): void
    {
        $this->makeCompany();
        $this->loginAs(['role' => 'company_admin', 'pos_role' => 'pos_admin']);
        $this->bindCompany();

        $cashier = User::forceCreate([
            'name'               => 'Cashier Two',
            'email'              => 'c2_' . uniqid() . '@shop.test',
            'password'           => bcrypt('pass'),
            'company_id'         => self::COMPANY_ID,
            'role'               => 'user',
            'pos_role'           => 'pos_cashier',
            'pos_billing_scope'  => 'pra',  // same as what we will submit
            'is_active'          => true,
        ]);

        $request = $this->makeRequest('PUT', [
            'name'              => $cashier->name,
            'email'             => $cashier->email,
            'pos_billing_scope' => 'pra',   // same value — no change
        ]);

        $controller = new PosController();
        $controller->updateCashier($request, $cashier->id);

        $this->assertSame(0, AuditLog::count(), 'No audit row when scope value did not change');
    }

    // ══════════════════════════════════════════════════════════════════════
    // 3. setBillingScopePermission — ALWAYS writes an audit row with old/new
    // ══════════════════════════════════════════════════════════════════════

    public function test_setBillingScopePermission_writes_audit_log_when_enabling(): void
    {
        $company = $this->makeCompany(['billing_scope_admin_enabled' => false]);
        $owner   = $this->loginAs(['role' => 'company_admin', 'pos_role' => 'pos_admin']);
        $this->bindCompany();

        $request = $this->makeRequest('POST', ['enabled' => '1']);

        $controller = new PosController();
        $controller->setBillingScopePermission($request);

        $this->assertSame(1, AuditLog::count(), 'Expected 1 audit row for permission enable');

        $log = AuditLog::first();
        $this->assertSame('pos_billing_scope_permission_toggled', $log->action);
        $this->assertSame('Company', $log->entity_type);
        $this->assertSame(self::COMPANY_ID, (int) $log->entity_id);
        $this->assertFalse($log->old_values['billing_scope_admin_enabled'] ?? null, 'old_values must reflect previous OFF state');
        $this->assertTrue($log->new_values['billing_scope_admin_enabled'] ?? null, 'new_values must reflect new ON state');
        $this->assertSame(self::COMPANY_ID, (int) $log->company_id);
        $this->assertSame($owner->id, (int) $log->user_id);
    }

    public function test_setBillingScopePermission_writes_audit_log_when_disabling(): void
    {
        $this->makeCompany(['billing_scope_admin_enabled' => true]);
        $owner = $this->loginAs(['role' => 'company_admin', 'pos_role' => 'pos_admin']);
        $this->bindCompany();

        $request = $this->makeRequest('POST', ['enabled' => '0']);

        $controller = new PosController();
        $controller->setBillingScopePermission($request);

        $this->assertSame(1, AuditLog::count(), 'Expected 1 audit row for permission disable');

        $log = AuditLog::first();
        $this->assertSame('pos_billing_scope_permission_toggled', $log->action);
        $this->assertTrue($log->old_values['billing_scope_admin_enabled'] ?? null);
        $this->assertFalse($log->new_values['billing_scope_admin_enabled'] ?? null);
    }

    public function test_setBillingScopePermission_writes_audit_log_even_when_value_unchanged(): void
    {
        // The controller unconditionally logs the toggle — this is intentional:
        // permission grant/revoke is security-relevant and every invocation must
        // be on record, even a redundant re-enable.
        $this->makeCompany(['billing_scope_admin_enabled' => true]);
        $this->loginAs(['role' => 'company_admin', 'pos_role' => 'pos_admin']);
        $this->bindCompany();

        // Submit the same value (true → true)
        $request = $this->makeRequest('POST', ['enabled' => '1']);

        $controller = new PosController();
        $controller->setBillingScopePermission($request);

        $this->assertSame(1, AuditLog::count(), 'Audit row written even when old == new (security invariant)');

        $log = AuditLog::first();
        $this->assertTrue($log->old_values['billing_scope_admin_enabled'] ?? null);
        $this->assertTrue($log->new_values['billing_scope_admin_enabled'] ?? null);
    }
}

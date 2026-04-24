<?php

namespace Tests\Feature;

use Tests\TestCase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Schema;
use Illuminate\Database\Schema\Blueprint;

/**
 * PHASE 3 — LOGIN ISOLATION (ADMIN-UNIFIED + COMPANY-STRICT)
 *
 * Six spec scenarios:
 *   1. Admin credentials on /pos/login   → PASS → /admin/dashboard
 *   2. Admin credentials on /login       → PASS → /admin/dashboard
 *   3. POS user on /login                → FAIL → "Invalid credentials" (no redirect, no info leak)
 *   4. FBR user on /pos/login            → FAIL → "Invalid credentials"
 *   5. DI user on /fbr-pos/login         → FAIL → "Invalid credentials"
 *   6. Correct credentials on each panel → PASS
 */
class Phase3LoginIsolationTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        // Build minimal schema needed for auth-isolation tests.
        Schema::dropAllTables();

        Schema::create('companies', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('ntn')->nullable();
            $table->string('cnic')->nullable();
            $table->string('email')->nullable();
            $table->string('phone')->nullable();
            $table->string('product_type')->nullable();
            $table->boolean('fbr_pos_enabled')->default(false);
            $table->boolean('restaurant_mode')->default(false);
            $table->string('fbr_registration_no')->nullable();
            $table->string('company_status')->default('approved');
            $table->softDeletes();
            $table->timestamps();
        });

        Schema::create('users', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('email')->unique();
            $table->string('phone')->nullable();
            $table->string('username')->nullable();
            $table->string('password');
            $table->unsignedBigInteger('company_id')->nullable();
            $table->string('role')->nullable();
            $table->string('pos_role')->nullable();
            $table->boolean('is_active')->default(true);
            $table->boolean('dark_mode')->default(false);
            $table->rememberToken();
            $table->timestamps();
        });

        Schema::create('admin_users', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('email')->unique();
            $table->string('password');
            $table->string('role')->default('super_admin');
            $table->rememberToken();
            $table->timestamps();
        });

        // Side-effect tables (writes during login flow)
        Schema::create('security_logs', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('user_id')->nullable();
            $table->string('action');
            $table->string('ip_address', 45)->nullable();
            $table->text('user_agent')->nullable();
            $table->text('metadata')->nullable();
            $table->timestamp('created_at')->nullable();
        });
        Schema::create('audit_logs', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('user_id')->nullable();
            $table->unsignedBigInteger('company_id')->nullable();
            $table->string('action');
            $table->string('entity_type')->nullable();
            $table->unsignedBigInteger('entity_id')->nullable();
            $table->text('changes')->nullable();
            $table->text('metadata')->nullable();
            $table->string('ip_address', 45)->nullable();
            $table->text('user_agent')->nullable();
            $table->string('hash')->nullable();
            $table->string('previous_hash')->nullable();
            $table->timestamps();
        });
        Schema::create('admin_audit_logs', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('admin_user_id')->nullable();
            $table->string('action');
            $table->text('metadata')->nullable();
            $table->string('ip_address', 45)->nullable();
            $table->timestamps();
        });

        // Seed test fixtures
        $this->seedFixtures();
    }

    private function seedFixtures(): void
    {
        // ── Admin (universal) ──
        DB::table('admin_users')->insert([
            'name' => 'Super Admin',
            'email' => 'admin@taxnest.test',
            'password' => Hash::make('Admin@12345'),
            'role' => 'super_admin',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        // ── DI company + user ──
        $diId = DB::table('companies')->insertGetId([
            'name' => 'DI Co', 'product_type' => 'di',
            'created_at' => now(), 'updated_at' => now(),
        ]);
        DB::table('users')->insert([
            'name' => 'DI User', 'email' => 'di@taxnest.test',
            'password' => Hash::make('DI@12345'),
            'company_id' => $diId, 'role' => 'company_admin', 'is_active' => true,
            'created_at' => now(), 'updated_at' => now(),
        ]);

        // ── POS company + user ──
        $posId = DB::table('companies')->insertGetId([
            'name' => 'POS Co', 'product_type' => 'pos',
            'created_at' => now(), 'updated_at' => now(),
        ]);
        DB::table('users')->insert([
            'name' => 'POS User', 'email' => 'pos@taxnest.test',
            'password' => Hash::make('POS@12345'),
            'company_id' => $posId, 'role' => 'company_admin', 'pos_role' => 'pos_admin', 'is_active' => true,
            'created_at' => now(), 'updated_at' => now(),
        ]);

        // ── FBR POS company + user ──
        $fbrId = DB::table('companies')->insertGetId([
            'name' => 'FBR POS Co', 'product_type' => 'fbrpos',
            'fbr_pos_enabled' => true,
            'created_at' => now(), 'updated_at' => now(),
        ]);
        DB::table('users')->insert([
            'name' => 'FBR User', 'email' => 'fbr@taxnest.test',
            'password' => Hash::make('FBR@12345'),
            'company_id' => $fbrId, 'role' => 'company_admin', 'is_active' => true,
            'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    // ════════════════════════════════════════════════════════════════════
    // ADMIN UNIVERSAL — Tests 1 & 2
    // ════════════════════════════════════════════════════════════════════

    /** Test 1: Admin on /pos/login → /admin/dashboard */
    public function test_admin_can_login_via_pos_panel(): void
    {
        $response = $this->post('/pos/login', [
            'login' => 'admin@taxnest.test',
            'password' => 'Admin@12345',
        ]);

        $response->assertRedirect('/admin/dashboard');
        $this->assertTrue(auth('admin')->check(), 'Admin guard must be authenticated');
        $this->assertFalse(auth('pos')->check(), 'POS guard must NOT be authenticated');
    }

    /** Test 2: Admin on /login → /admin/dashboard */
    public function test_admin_can_login_via_di_panel(): void
    {
        $response = $this->post('/login', [
            'login' => 'admin@taxnest.test',
            'password' => 'Admin@12345',
        ]);

        $response->assertRedirect('/admin/dashboard');
        $this->assertTrue(auth('admin')->check());
        $this->assertFalse(auth('web')->check(), 'Web guard must NOT be authenticated');
    }

    /** Bonus: Admin on /fbr-pos/login → /admin/dashboard */
    public function test_admin_can_login_via_fbr_pos_panel(): void
    {
        $response = $this->post('/fbr-pos/login', [
            'login' => 'admin@taxnest.test',
            'password' => 'Admin@12345',
        ]);

        $response->assertRedirect('/admin/dashboard');
        $this->assertTrue(auth('admin')->check());
        $this->assertFalse(auth('fbrpos')->check(), 'FBR POS guard must NOT be authenticated');
    }

    // ════════════════════════════════════════════════════════════════════
    // CROSS-PANEL ISOLATION — Tests 3, 4, 5
    // ════════════════════════════════════════════════════════════════════

    /** Test 3: POS user on /login → FAIL "Invalid credentials" (no redirect to /pos/login) */
    public function test_pos_user_blocked_on_di_login(): void
    {
        $response = $this->from('/login')->post('/login', [
            'login' => 'pos@taxnest.test',
            'password' => 'POS@12345',
        ]);

        $response->assertSessionHasErrors('login');
        $response->assertRedirect('/login');           // back, NOT /pos/login
        $this->assertFalse(auth('web')->check());
        $this->assertFalse(auth('pos')->check());
        $this->assertFalse(auth('admin')->check());
        // Generic error — must NOT mention "POS" / "NestPOS" / portal hint
        $errors = session('errors')->get('login');
        $this->assertNotEmpty($errors);
        $msg = strtolower(implode(' ', $errors));
        $this->assertStringNotContainsString('nestpos', $msg, 'Info leak: must not mention NestPOS');
        $this->assertStringNotContainsString('portal', $msg, 'Info leak: must not hint at other portal');
        $this->assertStringNotContainsString('pos account', $msg, 'Info leak: must not reveal account type');
    }

    /** Test 4: FBR user on /pos/login → FAIL "Invalid credentials" */
    public function test_fbr_user_blocked_on_pos_login(): void
    {
        $response = $this->from('/pos/login')->post('/pos/login', [
            'login' => 'fbr@taxnest.test',
            'password' => 'FBR@12345',
        ]);

        $response->assertSessionHasErrors('login');
        $response->assertRedirect('/pos/login');       // back, NOT /fbr-pos/login
        $this->assertFalse(auth('pos')->check());
        $this->assertFalse(auth('fbrpos')->check());
        $this->assertFalse(auth('admin')->check());
        $errors = session('errors')->get('login');
        $msg = strtolower(implode(' ', $errors));
        $this->assertStringNotContainsString('fbr pos account', $msg, 'Info leak');
        $this->assertStringNotContainsString('digital invoice', $msg, 'Info leak');
        $this->assertStringContainsString('invalid', $msg, 'Should be generic "Invalid credentials"');
    }

    /** Test 5: DI user on /fbr-pos/login → FAIL "Invalid credentials" */
    public function test_di_user_blocked_on_fbr_pos_login(): void
    {
        $response = $this->from('/fbr-pos/login')->post('/fbr-pos/login', [
            'login' => 'di@taxnest.test',
            'password' => 'DI@12345',
        ]);

        $response->assertSessionHasErrors('login');
        $response->assertRedirect('/fbr-pos/login');   // back, NOT /login
        $this->assertFalse(auth('fbrpos')->check());
        $this->assertFalse(auth('web')->check());
        $this->assertFalse(auth('admin')->check());
        $errors = session('errors')->get('login');
        $msg = strtolower(implode(' ', $errors));
        $this->assertStringNotContainsString('digital invoice', $msg, 'Info leak');
        $this->assertStringNotContainsString('portal', $msg, 'Info leak');
        $this->assertStringContainsString('invalid', $msg, 'Should be generic "Invalid credentials"');
    }

    /** Bonus: POS user on /fbr-pos/login → FAIL "Invalid credentials" */
    public function test_pos_user_blocked_on_fbr_pos_login(): void
    {
        $response = $this->from('/fbr-pos/login')->post('/fbr-pos/login', [
            'login' => 'pos@taxnest.test',
            'password' => 'POS@12345',
        ]);

        $response->assertSessionHasErrors('login');
        $this->assertFalse(auth('fbrpos')->check());
        $this->assertFalse(auth('pos')->check());
    }

    /** Bonus: FBR user on /login → FAIL "Invalid credentials" */
    public function test_fbr_user_blocked_on_di_login(): void
    {
        $response = $this->from('/login')->post('/login', [
            'login' => 'fbr@taxnest.test',
            'password' => 'FBR@12345',
        ]);

        $response->assertSessionHasErrors('login');
        $this->assertFalse(auth('web')->check());
        $this->assertFalse(auth('fbrpos')->check());
    }

    /** Bonus: DI user on /pos/login → FAIL "Invalid credentials" */
    public function test_di_user_blocked_on_pos_login(): void
    {
        $response = $this->from('/pos/login')->post('/pos/login', [
            'login' => 'di@taxnest.test',
            'password' => 'DI@12345',
        ]);

        $response->assertSessionHasErrors('login');
        $this->assertFalse(auth('pos')->check());
        $this->assertFalse(auth('web')->check());
    }

    // ════════════════════════════════════════════════════════════════════
    // CORRECT-PANEL HAPPY PATH — Test 6
    // ════════════════════════════════════════════════════════════════════

    /** Test 6a: DI user on /login → PASS → dashboard */
    public function test_di_user_can_login_on_di_panel(): void
    {
        $response = $this->post('/login', [
            'login' => 'di@taxnest.test',
            'password' => 'DI@12345',
        ]);

        $this->assertTrue(auth('web')->check(), 'DI user must be authenticated on web guard');
        $this->assertFalse(auth('admin')->check());
        $response->assertRedirect();
        $this->assertNotEquals('/admin/dashboard', $response->headers->get('Location'));
    }

    /** Test 6b: POS user on /pos/login → PASS → /pos/invoice/create */
    public function test_pos_user_can_login_on_pos_panel(): void
    {
        $response = $this->post('/pos/login', [
            'login' => 'pos@taxnest.test',
            'password' => 'POS@12345',
        ]);

        $response->assertRedirect('/pos/invoice/create');
        $this->assertTrue(auth('pos')->check());
        $this->assertFalse(auth('web')->check());
        $this->assertFalse(auth('admin')->check());
    }

    /** Test 6c: FBR user on /fbr-pos/login → PASS → /fbr-pos/create */
    public function test_fbr_user_can_login_on_fbr_pos_panel(): void
    {
        $response = $this->post('/fbr-pos/login', [
            'login' => 'fbr@taxnest.test',
            'password' => 'FBR@12345',
        ]);

        $response->assertRedirect('/fbr-pos/create');
        $this->assertTrue(auth('fbrpos')->check());
        $this->assertFalse(auth('web')->check());
        $this->assertFalse(auth('admin')->check());
    }

    // ════════════════════════════════════════════════════════════════════
    // EXTRA HARDENING — Wrong password on correct panel
    // ════════════════════════════════════════════════════════════════════

    public function test_wrong_password_di_panel(): void
    {
        $response = $this->from('/login')->post('/login', [
            'login' => 'di@taxnest.test',
            'password' => 'WRONG',
        ]);
        $response->assertSessionHasErrors('login');
        $this->assertFalse(auth('web')->check());
    }

    public function test_wrong_password_pos_panel(): void
    {
        $response = $this->from('/pos/login')->post('/pos/login', [
            'login' => 'pos@taxnest.test',
            'password' => 'WRONG',
        ]);
        $response->assertSessionHasErrors('login');
        $this->assertFalse(auth('pos')->check());
    }

    public function test_wrong_password_fbr_panel(): void
    {
        $response = $this->from('/fbr-pos/login')->post('/fbr-pos/login', [
            'login' => 'fbr@taxnest.test',
            'password' => 'WRONG',
        ]);
        $response->assertSessionHasErrors('login');
        $this->assertFalse(auth('fbrpos')->check());
    }
}

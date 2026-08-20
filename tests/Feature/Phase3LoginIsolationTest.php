<?php

namespace Tests\Feature;

use Tests\TestCase;
use App\Http\Middleware\PosAuth;
use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\URL;
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
    /**
     * Confined POS staff roles → the isolated portal each one owns. Every one
     * of them signs in on the SAME /pos/login URL and is auto-detected by
     * pos_role; PosAuth then holds each account inside its portal.
     */
    private const CONFINED_POS_PORTALS = [
        'archive_viewer' => '/pos/archive',
        'local_viewer'   => '/pos/local-bills',
        'pos_kitchen'    => '/pos/restaurant/kds',
        'pos_rider'      => '/pos/rider',
        'pos_delivery'   => '/pos/deliveries',
        'pos_waiter'     => '/pos/waiter',
    ];

    private const CONFINED_POS_PASSWORD = 'Staff@12345';

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

        // ── Confined POS staff accounts (kitchen, waiter, rider, delivery
        //    manager, archive viewer, local-bills viewer) ──
        // Same POS company, same /pos/login URL — each one owns an isolated
        // portal and must reach it straight after a valid sign-in.
        foreach (array_keys(self::CONFINED_POS_PORTALS) as $posRole) {
            DB::table('users')->insert([
                'name' => 'POS ' . $posRole, 'email' => $posRole . '@taxnest.test',
                'password' => Hash::make(self::CONFINED_POS_PASSWORD),
                'company_id' => $posId, 'role' => 'pos_user', 'pos_role' => $posRole, 'is_active' => true,
                'created_at' => now(), 'updated_at' => now(),
            ]);
        }

        // ── Restaurant POS practice user ──
        // This mirrors the retained local restaurant fixture: a restaurant is
        // still a POS-panel company and must use the normal POS login route.
        $restaurantId = DB::table('companies')->insertGetId([
            'name' => 'Practice Restaurant', 'product_type' => 'pos', 'restaurant_mode' => true,
            'created_at' => now(), 'updated_at' => now(),
        ]);
        DB::table('users')->insert([
            'name' => 'Practice Restaurant Admin', 'email' => 'restaurant@taxnest.test',
            'password' => Hash::make('Restaurant@12345'),
            'company_id' => $restaurantId, 'role' => 'company_admin', 'pos_role' => 'pos_admin', 'is_active' => true,
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

    /** A restaurant-mode POS admin follows the same isolated POS login route. */
    public function test_practice_restaurant_admin_can_login_on_pos_panel(): void
    {
        // The live app forces HTTPS URLs, but the development preview's local
        // bridge speaks HTTP. The login redirect must remain path-relative so
        // it does not become https://127.0.0.1:5000/... after authentication.
        URL::forceScheme('https');
        try {
            $response = $this->post('/pos/login', [
                'login' => 'restaurant@taxnest.test',
                'password' => 'Restaurant@12345',
            ]);
        } finally {
            URL::forceScheme(null);
        }

        $response->assertRedirect('/pos/invoice/create');
        $this->assertSame('/pos/invoice/create', $response->headers->get('Location'));
        $this->assertTrue(auth('pos')->check());
        $this->assertSame('restaurant@taxnest.test', auth('pos')->user()->email);
        $this->assertFalse(auth('web')->check());
        $this->assertFalse(auth('admin')->check());
        $this->assertFalse(auth('fbrpos')->check());
    }

    /**
     * Every confined POS staff role signs in on the shared /pos/login URL and
     * must land on its OWN portal with a path-relative redirect. The live app
     * forces HTTPS URLs, but the development preview's local bridge speaks
     * HTTP — an absolute Location would send the browser to TLS on the local
     * PHP server port and block a validly signed-in staff member.
     */
    public function test_confined_pos_roles_reach_their_portal_with_a_relative_redirect(): void
    {
        foreach (self::CONFINED_POS_PORTALS as $posRole => $portal) {
            $this->flushSession();
            $this->app['auth']->forgetGuards();

            URL::forceScheme('https');
            try {
                $response = $this->post('/pos/login', [
                    'login' => $posRole . '@taxnest.test',
                    'password' => self::CONFINED_POS_PASSWORD,
                ]);
            } finally {
                URL::forceScheme(null);
            }

            $response->assertRedirect($portal);
            $this->assertSame(
                $portal,
                $response->headers->get('Location'),
                "[$posRole] must be sent to $portal with a path-relative redirect"
            );
            $this->assertTrue(auth('pos')->check(), "[$posRole] must be authenticated on the POS guard");
            $this->assertSame($posRole . '@taxnest.test', auth('pos')->user()->email);
            $this->assertFalse(auth('web')->check());
            $this->assertFalse(auth('admin')->check());
            $this->assertFalse(auth('fbrpos')->check());
        }
    }

    /**
     * PosAuth keeps each confined role inside its portal. That bounce hits an
     * ALREADY signed-in staff member, so it has to stay path-relative too —
     * otherwise the first page the role opens after login is what breaks.
     * Role confinement itself must not loosen: the bounce still happens.
     */
    public function test_pos_auth_confinement_bounce_stays_path_relative(): void
    {
        foreach (self::CONFINED_POS_PORTALS as $posRole => $portal) {
            Auth::guard('pos')->setUser(User::where('email', $posRole . '@taxnest.test')->firstOrFail());

            URL::forceScheme('https');
            try {
                $response = (new PosAuth())->handle(
                    Request::create('https://nestpos.test/pos/dashboard', 'GET'),
                    fn () => response('NEXT-OK')
                );
            } finally {
                URL::forceScheme(null);
            }

            $this->assertInstanceOf(
                RedirectResponse::class,
                $response,
                "[$posRole] must stay confined to $portal"
            );
            $this->assertSame(
                $portal,
                $response->headers->get('Location'),
                "[$posRole] must be bounced home with a path-relative Location"
            );
        }
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

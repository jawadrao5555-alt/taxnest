<?php

namespace Tests\Feature;

use Tests\TestCase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Schema;
use Illuminate\Database\Schema\Blueprint;

/**
 * USERNAME LOGIN (owner report, 10 Aug 2026)
 *
 * The login field promises "Email / Phone / Username / NTN / CNIC" but team
 * accounts are created without a users.username — staff typing the short name
 * they know ("cashier1" for cashier1@gmail.com) always failed.
 *
 * Covered here:
 *   - users.username column login on POS / FBR POS / DI panels
 *   - email local-part fallback ("cashier1" → cashier1@gmail.com)
 *   - ambiguity (two same-panel accounts share a local part) → clear
 *     "use your full email" error, NEVER a login into the wrong account
 *   - panel scoping: a cross-panel local-part twin does not block or steal
 *     the login; guard isolation is preserved
 *   - regression: email / phone / CNIC / NTN identifiers keep working
 */
class UsernameLoginTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

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

        $this->seedFixtures();
    }

    private int $posCompanyId;
    private int $fbrCompanyId;
    private int $diCompanyId;

    private function seedFixtures(): void
    {
        // ── POS company: admin + cashier (cashier has NO username set — the
        //    real-world shape: team creation never assigns one) ──
        $this->posCompanyId = DB::table('companies')->insertGetId([
            'name' => 'POS Co', 'product_type' => 'pos',
            'ntn' => '1234567890123', 'cnic' => '3520212345671',
            'created_at' => now(), 'updated_at' => now(),
        ]);
        DB::table('users')->insert([
            'name' => 'POS Owner', 'email' => 'posowner@taxnest.test',
            'phone' => '03001234567',
            'username' => 'posowner',
            'password' => Hash::make('POS@12345'),
            'company_id' => $this->posCompanyId, 'role' => 'company_admin',
            'pos_role' => 'pos_admin', 'is_active' => true,
            'created_at' => now(), 'updated_at' => now(),
        ]);
        DB::table('users')->insert([
            'name' => 'Cashier One', 'email' => 'cashier1@gmail.test',
            'password' => Hash::make('Cash@12345'),
            'company_id' => $this->posCompanyId, 'role' => 'employee',
            'pos_role' => 'pos_cashier', 'is_active' => true,
            'created_at' => now(), 'updated_at' => now(),
        ]);

        // ── FBR POS company: admin + cashier (no usernames) ──
        $this->fbrCompanyId = DB::table('companies')->insertGetId([
            'name' => 'FBR Co', 'product_type' => 'fbrpos', 'fbr_pos_enabled' => true,
            'created_at' => now(), 'updated_at' => now(),
        ]);
        DB::table('users')->insert([
            'name' => 'FBR Owner', 'email' => 'fbrowner@taxnest.test',
            'username' => 'fbrowner',
            'password' => Hash::make('FBR@12345'),
            'company_id' => $this->fbrCompanyId, 'role' => 'company_admin', 'is_active' => true,
            'created_at' => now(), 'updated_at' => now(),
        ]);
        DB::table('users')->insert([
            'name' => 'FBR Cashier', 'email' => 'fbrcashier@gmail.test',
            'password' => Hash::make('FbrCash@12345'),
            'company_id' => $this->fbrCompanyId, 'role' => 'employee',
            'pos_role' => 'pos_cashier', 'is_active' => true,
            'created_at' => now(), 'updated_at' => now(),
        ]);

        // ── DI company + user ──
        $this->diCompanyId = DB::table('companies')->insertGetId([
            'name' => 'DI Co', 'product_type' => 'di',
            'ntn' => '7654321-8',
            'created_at' => now(), 'updated_at' => now(),
        ]);
        DB::table('users')->insert([
            'name' => 'DI User', 'email' => 'diuser@taxnest.test',
            'username' => 'diuser',
            'password' => Hash::make('DI@12345'),
            'company_id' => $this->diCompanyId, 'role' => 'company_admin', 'is_active' => true,
            'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    // ════════════════════════════════════════════════════════════════════
    // USERNAME COLUMN — all three panels
    // ════════════════════════════════════════════════════════════════════

    public function test_pos_user_can_login_with_username_column(): void
    {
        $response = $this->post('/pos/login', [
            'login' => 'posowner',
            'password' => 'POS@12345',
        ]);

        $response->assertRedirect('/pos/invoice/create');
        $this->assertTrue(auth('pos')->check());
    }

    public function test_fbr_user_can_login_with_username_column(): void
    {
        $response = $this->post('/fbr-pos/login', [
            'login' => 'fbrowner',
            'password' => 'FBR@12345',
        ]);

        $response->assertRedirect('/fbr-pos/create');
        $this->assertTrue(auth('fbrpos')->check());
    }

    public function test_di_user_can_login_with_username_column(): void
    {
        $this->post('/login', [
            'login' => 'diuser',
            'password' => 'DI@12345',
        ]);

        $this->assertTrue(auth('web')->check());
    }

    // ════════════════════════════════════════════════════════════════════
    // EMAIL LOCAL-PART FALLBACK — the owner's actual report
    // ════════════════════════════════════════════════════════════════════

    /** Cashier has NO username set; "cashier1" must find cashier1@gmail.test */
    public function test_pos_cashier_can_login_with_email_local_part(): void
    {
        $response = $this->post('/pos/login', [
            'login' => 'cashier1',
            'password' => 'Cash@12345',
        ]);

        $response->assertRedirect('/pos/invoice/create');
        $this->assertTrue(auth('pos')->check());
        $this->assertEquals('cashier1@gmail.test', auth('pos')->user()->email);
    }

    public function test_fbr_cashier_can_login_with_email_local_part(): void
    {
        $response = $this->post('/fbr-pos/login', [
            'login' => 'fbrcashier',
            'password' => 'FbrCash@12345',
        ]);

        $response->assertRedirect('/fbr-pos/create');
        $this->assertTrue(auth('fbrpos')->check());
    }

    public function test_di_user_can_login_with_email_local_part(): void
    {
        $this->post('/login', [
            'login' => 'diuser2',
            'password' => 'DI2@12345',
        ]);
        $this->assertFalse(auth('web')->check(), 'sanity: no such user yet');

        DB::table('users')->insert([
            'name' => 'DI User 2', 'email' => 'diuser2@taxnest.test',
            'password' => Hash::make('DI2@12345'),
            'company_id' => $this->diCompanyId, 'role' => 'employee', 'is_active' => true,
            'created_at' => now(), 'updated_at' => now(),
        ]);

        $this->post('/login', [
            'login' => 'diuser2',
            'password' => 'DI2@12345',
        ]);

        $this->assertTrue(auth('web')->check());
    }

    /** Username column wins over a different user's email local-part. */
    public function test_username_column_takes_precedence_over_local_part(): void
    {
        // A second POS user whose USERNAME equals another user's local part.
        DB::table('users')->insert([
            'name' => 'Named Cashier', 'email' => 'named@taxnest.test',
            'username' => 'cashier1',
            'password' => Hash::make('Named@12345'),
            'company_id' => $this->posCompanyId, 'role' => 'employee',
            'pos_role' => 'pos_cashier', 'is_active' => true,
            'created_at' => now(), 'updated_at' => now(),
        ]);

        $response = $this->post('/pos/login', [
            'login' => 'cashier1',
            'password' => 'Named@12345',
        ]);

        $response->assertRedirect('/pos/invoice/create');
        $this->assertEquals('named@taxnest.test', auth('pos')->user()->email);
    }

    // ════════════════════════════════════════════════════════════════════
    // AMBIGUITY — never guess, ask for the full email
    // ════════════════════════════════════════════════════════════════════

    public function test_ambiguous_local_part_same_panel_is_rejected_with_clear_error(): void
    {
        // Second POS-panel account sharing the "cashier1" local part.
        DB::table('users')->insert([
            'name' => 'Cashier One B', 'email' => 'cashier1@yahoo.test',
            'password' => Hash::make('CashB@12345'),
            'company_id' => $this->posCompanyId, 'role' => 'employee',
            'pos_role' => 'pos_cashier', 'is_active' => true,
            'created_at' => now(), 'updated_at' => now(),
        ]);

        $response = $this->from('/pos/login')->post('/pos/login', [
            'login' => 'cashier1',
            'password' => 'Cash@12345',
        ]);

        $response->assertRedirect('/pos/login');
        $response->assertSessionHasErrors('login');
        $this->assertFalse(auth('pos')->check(), 'Must NEVER log into a guessed account');

        $msg = strtolower(implode(' ', session('errors')->get('login')));
        $this->assertStringContainsString('email', $msg, 'Error must ask for the full email');
    }

    /** A cross-panel twin must NOT block (or steal) the POS login. */
    public function test_cross_panel_local_part_twin_does_not_block_login(): void
    {
        // FBR-panel user with the same "cashier1" local part.
        DB::table('users')->insert([
            'name' => 'FBR Twin', 'email' => 'cashier1@fbr.test',
            'password' => Hash::make('Twin@12345'),
            'company_id' => $this->fbrCompanyId, 'role' => 'employee',
            'pos_role' => 'pos_cashier', 'is_active' => true,
            'created_at' => now(), 'updated_at' => now(),
        ]);

        $response = $this->post('/pos/login', [
            'login' => 'cashier1',
            'password' => 'Cash@12345',
        ]);

        $response->assertRedirect('/pos/invoice/create');
        $this->assertTrue(auth('pos')->check());
        $this->assertEquals('cashier1@gmail.test', auth('pos')->user()->email, 'Panel scoping must pick the POS account');
    }

    /**
     * An OUT-of-panel exact username must not block an in-panel staff account
     * whose email local part is the same string (reviewer case): FBR user
     * with username "cashier1" + POS staff at cashier1@… → POS login with
     * "cashier1" must reach the POS staff account.
     */
    public function test_out_of_panel_username_does_not_block_pos_local_part_login(): void
    {
        DB::table('users')->insert([
            'name' => 'FBR Named', 'email' => 'fbrnamed@taxnest.test',
            'username' => 'cashier1',
            'password' => Hash::make('FbrNamed@12345'),
            'company_id' => $this->fbrCompanyId, 'role' => 'employee',
            'pos_role' => 'pos_cashier', 'is_active' => true,
            'created_at' => now(), 'updated_at' => now(),
        ]);

        $response = $this->post('/pos/login', [
            'login' => 'cashier1',
            'password' => 'Cash@12345',
        ]);

        $response->assertRedirect('/pos/invoice/create');
        $this->assertTrue(auth('pos')->check());
        $this->assertEquals('cashier1@gmail.test', auth('pos')->user()->email);
    }

    public function test_out_of_panel_username_does_not_block_fbr_local_part_login(): void
    {
        // POS user whose username equals the FBR cashier's email local part.
        DB::table('users')->insert([
            'name' => 'POS Named', 'email' => 'posnamed@taxnest.test',
            'username' => 'fbrcashier',
            'password' => Hash::make('PosNamed@12345'),
            'company_id' => $this->posCompanyId, 'role' => 'employee',
            'pos_role' => 'pos_cashier', 'is_active' => true,
            'created_at' => now(), 'updated_at' => now(),
        ]);

        $response = $this->post('/fbr-pos/login', [
            'login' => 'fbrcashier',
            'password' => 'FbrCash@12345',
        ]);

        $response->assertRedirect('/fbr-pos/create');
        $this->assertTrue(auth('fbrpos')->check());
        $this->assertEquals('fbrcashier@gmail.test', auth('fbrpos')->user()->email);
    }

    public function test_out_of_panel_username_does_not_block_di_local_part_login(): void
    {
        // POS user whose username equals the DI user's email local part.
        DB::table('users')->insert([
            'name' => 'POS Named 2', 'email' => 'posnamed2@taxnest.test',
            'username' => 'diuser',
            'password' => Hash::make('PosNamed2@12345'),
            'company_id' => $this->posCompanyId, 'role' => 'employee',
            'pos_role' => 'pos_cashier', 'is_active' => true,
            'created_at' => now(), 'updated_at' => now(),
        ]);
        // Remove the DI user's own username so the local-part fallback is
        // the only route (isolates the blocking scenario).
        DB::table('users')->where('email', 'diuser@taxnest.test')->update(['username' => null]);

        $this->post('/login', [
            'login' => 'diuser',
            'password' => 'DI@12345',
        ]);

        $this->assertTrue(auth('web')->check());
        $this->assertEquals('diuser@taxnest.test', auth('web')->user()->email);
    }

    /** Out-of-panel username with ITS OWN password still fails generically. */
    public function test_out_of_panel_username_still_rejected_with_own_password(): void
    {
        DB::table('users')->insert([
            'name' => 'FBR Named', 'email' => 'fbrnamed@taxnest.test',
            'username' => 'fbrnamed',
            'password' => Hash::make('FbrNamed@12345'),
            'company_id' => $this->fbrCompanyId, 'role' => 'employee',
            'pos_role' => 'pos_cashier', 'is_active' => true,
            'created_at' => now(), 'updated_at' => now(),
        ]);

        $response = $this->from('/pos/login')->post('/pos/login', [
            'login' => 'fbrnamed',
            'password' => 'FbrNamed@12345',
        ]);

        $response->assertSessionHasErrors('login');
        $this->assertFalse(auth('pos')->check());
        $this->assertFalse(auth('fbrpos')->check());
    }

    // ════════════════════════════════════════════════════════════════════
    // GUARD ISOLATION — username login must not cross panels
    // ════════════════════════════════════════════════════════════════════

    public function test_fbr_username_rejected_on_pos_panel(): void
    {
        $response = $this->from('/pos/login')->post('/pos/login', [
            'login' => 'fbrowner',
            'password' => 'FBR@12345',
        ]);

        $response->assertSessionHasErrors('login');
        $this->assertFalse(auth('pos')->check());
        $this->assertFalse(auth('fbrpos')->check());
    }

    public function test_pos_local_part_rejected_on_di_panel(): void
    {
        $response = $this->from('/login')->post('/login', [
            'login' => 'cashier1',
            'password' => 'Cash@12345',
        ]);

        $response->assertSessionHasErrors('login');
        $this->assertFalse(auth('web')->check());
        $this->assertFalse(auth('pos')->check());
    }

    public function test_wrong_password_with_username_fails(): void
    {
        $response = $this->from('/pos/login')->post('/pos/login', [
            'login' => 'cashier1',
            'password' => 'WRONG',
        ]);

        $response->assertSessionHasErrors('login');
        $this->assertFalse(auth('pos')->check());
    }

    // ════════════════════════════════════════════════════════════════════
    // REGRESSION — existing identifiers keep working exactly as before
    // ════════════════════════════════════════════════════════════════════

    public function test_email_login_still_works_on_pos_panel(): void
    {
        $response = $this->post('/pos/login', [
            'login' => 'cashier1@gmail.test',
            'password' => 'Cash@12345',
        ]);

        $response->assertRedirect('/pos/invoice/create');
        $this->assertTrue(auth('pos')->check());
    }

    public function test_phone_login_still_works_on_pos_panel(): void
    {
        $response = $this->post('/pos/login', [
            'login' => '03001234567',
            'password' => 'POS@12345',
        ]);

        $response->assertRedirect('/pos/invoice/create');
        $this->assertTrue(auth('pos')->check());
    }

    public function test_cnic_login_still_works_on_pos_panel(): void
    {
        // CNIC (13 digits, dashes stripped) → company admin login.
        $response = $this->post('/pos/login', [
            'login' => '35202-1234567-1',
            'password' => 'POS@12345',
        ]);

        $response->assertRedirect('/pos/invoice/create');
        $this->assertTrue(auth('pos')->check());
        $this->assertEquals('posowner@taxnest.test', auth('pos')->user()->email);
    }

    public function test_ntn_login_still_works_on_di_panel(): void
    {
        // DI NTN lookup (>=7 chars incl. dash) → oldest company_admin.
        $this->post('/login', [
            'login' => '7654321-8',
            'password' => 'DI@12345',
        ]);

        $this->assertTrue(auth('web')->check());
        $this->assertEquals('diuser@taxnest.test', auth('web')->user()->email);
    }
}

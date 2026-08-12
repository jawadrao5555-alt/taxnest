<?php

namespace Tests\Feature;

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

/**
 * Username LOGIN end-to-end (Task 529).
 *
 * The Team pages now let admins set staff usernames — this locks the actual
 * login promise through the real HTTP routes (not just the resolver):
 *
 *   1. POS staff username on /pos/login  → pos guard authenticated.
 *   2. FBR staff username on /fbr-pos/login → fbrpos guard authenticated.
 *   3. NUMERIC username (7–13 digits) NEVER resolves: both login controllers
 *      divert digit-shaped inputs into phone/NTN/CNIC resolution BEFORE the
 *      username resolver runs. This is exactly why the Team write paths
 *      reject identifier-shaped usernames (LoginIdentifierResolver::
 *      usernameRules) — the seeded row here bypasses validation on purpose
 *      to document the diversion.
 *
 * Pattern: minimal schema + HTTP POST, same as Phase3LoginIsolationTest.
 */
class PosUsernameLoginTest extends TestCase
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
            $table->string('company_status')->default('approved');
            $table->softDeletes();
            $table->timestamps();
        });

        Schema::create('users', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('email')->unique();
            $table->string('phone')->nullable();
            $table->string('username', 100)->nullable()->unique();
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

        // Side-effect table (SecurityLogService writes during login flow)
        Schema::create('security_logs', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('user_id')->nullable();
            $table->string('action');
            $table->string('ip_address', 45)->nullable();
            $table->text('user_agent')->nullable();
            $table->text('metadata')->nullable();
            $table->timestamp('created_at')->nullable();
        });
    }

    private function seedPosCashier(string $username = 'cashier1'): void
    {
        $posId = DB::table('companies')->insertGetId([
            'name' => 'POS Co', 'product_type' => 'pos',
            'created_at' => now(), 'updated_at' => now(),
        ]);
        DB::table('users')->insert([
            'name' => 'POS Cashier', 'email' => 'poscashier@taxnest.test',
            'username' => $username,
            'password' => Hash::make('Cash@12345'),
            'company_id' => $posId, 'role' => 'employee', 'pos_role' => 'pos_cashier',
            'is_active' => true,
            'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    private function seedFbrCashier(string $username = 'fbrkasir1'): void
    {
        $fbrId = DB::table('companies')->insertGetId([
            'name' => 'FBR Co', 'product_type' => 'fbrpos', 'fbr_pos_enabled' => true,
            'created_at' => now(), 'updated_at' => now(),
        ]);
        DB::table('users')->insert([
            'name' => 'FBR Cashier', 'email' => 'fbrcashier@taxnest.test',
            'username' => $username,
            'password' => Hash::make('Fbr@12345'),
            'company_id' => $fbrId, 'role' => 'employee', 'pos_role' => 'pos_cashier',
            'is_active' => true,
            'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    /** 1. Normal username logs into the POS panel. */
    public function test_pos_staff_can_login_with_username(): void
    {
        $this->seedPosCashier('cashier1');

        $response = $this->post('/pos/login', [
            'login' => 'cashier1',
            'password' => 'Cash@12345',
        ]);

        $response->assertStatus(302);
        $this->assertTrue(auth('pos')->check(), 'POS guard must be authenticated via username');
        $this->assertSame('poscashier@taxnest.test', auth('pos')->user()->email);
    }

    /** 2. Normal username logs into the FBR POS panel. */
    public function test_fbr_staff_can_login_with_username(): void
    {
        $this->seedFbrCashier('fbrkasir1');

        $response = $this->post('/fbr-pos/login', [
            'login' => 'fbrkasir1',
            'password' => 'Fbr@12345',
        ]);

        $response->assertStatus(302);
        $this->assertTrue(auth('fbrpos')->check(), 'FBR POS guard must be authenticated via username');
        $this->assertSame('fbrcashier@taxnest.test', auth('fbrpos')->user()->email);
    }

    /**
     * 3. A numeric username (seeded past validation on purpose) can NEVER log
     *    in — the router treats 7–13 digit inputs as phone/NTN/CNIC. Locks the
     *    reason the Team pages reject identifier-shaped usernames.
     */
    public function test_numeric_username_is_diverted_and_never_logs_in(): void
    {
        $this->seedPosCashier('1234567');   // bypasses usernameRules by design
        $this->seedFbrCashier('03001234567');

        $pos = $this->post('/pos/login', [
            'login' => '1234567',
            'password' => 'Cash@12345',
        ]);
        $this->assertFalse(auth('pos')->check(), 'Numeric login input must NOT resolve as a username');
        $pos->assertSessionHasErrors('login');

        $fbr = $this->post('/fbr-pos/login', [
            'login' => '03001234567',
            'password' => 'Fbr@12345',
        ]);
        $this->assertFalse(auth('fbrpos')->check(), 'Phone-shaped login input must NOT resolve as a username');
        $fbr->assertSessionHasErrors('login');
    }
}

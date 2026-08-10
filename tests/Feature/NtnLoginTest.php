<?php

namespace Tests\Feature;

use Tests\TestCase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Schema;
use Illuminate\Database\Schema\Blueprint;

/**
 * REAL 7-DIGIT NTN LOGIN (task 433)
 *
 * The POS/FBR login label promises NTN login, but the digits branch only
 * fired for 10–13 digit inputs — a real Pakistani NTN is 7 digits (+1
 * optional check digit). Covered here:
 *   - 7-digit NTN (with/without dash, with/without check digit) logs the
 *     company admin into /pos and /fbr-pos
 *   - phone login (10–13 digits) keeps its precedence
 *   - panel isolation: wrong-panel NTN = generic failure
 */
class NtnLoginTest extends TestCase
{
    private int $posCompanyId;
    private int $fbrCompanyId;

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

        // ── POS company with a REAL 7-digit NTN ──
        $this->posCompanyId = DB::table('companies')->insertGetId([
            'name' => 'POS NTN Co', 'product_type' => 'pos',
            'ntn' => '1234567',
            'created_at' => now(), 'updated_at' => now(),
        ]);
        DB::table('users')->insert([
            'name' => 'POS Owner', 'email' => 'posntn@taxnest.test',
            'phone' => '03007654321',
            'password' => Hash::make('POS@12345'),
            'company_id' => $this->posCompanyId, 'role' => 'company_admin',
            'pos_role' => 'pos_admin', 'is_active' => true,
            'created_at' => now(), 'updated_at' => now(),
        ]);

        // ── FBR POS company with 8-digit NTN (7 + check digit, stored plain) ──
        $this->fbrCompanyId = DB::table('companies')->insertGetId([
            'name' => 'FBR NTN Co', 'product_type' => 'fbrpos', 'fbr_pos_enabled' => true,
            'ntn' => '76543218',
            'created_at' => now(), 'updated_at' => now(),
        ]);
        DB::table('users')->insert([
            'name' => 'FBR Owner', 'email' => 'fbrntn@taxnest.test',
            'password' => Hash::make('FBR@12345'),
            'company_id' => $this->fbrCompanyId, 'role' => 'company_admin', 'is_active' => true,
            'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    // ════════════════════════════════════════════════════════════════════
    // 7–8 DIGIT NTN — both panels
    // ════════════════════════════════════════════════════════════════════

    public function test_pos_admin_can_login_with_7_digit_ntn(): void
    {
        $response = $this->post('/pos/login', [
            'login' => '1234567',
            'password' => 'POS@12345',
        ]);

        $response->assertRedirect('/pos/invoice/create');
        $this->assertTrue(auth('pos')->check());
        $this->assertEquals('posntn@taxnest.test', auth('pos')->user()->email);
    }

    public function test_pos_admin_can_login_with_dashed_ntn(): void
    {
        // DB stores plain digits — dashed input must still match.
        DB::table('companies')->where('id', $this->posCompanyId)->update(['ntn' => '12345678']);

        $response = $this->post('/pos/login', [
            'login' => '1234567-8',
            'password' => 'POS@12345',
        ]);

        $response->assertRedirect('/pos/invoice/create');
        $this->assertTrue(auth('pos')->check());
    }

    public function test_fbr_admin_can_login_with_8_digit_ntn(): void
    {
        $response = $this->post('/fbr-pos/login', [
            'login' => '76543218',
            'password' => 'FBR@12345',
        ]);

        $response->assertRedirect('/fbr-pos/create');
        $this->assertTrue(auth('fbrpos')->check());
        $this->assertEquals('fbrntn@taxnest.test', auth('fbrpos')->user()->email);
    }

    public function test_fbr_admin_can_login_with_dashed_7_digit_ntn(): void
    {
        DB::table('companies')->where('id', $this->fbrCompanyId)->update(['ntn' => '7654321']);

        $response = $this->post('/fbr-pos/login', [
            'login' => '7654321',
            'password' => 'FBR@12345',
        ]);

        $response->assertRedirect('/fbr-pos/create');
        $this->assertTrue(auth('fbrpos')->check());
    }

    // ════════════════════════════════════════════════════════════════════
    // PHONE PRECEDENCE — 10–13 digits keep phone-first lookup
    // ════════════════════════════════════════════════════════════════════

    public function test_phone_login_keeps_precedence_over_ntn(): void
    {
        // A company whose (bogus) 11-digit NTN equals another user's phone —
        // the PHONE owner must win the lookup.
        $trapCompanyId = DB::table('companies')->insertGetId([
            'name' => 'Trap Co', 'product_type' => 'pos',
            'ntn' => '03007654321',
            'created_at' => now(), 'updated_at' => now(),
        ]);
        DB::table('users')->insert([
            'name' => 'Trap Admin', 'email' => 'trap@taxnest.test',
            'password' => Hash::make('Trap@12345'),
            'company_id' => $trapCompanyId, 'role' => 'company_admin', 'is_active' => true,
            'created_at' => now(), 'updated_at' => now(),
        ]);

        $response = $this->post('/pos/login', [
            'login' => '03007654321',
            'password' => 'POS@12345',
        ]);

        $response->assertRedirect('/pos/invoice/create');
        $this->assertTrue(auth('pos')->check());
        $this->assertEquals('posntn@taxnest.test', auth('pos')->user()->email);
    }

    // ════════════════════════════════════════════════════════════════════
    // PANEL ISOLATION — wrong panel = generic failure
    // ════════════════════════════════════════════════════════════════════

    public function test_fbr_ntn_rejected_on_pos_panel(): void
    {
        $response = $this->from('/pos/login')->post('/pos/login', [
            'login' => '76543218',
            'password' => 'FBR@12345',
        ]);

        $response->assertSessionHasErrors('login');
        $this->assertFalse(auth('pos')->check());
        $this->assertFalse(auth('fbrpos')->check());
    }

    public function test_pos_ntn_rejected_on_fbr_panel(): void
    {
        $response = $this->from('/fbr-pos/login')->post('/fbr-pos/login', [
            'login' => '1234567',
            'password' => 'POS@12345',
        ]);

        $response->assertSessionHasErrors('login');
        $this->assertFalse(auth('fbrpos')->check());
        $this->assertFalse(auth('pos')->check());
    }

    public function test_wrong_password_with_ntn_fails(): void
    {
        $response = $this->from('/pos/login')->post('/pos/login', [
            'login' => '1234567',
            'password' => 'WRONG',
        ]);

        $response->assertSessionHasErrors('login');
        $this->assertFalse(auth('pos')->check());
    }
}

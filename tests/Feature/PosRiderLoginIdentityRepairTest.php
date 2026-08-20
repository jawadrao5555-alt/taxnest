<?php

namespace Tests\Feature;

use App\Http\Controllers\FbrPosRiderController;
use App\Http\Controllers\PosRiderController;
use App\Models\PosRider;
use App\Models\User;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Schema;
use Illuminate\Database\QueryException;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

/**
 * Regression coverage for Task #1332 — Rider Login Identity Repair.
 *
 * Covers:
 *   1. PosRider::riderLoginStatus() — valid linkage and all invalid/stale states
 *      (missing user, cross-company, wrong role, multiply-linked).
 *   2. PRA PosRiderController::update() and FBR FbrPosRiderController::update()
 *      keep rider login name / phone / is_active in lockstep.
 *   3. PosRiderController::saveLogin() — managing an existing login:
 *      - keeps own email (unique-exempt)
 *      - corrects email to a fresh value
 *      - omitting password leaves password and pos_team_password_enc unchanged
 *      - supplying a password updates both password and pos_team_password_enc
 *      - another user's email is rejected with a validation error
 *   4. FbrPosRiderController::saveLogin() mirrors the same invariants.
 *   5. Broken links (stale/wrong user_id) are repaired: saveLogin() creates a
 *      fresh pos_rider account, links it, and leaves all historical
 *      pos_transactions (rider_id) and pos_rider_settlements (rider_id) rows
 *      completely untouched.
 *
 * Pattern: SQLite :memory: + minimal Schema::create + controllers invoked
 * directly with crafted Requests (same approach as PosTeamUsernameTest and
 * PosRiderPortalDeliveredInvariantTest).
 */
class PosRiderLoginIdentityRepairTest extends TestCase
{
    private const COMPANY  = 10;
    private const COMPANY2 = 11;

    // ─── Schema setup ────────────────────────────────────────────────────────

    protected function setUp(): void
    {
        parent::setUp();

        Schema::dropAllTables();

        Schema::create('companies', function (Blueprint $table) {
            $table->id();
            $table->string('name')->nullable();
            $table->boolean('is_internal_account')->default(false);
            $table->string('product_type')->nullable();
            $table->softDeletes();
            $table->timestamps();
        });

        Schema::create('users', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('email')->unique();
            $table->string('username', 100)->nullable()->unique();
            $table->string('password');
            $table->unsignedBigInteger('company_id')->nullable();
            $table->string('role')->default('user');
            $table->string('pos_role')->nullable();
            $table->boolean('is_active')->default(true);
            $table->boolean('pra_reporting_enabled')->default(true);
            $table->string('pos_billing_scope')->nullable();
            $table->text('pos_team_password_enc')->nullable();
            $table->text('pos_custom_access')->nullable();
            $table->string('phone')->nullable();
            $table->unsignedBigInteger('default_branch_id')->nullable();
            $table->rememberToken();
            $table->timestamps();
        });

        Schema::create('pos_riders', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('company_id');
            $table->string('name');
            $table->string('phone')->nullable();
            $table->string('cnic')->nullable();
            $table->string('vehicle_no')->nullable();
            $table->boolean('is_active')->default(true);
            $table->unsignedBigInteger('user_id')->nullable();
            $table->string('login_link_issue', 30)->nullable();
            $table->timestamps();
        });

        Schema::create('pos_transactions', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('company_id');
            $table->string('invoice_number');
            $table->string('status')->default('completed');
            $table->string('invoice_mode')->nullable();
            $table->string('pra_status')->nullable();
            $table->string('pra_invoice_number')->nullable();
            $table->string('payment_method')->nullable();
            $table->decimal('total_amount', 12, 2)->default(0);
            $table->boolean('is_archived')->default(false);
            $table->string('order_type')->nullable();
            $table->unsignedBigInteger('rider_id')->nullable();
            $table->string('delivery_status')->nullable();
            $table->unsignedBigInteger('rider_settlement_id')->nullable();
            $table->timestamp('rider_settled_at')->nullable();
            $table->timestamps();
        });

        Schema::create('pos_rider_settlements', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('company_id');
            $table->unsignedBigInteger('rider_id');
            $table->unsignedBigInteger('settled_by')->nullable();
            $table->decimal('total_amount', 12, 2)->default(0);
            $table->integer('bill_count')->default(0);
            $table->string('notes')->nullable();
            $table->decimal('outstanding_after', 12, 2)->nullable();
            $table->json('allocation')->nullable();
            $table->string('panel')->nullable();
            $table->timestamps();
        });

        // Insert both companies.
        DB::table('companies')->insert([
            ['id' => self::COMPANY,  'name' => 'PRA Shop',   'is_internal_account' => true, 'product_type' => 'pos',    'created_at' => now(), 'updated_at' => now()],
            ['id' => self::COMPANY2, 'name' => 'Other Shop', 'is_internal_account' => true, 'product_type' => 'fbrpos', 'created_at' => now(), 'updated_at' => now()],
        ]);

        User::flushScopeColumnCache();
    }

    // ─── Fixtures ─────────────────────────────────────────────────────────────

    private function makeAdminUser(int $companyId = self::COMPANY, string $posRole = 'pos_admin'): User
    {
        return User::forceCreate([
            'name'       => 'Admin',
            'email'      => 'admin_' . uniqid() . '@test.test',
            'password'   => bcrypt('secret'),
            'company_id' => $companyId,
            'role'       => 'company_admin',
            'pos_role'   => $posRole,
            'is_active'  => true,
        ]);
    }

    private function makeRiderUser(int $companyId = self::COMPANY, string $posRole = 'pos_rider'): User
    {
        return User::forceCreate([
            'name'       => 'Rider',
            'email'      => 'rider_' . uniqid() . '@test.test',
            'password'   => bcrypt('secret'),
            'company_id' => $companyId,
            'role'       => 'employee',
            'pos_role'   => $posRole,
            'is_active'  => true,
        ]);
    }

    private function makeRider(array $attrs = []): PosRider
    {
        return PosRider::forceCreate(array_merge([
            'company_id' => self::COMPANY,
            'name'       => 'Test Rider',
            'phone'      => '03001234567',
            'is_active'  => true,
            'user_id'    => null,
        ], $attrs));
    }

    private function loginAsPraAdmin(): void
    {
        $admin = $this->makeAdminUser(self::COMPANY, 'pos_admin');
        auth('pos')->setUser($admin);
        app()->bind('currentCompanyId', fn () => self::COMPANY);
    }

    private function loginAsFbrAdmin(): void
    {
        $admin = User::forceCreate([
            'name'       => 'FBR Admin',
            'email'      => 'fbradmin_' . uniqid() . '@test.test',
            'password'   => bcrypt('secret'),
            'company_id' => self::COMPANY,
            'role'       => 'company_admin',
            'pos_role'   => null,
            'is_active'  => true,
        ]);
        auth('fbrpos')->setUser($admin);
        app()->bind('currentCompanyId', fn () => self::COMPANY);
    }

    private function makeRequest(string $method, array $data = []): Request
    {
        $request = Request::create('/test', $method, $data);
        $request->server->set('REMOTE_ADDR', '127.0.0.1');
        $request->headers->set('referer', 'http://localhost/pos/riders');
        $request->setLaravelSession(app('session.store'));
        app()->instance('request', $request);
        return $request;
    }

    private function praController(): PosRiderController
    {
        return app(PosRiderController::class);
    }

    private function fbrController(): FbrPosRiderController
    {
        return app(FbrPosRiderController::class);
    }

    // ─────────────────────────────────────────────────────────────────────────
    // SECTION 1 — PosRider::riderLoginStatus() states
    // ─────────────────────────────────────────────────────────────────────────

    /** 1a. No user_id → ['user' => null, 'issue' => null]. */
    public function test_riderLoginStatus_no_user_id_returns_null_issue(): void
    {
        $rider = $this->makeRider(['user_id' => null]);

        $status = $rider->riderLoginStatus();

        $this->assertNull($status['user']);
        $this->assertNull($status['issue']);
    }

    public function test_riderLoginStatus_returns_persisted_repair_issue_after_duplicate_quarantine(): void
    {
        $rider = $this->makeRider([
            'user_id' => null,
            'login_link_issue' => 'multiple_riders',
        ]);

        $status = $rider->riderLoginStatus();

        $this->assertNull($status['user']);
        $this->assertSame('multiple_riders', $status['issue']);
    }

    /** 1b. Valid, exclusive pos_rider in same company → user returned, no issue. */
    public function test_riderLoginStatus_valid_linkage_returns_user(): void
    {
        $user  = $this->makeRiderUser();
        $rider = $this->makeRider(['user_id' => $user->id]);

        $status = $rider->riderLoginStatus();

        $this->assertNotNull($status['user']);
        $this->assertSame($user->id, $status['user']->id);
        $this->assertNull($status['issue']);
    }

    /** 1c. Stale link — user_id points to a deleted (non-existent) record. */
    public function test_riderLoginStatus_missing_user_returns_missing_issue(): void
    {
        $rider = $this->makeRider(['user_id' => 99999]);

        $status = $rider->riderLoginStatus();

        $this->assertNull($status['user']);
        $this->assertSame('missing', $status['issue']);
    }

    /** 1d. Cross-company link — user belongs to a different company. */
    public function test_riderLoginStatus_cross_company_returns_cross_company_issue(): void
    {
        $foreignUser = $this->makeRiderUser(self::COMPANY2);
        $rider       = $this->makeRider(['user_id' => $foreignUser->id]);

        $status = $rider->riderLoginStatus();

        $this->assertNull($status['user']);
        $this->assertSame('cross_company', $status['issue']);
    }

    /** 1e. Wrong role — user exists in same company but has a non-pos_rider pos_role. */
    public function test_riderLoginStatus_wrong_role_returns_wrong_role_issue(): void
    {
        $cashier = $this->makeRiderUser(self::COMPANY, 'pos_cashier');
        $rider   = $this->makeRider(['user_id' => $cashier->id]);

        $status = $rider->riderLoginStatus();

        $this->assertNull($status['user']);
        $this->assertSame('wrong_role', $status['issue']);
    }

    /** 1f. Multiply-linked — same user_id referenced by two riders → 'multiple_riders'. */
    public function test_riderLoginStatus_multiply_linked_returns_multiple_riders_issue(): void
    {
        $user    = $this->makeRiderUser();
        $rider1  = $this->makeRider(['user_id' => $user->id]);
        $rider2  = $this->makeRider(['user_id' => $user->id]); // second rider, same user

        // From rider1's perspective, a second rider is mapped → multiple_riders.
        $status1 = $rider1->riderLoginStatus();
        $this->assertNull($status1['user']);
        $this->assertSame('multiple_riders', $status1['issue']);

        // rider2 also sees multiple_riders (symmetry).
        $status2 = $rider2->riderLoginStatus();
        $this->assertNull($status2['user']);
        $this->assertSame('multiple_riders', $status2['issue']);
    }

    // ─────────────────────────────────────────────────────────────────────────
    // SECTION 2 — update() keeps login in lockstep (PRA + FBR parity)
    // ─────────────────────────────────────────────────────────────────────────

    /** 2a. PRA update — name/phone/is_active sync to rider login. */
    public function test_pra_update_syncs_name_phone_active_to_rider_login(): void
    {
        $this->loginAsPraAdmin();

        $user  = $this->makeRiderUser();
        $rider = $this->makeRider(['user_id' => $user->id, 'name' => 'Old Name', 'phone' => '0300111']);

        $this->praController()->update(
            $this->makeRequest('PUT', [
                'name'      => 'New Name',
                'phone'     => '0300999',
                'cnic'      => '',
                'vehicle_no' => '',
                'is_active' => '0',
            ]),
            $rider->id
        );

        $fresh = $user->fresh();
        $this->assertSame('New Name', $fresh->name,  'PRA: login name must sync');
        $this->assertSame('0300999',  $fresh->phone, 'PRA: login phone must sync');
        $this->assertFalse((bool) $fresh->is_active, 'PRA: login is_active must sync');
    }

    /** 2b. FBR update — same lockstep behaviour. */
    public function test_fbr_update_syncs_name_phone_active_to_rider_login(): void
    {
        $this->loginAsFbrAdmin();

        $user  = $this->makeRiderUser();
        $rider = $this->makeRider(['user_id' => $user->id, 'name' => 'FBR Old', 'phone' => '0301111']);

        $this->fbrController()->update(
            $this->makeRequest('PUT', [
                'name'      => 'FBR New',
                'phone'     => '0301999',
                'cnic'      => '',
                'vehicle_no' => '',
                'is_active' => '1',
            ]),
            $rider->id
        );

        $fresh = $user->fresh();
        $this->assertSame('FBR New',  $fresh->name,  'FBR: login name must sync');
        $this->assertSame('0301999',  $fresh->phone, 'FBR: login phone must sync');
        $this->assertTrue((bool) $fresh->is_active,  'FBR: login is_active must sync');
    }

    /** 2c. update() with a stale/broken link must NOT crash and must leave
     *      the rider record updated (login sync simply skipped). */
    public function test_pra_update_with_broken_link_skips_login_sync_silently(): void
    {
        $this->loginAsPraAdmin();

        $rider = $this->makeRider(['user_id' => 99999, 'name' => 'Stale Rider']);

        $this->praController()->update(
            $this->makeRequest('PUT', [
                'name'      => 'Updated Stale',
                'phone'     => '',
                'cnic'      => '',
                'vehicle_no' => '',
                'is_active' => '1',
            ]),
            $rider->id
        );

        $this->assertSame('Updated Stale', $rider->fresh()->name);
    }

    /** 2d. FBR parity: stale link also skipped silently. */
    public function test_fbr_update_with_broken_link_skips_login_sync_silently(): void
    {
        $this->loginAsFbrAdmin();

        $rider = $this->makeRider(['user_id' => 99999, 'name' => 'FBR Stale']);

        $this->fbrController()->update(
            $this->makeRequest('PUT', [
                'name'      => 'FBR Updated',
                'phone'     => '',
                'cnic'      => '',
                'vehicle_no' => '',
                'is_active' => '1',
            ]),
            $rider->id
        );

        $this->assertSame('FBR Updated', $rider->fresh()->name);
    }

    // ─────────────────────────────────────────────────────────────────────────
    // SECTION 3 — saveLogin() managing an existing login (PRA)
    // ─────────────────────────────────────────────────────────────────────────

    /** 3a. Existing login: submitting its own email passes (unique-exempt). */
    public function test_saveLogin_existing_login_keeps_own_email(): void
    {
        $this->loginAsPraAdmin();

        $user  = $this->makeRiderUser();
        $rider = $this->makeRider(['user_id' => $user->id]);

        // Submit the same email with no password (omit change).
        $this->praController()->saveLogin(
            $this->makeRequest('POST', ['email' => $user->email]),
            $rider->id
        );

        $this->assertSame($user->email, $user->fresh()->email);
    }

    /** 3b. Existing login: email can be corrected to a fresh new value. */
    public function test_saveLogin_existing_login_updates_email(): void
    {
        $this->loginAsPraAdmin();

        $user  = $this->makeRiderUser();
        $rider = $this->makeRider(['user_id' => $user->id]);
        $newEmail = 'newemail_' . uniqid() . '@shop.test';

        $this->praController()->saveLogin(
            $this->makeRequest('POST', ['email' => $newEmail]),
            $rider->id
        );

        $this->assertSame($newEmail, $user->fresh()->email);
    }

    /** 3c. Omitting password leaves the hashed password AND enc copy unchanged. */
    public function test_saveLogin_omit_password_does_not_change_password_or_enc(): void
    {
        $this->loginAsPraAdmin();

        $originalHash = bcrypt('original123');
        $originalEnc  = Crypt::encryptString('original123');

        $user = User::forceCreate([
            'name'                  => 'Rider',
            'email'                 => 'rider_' . uniqid() . '@test.test',
            'password'              => $originalHash,
            'company_id'            => self::COMPANY,
            'role'                  => 'employee',
            'pos_role'              => 'pos_rider',
            'is_active'             => true,
            'pos_team_password_enc' => $originalEnc,
        ]);

        $rider = $this->makeRider(['user_id' => $user->id]);

        // No 'password' key → omitted.
        $this->praController()->saveLogin(
            $this->makeRequest('POST', ['email' => $user->email]),
            $rider->id
        );

        $fresh = $user->fresh();
        $this->assertSame($originalHash, $fresh->password,              'password must not change when omitted');
        $this->assertSame($originalEnc,  $fresh->pos_team_password_enc, 'enc copy must not change when omitted');
    }

    /** 3d. Supplying a new password updates both the hash and the enc copy. */
    public function test_saveLogin_new_password_updates_hash_and_enc_copy(): void
    {
        $this->loginAsPraAdmin();

        $user  = $this->makeRiderUser();
        $rider = $this->makeRider(['user_id' => $user->id]);

        $this->praController()->saveLogin(
            $this->makeRequest('POST', [
                'email'    => $user->email,
                'password' => 'newpass99',
            ]),
            $rider->id
        );

        $fresh = $user->fresh();
        $this->assertTrue(Hash::check('newpass99', $fresh->password), 'new password hash must verify');
        $this->assertNotNull($fresh->pos_team_password_enc, 'enc copy must be set');
        $this->assertSame('newpass99', Crypt::decryptString($fresh->pos_team_password_enc), 'decrypted enc copy must match new password');
    }

    /** 3e. Another user's email is rejected with a validation error. */
    public function test_saveLogin_rejects_another_users_email(): void
    {
        $this->loginAsPraAdmin();

        $otherUser = $this->makeRiderUser();  // already has its email
        $user      = $this->makeRiderUser();
        $rider     = $this->makeRider(['user_id' => $user->id]);

        $this->expectException(ValidationException::class);

        $this->praController()->saveLogin(
            $this->makeRequest('POST', ['email' => $otherUser->email]),
            $rider->id
        );
    }

    // ─────────────────────────────────────────────────────────────────────────
    // SECTION 4 — saveLogin() parity for FBR controller
    // ─────────────────────────────────────────────────────────────────────────

    /** 4a. FBR: existing login keeps own email (unique-exempt). */
    public function test_fbr_saveLogin_existing_login_keeps_own_email(): void
    {
        $this->loginAsFbrAdmin();

        $user  = $this->makeRiderUser();
        $rider = $this->makeRider(['user_id' => $user->id]);

        $this->fbrController()->saveLogin(
            $this->makeRequest('POST', ['email' => $user->email]),
            $rider->id
        );

        $this->assertSame($user->email, $user->fresh()->email);
    }

    /** 4b. FBR: omit password → password + enc unchanged. */
    public function test_fbr_saveLogin_omit_password_does_not_change_password_or_enc(): void
    {
        $this->loginAsFbrAdmin();

        $originalHash = bcrypt('fbr_orig');
        $originalEnc  = Crypt::encryptString('fbr_orig');

        $user = User::forceCreate([
            'name'                  => 'FBR Rider',
            'email'                 => 'fbrrider_' . uniqid() . '@test.test',
            'password'              => $originalHash,
            'company_id'            => self::COMPANY,
            'role'                  => 'employee',
            'pos_role'              => 'pos_rider',
            'is_active'             => true,
            'pos_team_password_enc' => $originalEnc,
        ]);
        $rider = $this->makeRider(['user_id' => $user->id]);

        $this->fbrController()->saveLogin(
            $this->makeRequest('POST', ['email' => $user->email]),
            $rider->id
        );

        $fresh = $user->fresh();
        $this->assertSame($originalHash, $fresh->password,              'FBR: password must not change when omitted');
        $this->assertSame($originalEnc,  $fresh->pos_team_password_enc, 'FBR: enc copy must not change when omitted');
    }

    /** 4c. FBR: supplying password updates hash + enc copy. */
    public function test_fbr_saveLogin_new_password_updates_hash_and_enc_copy(): void
    {
        $this->loginAsFbrAdmin();

        $user  = $this->makeRiderUser();
        $rider = $this->makeRider(['user_id' => $user->id]);

        $this->fbrController()->saveLogin(
            $this->makeRequest('POST', [
                'email'    => $user->email,
                'password' => 'fbrpass99',
            ]),
            $rider->id
        );

        $fresh = $user->fresh();
        $this->assertTrue(Hash::check('fbrpass99', $fresh->password));
        $this->assertSame('fbrpass99', Crypt::decryptString($fresh->pos_team_password_enc));
    }

    /** 4d. FBR: another user's email is rejected. */
    public function test_fbr_saveLogin_rejects_another_users_email(): void
    {
        $this->loginAsFbrAdmin();

        $otherUser = $this->makeRiderUser();
        $user      = $this->makeRiderUser();
        $rider     = $this->makeRider(['user_id' => $user->id]);

        $this->expectException(ValidationException::class);

        $this->fbrController()->saveLogin(
            $this->makeRequest('POST', ['email' => $otherUser->email]),
            $rider->id
        );
    }

    // ─────────────────────────────────────────────────────────────────────────
    // SECTION 5 — Broken links repaired; historical data untouched
    // ─────────────────────────────────────────────────────────────────────────

    /**
     * 5a. saveLogin() on a rider with a stale user_id (user doesn't exist):
     *     - creates a new pos_rider User
     *     - links rider.user_id to the new account
     *     - all existing pos_transactions for this rider retain their rider_id
     *     - all existing pos_rider_settlements for this rider retain their rider_id
     */
    public function test_saveLogin_stale_link_repaired_and_history_untouched(): void
    {
        $this->loginAsPraAdmin();

        // Rider with a stale user_id pointing to a non-existent user.
        $rider = $this->makeRider(['user_id' => 77777]);
        $riderId = $rider->id;

        // Seed historical delivery transactions for this rider.
        $txId1 = DB::table('pos_transactions')->insertGetId([
            'company_id'     => self::COMPANY,
            'invoice_number' => 'POS-HIST-001',
            'status'         => 'completed',
            'payment_method' => 'cash',
            'total_amount'   => 500.00,
            'is_archived'    => false,
            'rider_id'       => $riderId,
            'delivery_status' => 'delivered',
            'created_at'     => now(),
            'updated_at'     => now(),
        ]);
        $txId2 = DB::table('pos_transactions')->insertGetId([
            'company_id'     => self::COMPANY,
            'invoice_number' => 'POS-HIST-002',
            'status'         => 'completed',
            'payment_method' => 'card',
            'total_amount'   => 300.00,
            'is_archived'    => false,
            'rider_id'       => $riderId,
            'delivery_status' => 'delivered',
            'created_at'     => now(),
            'updated_at'     => now(),
        ]);

        // Seed a historical settlement for this rider.
        $settlementId = DB::table('pos_rider_settlements')->insertGetId([
            'company_id'  => self::COMPANY,
            'rider_id'    => $riderId,
            'total_amount' => 500.00,
            'bill_count'  => 1,
            'created_at'  => now(),
            'updated_at'  => now(),
        ]);

        // Call saveLogin — broken link, so a fresh pos_rider account is created.
        $this->praController()->saveLogin(
            $this->makeRequest('POST', [
                'email'    => 'freshlogin_' . uniqid() . '@shop.test',
                'password' => 'secret123',
            ]),
            $riderId
        );

        // Rider must now point to a new user.
        $freshRider = PosRider::find($riderId);
        $this->assertNotNull($freshRider->user_id, 'rider.user_id must be set after repair');
        $this->assertNotEquals(77777, $freshRider->user_id, 'rider.user_id must not still be the stale value');

        $newUser = User::find($freshRider->user_id);
        $this->assertNotNull($newUser, 'new pos_rider user must exist');
        $this->assertSame('pos_rider',  $newUser->pos_role);
        $this->assertSame(self::COMPANY, (int) $newUser->company_id);

        // Historical transactions must be byte-for-byte unchanged.
        $tx1 = DB::table('pos_transactions')->find($txId1);
        $tx2 = DB::table('pos_transactions')->find($txId2);
        $this->assertSame($riderId, (int) $tx1->rider_id, 'tx1 rider_id must not change');
        $this->assertSame($riderId, (int) $tx2->rider_id, 'tx2 rider_id must not change');
        $this->assertSame('POS-HIST-001', $tx1->invoice_number);
        $this->assertSame('POS-HIST-002', $tx2->invoice_number);

        // Historical settlement must be byte-for-byte unchanged.
        $settlement = DB::table('pos_rider_settlements')->find($settlementId);
        $this->assertSame($riderId, (int) $settlement->rider_id, 'settlement rider_id must not change');
    }

    /**
     * 5b. saveLogin() with a cross-company broken link also repairs and leaves
     *     historical data untouched.
     */
    public function test_saveLogin_cross_company_link_repaired_and_history_untouched(): void
    {
        $this->loginAsPraAdmin();

        // Create a user that belongs to COMPANY2 — cross-company link.
        $foreignUser = $this->makeRiderUser(self::COMPANY2);
        $rider       = $this->makeRider(['user_id' => $foreignUser->id]);
        $riderId     = $rider->id;

        // Historical delivery bill.
        $txId = DB::table('pos_transactions')->insertGetId([
            'company_id'     => self::COMPANY,
            'invoice_number' => 'POS-CROSS-001',
            'status'         => 'completed',
            'payment_method' => 'cash',
            'total_amount'   => 200.00,
            'is_archived'    => false,
            'rider_id'       => $riderId,
            'delivery_status' => 'delivered',
            'created_at'     => now(),
            'updated_at'     => now(),
        ]);

        $this->praController()->saveLogin(
            $this->makeRequest('POST', [
                'email'    => 'crossfix_' . uniqid() . '@shop.test',
                'password' => 'secret123',
            ]),
            $riderId
        );

        $freshRider = PosRider::find($riderId);
        $this->assertNotNull($freshRider->user_id);
        $this->assertNotEquals($foreignUser->id, $freshRider->user_id, 'must not reuse the cross-company user');

        $newUser = User::find($freshRider->user_id);
        $this->assertSame((int) self::COMPANY, (int) $newUser->company_id, 'new user must belong to this company');

        $tx = DB::table('pos_transactions')->find($txId);
        $this->assertSame($riderId, (int) $tx->rider_id, 'historical tx rider_id must be untouched');
    }

    /**
     * 5c. FBR saveLogin() — broken link (wrong role user) is repaired and
     *     historical data untouched (parity with PRA 5a).
     */
    public function test_fbr_saveLogin_wrong_role_link_repaired_and_history_untouched(): void
    {
        $this->loginAsFbrAdmin();

        // Link to a cashier (wrong role).
        $cashier = $this->makeRiderUser(self::COMPANY, 'pos_cashier');
        $rider   = $this->makeRider(['user_id' => $cashier->id]);
        $riderId = $rider->id;

        $txId = DB::table('pos_transactions')->insertGetId([
            'company_id'     => self::COMPANY,
            'invoice_number' => 'POS-WRONGROLE-001',
            'status'         => 'completed',
            'payment_method' => 'cash',
            'total_amount'   => 700.00,
            'is_archived'    => false,
            'rider_id'       => $riderId,
            'delivery_status' => 'delivered',
            'created_at'     => now(),
            'updated_at'     => now(),
        ]);

        $settlementId = DB::table('pos_rider_settlements')->insertGetId([
            'company_id'  => self::COMPANY,
            'rider_id'    => $riderId,
            'total_amount' => 700.00,
            'bill_count'  => 1,
            'created_at'  => now(),
            'updated_at'  => now(),
        ]);

        $this->fbrController()->saveLogin(
            $this->makeRequest('POST', [
                'email'    => 'fbrfix_' . uniqid() . '@shop.test',
                'password' => 'secret123',
            ]),
            $riderId
        );

        $freshRider = PosRider::find($riderId);
        $this->assertNotNull($freshRider->user_id);
        $this->assertNotEquals($cashier->id, $freshRider->user_id, 'must not reuse the wrong-role user');

        $newUser = User::find($freshRider->user_id);
        $this->assertSame('pos_rider', $newUser->pos_role);

        $tx = DB::table('pos_transactions')->find($txId);
        $this->assertSame($riderId, (int) $tx->rider_id, 'FBR: historical tx rider_id must be untouched');

        $settlement = DB::table('pos_rider_settlements')->find($settlementId);
        $this->assertSame($riderId, (int) $settlement->rider_id, 'FBR: settlement rider_id must be untouched');
    }

    /**
     * 5d. saveLogin() on a rider with a multiply-linked user is treated as
     *     broken (issue = 'multiple_riders') and a fresh account is created.
     */
    public function test_saveLogin_multiply_linked_repaired_fresh_user_created(): void
    {
        $this->loginAsPraAdmin();

        $sharedUser = $this->makeRiderUser();

        // Two riders point to the same user.
        $rider1 = $this->makeRider(['user_id' => $sharedUser->id, 'name' => 'Rider One']);
        $rider2 = $this->makeRider(['user_id' => $sharedUser->id, 'name' => 'Rider Two']);

        // Repair rider1 — its link is broken (multiple_riders).
        $newEmail = 'repaired_' . uniqid() . '@shop.test';
        $this->praController()->saveLogin(
            $this->makeRequest('POST', [
                'email'    => $newEmail,
                'password' => 'secret123',
            ]),
            $rider1->id
        );

        $freshRider1 = PosRider::find($rider1->id);
        $this->assertNotNull($freshRider1->user_id);
        $this->assertNotEquals($sharedUser->id, $freshRider1->user_id, 'shared user must not be reused');

        $newUser = User::find($freshRider1->user_id);
        $this->assertSame($newEmail,    $newUser->email);
        $this->assertSame('pos_rider',  $newUser->pos_role);
        $this->assertSame(self::COMPANY, (int) $newUser->company_id);
        $this->assertNull($freshRider1->login_link_issue);

        // rider2 still points to the shared user (we only repaired rider1).
        $this->assertSame($sharedUser->id, (int) PosRider::find($rider2->id)->user_id);
    }

    public function test_migration_quarantines_legacy_duplicates_and_database_rejects_new_ones(): void
    {
        $sharedUser = $this->makeRiderUser();
        $rider1 = $this->makeRider(['user_id' => $sharedUser->id]);
        $rider2 = $this->makeRider(['user_id' => $sharedUser->id]);

        $migration = require database_path('migrations/2026_09_02_160000_enforce_unique_rider_login_links.php');
        $migration->up();

        foreach ([$rider1->id, $rider2->id] as $riderId) {
            $row = DB::table('pos_riders')->find($riderId);
            $this->assertNull($row->user_id);
            $this->assertSame('multiple_riders', $row->login_link_issue);
        }

        $hasUniqueIndex = collect(Schema::getIndexes('pos_riders'))
            ->contains(fn (array $index) => ($index['unique'] ?? false)
                && array_values($index['columns'] ?? []) === ['user_id']);
        $this->assertTrue($hasUniqueIndex);

        DB::table('pos_riders')->where('id', $rider1->id)->update([
            'user_id' => $sharedUser->id,
            'login_link_issue' => null,
        ]);

        $this->expectException(QueryException::class);
        DB::table('pos_riders')->where('id', $rider2->id)->update([
            'user_id' => $sharedUser->id,
            'login_link_issue' => null,
        ]);
    }
}

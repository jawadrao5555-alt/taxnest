<?php

namespace Tests\Feature;

use App\Http\Controllers\FbrPosController;
use App\Http\Controllers\PosController;
use App\Models\Company;
use App\Models\User;
use App\Services\LoginIdentifierResolver;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Schema;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

/**
 * Team page username option (Task 529).
 *
 * Locks the new admin-facing username management on /pos/team:
 *   1. storeCashier saves an optional username → the member resolves at login
 *      via LoginIdentifierResolver (the exact path the login form uses).
 *   2. Duplicate usernames are rejected with the pos.username_taken message.
 *   3. Spaces / @ (email-looking values) are rejected (pos.username_format_invalid).
 *   4. updateCashier can set/change a username; resubmitting the member's OWN
 *      username passes (unique exempt), and blank clears it back to NULL.
 *
 * Pattern: sqlite :memory: + minimal Schema::create + controller invoked
 * directly with a crafted Request (same as PosBillingScopeAuditTest).
 * The FBR twin handlers share the identical rules/write pattern.
 */
class PosTeamUsernameTest extends TestCase
{
    private const COMPANY_ID = 42;

    protected function setUp(): void
    {
        parent::setUp();

        Schema::dropAllTables();

        Schema::create('companies', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('email')->nullable();
            $table->string('status')->default('approved');
            $table->string('company_status')->default('active');
            $table->boolean('is_internal_account')->default(false);
            $table->string('product_type')->nullable();
            $table->boolean('billing_scope_admin_enabled')->default(false);
            $table->softDeletes();
            $table->timestamps();
        });

        Schema::create('users', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('email')->unique();
            // Production column: string(100) + GLOBAL unique index.
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

        User::flushScopeColumnCache();
    }

    private function makeCompany(): Company
    {
        return Company::forceCreate([
            'id'                  => self::COMPANY_ID,
            'name'                => 'Test Shop',
            'is_internal_account' => true,   // skips PlanLimitService quota
            'product_type'        => 'pos',  // panel scope for the login resolver
        ]);
    }

    private function loginAsOwner(): User
    {
        $user = User::forceCreate([
            'name'       => 'Owner',
            'email'      => 'owner_' . uniqid() . '@test.test',
            'password'   => bcrypt('secret'),
            'company_id' => self::COMPANY_ID,
            'role'       => 'company_admin',
            'pos_role'   => 'pos_admin',
            'is_active'  => true,
        ]);

        auth('pos')->setUser($user);
        app()->bind('currentCompanyId', fn () => self::COMPANY_ID);

        return $user;
    }

    private function makeRequest(string $method, array $data = []): Request
    {
        $request = Request::create('/test', $method, $data);
        $request->server->set('REMOTE_ADDR', '127.0.0.1');
        $request->headers->set('referer', 'http://localhost/pos/team');
        app()->instance('request', $request);

        return $request;
    }

    private function storePayload(array $overrides = []): array
    {
        return array_merge([
            'name'     => 'New Cashier',
            'email'    => 'cashier_' . uniqid() . '@shop.test',
            'phone'    => '',
            'password' => 'secret123',
            'pos_role' => 'pos_cashier',
        ], $overrides);
    }

    // ══════════════════════════════════════════════════════════════════════

    public function test_storeCashier_saves_username_and_it_resolves_at_login(): void
    {
        $this->makeCompany();
        $this->loginAsOwner();

        $request = $this->makeRequest('POST', $this->storePayload(['username' => 'cashier1']));
        (new PosController())->storeCashier($request);

        $member = User::where('username', 'cashier1')->first();
        $this->assertNotNull($member, 'Member must be created with the username');
        $this->assertSame('pos_cashier', $member->pos_role);

        // The exact resolution path the login form uses for username input.
        $resolved = LoginIdentifierResolver::resolveUsernameColumn('cashier1', ['pos']);
        $this->assertNotNull($resolved, 'Username must resolve for the POS panel');
        $this->assertSame($member->id, $resolved->id);
    }

    public function test_storeCashier_blank_username_stays_null(): void
    {
        $this->makeCompany();
        $this->loginAsOwner();

        $email = 'blankuser_' . uniqid() . '@shop.test';
        $request = $this->makeRequest('POST', $this->storePayload(['email' => $email, 'username' => '']));
        (new PosController())->storeCashier($request);

        $member = User::where('email', $email)->first();
        $this->assertNotNull($member);
        $this->assertNull($member->username, 'Blank must save as NULL, never empty string');
    }

    public function test_storeCashier_rejects_duplicate_username(): void
    {
        $this->makeCompany();
        $this->loginAsOwner();

        (new PosController())->storeCashier(
            $this->makeRequest('POST', $this->storePayload(['username' => 'taken1']))
        );

        try {
            (new PosController())->storeCashier(
                $this->makeRequest('POST', $this->storePayload(['username' => 'taken1']))
            );
            $this->fail('Duplicate username must throw a validation error');
        } catch (ValidationException $e) {
            $this->assertSame(__('pos.username_taken'), $e->errors()['username'][0] ?? null);
        }
    }

    public function test_storeCashier_rejects_spaces_and_email_like_usernames(): void
    {
        $this->makeCompany();
        $this->loginAsOwner();

        foreach (['has space', 'user@mail.com'] as $bad) {
            try {
                (new PosController())->storeCashier(
                    $this->makeRequest('POST', $this->storePayload(['username' => $bad]))
                );
                $this->fail("Username '{$bad}' must throw a validation error");
            } catch (ValidationException $e) {
                $this->assertSame(__('pos.username_format_invalid'), $e->errors()['username'][0] ?? null);
            }
        }
    }

    public function test_storeCashier_rejects_numeric_and_identifier_shaped_usernames(): void
    {
        $this->makeCompany();
        $this->loginAsOwner();

        // Both login routers divert any input whose digit-stripped form is
        // 7–13 digits into phone/NTN/CNIC resolution BEFORE the username
        // resolver — such usernames would save but never log in. Numeric-only
        // values of any length are reserved too.
        foreach (['1234567', '03001234567', 'ali1234567', '123456'] as $bad) {
            try {
                (new PosController())->storeCashier(
                    $this->makeRequest('POST', $this->storePayload(['username' => $bad]))
                );
                $this->fail("Username '{$bad}' must throw a validation error");
            } catch (ValidationException $e) {
                $this->assertSame(__('pos.username_digits_reserved'), $e->errors()['username'][0] ?? null);
            }
        }

        // Few digits + letters stays fine ("ali99").
        (new PosController())->storeCashier(
            $this->makeRequest('POST', $this->storePayload(['username' => 'ali99']))
        );
        $this->assertNotNull(User::where('username', 'ali99')->first());
    }

    public function test_updateCashier_sets_changes_and_clears_username(): void
    {
        $this->makeCompany();
        $this->loginAsOwner();

        $cashier = User::forceCreate([
            'name'       => 'Cashier One',
            'email'      => 'c1_' . uniqid() . '@shop.test',
            'password'   => bcrypt('pass'),
            'company_id' => self::COMPANY_ID,
            'role'       => 'user',
            'pos_role'   => 'pos_cashier',
            'is_active'  => true,
        ]);

        $base = ['name' => $cashier->name, 'email' => $cashier->email, 'phone' => ''];
        $controller = new PosController();

        // Set from the edit row.
        $controller->updateCashier($this->makeRequest('PUT', $base + ['username' => 'ali99']), $cashier->id);
        $this->assertSame('ali99', $cashier->fresh()->username);

        // Resubmitting the member's OWN username passes (unique exempts own row).
        $controller->updateCashier($this->makeRequest('PUT', $base + ['username' => 'ali99']), $cashier->id);
        $this->assertSame('ali99', $cashier->fresh()->username);

        // Another member's username is rejected.
        User::forceCreate([
            'name' => 'Cashier Two', 'email' => 'c2_' . uniqid() . '@shop.test',
            'password' => bcrypt('pass'), 'company_id' => self::COMPANY_ID,
            'role' => 'user', 'pos_role' => 'pos_cashier', 'is_active' => true,
            'username' => 'sara88',
        ]);
        try {
            $controller->updateCashier($this->makeRequest('PUT', $base + ['username' => 'sara88']), $cashier->id);
            $this->fail('Another member\'s username must throw a validation error');
        } catch (ValidationException $e) {
            $this->assertSame(__('pos.username_taken'), $e->errors()['username'][0] ?? null);
        }

        // Blank clears back to email-only login.
        $controller->updateCashier($this->makeRequest('PUT', $base + ['username' => '']), $cashier->id);
        $this->assertNull($cashier->fresh()->username);
    }

    // ══════════════════════════════════════════════════════════════════════
    // FBR twin — fbrStoreTeamMember / fbrUpdateTeamMember share the same
    // shared rules; dedicated coverage so the twin can never drift.
    // ══════════════════════════════════════════════════════════════════════

    private function loginAsFbrOwner(): User
    {
        $user = User::forceCreate([
            'name'       => 'FBR Owner',
            'email'      => 'fbrowner_' . uniqid() . '@test.test',
            'password'   => bcrypt('secret'),
            'company_id' => self::COMPANY_ID,
            'role'       => 'company_admin',
            'pos_role'   => 'pos_admin',
            'is_active'  => true,
        ]);

        auth('fbrpos')->setUser($user);
        app()->bind('currentCompanyId', fn () => self::COMPANY_ID);

        return $user;
    }

    public function test_fbr_store_team_member_saves_username_and_rejects_bad_shapes(): void
    {
        Company::forceCreate([
            'id' => self::COMPANY_ID, 'name' => 'FBR Shop',
            'is_internal_account' => true, 'product_type' => 'fbrpos',
        ]);
        $this->loginAsFbrOwner();
        $controller = new FbrPosController();

        // Valid username saves + resolves for the FBR panel.
        $controller->fbrStoreTeamMember($this->makeRequest('POST', [
            'name' => 'FBR Cashier', 'email' => 'fbrc_' . uniqid() . '@shop.test',
            'password' => 'secret123', 'pos_role' => 'pos_cashier',
            'username' => 'fbrkasir1',
        ]));
        $member = User::where('username', 'fbrkasir1')->first();
        $this->assertNotNull($member, 'FBR member must be created with the username');
        $resolved = LoginIdentifierResolver::resolveUsernameColumn('fbrkasir1', ['fbrpos']);
        $this->assertSame($member->id, $resolved?->id);

        // Duplicate + identifier-shaped are rejected with the shared messages.
        foreach ([['fbrkasir1', 'pos.username_taken'], ['1234567', 'pos.username_digits_reserved']] as [$bad, $key]) {
            try {
                $controller->fbrStoreTeamMember($this->makeRequest('POST', [
                    'name' => 'Dup', 'email' => 'dup_' . uniqid() . '@shop.test',
                    'password' => 'secret123', 'pos_role' => 'pos_cashier',
                    'username' => $bad,
                ]));
                $this->fail("FBR username '{$bad}' must throw a validation error");
            } catch (ValidationException $e) {
                $this->assertSame(__($key), $e->errors()['username'][0] ?? null);
            }
        }
    }

    public function test_fbr_update_team_member_sets_own_exempt_and_clears_username(): void
    {
        Company::forceCreate([
            'id' => self::COMPANY_ID, 'name' => 'FBR Shop',
            'is_internal_account' => true, 'product_type' => 'fbrpos',
        ]);
        $this->loginAsFbrOwner();
        $controller = new FbrPosController();

        $member = User::forceCreate([
            'name' => 'FBR Cashier', 'email' => 'fbrc_' . uniqid() . '@shop.test',
            'password' => bcrypt('pass'), 'company_id' => self::COMPANY_ID,
            'role' => 'employee', 'pos_role' => 'pos_cashier', 'is_active' => true,
        ]);
        $base = ['name' => $member->name, 'email' => $member->email, 'pos_role' => 'pos_cashier'];

        // Set, then resubmit own username (unique exempts own row).
        $controller->fbrUpdateTeamMember($this->makeRequest('PUT', $base + ['username' => 'fbrali7']), $member->id);
        $this->assertSame('fbrali7', $member->fresh()->username);
        $controller->fbrUpdateTeamMember($this->makeRequest('PUT', $base + ['username' => 'fbrali7']), $member->id);
        $this->assertSame('fbrali7', $member->fresh()->username);

        // Blank clears back to email-only login.
        $controller->fbrUpdateTeamMember($this->makeRequest('PUT', $base + ['username' => '']), $member->id);
        $this->assertNull($member->fresh()->username);
    }
}

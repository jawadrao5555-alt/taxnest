<?php

namespace Tests\Feature;

use App\Http\Controllers\PosCallerIdController;
use App\Models\Company;
use App\Models\User;
use App\Services\PosFeatureService;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

/**
 * CALLER ID plan gate (Task 1071) — Unlimited-only lock via
 * pricing_plans.caller_id_enabled + PosFeatureService::planAllows.
 *
 * Invariants locked here:
 *   1. planAllows('caller_id_enabled') — service-level gate matrix:
 *       - locked paid plan (Business)  → false
 *       - Unlimited plan               → true
 *       - active trial                 → true  (evaluate-before-buying rule)
 *       - expired trial                → false
 *       - active admin override        → true  (override_type=lifetime/temporary)
 *       - internal account             → true
 *       - no active subscription       → false
 *   2. POST /api/caller-app/v1/login on a locked plan → 403 plan_locked.
 *   3. POST /api/caller-app/v1/login on Unlimited → 200.
 *   4. POST /pos/settings/caller-id {enabled:true}  on locked plan → 403.
 *   5. POST /pos/settings/caller-id {enabled:false} on locked plan → 200
 *      (turning OFF is always allowed, no plan check needed).
 *   6. GET  /pos/api/caller-events on locked plan → enabled:false even when
 *      the company row has caller_id_enabled=true.
 *   7. POST /api/caller-app/v1/ring on locked plan → accepted:false + plan_locked.
 *   8. GET  /api/caller-app/v1/me on locked plan → enabled:false + plan_locked:true.
 *
 * Pattern: sqlite :memory: + minimal Schema::create, controller invoked
 * directly (same style as PosCallerIdToggleAndEventsTest / OfflinePlanGateTest).
 */
class PosCallerIdPlanGateTest extends TestCase
{
    // ─── Schema setup ────────────────────────────────────────────────────────

    protected function setUp(): void
    {
        parent::setUp();

        // planAllows caches per company id — flush so ids restarting at 1
        // after dropAllTables cannot leak stale results between tests.
        PosFeatureService::flushGateCaches();

        Schema::dropAllTables();

        Schema::create('companies', function (Blueprint $t) {
            $t->id();
            $t->string('name')->nullable();
            $t->string('product_type')->default('pos');
            $t->boolean('is_internal_account')->default(false);
            $t->boolean('caller_id_enabled')->default(false);
            $t->string('caller_app_token')->nullable();
            $t->unsignedBigInteger('caller_app_user_id')->nullable();
            $t->string('caller_app_device')->nullable();
            $t->timestamp('caller_app_last_seen_at')->nullable();
            $t->string('status')->default('active');
            $t->string('company_status')->default('active');
            $t->softDeletes();
            $t->timestamps();
        });

        Schema::create('pricing_plans', function (Blueprint $t) {
            $t->id();
            $t->string('name');
            $t->string('product_type')->default('pos');
            $t->boolean('is_trial')->default(false);
            $t->boolean('caller_id_enabled')->default(false);
            $t->timestamps();
        });

        Schema::create('subscriptions', function (Blueprint $t) {
            $t->id();
            $t->unsignedBigInteger('company_id');
            $t->unsignedBigInteger('pricing_plan_id')->nullable();
            $t->boolean('active')->default(true);
            $t->date('start_date')->nullable();
            $t->date('end_date')->nullable();
            $t->timestamp('trial_ends_at')->nullable();
            $t->string('override_type')->default('none');
            $t->timestamp('override_until')->nullable();
            $t->timestamp('override_granted_at')->nullable();
            $t->integer('free_invoice_limit')->nullable();
            $t->timestamps();
        });

        Schema::create('users', function (Blueprint $t) {
            $t->id();
            $t->unsignedBigInteger('company_id')->nullable();
            $t->string('name')->nullable();
            $t->string('email')->nullable();
            $t->string('password')->nullable();
            $t->boolean('is_active')->default(true);
            $t->string('role')->nullable();
            $t->string('pos_role')->nullable();
            $t->text('pos_custom_access')->nullable();
            $t->timestamps();
        });

        Schema::create('pos_caller_events', function (Blueprint $t) {
            $t->id();
            $t->unsignedBigInteger('company_id');
            $t->string('phone')->nullable();
            $t->string('caller_name')->nullable();
            $t->string('source')->default('sim');
            $t->timestamp('ring_at')->nullable();
            $t->timestamp('created_at')->nullable();
        });

        Schema::create('pos_customers', function (Blueprint $t) {
            $t->id();
            $t->unsignedBigInteger('company_id');
            $t->string('name')->nullable();
            $t->string('phone')->nullable();
            $t->string('address')->nullable();
            $t->timestamps();
        });

        Schema::create('pos_transactions', function (Blueprint $t) {
            $t->id();
            $t->unsignedBigInteger('company_id');
            $t->unsignedBigInteger('customer_id')->nullable();
            $t->string('customer_name')->nullable();
            $t->string('customer_phone')->nullable();
            $t->string('status')->nullable();
            $t->string('transaction_type')->nullable();
            $t->decimal('total_amount', 12, 2)->default(0);
            $t->timestamps();
        });
    }

    // ─── Helpers ─────────────────────────────────────────────────────────────

    /**
     * Insert company + plan + subscription rows; return the Company model.
     *
     * @param  array $planAttrs   Overrides for pricing_plans row (e.g. caller_id_enabled).
     * @param  array $subAttrs    Overrides for subscriptions row (e.g. override_type, trial_ends_at).
     * @param  array $companyAttrs Overrides for companies row (e.g. is_internal_account).
     */
    private function makeCompany(
        array $planAttrs = [],
        array $subAttrs = [],
        array $companyAttrs = []
    ): Company {
        $companyId = DB::table('companies')->insertGetId(array_merge([
            'name'           => 'Test Shop',
            'product_type'   => 'pos',
            'created_at'     => now(),
            'updated_at'     => now(),
        ], $companyAttrs));

        $planId = DB::table('pricing_plans')->insertGetId(array_merge([
            'name'               => 'Business',
            'product_type'       => 'pos',
            'caller_id_enabled'  => false,
            'created_at'         => now(),
            'updated_at'         => now(),
        ], $planAttrs));

        DB::table('subscriptions')->insert(array_merge([
            'company_id'       => $companyId,
            'pricing_plan_id'  => $planId,
            'active'           => true,
            'start_date'       => now()->subMonth()->toDateString(),
            'end_date'         => now()->addMonth()->toDateString(),
            'override_type'    => 'none',
            'created_at'       => now(),
            'updated_at'       => now(),
        ], $subAttrs));

        return Company::findOrFail($companyId);
    }

    /**
     * Insert a pos_admin user for the given company, authenticate them in
     * the 'pos' guard, and return the model.
     */
    private function makeAdmin(int $companyId, string $email = 'admin@shop.test'): User
    {
        $user = User::forceCreate([
            'company_id' => $companyId,
            'name'       => 'Shop Admin',
            'email'      => $email,
            'password'   => Hash::make('secret123'),
            'is_active'  => true,
            'pos_role'   => 'pos_admin',
        ]);
        Auth::guard('pos')->setUser($user);
        return $user;
    }

    /**
     * Generate a raw bearer token, store its SHA-256 hash on the company,
     * and return the raw token for use in requests.
     */
    private function bindToken(Company $company): string
    {
        $plain = $company->id . '|' . str_repeat('x', 48);
        DB::table('companies')->where('id', $company->id)->update([
            'caller_app_token'    => hash('sha256', $plain),
            'caller_app_user_id'  => null,
        ]);
        return $plain;
    }

    /** Invoke PosCallerIdController::toggle via the pos guard session. */
    private function callToggle(bool $enabled): \Illuminate\Http\JsonResponse
    {
        $request = Request::create('/pos/settings/caller-id', 'POST', ['enabled' => $enabled]);
        $request->setLaravelSession(app('session.store'));
        app()->instance('request', $request);
        return app(PosCallerIdController::class)->toggle($request);
    }

    /** Invoke PosCallerIdController::events and return decoded array. */
    private function callEvents(int $after = 0): array
    {
        $request = Request::create('/pos/api/caller-events', 'GET', ['after' => $after]);
        $request->setLaravelSession(app('session.store'));
        app()->instance('request', $request);
        return app(PosCallerIdController::class)->events($request)->getData(true);
    }

    /** Invoke PosCallerIdController::appLogin with given credentials. */
    private function callAppLogin(string $email, string $password): \Illuminate\Http\JsonResponse
    {
        $request = Request::create('/api/caller-app/v1/login', 'POST', [
            'email'    => $email,
            'password' => $password,
        ]);
        app()->instance('request', $request);
        return app(PosCallerIdController::class)->appLogin($request);
    }

    /** Invoke PosCallerIdController::appRing with a bearer token. */
    private function callAppRing(string $bearerToken, array $payload = []): \Illuminate\Http\JsonResponse
    {
        $request = Request::create('/api/caller-app/v1/ring', 'POST', array_merge([
            'phone' => '03001234567',
        ], $payload));
        $request->headers->set('Authorization', 'Bearer ' . $bearerToken);
        app()->instance('request', $request);
        return app(PosCallerIdController::class)->appRing($request);
    }

    /** Invoke PosCallerIdController::appMe with a bearer token. */
    private function callAppMe(string $bearerToken): array
    {
        $request = Request::create('/api/caller-app/v1/me', 'GET');
        $request->headers->set('Authorization', 'Bearer ' . $bearerToken);
        app()->instance('request', $request);
        return app(PosCallerIdController::class)->appMe($request)->getData(true);
    }

    // =========================================================================
    // Part 1: Service-level gate matrix (planAllows)
    // =========================================================================

    public function test_business_plan_is_blocked(): void
    {
        $company = $this->makeCompany(['name' => 'Business', 'caller_id_enabled' => false]);
        $this->assertFalse(PosFeatureService::planAllows($company, 'caller_id_enabled'));
    }

    public function test_starter_plan_is_blocked(): void
    {
        $company = $this->makeCompany(['name' => 'Starter', 'caller_id_enabled' => false]);
        $this->assertFalse(PosFeatureService::planAllows($company, 'caller_id_enabled'));
    }

    public function test_unlimited_plan_is_allowed(): void
    {
        $company = $this->makeCompany(['name' => 'Unlimited', 'caller_id_enabled' => true]);
        $this->assertTrue(PosFeatureService::planAllows($company, 'caller_id_enabled'));
    }

    public function test_active_trial_is_allowed_even_with_gate_off(): void
    {
        // Active-trial companies get to evaluate everything before buying.
        $company = $this->makeCompany(
            ['name' => 'Business', 'is_trial' => true, 'caller_id_enabled' => false],
            ['trial_ends_at' => now()->addDays(7)]
        );
        $this->assertTrue(PosFeatureService::planAllows($company, 'caller_id_enabled'));
    }

    public function test_expired_trial_is_blocked(): void
    {
        $company = $this->makeCompany(
            ['name' => 'Business', 'is_trial' => true, 'caller_id_enabled' => false],
            ['trial_ends_at' => now()->subDay()]
        );
        $this->assertFalse(PosFeatureService::planAllows($company, 'caller_id_enabled'));
    }

    public function test_active_lifetime_override_on_locked_plan_is_allowed(): void
    {
        $company = $this->makeCompany(
            ['name' => 'Business', 'caller_id_enabled' => false],
            ['override_type' => 'lifetime']
        );
        $this->assertTrue(PosFeatureService::planAllows($company, 'caller_id_enabled'));
    }

    public function test_active_temporary_override_on_locked_plan_is_allowed(): void
    {
        $company = $this->makeCompany(
            ['name' => 'Business', 'caller_id_enabled' => false],
            ['override_type' => 'temporary', 'override_until' => now()->addDays(14)]
        );
        $this->assertTrue(PosFeatureService::planAllows($company, 'caller_id_enabled'));
    }

    public function test_expired_temporary_override_is_blocked(): void
    {
        $company = $this->makeCompany(
            ['name' => 'Business', 'caller_id_enabled' => false],
            ['override_type' => 'temporary', 'override_until' => now()->subDay()]
        );
        $this->assertFalse(PosFeatureService::planAllows($company, 'caller_id_enabled'));
    }

    public function test_internal_account_is_always_allowed(): void
    {
        $company = $this->makeCompany(
            ['name' => 'Business', 'caller_id_enabled' => false],
            [],
            ['is_internal_account' => true]
        );
        $this->assertTrue(PosFeatureService::planAllows($company, 'caller_id_enabled'));
    }

    public function test_no_active_subscription_is_blocked(): void
    {
        $company = $this->makeCompany(
            ['name' => 'Unlimited', 'caller_id_enabled' => true],
            ['active' => false]
        );
        $this->assertFalse(PosFeatureService::planAllows($company, 'caller_id_enabled'));
    }

    public function test_missing_column_fails_open_schema_lag_escape_hatch(): void
    {
        // Simulate a lagging production deploy where the migration that adds
        // pricing_plans.caller_id_enabled has not yet run.  planAllows must
        // return true (fail-open) so that paying users are NEVER locked out
        // during the migration window.
        $company = $this->makeCompany(['name' => 'Business', 'caller_id_enabled' => false]);

        // Drop the column to reproduce the pre-migration schema state.
        Schema::table('pricing_plans', function (Blueprint $t) {
            $t->dropColumn('caller_id_enabled');
        });

        // Flush the per-request gate cache: a cached false from an earlier
        // test on the same company id must not mask the fail-open result.
        PosFeatureService::flushGateCaches();

        $this->assertTrue(
            PosFeatureService::planAllows($company, 'caller_id_enabled'),
            'planAllows must fail OPEN when the pricing_plans.caller_id_enabled column is absent (schema-lag escape hatch)'
        );
    }

    // =========================================================================
    // Part 2: Controller endpoint — app login
    // =========================================================================

    public function test_app_login_blocked_on_locked_plan(): void
    {
        $company = $this->makeCompany(['name' => 'Business', 'caller_id_enabled' => false]);
        $this->makeAdmin((int) $company->id);

        $res  = $this->callAppLogin('admin@shop.test', 'secret123');
        $data = $res->getData(true);

        $this->assertSame(403, $res->getStatusCode(), 'locked plan login must be 403');
        $this->assertSame('plan_locked', $data['error'] ?? null);
    }

    public function test_app_login_succeeds_on_unlimited_plan(): void
    {
        $company = $this->makeCompany(['name' => 'Unlimited', 'caller_id_enabled' => true]);
        $this->makeAdmin((int) $company->id);

        $res  = $this->callAppLogin('admin@shop.test', 'secret123');
        $data = $res->getData(true);

        $this->assertSame(200, $res->getStatusCode(), 'Unlimited plan login must succeed');
        $this->assertTrue($data['ok']);
        $this->assertArrayHasKey('token', $data);
    }

    public function test_app_login_succeeds_on_active_trial(): void
    {
        $company = $this->makeCompany(
            ['name' => 'Business', 'caller_id_enabled' => false],
            ['trial_ends_at' => now()->addDays(5)]
        );
        $this->makeAdmin((int) $company->id);

        $res = $this->callAppLogin('admin@shop.test', 'secret123');
        $this->assertSame(200, $res->getStatusCode(), 'active-trial login must succeed');
        $this->assertTrue($res->getData(true)['ok']);
    }

    public function test_app_login_succeeds_with_active_override(): void
    {
        $company = $this->makeCompany(
            ['name' => 'Business', 'caller_id_enabled' => false],
            ['override_type' => 'lifetime']
        );
        $this->makeAdmin((int) $company->id);

        $res = $this->callAppLogin('admin@shop.test', 'secret123');
        $this->assertSame(200, $res->getStatusCode(), 'override login must succeed');
    }

    public function test_app_login_succeeds_for_internal_account(): void
    {
        $company = $this->makeCompany(
            ['name' => 'Business', 'caller_id_enabled' => false],
            [],
            ['is_internal_account' => true]
        );
        $this->makeAdmin((int) $company->id);

        $res = $this->callAppLogin('admin@shop.test', 'secret123');
        $this->assertSame(200, $res->getStatusCode(), 'internal-account login must succeed');
    }

    // =========================================================================
    // Part 3: Controller endpoint — POS toggle (POST /pos/settings/caller-id)
    // =========================================================================

    public function test_toggle_enable_blocked_on_locked_plan(): void
    {
        $company = $this->makeCompany(['name' => 'Business', 'caller_id_enabled' => false]);
        $this->makeAdmin((int) $company->id);
        app()->instance('currentCompanyId', (int) $company->id);

        $res  = $this->callToggle(true);
        $data = $res->getData(true);

        $this->assertSame(403, $res->getStatusCode(), 'enabling on locked plan must be 403');
        $this->assertSame('plan_locked', $data['error'] ?? null);
        // DB column must stay false — no write occurred.
        $this->assertFalse((bool) DB::table('companies')->where('id', $company->id)->value('caller_id_enabled'));
    }

    public function test_toggle_disable_allowed_on_locked_plan(): void
    {
        // Turning OFF is always allowed (no plan check) — even on a locked plan.
        $company = $this->makeCompany(['name' => 'Business', 'caller_id_enabled' => false]);
        DB::table('companies')->where('id', $company->id)->update(['caller_id_enabled' => true]);
        $this->makeAdmin((int) $company->id);
        app()->instance('currentCompanyId', (int) $company->id);

        $res = $this->callToggle(false);
        $this->assertSame(200, $res->getStatusCode(), 'disabling must always succeed');
        $this->assertFalse((bool) DB::table('companies')->where('id', $company->id)->value('caller_id_enabled'));
    }

    public function test_toggle_enable_succeeds_on_unlimited_plan(): void
    {
        $company = $this->makeCompany(['name' => 'Unlimited', 'caller_id_enabled' => true]);
        $this->makeAdmin((int) $company->id);
        app()->instance('currentCompanyId', (int) $company->id);

        $res  = $this->callToggle(true);
        $data = $res->getData(true);

        $this->assertSame(200, $res->getStatusCode(), 'Unlimited toggle must succeed');
        $this->assertTrue($data['enabled']);
        $this->assertTrue((bool) DB::table('companies')->where('id', $company->id)->value('caller_id_enabled'));
    }

    public function test_toggle_enable_succeeds_on_active_trial(): void
    {
        $company = $this->makeCompany(
            ['name' => 'Business', 'caller_id_enabled' => false],
            ['trial_ends_at' => now()->addDays(3)]
        );
        $this->makeAdmin((int) $company->id);
        app()->instance('currentCompanyId', (int) $company->id);

        $res = $this->callToggle(true);
        $this->assertSame(200, $res->getStatusCode(), 'active-trial toggle must succeed');
    }

    public function test_toggle_enable_succeeds_with_active_override(): void
    {
        $company = $this->makeCompany(
            ['name' => 'Business', 'caller_id_enabled' => false],
            ['override_type' => 'lifetime']
        );
        $this->makeAdmin((int) $company->id);
        app()->instance('currentCompanyId', (int) $company->id);

        $res = $this->callToggle(true);
        $this->assertSame(200, $res->getStatusCode(), 'override toggle must succeed');
    }

    public function test_toggle_enable_succeeds_for_internal_account(): void
    {
        $company = $this->makeCompany(
            ['name' => 'Business', 'caller_id_enabled' => false],
            [],
            ['is_internal_account' => true]
        );
        $this->makeAdmin((int) $company->id);
        app()->instance('currentCompanyId', (int) $company->id);

        $res = $this->callToggle(true);
        $this->assertSame(200, $res->getStatusCode(), 'internal-account toggle must succeed');
    }

    // =========================================================================
    // Part 4: Controller endpoint — poll (GET /pos/api/caller-events)
    // =========================================================================

    public function test_events_returns_disabled_on_locked_plan_even_when_row_is_on(): void
    {
        // Company row says enabled, but the plan lock must override it.
        $company = $this->makeCompany(['name' => 'Business', 'caller_id_enabled' => false]);
        DB::table('companies')->where('id', $company->id)->update(['caller_id_enabled' => true]);
        app()->instance('currentCompanyId', (int) $company->id);

        $res = $this->callEvents();
        $this->assertFalse($res['enabled'], 'locked plan must suppress events even when row=true');
        $this->assertSame([], $res['events']);
    }

    public function test_events_returns_enabled_on_unlimited_plan(): void
    {
        $company = $this->makeCompany(['name' => 'Unlimited', 'caller_id_enabled' => true]);
        DB::table('companies')->where('id', $company->id)->update(['caller_id_enabled' => true]);
        app()->instance('currentCompanyId', (int) $company->id);

        $res = $this->callEvents();
        $this->assertTrue($res['enabled'], 'Unlimited plan must have events enabled');
    }

    public function test_events_returns_enabled_on_active_trial(): void
    {
        $company = $this->makeCompany(
            ['name' => 'Business', 'caller_id_enabled' => false],
            ['trial_ends_at' => now()->addDays(5)]
        );
        DB::table('companies')->where('id', $company->id)->update(['caller_id_enabled' => true]);
        app()->instance('currentCompanyId', (int) $company->id);

        $res = $this->callEvents();
        $this->assertTrue($res['enabled'], 'active trial must allow events');
    }

    public function test_events_returns_enabled_for_internal_account(): void
    {
        $company = $this->makeCompany(
            ['name' => 'Business', 'caller_id_enabled' => false],
            [],
            ['is_internal_account' => true]
        );
        DB::table('companies')->where('id', $company->id)->update(['caller_id_enabled' => true]);
        app()->instance('currentCompanyId', (int) $company->id);

        $res = $this->callEvents();
        $this->assertTrue($res['enabled'], 'internal account must allow events');
    }

    // =========================================================================
    // Part 5: Controller endpoint — appRing
    // =========================================================================

    public function test_app_ring_returns_plan_locked_on_locked_plan(): void
    {
        $company  = $this->makeCompany(['name' => 'Business', 'caller_id_enabled' => false]);
        $token    = $this->bindToken($company);

        $res  = $this->callAppRing($token);
        $data = $res->getData(true);

        $this->assertSame(200, $res->getStatusCode());          // soft 200, not a hard error
        $this->assertFalse($data['accepted'], 'ring must not be accepted on locked plan');
        $this->assertSame('plan_locked', $data['reason']);
        // No event row should have been inserted.
        $this->assertSame(0, (int) DB::table('pos_caller_events')->count());
    }

    public function test_app_ring_accepted_on_unlimited_plan(): void
    {
        $company = $this->makeCompany(['name' => 'Unlimited', 'caller_id_enabled' => true]);
        DB::table('companies')->where('id', $company->id)->update(['caller_id_enabled' => true]);
        $token = $this->bindToken($company);

        $res  = $this->callAppRing($token, ['phone' => '03001234567']);
        $data = $res->getData(true);

        $this->assertTrue($data['ok']);
        $this->assertTrue($data['accepted'], 'ring must be accepted on Unlimited plan');
        $this->assertSame(1, (int) DB::table('pos_caller_events')->count());
    }

    public function test_app_ring_accepted_on_active_trial(): void
    {
        $company = $this->makeCompany(
            ['name' => 'Business', 'caller_id_enabled' => false],
            ['trial_ends_at' => now()->addDays(5)]
        );
        DB::table('companies')->where('id', $company->id)->update(['caller_id_enabled' => true]);
        $token = $this->bindToken($company);

        $data = $this->callAppRing($token)->getData(true);
        $this->assertTrue($data['accepted'], 'ring must be accepted on active trial');
    }

    public function test_app_ring_accepted_with_active_override(): void
    {
        $company = $this->makeCompany(
            ['name' => 'Business', 'caller_id_enabled' => false],
            ['override_type' => 'lifetime']
        );
        DB::table('companies')->where('id', $company->id)->update(['caller_id_enabled' => true]);
        $token = $this->bindToken($company);

        $data = $this->callAppRing($token)->getData(true);
        $this->assertTrue($data['accepted'], 'ring must be accepted with active override');
    }

    public function test_app_ring_accepted_for_internal_account(): void
    {
        $company = $this->makeCompany(
            ['name' => 'Business', 'caller_id_enabled' => false],
            [],
            ['is_internal_account' => true]
        );
        DB::table('companies')->where('id', $company->id)->update(['caller_id_enabled' => true]);
        $token = $this->bindToken($company);

        $data = $this->callAppRing($token)->getData(true);
        $this->assertTrue($data['accepted'], 'ring must be accepted for internal account');
    }

    // =========================================================================
    // Part 6: Controller endpoint — appMe
    // =========================================================================

    public function test_app_me_reports_plan_locked_on_locked_plan(): void
    {
        $company = $this->makeCompany(['name' => 'Business', 'caller_id_enabled' => false]);
        DB::table('companies')->where('id', $company->id)->update(['caller_id_enabled' => true]);
        $token = $this->bindToken($company);

        $data = $this->callAppMe($token);

        $this->assertTrue($data['ok']);
        // enabled must be false even though the company row says true
        $this->assertFalse($data['enabled'], 'locked plan must surface enabled=false in /me');
        $this->assertTrue($data['plan_locked'], 'locked plan must surface plan_locked=true in /me');
    }

    public function test_app_me_reports_enabled_on_unlimited_plan(): void
    {
        $company = $this->makeCompany(['name' => 'Unlimited', 'caller_id_enabled' => true]);
        DB::table('companies')->where('id', $company->id)->update(['caller_id_enabled' => true]);
        $token = $this->bindToken($company);

        $data = $this->callAppMe($token);

        $this->assertTrue($data['ok']);
        $this->assertTrue($data['enabled'], 'Unlimited plan must surface enabled=true in /me');
        $this->assertFalse($data['plan_locked'], 'Unlimited plan must surface plan_locked=false in /me');
    }

    public function test_app_me_reports_enabled_on_active_trial(): void
    {
        $company = $this->makeCompany(
            ['name' => 'Business', 'caller_id_enabled' => false],
            ['trial_ends_at' => now()->addDays(5)]
        );
        DB::table('companies')->where('id', $company->id)->update(['caller_id_enabled' => true]);
        $token = $this->bindToken($company);

        $data = $this->callAppMe($token);
        $this->assertTrue($data['enabled'], 'active trial must report enabled=true in /me');
        $this->assertFalse($data['plan_locked'], 'active trial must report plan_locked=false in /me');
    }

    public function test_app_me_reports_enabled_with_active_override(): void
    {
        $company = $this->makeCompany(
            ['name' => 'Business', 'caller_id_enabled' => false],
            ['override_type' => 'lifetime']
        );
        DB::table('companies')->where('id', $company->id)->update(['caller_id_enabled' => true]);
        $token = $this->bindToken($company);

        $data = $this->callAppMe($token);
        $this->assertTrue($data['enabled'], 'override must report enabled=true in /me');
        $this->assertFalse($data['plan_locked'], 'override must report plan_locked=false in /me');
    }

    public function test_app_me_reports_enabled_for_internal_account(): void
    {
        $company = $this->makeCompany(
            ['name' => 'Business', 'caller_id_enabled' => false],
            [],
            ['is_internal_account' => true]
        );
        DB::table('companies')->where('id', $company->id)->update(['caller_id_enabled' => true]);
        $token = $this->bindToken($company);

        $data = $this->callAppMe($token);
        $this->assertTrue($data['enabled'], 'internal account must report enabled=true in /me');
        $this->assertFalse($data['plan_locked'], 'internal account must report plan_locked=false in /me');
    }
}

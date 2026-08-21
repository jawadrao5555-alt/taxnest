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
 * CALLER ID v2 (Task 1101) — multi-device pairing, repeat-order and
 * recent-calls endpoints, khata in match.
 *
 * Invariants locked here:
 *   1. Multi-device: two app logins yield two WORKING tokens (second login
 *      does NOT log out the first phone); logout removes ONLY its own device;
 *      cap: the least-recently-seen device is bumped at the limit.
 *   2. Legacy backward-compat: an old companies-row token keeps working after
 *      the devices table exists.
 *   3. Device revoke is admin-only (cashier 403, no delete).
 *   4. caller-recent: last-24h rows only, plan-locked → enabled:false.
 *   4b. caller-clear (Task 1380): a cleared ring leaves the recent list AND the
 *       poll for EVERY counter of that shop, uncleared rings survive, and the
 *       ring cursor keeps moving so the next call still arrives.
 *   5. caller-last-order: last completed bill's product lines; deal/manual
 *      lines reported as skipped; disabled/plan-locked → 403.
 *   6. matchCustomer surfaces khata_balance as an int.
 *
 * Pattern: sqlite :memory: + minimal Schema::create, controller invoked
 * directly (same as PosCallerIdPlanGateTest).
 */
class PosCallerIdV2Test extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
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
        Schema::create('pos_caller_devices', function (Blueprint $t) {
            $t->id();
            $t->unsignedBigInteger('company_id');
            $t->unsignedBigInteger('user_id')->nullable();
            $t->string('device', 120)->nullable();
            $t->string('token_hash', 64);
            $t->timestamp('last_seen_at')->nullable();
            $t->timestamps();
        });
        Schema::create('pos_caller_events', function (Blueprint $t) {
            $t->id();
            $t->unsignedBigInteger('company_id');
            $t->string('phone')->nullable();
            $t->string('caller_name')->nullable();
            $t->string('source')->default('sim');
            $t->timestamp('ring_at')->nullable();
            $t->timestamp('cleared_at')->nullable();
            $t->timestamp('created_at')->nullable();
        });
        Schema::create('pos_customers', function (Blueprint $t) {
            $t->id();
            $t->unsignedBigInteger('company_id');
            $t->string('name')->nullable();
            $t->string('phone')->nullable();
            $t->string('address')->nullable();
            $t->decimal('khata_balance', 12, 2)->default(0);
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
        Schema::create('pos_transaction_items', function (Blueprint $t) {
            $t->id();
            $t->unsignedBigInteger('transaction_id');
            $t->string('item_type')->default('product');
            $t->unsignedBigInteger('item_id')->nullable();
            $t->string('item_name')->nullable();
            $t->decimal('quantity', 12, 2)->default(1);
            $t->timestamps();
        });
    }

    // ─── Helpers (PosCallerIdPlanGateTest pattern) ──────────────────────────

    private function makeCompany(array $companyAttrs = []): Company
    {
        $companyId = DB::table('companies')->insertGetId(array_merge([
            'name' => 'Test Shop', 'product_type' => 'pos',
            'caller_id_enabled' => true,
            'created_at' => now(), 'updated_at' => now(),
        ], $companyAttrs));
        $planId = DB::table('pricing_plans')->insertGetId([
            'name' => 'Unlimited', 'product_type' => 'pos',
            'caller_id_enabled' => true, 'created_at' => now(), 'updated_at' => now(),
        ]);
        DB::table('subscriptions')->insert([
            'company_id' => $companyId, 'pricing_plan_id' => $planId, 'active' => true,
            'start_date' => now()->subMonth()->toDateString(),
            'end_date' => now()->addMonth()->toDateString(),
            'override_type' => 'none', 'created_at' => now(), 'updated_at' => now(),
        ]);
        return Company::findOrFail($companyId);
    }

    /** Downgrade the company's plan to a locked one. */
    private function lockPlan(Company $company): void
    {
        DB::table('pricing_plans')
            ->whereIn('id', DB::table('subscriptions')->where('company_id', $company->id)->pluck('pricing_plan_id'))
            ->update(['name' => 'Business', 'caller_id_enabled' => false]);
        PosFeatureService::flushGateCaches();
    }

    private function makeUser(int $companyId, string $posRole = 'pos_admin', string $email = 'admin@shop.test'): User
    {
        $user = User::forceCreate([
            'company_id' => $companyId, 'name' => 'U ' . $posRole, 'email' => $email,
            'password' => Hash::make('secret123'), 'is_active' => true, 'pos_role' => $posRole,
        ]);
        Auth::guard('pos')->setUser($user);
        return $user;
    }

    private function login(string $email = 'admin@shop.test', string $device = 'Phone'): \Illuminate\Http\JsonResponse
    {
        $request = Request::create('/api/caller-app/v1/login', 'POST', [
            'email' => $email, 'password' => 'secret123', 'device' => $device,
        ]);
        app()->instance('request', $request);
        return app(PosCallerIdController::class)->appLogin($request);
    }

    private function me(string $token): array
    {
        $request = Request::create('/api/caller-app/v1/me', 'GET');
        $request->headers->set('Authorization', 'Bearer ' . $token);
        app()->instance('request', $request);
        return app(PosCallerIdController::class)->appMe($request)->getData(true);
    }

    private function logout(string $token): void
    {
        $request = Request::create('/api/caller-app/v1/logout', 'POST');
        $request->headers->set('Authorization', 'Bearer ' . $token);
        app()->instance('request', $request);
        app(PosCallerIdController::class)->appLogout($request);
    }

    private function posGet(string $method, array $query = []): \Illuminate\Http\JsonResponse
    {
        $request = Request::create('/pos/api/x', 'GET', $query);
        $request->setLaravelSession(app('session.store'));
        app()->instance('request', $request);
        return app(PosCallerIdController::class)->{$method}($request);
    }

    // ─── 1. Multi-device ─────────────────────────────────────────────────────

    public function test_second_login_does_not_kill_first_device(): void
    {
        $company = $this->makeCompany();
        $this->makeUser((int) $company->id);

        $t1 = $this->login('admin@shop.test', 'SIM Phone')->getData(true)['token'];
        $t2 = $this->login('admin@shop.test', 'WhatsApp Phone')->getData(true)['token'];

        $this->assertSame(2, DB::table('pos_caller_devices')->count());
        $this->assertTrue($this->me($t1)['ok'] ?? false, 'first phone must stay paired');
        $this->assertTrue($this->me($t2)['ok'] ?? false);
    }

    public function test_logout_removes_only_own_device(): void
    {
        $company = $this->makeCompany();
        $this->makeUser((int) $company->id);
        $t1 = $this->login()->getData(true)['token'];
        $t2 = $this->login()->getData(true)['token'];

        $this->logout($t1);
        $this->assertSame(1, DB::table('pos_caller_devices')->count());
        $this->assertTrue($this->me($t2)['ok'] ?? false, 'other phone unaffected');
    }

    public function test_device_cap_bumps_least_recently_seen(): void
    {
        $company = $this->makeCompany();
        $this->makeUser((int) $company->id);
        $t1 = $this->login('admin@shop.test', 'oldest')->getData(true)['token'];
        DB::table('pos_caller_devices')->update(['last_seen_at' => now()->subDays(3)]);
        $this->login('admin@shop.test', 'two');
        $this->login('admin@shop.test', 'three');
        $this->login('admin@shop.test', 'four'); // cap 3 → 'oldest' bumped

        $this->assertSame(3, DB::table('pos_caller_devices')->count());
        $this->assertNull(DB::table('pos_caller_devices')->where('device', 'oldest')->first());
        // Bumped token no longer authenticates (companyFromToken aborts 401).
        try {
            $this->me($t1);
            $this->fail('bumped device token must be rejected');
        } catch (\Illuminate\Http\Exceptions\HttpResponseException $e) {
            $this->assertSame(401, $e->getResponse()->getStatusCode());
        }
    }

    public function test_legacy_company_row_token_still_works(): void
    {
        $company = $this->makeCompany();
        $plain = $company->id . '|' . str_repeat('x', 48);
        DB::table('companies')->where('id', $company->id)
            ->update(['caller_app_token' => hash('sha256', $plain)]);

        $this->assertTrue($this->me($plain)['ok'] ?? false, 'beta phone must keep working without re-login');
    }

    public function test_revoke_is_admin_only(): void
    {
        $company = $this->makeCompany();
        app()->instance('currentCompanyId', (int) $company->id);
        $admin = $this->makeUser((int) $company->id);
        $this->login();
        $deviceId = (int) DB::table('pos_caller_devices')->value('id');

        // Cashier → 403, row survives.
        $this->makeUser((int) $company->id, 'pos_cashier', 'cash@shop.test');
        $request = Request::create('/pos/settings/caller-devices/revoke', 'POST', ['device_id' => $deviceId]);
        $request->setLaravelSession(app('session.store'));
        app()->instance('request', $request);
        $res = app(PosCallerIdController::class)->revokeDevice($request);
        $this->assertSame(403, $res->getStatusCode());
        $this->assertSame(1, DB::table('pos_caller_devices')->count());

        // Admin → deleted.
        Auth::guard('pos')->setUser($admin);
        $request = Request::create('/pos/settings/caller-devices/revoke', 'POST', ['device_id' => $deviceId]);
        $request->setLaravelSession(app('session.store'));
        app()->instance('request', $request);
        $res = app(PosCallerIdController::class)->revokeDevice($request);
        $this->assertSame(200, $res->getStatusCode());
        $this->assertSame(0, DB::table('pos_caller_devices')->count());
    }

    // ─── 2. Recent calls ─────────────────────────────────────────────────────

    public function test_recent_calls_returns_last_24h_only_and_plan_locks(): void
    {
        $company = $this->makeCompany();
        app()->instance('currentCompanyId', (int) $company->id);
        $this->makeUser((int) $company->id);

        DB::table('pos_caller_events')->insert([
            ['company_id' => $company->id, 'phone' => '923001112223', 'source' => 'sim', 'ring_at' => now(), 'created_at' => now()],
            ['company_id' => $company->id, 'phone' => '923004445556', 'source' => 'sim', 'ring_at' => now()->subHours(30), 'created_at' => now()->subHours(30)],
        ]);

        $data = $this->posGet('recentCalls')->getData(true);
        $this->assertTrue($data['enabled']);
        $this->assertCount(1, $data['calls']);

        $this->lockPlan($company);
        $data = $this->posGet('recentCalls')->getData(true);
        $this->assertFalse($data['enabled']);
        $this->assertSame([], $data['calls']);
    }

    // ─── 2b. Clearing handled calls (Task 1380) ──────────────────────────────

    private function posPost(string $method, array $body = []): \Illuminate\Http\JsonResponse
    {
        $request = Request::create('/pos/api/caller-clear', 'POST', $body);
        $request->setLaravelSession(app('session.store'));
        app()->instance('request', $request);
        return app(PosCallerIdController::class)->{$method}($request);
    }

    public function test_cleared_call_leaves_the_list_and_the_poll_but_new_rings_still_arrive(): void
    {
        $company = $this->makeCompany();
        app()->instance('currentCompanyId', (int) $company->id);
        $this->makeUser((int) $company->id, 'pos_cashier', 'cash@shop.test'); // cashier may clear

        $handled = DB::table('pos_caller_events')->insertGetId([
            'company_id' => $company->id, 'phone' => '923001112223', 'source' => 'sim',
            'ring_at' => now(), 'created_at' => now(),
        ]);
        $other = DB::table('pos_caller_events')->insertGetId([
            'company_id' => $company->id, 'phone' => '923004445556', 'source' => 'sim',
            'ring_at' => now(), 'created_at' => now(),
        ]);

        $res = $this->posPost('clearCalls', ['id' => $handled])->getData(true);
        $this->assertTrue($res['ok']);
        $this->assertSame(1, $res['cleared']);

        // Row kept (retention purge owns deletion), just flagged.
        $this->assertNotNull(DB::table('pos_caller_events')->where('id', $handled)->value('cleared_at'));

        // Gone from the list on EVERY counter; the untouched ring survives.
        $calls = $this->posGet('recentCalls')->getData(true)['calls'];
        $this->assertSame([$other], array_map(fn ($c) => (int) $c['id'], $calls));

        // Gone from the popup poll too — but the cursor still advances past it,
        // so a fresh ring after the cleared one is delivered normally.
        $poll = $this->posGet('events', ['after' => 0])->getData(true);
        $this->assertSame([$other], array_map(fn ($e) => (int) $e['id'], $poll['events']));
        $this->assertSame($other, (int) $poll['last_id']);

        $fresh = DB::table('pos_caller_events')->insertGetId([
            'company_id' => $company->id, 'phone' => '923007778889', 'source' => 'sim',
            'ring_at' => now(), 'created_at' => now(),
        ]);
        $poll = $this->posGet('events', ['after' => $other])->getData(true);
        $this->assertSame([$fresh], array_map(fn ($e) => (int) $e['id'], $poll['events']));
    }

    public function test_clear_all_empties_the_list_and_is_company_scoped(): void
    {
        $company = $this->makeCompany();
        $otherShop = $this->makeCompany(['name' => 'Other Shop']);
        app()->instance('currentCompanyId', (int) $company->id);
        $this->makeUser((int) $company->id);

        DB::table('pos_caller_events')->insert([
            ['company_id' => $company->id, 'phone' => '92300111', 'source' => 'sim', 'ring_at' => now(), 'created_at' => now()],
            ['company_id' => $company->id, 'phone' => '92300222', 'source' => 'sim', 'ring_at' => now(), 'created_at' => now()],
            ['company_id' => $otherShop->id, 'phone' => '92300333', 'source' => 'sim', 'ring_at' => now(), 'created_at' => now()],
        ]);

        $res = $this->posPost('clearCalls', ['all' => true])->getData(true);
        $this->assertSame(2, $res['cleared']);
        $this->assertSame([], $this->posGet('recentCalls')->getData(true)['calls']);
        // Another shop's ring is untouched.
        $this->assertNull(DB::table('pos_caller_events')->where('company_id', $otherShop->id)->value('cleared_at'));

        // Nothing to clear / no target → no silent success.
        $this->assertSame(422, $this->posPost('clearCalls', [])->getStatusCode());
    }

    public function test_clear_is_refused_when_caller_id_is_off_or_plan_locked(): void
    {
        $company = $this->makeCompany(['caller_id_enabled' => false]);
        app()->instance('currentCompanyId', (int) $company->id);
        $this->makeUser((int) $company->id);
        $id = DB::table('pos_caller_events')->insertGetId([
            'company_id' => $company->id, 'phone' => '92300111', 'source' => 'sim',
            'ring_at' => now(), 'created_at' => now(),
        ]);

        $this->assertSame(403, $this->posPost('clearCalls', ['id' => $id])->getStatusCode());

        DB::table('companies')->where('id', $company->id)->update(['caller_id_enabled' => true]);
        $this->lockPlan(Company::findOrFail($company->id));
        $this->assertSame(403, $this->posPost('clearCalls', ['id' => $id])->getStatusCode());
        $this->assertNull(DB::table('pos_caller_events')->where('id', $id)->value('cleared_at'));
    }

    // ─── 3. Repeat last order + khata ────────────────────────────────────────

    public function test_last_order_returns_latest_completed_bill_lines(): void
    {
        $company = $this->makeCompany();
        app()->instance('currentCompanyId', (int) $company->id);
        $this->makeUser((int) $company->id);

        $custId = DB::table('pos_customers')->insertGetId([
            'company_id' => $company->id, 'name' => 'Ahmad', 'phone' => '923001234567',
            'khata_balance' => 450.40, 'created_at' => now(), 'updated_at' => now(),
        ]);
        $old = DB::table('pos_transactions')->insertGetId([
            'company_id' => $company->id, 'customer_id' => $custId, 'status' => 'completed',
            'total_amount' => 100, 'created_at' => now()->subDays(2), 'updated_at' => now()->subDays(2),
        ]);
        $latest = DB::table('pos_transactions')->insertGetId([
            'company_id' => $company->id, 'customer_id' => $custId, 'status' => 'completed',
            'total_amount' => 900, 'created_at' => now()->subDay(), 'updated_at' => now()->subDay(),
        ]);
        // A newer but NON-completed bill must be ignored.
        DB::table('pos_transactions')->insert([
            'company_id' => $company->id, 'customer_id' => $custId, 'status' => 'held',
            'total_amount' => 50, 'created_at' => now(), 'updated_at' => now(),
        ]);
        DB::table('pos_transaction_items')->insert([
            ['transaction_id' => $old, 'item_type' => 'product', 'item_id' => 1, 'item_name' => 'Old Burger', 'quantity' => 1],
            ['transaction_id' => $latest, 'item_type' => 'product', 'item_id' => 7, 'item_name' => 'Zinger', 'quantity' => 2],
            ['transaction_id' => $latest, 'item_type' => 'service', 'item_id' => 3, 'item_name' => 'Delivery Svc', 'quantity' => 1],
            ['transaction_id' => $latest, 'item_type' => 'deal', 'item_id' => 9, 'item_name' => 'Mega Deal', 'quantity' => 1],
            ['transaction_id' => $latest, 'item_type' => 'manual', 'item_id' => null, 'item_name' => 'Extra Sauce', 'quantity' => 1],
        ]);

        $data = $this->posGet('lastOrder', ['customer_id' => $custId])->getData(true);
        $this->assertTrue($data['ok']);
        $this->assertSame(
            [['product', 7, 2.0], ['service', 3, 1.0]],
            array_map(fn ($i) => [$i['item_type'], $i['item_id'], (float) $i['quantity']], $data['items'])
        );
        $this->assertSame(['Mega Deal', 'Extra Sauce'], $data['skipped']);

        // Khata balance surfaces (int) in matchCustomer output via events path:
        $match = (new \ReflectionMethod(PosCallerIdController::class, 'matchCustomer'))
            ->invoke(app(PosCallerIdController::class), (int) $company->id, '923001234567', null);
        $this->assertSame(450, $match['khata_balance']);

        // Plan lock → 403.
        $this->lockPlan($company);
        $res = $this->posGet('lastOrder', ['customer_id' => $custId]);
        $this->assertSame(403, $res->getStatusCode());
    }
}

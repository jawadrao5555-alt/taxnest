<?php

namespace Tests\Feature;

use App\Http\Controllers\PosController;
use App\Models\User;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

/**
 * Task 705 — Manager PRA-only view + khufia key (Ctrl+Alt+Shift+L):
 * local-check mode & LOCAL cashier station identity switch.
 *
 * Locked in this suite:
 *   1. Manager default = PRA-only: transactions tab=local is forced to 'pra'
 *      until the session local-check flag is ON; toggle endpoint flips the
 *      flag (manager OK, cashier = hard 403).
 *   2. Identity switch round-trip: LOCAL-scoped cashier with an owner-set
 *      counterpart flips the pos-guard session to the PRA cashier and back;
 *      the original-id memory is session-based; NO Staff Hazri row is written
 *      for switch logins (double-count guard).
 *   3. Silent no-ops: unlinked cashier, pra-scoped cashier, link pointing at
 *      a manager (role-escalation block), cross-company link.
 *   4. Counterpart link save on the Team edit row is owner-only
 *      (canManageBillingScope pattern); local-scoped targets are refused.
 *   5. Day close stores the COMPLETE local stream: L-series provisionals join
 *      the frozen stream_summary local box while the report's own figures
 *      stay PRA-set (compliance boundary — reporting logic untouched).
 *   6. Z/X mode-gating: dayCloseReport/thermal expose showLocalStream =
 *      false by default, true when the local-check flag is ON.
 *
 * Pattern: APP_ENV=testing + sqlite :memory: + minimal Schema::create
 * (PosDayCloseStreamSplitTest approach; direct controller invocation).
 */
class PosManagerLocalCheckIdentitySwitchTest extends TestCase
{
    private static int $userSeq = 0;

    protected function setUp(): void
    {
        parent::setUp();

        Schema::dropAllTables();
        User::flushScopeColumnCache();
        \App\Services\PosFeatureService::flushGateCaches();

        Schema::create('companies', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('product_type')->nullable();
            $table->string('status')->nullable();
            $table->string('pos_dayclose_provisional_action')->nullable();
            $table->string('pos_dayclose_final_local_action')->nullable();
            $table->boolean('pos_customer_spend_persist')->default(true);
            $table->string('pos_business_day_cutoff')->nullable();
            $table->boolean('is_internal_account')->default(false);
            $table->integer('invoice_limit_override')->nullable();
            $table->boolean('pra_reporting_enabled')->default(false);
            $table->string('pra_connection_mode')->nullable();
            $table->boolean('pos_setup_completed')->default(true);
            $table->boolean('billing_scope_admin_enabled')->default(false);
            $table->softDeletes();
            $table->timestamps();
        });

        Schema::create('users', function (Blueprint $table) {
            $table->id();
            $table->string('name')->nullable();
            $table->string('email')->nullable()->unique();
            $table->string('username')->nullable();
            $table->string('phone')->nullable();
            $table->string('password')->nullable();
            $table->unsignedBigInteger('company_id')->nullable();
            $table->string('role')->nullable();
            $table->string('pos_role')->nullable();
            $table->string('pos_billing_scope', 10)->nullable();
            $table->unsignedBigInteger('pos_counterpart_user_id')->nullable();
            $table->boolean('pra_reporting_enabled')->nullable();
            $table->boolean('is_active')->default(true);
            $table->rememberToken();
            $table->timestamps();
        });

        Schema::create('pos_transactions', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('company_id');
            $table->string('invoice_number');
            $table->string('transaction_type')->nullable()->default('sale');
            $table->unsignedBigInteger('parent_transaction_id')->nullable();
            $table->string('business_date')->nullable();
            $table->string('status');
            $table->string('invoice_mode')->nullable();
            $table->string('pra_status')->nullable();
            $table->string('pra_invoice_number')->nullable();
            $table->boolean('is_archived')->default(false);
            $table->timestamp('archived_at')->nullable();
            $table->unsignedBigInteger('archived_by_report_id')->nullable();
            $table->unsignedBigInteger('created_by')->nullable();
            $table->unsignedBigInteger('customer_id')->nullable();
            $table->string('customer_name')->nullable();
            $table->string('customer_phone')->nullable();
            $table->decimal('subtotal', 12, 2)->default(0);
            $table->decimal('discount_amount', 12, 2)->default(0);
            $table->decimal('tax_rate', 8, 2)->nullable();
            $table->decimal('tax_amount', 12, 2)->default(0);
            $table->decimal('exempt_amount', 12, 2)->nullable();
            $table->decimal('total_amount', 12, 2)->default(0);
            $table->string('payment_method')->nullable();
            $table->boolean('tax_inclusive')->default(false);
            $table->unsignedBigInteger('rider_id')->nullable();
            $table->string('delivery_status')->nullable();
            $table->unsignedBigInteger('rider_settlement_id')->nullable();
            $table->timestamp('rider_settled_at')->nullable();
            $table->timestamps();
        });

        Schema::create('pos_transaction_items', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('transaction_id');
            $table->string('item_type')->nullable();
            $table->unsignedBigInteger('item_id')->nullable();
            $table->string('item_name');
            $table->decimal('quantity', 12, 3)->default(1);
            $table->decimal('unit_price', 12, 2)->default(0);
            $table->decimal('cost_price', 12, 2)->nullable();
            $table->decimal('subtotal', 12, 2)->default(0);
            $table->boolean('is_tax_exempt')->default(false);
            $table->decimal('tax_rate', 8, 2)->nullable();
            $table->decimal('tax_amount', 12, 2)->nullable();
            $table->decimal('item_discount_amount', 12, 2)->nullable();
            $table->timestamps();
        });

        Schema::create('pos_payments', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('transaction_id');
            $table->string('payment_method')->nullable();
            $table->decimal('amount', 12, 2)->default(0);
            $table->timestamps();
        });

        Schema::create('pos_day_close_reports', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('company_id');
            $table->date('report_date');
            $table->string('report_number')->nullable();
            $table->integer('deleted_final_count')->default(0);
            $table->integer('deleted_provisional_count')->default(0);
            $table->text('local_summary')->nullable();
            $table->text('rider_summary')->nullable();
            $table->text('stream_summary')->nullable();
            $table->integer('total_invoices')->default(0);
            $table->integer('pra_invoices')->default(0);
            $table->integer('local_invoices')->default(0);
            $table->integer('offline_invoices')->default(0);
            $table->decimal('gross_sales', 14, 2)->default(0);
            $table->decimal('total_discount', 14, 2)->default(0);
            $table->decimal('net_sales', 14, 2)->default(0);
            $table->decimal('total_tax', 14, 2)->default(0);
            $table->decimal('total_amount', 14, 2)->default(0);
            $table->decimal('cash_amount', 14, 2)->default(0);
            $table->decimal('card_amount', 14, 2)->default(0);
            $table->decimal('other_amount', 14, 2)->default(0);
            $table->integer('returns_count')->default(0);
            $table->decimal('returns_amount', 14, 2)->default(0);
            $table->string('first_invoice_number')->nullable();
            $table->string('last_invoice_number')->nullable();
            $table->timestamp('first_invoice_time')->nullable();
            $table->timestamp('last_invoice_time')->nullable();
            $table->unsignedBigInteger('closed_by')->nullable();
            $table->text('notes')->nullable();
            $table->string('hash')->nullable();
            $table->decimal('opening_float', 14, 2)->nullable();
            $table->decimal('counted_cash', 14, 2)->nullable();
            $table->decimal('expected_cash', 14, 2)->nullable();
            $table->decimal('cash_variance', 14, 2)->nullable();
            $table->timestamps();
        });

        Schema::create('pos_riders', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('company_id');
            $table->string('name');
            $table->timestamps();
        });

        Schema::create('restaurant_orders', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('company_id');
            $table->string('status')->nullable();
            $table->unsignedBigInteger('table_id')->nullable();
            $table->string('source')->nullable();
            $table->unsignedBigInteger('pos_transaction_id')->nullable();
            $table->unsignedBigInteger('created_by')->nullable();
            $table->timestamps();
        });

        Schema::create('branches', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('company_id');
            $table->string('name');
            $table->boolean('is_active')->default(true);
            $table->boolean('is_head_office')->default(false);
            $table->timestamps();
        });

        Schema::create('pos_products', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('company_id');
            $table->string('name');
            $table->string('category')->nullable();
            $table->decimal('price', 12, 2)->default(0);
            $table->decimal('cost_price', 12, 2)->default(0);
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });

        Schema::create('subscriptions', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('company_id');
            $table->unsignedBigInteger('pricing_plan_id')->nullable();
            $table->boolean('active')->default(false);
            $table->timestamps();
        });

        Schema::create('pricing_plans', function (Blueprint $table) {
            $table->id();
            $table->string('name')->nullable();
            $table->boolean('restaurant_enabled')->default(false);
            $table->timestamps();
        });

        Schema::create('pos_day_openings', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('company_id');
            $table->date('business_date');
            $table->decimal('opening_cash', 14, 2)->default(0);
            $table->timestamps();
        });

        // Login-event listener side tables (identity switch fires Login):
        // security_logs create is NOT try/caught in the listener — must exist.
        Schema::create('security_logs', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('user_id')->nullable();
            $table->string('action')->nullable();
            $table->string('ip_address')->nullable();
            $table->text('user_agent')->nullable();
            $table->text('metadata')->nullable();
            $table->timestamp('created_at')->nullable();
            $table->timestamp('updated_at')->nullable();
        });

        // Staff Hazri rows — the switch must NEVER add one (double-count guard).
        Schema::create('pos_user_sessions', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('company_id');
            $table->unsignedBigInteger('user_id');
            $table->timestamp('login_at')->nullable();
            $table->timestamp('logout_at')->nullable();
            $table->timestamp('last_activity_at')->nullable();
            $table->string('ip')->nullable();
            $table->timestamps();
        });

        // Counterpart-link changes are audit-logged (updateCashier).
        Schema::create('audit_logs', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('company_id')->nullable();
            $table->unsignedBigInteger('user_id')->nullable();
            $table->string('action')->nullable();
            $table->string('entity_type')->nullable();
            $table->unsignedBigInteger('entity_id')->nullable();
            $table->text('old_values')->nullable();
            $table->text('new_values')->nullable();
            $table->string('ip_address')->nullable();
            $table->string('sha256_hash')->nullable();
            $table->timestamp('created_at')->nullable();
            $table->timestamp('updated_at')->nullable();
        });
    }

    protected function tearDown(): void
    {
        Auth::guard('pos')->logout();
        session()->flush();
        parent::tearDown();
    }

    // ── fixtures ────────────────────────────────────────────────────────────

    private function makeCompany(array $attrs = []): int
    {
        return (int) DB::table('companies')->insertGetId(array_merge([
            'name' => 'Local Check Test Co',
            'product_type' => 'pos',
            'status' => 'active',
            'pos_dayclose_provisional_action' => 'save',
            'pos_dayclose_final_local_action' => 'save',
            'pos_setup_completed' => true,
            'created_at' => now(), 'updated_at' => now(),
        ], $attrs));
    }

    private function makeUser(int $companyId, array $attrs = []): User
    {
        $seq = ++self::$userSeq;
        $id = DB::table('users')->insertGetId(array_merge([
            'name' => 'User ' . $seq,
            'email' => "user{$seq}@t705.test",
            'password' => bcrypt('Secret@12345'),
            'company_id' => $companyId,
            'role' => 'employee',
            'pos_role' => 'pos_cashier',
            'is_active' => true,
            'created_at' => now(), 'updated_at' => now(),
        ], $attrs));

        return User::find($id);
    }

    private function makeTxn(int $companyId, string $number, array $attrs = []): int
    {
        return (int) DB::table('pos_transactions')->insertGetId(array_merge([
            'company_id' => $companyId,
            'invoice_number' => $number,
            'transaction_type' => 'sale',
            'business_date' => now()->toDateString(),
            'status' => 'completed',
            'invoice_mode' => 'pra',
            'pra_status' => 'submitted',
            'pra_invoice_number' => 'PRA-' . $number,
            'subtotal' => 0, 'discount_amount' => 0, 'tax_amount' => 0, 'total_amount' => 0,
            'payment_method' => 'cash',
            'created_at' => now(), 'updated_at' => now(),
        ], $attrs));
    }

    private function actAs(User $user, int $companyId): void
    {
        Auth::guard('pos')->setUser($user);
        app()->instance('currentCompanyId', $companyId);
    }

    // ── 1. Manager default PRA-only + toggle ─────────────────────────────────

    public function test_manager_local_tab_forced_to_pra_until_local_check_mode(): void
    {
        $cid = $this->makeCompany();
        $manager = $this->makeUser($cid, ['pos_role' => 'pos_manager']);
        $this->makeTxn($cid, 'P-0001', ['total_amount' => 100]);
        $this->actAs($manager, $cid);

        $request = Request::create('/pos/transactions', 'GET', ['tab' => 'local']);
        $data = (new PosController())->transactions($request)->getData();
        $this->assertSame('pra', $data['tab'], 'manager default must FORCE tab=local back to pra');

        // Toggle ON (manager allowed) → flag set → local tab honored again.
        $resp = (new PosController())->toggleLocalCheck(Request::create('/pos/api/local-check-toggle', 'POST'));
        $this->assertTrue($resp->getData(true)['on']);
        $this->assertTrue((bool) session('pos_local_check'));

        $data = (new PosController())->transactions($request)->getData();
        $this->assertSame('local', $data['tab'], 'local-check mode ON must reveal the Local tab');

        // Toggle OFF again — round trip.
        $resp = (new PosController())->toggleLocalCheck(Request::create('/pos/api/local-check-toggle', 'POST'));
        $this->assertFalse($resp->getData(true)['on']);
        $this->assertFalse((bool) session('pos_local_check'));
    }

    public function test_owner_admin_local_tab_unaffected_by_default(): void
    {
        $cid = $this->makeCompany();
        $admin = $this->makeUser($cid, ['pos_role' => 'pos_admin']);
        $this->actAs($admin, $cid);

        $request = Request::create('/pos/transactions', 'GET', ['tab' => 'local']);
        $data = (new PosController())->transactions($request)->getData();
        $this->assertSame('local', $data['tab'], 'owner/admin visibility must stay unchanged (no local-check needed)');
    }

    public function test_local_check_toggle_403_for_cashier(): void
    {
        $cid = $this->makeCompany();
        $cashier = $this->makeUser($cid, ['pos_role' => 'pos_cashier']);
        $this->actAs($cashier, $cid);

        $this->expectException(\Symfony\Component\HttpKernel\Exception\HttpException::class);
        (new PosController())->toggleLocalCheck(Request::create('/pos/api/local-check-toggle', 'POST'));
    }

    // ── 2. Identity switch round-trip + hazri guard ──────────────────────────

    public function test_identity_switch_round_trip_no_hazri_rows(): void
    {
        $cid = $this->makeCompany();
        $pra = $this->makeUser($cid, ['pos_billing_scope' => 'pra']);
        $local = $this->makeUser($cid, ['pos_billing_scope' => 'local', 'pos_counterpart_user_id' => $pra->id]);
        $this->actAs($local, $cid);

        // Forward: local → linked PRA counterpart.
        $resp = (new PosController())->identitySwitch(Request::create('/pos/api/identity-switch', 'POST'));
        $this->assertTrue($resp->getData(true)['switched']);
        $this->assertSame($pra->id, Auth::guard('pos')->id(), 'session must now bill as the PRA cashier ID');
        $this->assertSame($local->id, (int) session('pos_identity_original_id'), 'original local ID remembered in the session');

        // Back: PRA → original local ID; memory cleared.
        $resp = (new PosController())->identitySwitch(Request::create('/pos/api/identity-switch', 'POST'));
        $this->assertTrue($resp->getData(true)['switched']);
        $this->assertSame($local->id, Auth::guard('pos')->id(), 'second key press must restore the original local ID');
        $this->assertNull(session('pos_identity_original_id'));

        // Staff Hazri double-count guard: switch logins write NO session rows.
        $this->assertSame(0, DB::table('pos_user_sessions')->count(), 'identity switch must NEVER create a hazri row');
    }

    public function test_identity_switch_silent_noop_when_ineligible(): void
    {
        $cid = $this->makeCompany();
        $otherCid = $this->makeCompany(['name' => 'Other Co']);

        // (a) unlinked local cashier — nothing happens.
        $unlinked = $this->makeUser($cid, ['pos_billing_scope' => 'local']);
        $this->actAs($unlinked, $cid);
        $resp = (new PosController())->identitySwitch(Request::create('/pos/api/identity-switch', 'POST'));
        $this->assertFalse($resp->getData(true)['switched']);
        $this->assertSame($unlinked->id, Auth::guard('pos')->id());

        // (b) pra-scoped cashier even WITH a link — switch is local-side only.
        $target = $this->makeUser($cid, ['pos_billing_scope' => 'pra']);
        $praScoped = $this->makeUser($cid, ['pos_billing_scope' => 'pra', 'pos_counterpart_user_id' => $target->id]);
        $this->actAs($praScoped, $cid);
        $resp = (new PosController())->identitySwitch(Request::create('/pos/api/identity-switch', 'POST'));
        $this->assertFalse($resp->getData(true)['switched']);
        $this->assertSame($praScoped->id, Auth::guard('pos')->id());

        // (c) crafted link at a MANAGER — role-escalation hard block.
        $manager = $this->makeUser($cid, ['pos_role' => 'pos_manager']);
        $crafted = $this->makeUser($cid, ['pos_billing_scope' => 'local', 'pos_counterpart_user_id' => $manager->id]);
        $this->actAs($crafted, $cid);
        $resp = (new PosController())->identitySwitch(Request::create('/pos/api/identity-switch', 'POST'));
        $this->assertFalse($resp->getData(true)['switched'], 'switch must NEVER land on a manager/owner/admin account');
        $this->assertSame($crafted->id, Auth::guard('pos')->id());

        // (d) cross-company link — company isolation.
        $foreign = $this->makeUser($otherCid, ['pos_billing_scope' => 'pra']);
        $crossed = $this->makeUser($cid, ['pos_billing_scope' => 'local', 'pos_counterpart_user_id' => $foreign->id]);
        $this->actAs($crossed, $cid);
        $resp = (new PosController())->identitySwitch(Request::create('/pos/api/identity-switch', 'POST'));
        $this->assertFalse($resp->getData(true)['switched'], 'cross-company switch must be refused');
        $this->assertSame($crossed->id, Auth::guard('pos')->id());

        // (e) LOCAL-scoped counterpart — target must be able to bill PRA.
        $localTarget = $this->makeUser($cid, ['pos_billing_scope' => 'local']);
        $badLink = $this->makeUser($cid, ['pos_billing_scope' => 'local', 'pos_counterpart_user_id' => $localTarget->id]);
        $this->actAs($badLink, $cid);
        $resp = (new PosController())->identitySwitch(Request::create('/pos/api/identity-switch', 'POST'));
        $this->assertFalse($resp->getData(true)['switched']);

        $this->assertSame(0, DB::table('pos_user_sessions')->count());
    }

    /**
     * FULL Hazri lifecycle across an identity switch — the production paths:
     * real guard login (listener writes the attendance row), real PosAuth
     * middleware heartbeat, real logout closure. While switched, heartbeat +
     * logout must follow the ORIGINAL cashier (the physically-present staff),
     * and must never touch the PRA counterpart's own row from their real PC.
     */
    public function test_hazri_lifecycle_login_switch_heartbeat_logout(): void
    {
        $cid = $this->makeCompany();
        $pra = $this->makeUser($cid, ['pos_billing_scope' => 'pra']);
        $local = $this->makeUser($cid, ['pos_billing_scope' => 'local', 'pos_counterpart_user_id' => $pra->id]);
        app()->instance('currentCompanyId', $cid);

        // The PRA cashier is REALLY logged in on their own PC (own open row).
        $praRowId = DB::table('pos_user_sessions')->insertGetId([
            'company_id' => $cid, 'user_id' => $pra->id,
            'login_at' => now()->subHours(2), 'last_activity_at' => now()->subHour(),
            'created_at' => now()->subHours(2), 'updated_at' => now()->subHour(),
        ]);

        // 1. REAL login on this station — Login listener writes the row.
        Auth::guard('pos')->login($local);
        $localRow = DB::table('pos_user_sessions')->where('user_id', $local->id)->first();
        $this->assertNotNull($localRow, 'real login must create the attendance row');
        $this->assertNull($localRow->logout_at);
        // Backdate so elapsed duty time is measurable.
        DB::table('pos_user_sessions')->where('id', $localRow->id)
            ->update(['login_at' => now()->subMinutes(30)]);

        // 2. Switch to the PRA counterpart — no new row for anyone.
        $resp = (new PosController())->identitySwitch(Request::create('/pos/api/identity-switch', 'POST'));
        $this->assertTrue($resp->getData(true)['switched']);
        $this->assertSame($pra->id, Auth::guard('pos')->id());
        $this->assertSame(1, DB::table('pos_user_sessions')->where('user_id', $local->id)->count());
        $this->assertSame(1, DB::table('pos_user_sessions')->where('user_id', $pra->id)->count());

        // 3. Heartbeat WHILE SWITCHED (real middleware) — must stamp the
        // ORIGINAL cashier's row, not the PRA counterpart's other-PC row.
        $stale = now()->subMinutes(20);
        DB::table('pos_user_sessions')->where('id', $localRow->id)
            ->update(['last_activity_at' => $stale]);
        cache()->flush(); // clear the 5-min heartbeat throttle
        $mwReq = Request::create('/pos/dashboard', 'GET');
        $mwReq->setLaravelSession(app('session.store'));
        $mwResp = (new \App\Http\Middleware\PosAuth())->handle($mwReq, fn ($r) => response('ok'));
        $this->assertSame(200, $mwResp->getStatusCode());

        $localBeat = DB::table('pos_user_sessions')->where('id', $localRow->id)->value('last_activity_at');
        $this->assertTrue(\Carbon\Carbon::parse($localBeat)->gt(now()->subMinute()),
            'heartbeat while switched must stamp the ORIGINAL cashier row');
        $praBeat = DB::table('pos_user_sessions')->where('id', $praRowId)->value('last_activity_at');
        $this->assertTrue(\Carbon\Carbon::parse($praBeat)->lt(now()->subMinutes(50)),
            "the PRA counterpart's own-PC row must stay untouched by this station");

        // 4. Logout WHILE SWITCHED — closes the original cashier's row only.
        $outReq = Request::create('/pos/logout', 'POST');
        $outReq->setLaravelSession(app('session.store'));
        (new \App\Http\Controllers\PosAuthController())->logout($outReq);

        $localRowAfter = DB::table('pos_user_sessions')->where('id', $localRow->id)->first();
        $this->assertNotNull($localRowAfter->logout_at, "logout while switched must close the original cashier's row");
        $this->assertNull(DB::table('pos_user_sessions')->where('id', $praRowId)->value('logout_at'),
            "logout while switched must NOT clock out the PRA cashier's own PC");

        // 5. Duty hours = actual elapsed (~30 min), never inflated to cutoff.
        $duty = \App\Support\PosHazriDutyHours::fromSessions(
            collect([DB::table('pos_user_sessions')->where('id', $localRow->id)->first()]),
            now()->endOfDay()
        );
        $this->assertEqualsWithDelta(30, $duty->minutes, 2,
            'duty time must be the real elapsed window, not counted through the cutoff');
    }

    // ── 3. Counterpart link save — owner-only (Team edit row) ────────────────

    private function updatePayload(User $cashier, array $overrides = []): array
    {
        return array_merge([
            'name' => $cashier->name,
            'email' => $cashier->email,
            'phone' => '',
        ], $overrides);
    }

    public function test_counterpart_link_save_owner_only_and_validated(): void
    {
        $cid = $this->makeCompany(['billing_scope_admin_enabled' => false]);
        $owner = $this->makeUser($cid, ['role' => 'company_admin', 'pos_role' => null]);
        $manager = $this->makeUser($cid, ['pos_role' => 'pos_manager']);
        $pra = $this->makeUser($cid, ['pos_billing_scope' => 'pra']);
        $local = $this->makeUser($cid, ['pos_billing_scope' => 'local']);

        // Non-owner manager: input silently ignored (visibility rule).
        $this->actAs($manager, $cid);
        (new PosController())->updateCashier(
            Request::create("/pos/team/cashier/{$local->id}", 'PUT',
                $this->updatePayload($local, ['pos_counterpart_user_id' => (string) $pra->id])),
            $local->id
        );
        $this->assertNull(DB::table('users')->where('id', $local->id)->value('pos_counterpart_user_id'),
            'non-owner manager must not set the counterpart link');

        // Owner: link saved + audit-logged.
        $this->actAs($owner, $cid);
        (new PosController())->updateCashier(
            Request::create("/pos/team/cashier/{$local->id}", 'PUT',
                $this->updatePayload($local, ['pos_counterpart_user_id' => (string) $pra->id])),
            $local->id
        );
        $this->assertSame($pra->id, (int) DB::table('users')->where('id', $local->id)->value('pos_counterpart_user_id'));
        $this->assertSame(1, DB::table('audit_logs')->where('action', 'pos_counterpart_link_changed')->count());

        // Owner but INVALID target (local-scoped) — old link kept.
        $localTarget = $this->makeUser($cid, ['pos_billing_scope' => 'local']);
        (new PosController())->updateCashier(
            Request::create("/pos/team/cashier/{$local->id}", 'PUT',
                $this->updatePayload($local, ['pos_counterpart_user_id' => (string) $localTarget->id])),
            $local->id
        );
        $this->assertSame($pra->id, (int) DB::table('users')->where('id', $local->id)->value('pos_counterpart_user_id'),
            'a local-scoped target must be refused (old link kept)');

        // Owner clears the link with an empty value.
        (new PosController())->updateCashier(
            Request::create("/pos/team/cashier/{$local->id}", 'PUT',
                $this->updatePayload($local, ['pos_counterpart_user_id' => ''])),
            $local->id
        );
        $this->assertNull(DB::table('users')->where('id', $local->id)->value('pos_counterpart_user_id'));
    }

    // ── 4. Day close stores the COMPLETE local stream ────────────────────────

    public function test_day_close_stream_summary_includes_l_series_provisionals(): void
    {
        $cid = $this->makeCompany();
        // PRA-set rows: one submitted + one reporting-OFF final (NULL status).
        $this->makeTxn($cid, 'P-0001', ['subtotal' => 1000, 'tax_amount' => 170, 'total_amount' => 1170]);
        $this->makeTxn($cid, 'P-0002', ['pra_status' => null, 'pra_invoice_number' => null,
            'subtotal' => 300, 'total_amount' => 300]);
        // L-series provisional — invoice_mode='local' (excluded from the
        // PRA-set figures but MUST appear in the stored local stream box).
        $this->makeTxn($cid, 'L-0001', ['invoice_mode' => 'local', 'pra_status' => 'local',
            'pra_invoice_number' => null, 'subtotal' => 250, 'total_amount' => 250]);

        $result = (new PosController())->performDayClose($cid, now()->toDateString(), null);
        $this->assertSame('created', $result['status']);

        $row = DB::table('pos_day_close_reports')->where('company_id', $cid)->first();
        $split = json_decode($row->stream_summary, true);

        // Local box = reporting-OFF final (300) + L-series provisional (250).
        $this->assertSame(2, $split['local']['count'], 'local box must count provisionals too');
        $this->assertSame(550.0, (float) $split['local']['sales'], '300 final + 250 L-series provisional');
        $this->assertSame(1170.0, (float) $split['pra']['sales']);

        // COMPLIANCE: the report's own figures stay PRA-set — the L-series
        // provisional must NOT inflate any stored report column.
        $this->assertSame(1470.0, (float) $row->total_amount, 'report figures stay computed from the PRA set');
        $this->assertSame(2, (int) $row->total_invoices);
    }

    // ── 5. Z/X mode-gating flag ───────────────────────────────────────────────

    public function test_day_close_page_show_local_stream_follows_mode(): void
    {
        $cid = $this->makeCompany();
        $admin = $this->makeUser($cid, ['pos_role' => 'pos_admin']);
        $this->makeTxn($cid, 'P-0001', ['total_amount' => 100]);
        $this->actAs($admin, $cid);

        $request = Request::create('/pos/day-close', 'GET', ['date' => now()->toDateString()]);
        $data = (new PosController())->dayCloseReport($request)->getData();
        $this->assertFalse($data['showLocalStream'], 'normal mode = PRA-only Z display');

        session(['pos_local_check' => true]);
        $data = (new PosController())->dayCloseReport($request)->getData();
        $this->assertTrue($data['showLocalStream'], 'local-check mode ON = both streams on the Z display');
        session()->forget('pos_local_check');

        // LOCAL-scoped viewer always sees their own local world.
        $localManager = $this->makeUser($cid, ['pos_role' => 'pos_manager', 'pos_billing_scope' => 'local']);
        $this->actAs($localManager, $cid);
        $data = (new PosController())->dayCloseReport($request)->getData();
        $this->assertTrue($data['showLocalStream']);
    }

    public function test_thermal_z_show_local_stream_follows_mode_on_closed_day(): void
    {
        $cid = $this->makeCompany();
        $admin = $this->makeUser($cid, ['pos_role' => 'pos_admin']);
        $this->makeTxn($cid, 'P-0001', ['total_amount' => 117, 'tax_amount' => 17]);
        (new PosController())->performDayClose($cid, now()->toDateString(), null);
        $reportId = (int) DB::table('pos_day_close_reports')->where('company_id', $cid)->value('id');
        $this->actAs($admin, $cid);

        $data = (new PosController())->dayCloseThermal($reportId)->getData();
        $this->assertFalse($data['showLocalStream'], 'closed-day thermal Z defaults to PRA-only');

        session(['pos_local_check' => true]);
        $data = (new PosController())->dayCloseThermal($reportId)->getData();
        $this->assertTrue($data['showLocalStream'], 'old closed reports obey the mode too');
    }
}

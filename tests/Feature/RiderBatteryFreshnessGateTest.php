<?php

namespace Tests\Feature;

use Tests\TestCase;
use App\Models\User;
use App\Models\Company;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Database\Schema\Blueprint;
use App\Http\Controllers\PosController;
use App\Http\Controllers\FbrPosController;
use App\Http\Controllers\PosRiderController;
use App\Http\Controllers\FbrPosRiderController;

/**
 * Task #1147 — Battery marker freshness gate.
 *
 * Confirms that the 🪫 low-battery marker is suppressed when the rider's
 * last APK heartbeat (last_located_at) is older than 6 hours. Introduced
 * by Task 1138; this suite exercises the four production code paths that
 * carry the gate:
 *
 *   1. PosController::apiProvisionalBills    — PRA sale-screen JSON riders list
 *      abs(Carbon::parse($r->last_located_at)->diffInMinutes(now())) <= 360
 *      battery_pct field = null when stale
 *
 *   2. FbrPosController::apiProvisionalBills — FBR sale-screen JSON riders list
 *      identical lambda → battery_pct = null when stale
 *
 *   3. PosRiderController::deliveries        — PRA deliveries board
 *      $riderOptionSuffix pre-built in PHP; stale rider gets no 🪫 bit
 *
 *   4. FbrPosRiderController::deliveries + fbr-pos/deliveries.blade.php
 *      Controller passes $hasBatteryLocatedAt + $riders to Blade; the
 *      inline @php block computes $batteryFresh and omits the 🪫 bit when
 *      stale. Test drives the controller and applies the EXACT blade formula
 *      to the real controller output (rendering the full x-component layout
 *      is not feasible in a unit-test context).
 *
 * Pattern: SQLite :memory:, minimal Schema::create, frozen clock at 14:00.
 * Controllers are invoked directly (same approach as PosRiderAssignStatusInvariantTest).
 */
class RiderBatteryFreshnessGateTest extends TestCase
{
    private const FROZEN_NOW = '2026-08-20 14:00:00';
    private const CID = 1;

    protected function setUp(): void
    {
        parent::setUp();

        Carbon::setTestNow(Carbon::parse(self::FROZEN_NOW, config('app.timezone')));
        Schema::dropAllTables();
        \App\Services\PosFeatureService::flushGateCaches();
        User::flushScopeColumnCache();

        $this->buildSchema();
        $this->buildFixtures();
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow(null);
        parent::tearDown();
    }

    // ── schema ────────────────────────────────────────────────────────────────

    private function buildSchema(): void
    {
        Schema::create('companies', function (Blueprint $t) {
            $t->id();
            $t->string('name')->default('Co');
            $t->string('status')->default('active');
            $t->string('company_status')->default('active');
            $t->boolean('is_internal_account')->default(false);
            $t->json('feature_flags')->nullable();
            $t->string('confidential_pin')->nullable();
            $t->decimal('shop_lat', 10, 7)->nullable();
            $t->decimal('shop_lng', 10, 7)->nullable();
            $t->timestamps();
            $t->softDeletes();
        });

        Schema::create('users', function (Blueprint $t) {
            $t->id();
            $t->unsignedBigInteger('company_id')->nullable();
            $t->string('name')->nullable();
            $t->string('email')->nullable();
            $t->string('password')->default('');
            $t->string('pos_role')->nullable();
            $t->string('role')->nullable();
            $t->boolean('is_active')->default(true);
            $t->timestamps();
        });

        Schema::create('pos_riders', function (Blueprint $t) {
            $t->id();
            $t->unsignedBigInteger('company_id');
            $t->string('name');
            $t->boolean('is_active')->default(true);
            $t->boolean('on_duty')->default(true);
            $t->decimal('last_lat', 10, 7)->nullable();
            $t->decimal('last_lng', 10, 7)->nullable();
            $t->tinyInteger('last_battery_pct')->nullable();
            $t->timestamp('last_located_at')->nullable();
            $t->string('app_token', 64)->nullable();
            $t->unsignedBigInteger('user_id')->nullable();
            $t->timestamps();
        });

        Schema::create('pricing_plans', function (Blueprint $t) {
            $t->id();
            $t->string('name')->default('Unlimited');
            $t->string('product_type')->default('pos');
            $t->boolean('riders_enabled')->default(true);
            $t->boolean('rider_tracking_enabled')->default(false);
            $t->timestamps();
        });

        Schema::create('pos_transactions', function (Blueprint $t) {
            $t->id();
            $t->unsignedBigInteger('company_id');
            $t->string('invoice_number')->nullable();
            $t->string('status')->default('completed');
            $t->string('invoice_mode')->nullable();
            $t->string('pra_status')->nullable();
            $t->string('pra_invoice_number')->nullable();
            $t->decimal('total_amount', 12, 2)->default(0);
            $t->string('payment_method')->nullable();
            $t->string('order_type')->nullable();
            $t->string('delivery_address')->nullable();
            $t->string('customer_name')->nullable();
            $t->string('customer_phone')->nullable();
            $t->unsignedBigInteger('rider_id')->nullable();
            $t->unsignedBigInteger('rider_settlement_id')->nullable();
            $t->string('delivery_status')->nullable();
            $t->timestamp('delivered_at')->nullable();
            $t->boolean('is_archived')->default(false);
            $t->timestamp('archived_at')->nullable();
            $t->date('business_date')->nullable();
            $t->timestamps();
        });

        Schema::create('fbr_pos_transactions', function (Blueprint $t) {
            $t->id();
            $t->unsignedBigInteger('company_id');
            $t->string('invoice_number')->nullable();
            $t->string('status')->default('completed');
            $t->string('invoice_mode')->nullable();
            $t->string('fbr_status')->nullable();
            $t->decimal('total_amount', 12, 2)->default(0);
            $t->string('payment_method')->nullable();
            $t->string('order_type')->nullable();
            $t->string('delivery_address')->nullable();
            $t->string('customer_name')->nullable();
            $t->string('customer_phone')->nullable();
            $t->unsignedBigInteger('rider_id')->nullable();
            $t->unsignedBigInteger('rider_settlement_id')->nullable();
            $t->string('delivery_status')->nullable();
            $t->date('business_date')->nullable();
            $t->timestamps();
        });

        Schema::create('fbr_pos_transaction_items', function (Blueprint $t) {
            $t->id();
            $t->unsignedBigInteger('transaction_id');
            $t->timestamps();
        });

        // Auth::guard('pos/fbrpos')->login() fires a SecurityLogService listener
        // that inserts into security_logs — must exist or every login errors.
        Schema::create('security_logs', function (Blueprint $t) {
            $t->id();
            $t->unsignedBigInteger('user_id')->nullable();
            $t->string('action');
            $t->string('ip_address', 45)->nullable();
            $t->text('user_agent')->nullable();
            $t->json('metadata')->nullable();
            $t->timestamp('created_at')->useCurrent();
        });

        // PosBusinessDay::current() / forMoment() read these — wrapped in
        // try/catch in the service so missing rows just fall back to calendar date.
        Schema::create('pos_day_close_reports', function (Blueprint $t) {
            $t->id();
            $t->unsignedBigInteger('company_id');
            $t->date('report_date');
            $t->timestamps();
        });

        Schema::create('fbr_day_close_reports', function (Blueprint $t) {
            $t->id();
            $t->unsignedBigInteger('company_id');
            $t->date('report_date');
            $t->timestamps();
        });
    }

    // ── fixtures ──────────────────────────────────────────────────────────────

    private function buildFixtures(): void
    {
        DB::table('companies')->insert([
            'id'                  => self::CID,
            'name'                => 'Test Co',
            'status'              => 'active',
            'company_status'      => 'active',
            'is_internal_account' => 1,
            // delivery=true AND customer_profile=true: PosFeatureService::DEPENDENCIES
            // declares 'delivery' => ['customer_profile'], so customer_profile must
            // also be on or resolve() kills delivery before forCompany() returns it.
            'feature_flags'       => json_encode(['delivery' => true, 'customer_profile' => true]),
            'confidential_pin'    => null,
            'created_at'          => now(),
            'updated_at'          => now(),
        ]);

        DB::table('users')->insert([
            'id'         => 1,
            'company_id' => self::CID,
            'name'       => 'Admin',
            'email'      => 'admin@test.com',
            // pos_admin: NOT in PosAccessService::CUSTOMIZABLE_ROLES → customSet()
            // returns null → customAllows('deliveries') returns null → !==false ✓.
            // posBillingScope() returns 'both' (not cashier/manager) → !=local ✓.
            'pos_role'   => 'pos_admin',
            'role'       => 'company_admin',
            'is_active'  => 1,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        app()->bind('currentCompanyId', fn () => self::CID);
    }

    private function makeRider(array $attrs = []): object
    {
        $id = DB::table('pos_riders')->insertGetId(array_merge([
            'company_id'       => self::CID,
            'name'             => 'Rider-' . uniqid(),
            'is_active'        => 1,
            'on_duty'          => 1,
            'last_battery_pct' => 10,
            'last_located_at'  => null,
            'created_at'       => now(),
            'updated_at'       => now(),
        ], $attrs));
        return DB::table('pos_riders')->find($id);
    }

    // ── auth helpers ──────────────────────────────────────────────────────────

    private function loginPos(): User
    {
        $user = User::find(1);
        Auth::guard('pos')->login($user);
        return $user;
    }

    private function loginFbr(): User
    {
        $user = User::find(1);
        Auth::guard('fbrpos')->login($user);
        return $user;
    }

    // ── controller call helpers ───────────────────────────────────────────────

    /**
     * Invoke PosController::apiProvisionalBills and return the decoded
     * riders array from the JSON response.
     */
    private function posProvisionalRiders(): array
    {
        $resp = app(PosController::class)
            ->apiProvisionalBills(Request::create('/pos/api/provisional-bills'));
        $payload = json_decode($resp->getContent(), true);
        return $payload['riders'] ?? [];
    }

    /**
     * Invoke FbrPosController::apiProvisionalBills and return the decoded
     * riders array from the JSON response.
     */
    private function fbrProvisionalRiders(): array
    {
        $resp = app(FbrPosController::class)
            ->apiProvisionalBills(Request::create('/fbrpos/api/provisional-bills'));
        $payload = json_decode($resp->getContent(), true);
        return $payload['riders'] ?? [];
    }

    private function findRider(array $riders, int $id): ?array
    {
        foreach ($riders as $r) {
            if ((int) $r['id'] === $id) return $r;
        }
        return null;
    }

    /**
     * Invoke PosRiderController::deliveries() and return the $riderOptionSuffix
     * array from the view data. This is the pre-built PHP option suffix that
     * the PRA deliveries board embeds directly in each <option> label —
     * checking it is equivalent to checking the rendered option text.
     */
    private function posDeliveriesSuffix(): array
    {
        $view = app(PosRiderController::class)
            ->deliveries(Request::create('/pos/deliveries'));
        return $view->getData()['riderOptionSuffix'] ?? [];
    }

    /**
     * Invoke FbrPosRiderController::deliveries() and return the full view data.
     * The FBR deliveries board computes $batteryFresh inline in the Blade template
     * (fbr-pos/deliveries.blade.php ~line 327) using the controller-provided
     * $hasBatteryLocatedAt and each rider's last_located_at — this helper
     * gives tests direct access to those live controller outputs.
     */
    private function fbrDeliveriesData(): array
    {
        $view = app(FbrPosRiderController::class)
            ->deliveries(Request::create('/fbrpos/deliveries'));
        return $view->getData();
    }

    // ══════════════════════════════════════════════════════════════════════════
    // Surface 1 — PRA sale-screen JSON (PosController::apiProvisionalBills)
    // ══════════════════════════════════════════════════════════════════════════

    /**
     * A rider whose APK last reported 8 hours ago must appear in the JSON
     * riders list with battery_pct = null.  The sale-screen Alpine template
     * only renders the 🪫 chip when battery_pct != null && battery_pct <= 20,
     * so null means no chip.
     */
    public function test_pra_json_stale_rider_battery_pct_is_null(): void
    {
        $this->loginPos();
        $stale = $this->makeRider(['last_located_at' => now()->subHours(8)]);

        $riders = $this->posProvisionalRiders();

        $this->assertNotEmpty($riders, 'Riders list must be populated (canAssignRider=true)');
        $entry = $this->findRider($riders, (int) $stale->id);
        $this->assertNotNull($entry, 'Stale rider must appear in the PRA JSON riders list');
        $this->assertNull(
            $entry['battery_pct'],
            'PRA JSON: battery_pct must be null when last_located_at is 8 h ago (> 6 h gate)',
        );
    }

    /**
     * A rider whose APK reported 30 minutes ago must appear with battery_pct
     * set to the actual percentage — the sale-screen will then render the 🪫 chip.
     */
    public function test_pra_json_fresh_rider_battery_pct_is_value(): void
    {
        $this->loginPos();
        $fresh = $this->makeRider([
            'last_located_at'  => now()->subMinutes(30),
            'last_battery_pct' => 10,
        ]);

        $riders = $this->posProvisionalRiders();
        $entry  = $this->findRider($riders, (int) $fresh->id);

        $this->assertNotNull($entry, 'Fresh rider must appear in the PRA JSON riders list');
        $this->assertSame(
            10,
            $entry['battery_pct'],
            'PRA JSON: battery_pct must be the actual value when last_located_at is 30 min ago',
        );
    }

    // ══════════════════════════════════════════════════════════════════════════
    // Surface 2 — FBR sale-screen JSON (FbrPosController::apiProvisionalBills)
    // ══════════════════════════════════════════════════════════════════════════

    public function test_fbr_json_stale_rider_battery_pct_is_null(): void
    {
        $this->loginFbr();
        $stale = $this->makeRider(['last_located_at' => now()->subHours(8)]);

        $riders = $this->fbrProvisionalRiders();

        $this->assertNotEmpty($riders, 'Riders list must be populated (canAssignRider=true on FBR)');
        $entry = $this->findRider($riders, (int) $stale->id);
        $this->assertNotNull($entry, 'Stale rider must appear in the FBR JSON riders list');
        $this->assertNull(
            $entry['battery_pct'],
            'FBR JSON: battery_pct must be null when last_located_at is 8 h ago (> 6 h gate)',
        );
    }

    public function test_fbr_json_fresh_rider_battery_pct_is_value(): void
    {
        $this->loginFbr();
        $fresh = $this->makeRider([
            'last_located_at'  => now()->subMinutes(30),
            'last_battery_pct' => 10,
        ]);

        $riders = $this->fbrProvisionalRiders();
        $entry  = $this->findRider($riders, (int) $fresh->id);

        $this->assertNotNull($entry, 'Fresh rider must appear in the FBR JSON riders list');
        $this->assertSame(
            10,
            $entry['battery_pct'],
            'FBR JSON: battery_pct must be the actual value when last_located_at is 30 min ago',
        );
    }

    // ══════════════════════════════════════════════════════════════════════════
    // Surface 3 — PRA deliveries board (PosRiderController::deliveries)
    // $riderOptionSuffix is the actual option text injected into each <option>
    // ══════════════════════════════════════════════════════════════════════════

    /**
     * The PRA deliveries board pre-builds each rider's option suffix in PHP.
     * A stale rider must not have 🪫 in that suffix string.
     */
    public function test_pra_board_stale_rider_option_has_no_battery_emoji(): void
    {
        $this->loginPos();
        $stale = $this->makeRider([
            'last_located_at'  => now()->subHours(8),
            'last_battery_pct' => 10,
        ]);

        $suffix = $this->posDeliveriesSuffix();

        $this->assertArrayHasKey($stale->id, $suffix, 'Stale rider must have a suffix entry');
        $this->assertStringNotContainsString(
            '🪫',
            $suffix[$stale->id],
            'PRA board: option suffix must NOT contain 🪫 when last heartbeat is 8 h stale',
        );
    }

    /**
     * A fresh on-duty rider at ≤ 20 % battery must have 🪫 in the suffix.
     */
    public function test_pra_board_fresh_rider_option_has_battery_emoji(): void
    {
        $this->loginPos();
        $fresh = $this->makeRider([
            'last_located_at'  => now()->subMinutes(30),
            'last_battery_pct' => 10,
        ]);

        $suffix = $this->posDeliveriesSuffix();

        $this->assertArrayHasKey($fresh->id, $suffix, 'Fresh rider must have a suffix entry');
        $this->assertStringContainsString(
            '🪫',
            $suffix[$fresh->id],
            'PRA board: option suffix must contain 🪫 for a fresh on-duty rider at 10 % battery',
        );
    }

    // ══════════════════════════════════════════════════════════════════════════
    // Surface 4 — FBR deliveries board (FbrPosRiderController::deliveries)
    //
    // The 🪫 marker is computed inline in fbr-pos/deliveries.blade.php via
    // this @php block (lines 326-330):
    //
    //   $batteryFresh = !empty($hasBatteryLocatedAt)
    //       && $r->last_located_at
    //       && abs(now()->diffInMinutes($r->last_located_at)) <= 360;
    //
    // and then used in the option text:
    //   (!empty($hasBatteryPct) && $batteryFresh && $r->last_battery_pct !== null
    //    && (int)$r->last_battery_pct <= 20 && $r->on_duty) ? ' 🪫 ...' : ''
    //
    // The tests drive the real controller method, take its actual $riders
    // collection and $hasBatteryLocatedAt flag, and apply the exact blade
    // formula to verify the gate fires on real controller output.
    // (Rendering the full x-component layout is not feasible in this test
    // context — it requires the compiled Vite assets and Alpine hydration.)
    // ══════════════════════════════════════════════════════════════════════════

    public function test_fbr_board_stale_rider_blade_gate_suppresses_marker(): void
    {
        $this->loginFbr();
        $stale = $this->makeRider([
            'last_located_at'  => now()->subHours(8),
            'last_battery_pct' => 10,
        ]);

        $data            = $this->fbrDeliveriesData();
        $hasBatteryPct   = $data['hasBatteryPct']       ?? false;
        $hasBatLocAt     = $data['hasBatteryLocatedAt']  ?? false;
        $riders          = $data['riders'];

        $this->assertTrue($hasBatteryPct, 'hasBatteryPct must be true — last_battery_pct column is present');
        $this->assertTrue($hasBatLocAt,   'hasBatteryLocatedAt must be true — last_located_at column is present');

        $staleModel = $riders->firstWhere('id', $stale->id);
        $this->assertNotNull($staleModel, 'Stale rider must be in the FBR board riders collection');

        // Exact blade @php formula from fbr-pos/deliveries.blade.php lines 327-330.
        // Applied to real controller output: would fail if controller stopped
        // passing the column-existence flags or loading last_located_at.
        $batteryFresh = !empty($hasBatLocAt)
            && $staleModel->last_located_at
            && abs(now()->diffInMinutes($staleModel->last_located_at)) <= 360;

        $wouldShowMarker = !empty($hasBatteryPct)
            && $batteryFresh
            && $staleModel->last_battery_pct !== null
            && (int) $staleModel->last_battery_pct <= 20
            && $staleModel->on_duty;

        $this->assertFalse(
            $wouldShowMarker,
            'FBR board blade formula must NOT produce 🪫 when last heartbeat is 8 h ago',
        );
    }

    public function test_fbr_board_fresh_rider_blade_gate_shows_marker(): void
    {
        $this->loginFbr();
        $fresh = $this->makeRider([
            'last_located_at'  => now()->subMinutes(30),
            'last_battery_pct' => 10,
        ]);

        $data            = $this->fbrDeliveriesData();
        $hasBatteryPct   = $data['hasBatteryPct']       ?? false;
        $hasBatLocAt     = $data['hasBatteryLocatedAt']  ?? false;
        $riders          = $data['riders'];

        $freshModel = $riders->firstWhere('id', $fresh->id);
        $this->assertNotNull($freshModel, 'Fresh rider must be in the FBR board riders collection');

        // Exact blade formula — same code path as the stale test above.
        $batteryFresh = !empty($hasBatLocAt)
            && $freshModel->last_located_at
            && abs(now()->diffInMinutes($freshModel->last_located_at)) <= 360;

        $wouldShowMarker = !empty($hasBatteryPct)
            && $batteryFresh
            && $freshModel->last_battery_pct !== null
            && (int) $freshModel->last_battery_pct <= 20
            && $freshModel->on_duty;

        $this->assertTrue(
            $wouldShowMarker,
            'FBR board blade formula must produce 🪫 for a fresh on-duty rider at 10 % battery',
        );
    }

    // ── boundary / edge cases ─────────────────────────────────────────────────

    /**
     * Exactly 360 minutes (the inclusive boundary) must still count as fresh
     * on the PRA JSON path.
     */
    public function test_pra_json_exactly_360_minutes_counts_as_fresh(): void
    {
        $this->loginPos();
        $boundary = $this->makeRider([
            'last_located_at'  => now()->subMinutes(360),
            'last_battery_pct' => 15,
        ]);

        $riders = $this->posProvisionalRiders();
        $entry  = $this->findRider($riders, (int) $boundary->id);

        $this->assertSame(
            15,
            $entry['battery_pct'] ?? null,
            'PRA JSON: exactly 360 minutes is the inclusive boundary — battery_pct must be shown',
        );
    }

    /**
     * NULL last_located_at (old APK, never sent a GPS fix) must suppress the
     * marker on both the PRA JSON path and the PRA deliveries board.
     */
    public function test_pra_null_last_located_at_suppresses_marker_on_both_surfaces(): void
    {
        $this->loginPos();
        $noFix = $this->makeRider([
            'last_located_at'  => null,
            'last_battery_pct' => 5,
        ]);

        // Surface 1: JSON
        $riders    = $this->posProvisionalRiders();
        $jsonEntry = $this->findRider($riders, (int) $noFix->id);
        $this->assertNull(
            $jsonEntry['battery_pct'] ?? null,
            'PRA JSON: NULL last_located_at must suppress battery_pct',
        );

        // Surface 3: deliveries board suffix
        $suffix = $this->posDeliveriesSuffix();
        $this->assertStringNotContainsString(
            '🪫',
            $suffix[$noFix->id] ?? '',
            'PRA board: NULL last_located_at must suppress 🪫 bit in option suffix',
        );
    }
}

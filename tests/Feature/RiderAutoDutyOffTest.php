<?php

namespace Tests\Feature;

use Tests\TestCase;
use App\Models\PosRider;
use Carbon\Carbon;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use App\Http\Controllers\PosRiderTrackingController;
use Illuminate\Http\Request;

/**
 * Task #1102 — live-map warnings + lazy auto duty-off.
 *
 * Covers:
 *  1. trackingData poll sweeps: duty running past the 3 AM cutoff flips
 *     on_duty=false + stamps duty_auto_off_at; a session started after the
 *     cutoff is untouched. Sweep is idempotent (second poll changes nothing).
 *  2. auto_off note surfaces in the trackingData payload.
 *  3. Upload path: a forgotten-duty rider's fresh points are rejected with
 *     409 (the app's existing self-correct signal) because maybeAutoDutyOff
 *     runs before the per-point duty gate.
 *  4. appDuty toggle clears the duty_auto_off_at stamp.
 *  5. is_silent: on-duty rider with a stale last fix flags red; a rider who
 *     just came on duty gets the full window as grace (yesterday's fix age
 *     is ignored).
 *  6. is_idle: stationary-with-open-deliveries rider flags amber; a moving
 *     rider does not.
 *
 * Pattern: SQLite :memory:, minimal Schema::create, controller invoked
 * directly — same approach as RiderOfflineLocationSyncTest. Time is frozen
 * at 14:00 so the 3 AM cutoff math is deterministic regardless of when the
 * suite runs.
 */
class RiderAutoDutyOffTest extends TestCase
{
    private const FROZEN_NOW = '2026-08-20 14:00:00';

    protected function setUp(): void
    {
        parent::setUp();

        Carbon::setTestNow(Carbon::parse(self::FROZEN_NOW, config('app.timezone')));

        Schema::dropAllTables();
        \App\Services\PosFeatureService::flushGateCaches();

        // is_internal_account = true short-circuits PosFeatureService::planAllows.
        Schema::create('companies', function (Blueprint $table) {
            $table->id();
            $table->string('name')->default('Test Co');
            $table->string('status')->default('active');
            $table->string('company_status')->default('active');
            $table->boolean('is_internal_account')->default(false);
            $table->timestamps();
            $table->softDeletes();
        });

        Schema::create('users', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('company_id')->nullable();
            $table->string('email')->nullable();
            $table->string('password')->nullable();
            $table->string('pos_role')->nullable();
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });

        // planAllows probes pricing_plans columns even for internal accounts.
        Schema::create('pricing_plans', function (Blueprint $table) {
            $table->id();
            $table->string('name')->default('Unlimited');
            $table->string('product_type')->default('pos');
            $table->boolean('riders_enabled')->default(true);
            $table->boolean('rider_tracking_enabled')->default(true);
            $table->timestamps();
        });

        Schema::create('pos_riders', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('company_id');
            $table->string('name')->default('Rider');
            $table->string('phone')->nullable();
            $table->boolean('is_active')->default(true);
            $table->boolean('on_duty')->default(false);
            $table->timestamp('duty_started_at')->nullable();
            $table->timestamp('duty_auto_off_at')->nullable();
            $table->decimal('last_lat', 10, 7)->nullable();
            $table->decimal('last_lng', 10, 7)->nullable();
            $table->timestamp('last_located_at')->nullable();
            $table->string('app_token', 64)->nullable();
            $table->unsignedBigInteger('user_id')->nullable();
            $table->timestamps();
        });

        Schema::create('pos_rider_locations', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('company_id');
            $table->unsignedBigInteger('rider_id');
            $table->decimal('lat', 10, 7);
            $table->decimal('lng', 10, 7);
            $table->unsignedSmallInteger('accuracy_m')->nullable();
            $table->dateTime('recorded_at');
            $table->bigInteger('client_ts_ms')->nullable()->unsigned();
            $table->boolean('is_offline')->nullable()->default(null);
            $table->timestamp('created_at')->useCurrent();
            $table->unique(['rider_id', 'client_ts_ms'], 'prl_rider_client_ts_dedup');
            $table->index(['company_id', 'rider_id', 'recorded_at'], 'prl_company_rider_time');
        });

        // Minimal pos_transactions for the open-deliveries grouped query.
        // No rider_assigned_at column → controller falls back to created_at.
        Schema::create('pos_transactions', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('company_id');
            $table->unsignedBigInteger('rider_id')->nullable();
            $table->string('delivery_status')->nullable();
            $table->timestamps();
        });

        DB::table('companies')->insert([
            'id' => 1, 'is_internal_account' => 1,
            'created_at' => now(), 'updated_at' => now(),
        ]);
        DB::table('pricing_plans')->insert([
            'name' => 'Unlimited', 'product_type' => 'pos',
            'riders_enabled' => 1, 'rider_tracking_enabled' => 1,
            'created_at' => now(), 'updated_at' => now(),
        ]);

        app()->bind('currentCompanyId', fn () => 1);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow(null);
        parent::tearDown();
    }

    // ── fixtures ─────────────────────────────────────────────────────────────

    private function makeRider(array $attrs = []): PosRider
    {
        return PosRider::create(array_merge([
            'company_id' => 1,
            'name'       => 'Rider ' . uniqid(),
            'is_active'  => true,
            'on_duty'    => true,
        ], $attrs));
    }

    private function trackingData(): array
    {
        $resp = app(PosRiderTrackingController::class)->trackingData();
        $this->assertEquals(200, $resp->status());

        return json_decode($resp->getContent(), true);
    }

    private function riderRow(array $payload, int $riderId): array
    {
        foreach ($payload['riders'] as $row) {
            if ($row['id'] === $riderId) {
                return $row;
            }
        }
        $this->fail("Rider {$riderId} missing from trackingData payload");
    }

    // ── 1+2: sweep on the poll ───────────────────────────────────────────────

    public function test_poll_sweep_flips_forgotten_duty_and_stamps_note(): void
    {
        // Duty started 26h ago — always before the most recent 3 AM cutoff.
        $forgotten = $this->makeRider(['duty_started_at' => now()->subHours(26)]);
        // Duty started at 08:00 today — after the 03:00 cutoff, must survive.
        $fresh = $this->makeRider(['duty_started_at' => now()->subHours(6)]);

        $payload = $this->trackingData();

        $forgotten->refresh();
        $fresh->refresh();

        $this->assertFalse((bool) $forgotten->on_duty, 'Duty past the cutoff must be auto-ended');
        $this->assertNotNull($forgotten->duty_auto_off_at, 'Auto-off must stamp duty_auto_off_at');
        $this->assertTrue((bool) $fresh->on_duty, 'Duty started after the cutoff must survive the sweep');
        $this->assertNull($fresh->duty_auto_off_at);

        // The very same poll already reports the flip + the note.
        $this->assertFalse($this->riderRow($payload, $forgotten->id)['on_duty']);
        $this->assertTrue($this->riderRow($payload, $forgotten->id)['auto_off']);
        $this->assertFalse($this->riderRow($payload, $fresh->id)['auto_off']);

        // Idempotent: a second poll changes nothing.
        $stamp = (string) $forgotten->duty_auto_off_at;
        Carbon::setTestNow(now()->addMinutes(5));
        $this->trackingData();
        $forgotten->refresh();
        $this->assertEquals($stamp, (string) $forgotten->duty_auto_off_at,
            'Second sweep must not re-stamp an already-flipped rider');
    }

    public function test_sweep_leaves_null_started_at_alone(): void
    {
        // NULL duty_started_at = session age unprovable — the sweep must not
        // guess (also keeps legacy fixtures/rows safe).
        $legacy = $this->makeRider(['duty_started_at' => null]);

        $this->trackingData();

        $legacy->refresh();
        $this->assertTrue((bool) $legacy->on_duty);
        $this->assertNull($legacy->duty_auto_off_at);
    }

    // ── 3: upload path self-corrects the app via 409 ─────────────────────────

    public function test_upload_after_cutoff_flips_duty_and_returns_409(): void
    {
        $plain = '1|' . str_repeat('x', 48);
        $rider = $this->makeRider([
            'duty_started_at' => now()->subHours(26),
            'app_token'       => hash('sha256', $plain),
        ]);

        $request = Request::create('/api/rider-app/v1/locations', 'POST',
            ['points' => [['lat' => 31.5204, 'lng' => 74.3587]]], [], [],
            ['HTTP_AUTHORIZATION' => 'Bearer ' . $plain]);
        $resp = app(PosRiderTrackingController::class)->appLocations($request);

        // Fresh point rejected by the duty gate → 409 (existing stop signal).
        $this->assertEquals(409, $resp->status());
        $rider->refresh();
        $this->assertFalse((bool) $rider->on_duty);
        $this->assertNotNull($rider->duty_auto_off_at);
        $this->assertEquals(0, DB::table('pos_rider_locations')->count());
    }

    // ── 4: duty toggle clears the stamp ──────────────────────────────────────

    public function test_duty_on_clears_auto_off_stamp(): void
    {
        $plain = '1|' . str_repeat('y', 48);
        $rider = $this->makeRider([
            'on_duty'          => false,
            'duty_auto_off_at' => now()->subHours(10),
            'app_token'        => hash('sha256', $plain),
        ]);

        $request = Request::create('/api/rider-app/v1/duty', 'POST',
            ['on' => true], [], [], ['HTTP_AUTHORIZATION' => 'Bearer ' . $plain]);
        $resp = app(PosRiderTrackingController::class)->appDuty($request);

        $this->assertEquals(200, $resp->status());
        $rider->refresh();
        $this->assertTrue((bool) $rider->on_duty);
        $this->assertNull($rider->duty_auto_off_at, 'Duty toggle must clear the auto-off note');
    }

    // ── 5: is_silent ─────────────────────────────────────────────────────────

    public function test_silent_flag_uses_later_of_duty_start_and_last_fix(): void
    {
        // On duty for hours, last fix 12 min ago → silent (threshold 10 min).
        $silent = $this->makeRider([
            'duty_started_at' => now()->subHours(3),
            'last_lat'        => 31.52, 'last_lng' => 74.35,
            'last_located_at' => now()->subMinutes(12),
        ]);
        // Just came on duty 2 min ago; last fix is from yesterday → grace, not silent.
        $justOn = $this->makeRider([
            'duty_started_at' => now()->subMinutes(2),
            'last_lat'        => 31.52, 'last_lng' => 74.35,
            'last_located_at' => now()->subHours(20),
        ]);
        // Off-duty rider with an ancient fix → never silent.
        $off = $this->makeRider([
            'on_duty'         => false,
            'last_located_at' => now()->subHours(20),
        ]);

        $payload = $this->trackingData();

        $this->assertTrue($this->riderRow($payload, $silent->id)['is_silent']);
        $this->assertFalse($this->riderRow($payload, $justOn->id)['is_silent']);
        $this->assertFalse($this->riderRow($payload, $off->id)['is_silent']);
    }

    // ── 6: is_idle ───────────────────────────────────────────────────────────

    private function insertPoints(int $riderId, array $specs): void
    {
        // specs: [minutesAgo, lat, lng]
        foreach ($specs as [$minsAgo, $lat, $lng]) {
            DB::table('pos_rider_locations')->insert([
                'company_id'  => 1,
                'rider_id'    => $riderId,
                'lat'         => $lat,
                'lng'         => $lng,
                'recorded_at' => now()->subMinutes($minsAgo)->format('Y-m-d H:i:s'),
                'created_at'  => now(),
            ]);
        }
    }

    public function test_idle_flag_for_stationary_rider_with_open_deliveries(): void
    {
        $mk = fn () => $this->makeRider([
            'duty_started_at' => now()->subHours(2),
            'last_lat'        => 31.5204, 'last_lng' => 74.3587,
            'last_located_at' => now()->subMinutes(1),
        ]);
        $stuck = $mk();     // stationary + open delivery → idle
        $moving = $mk();    // moving + open delivery → not idle
        $noBills = $mk();   // stationary, NO open delivery → not idle

        foreach ([$stuck, $moving, $noBills] as $r) {
            $this->insertPoints($r->id, $r->id === $moving->id
                ? [[14, 31.5204, 74.3587], [8, 31.5304, 74.3687], [1, 31.5404, 74.3787]]
                : [[14, 31.5204, 74.3587], [8, 31.52045, 74.35872], [1, 31.5204, 74.3587]]);
        }
        foreach ([$stuck, $moving] as $r) {
            DB::table('pos_transactions')->insert([
                'company_id' => 1, 'rider_id' => $r->id,
                'delivery_status' => 'dispatched',
                'created_at' => now()->subHours(1), 'updated_at' => now(),
            ]);
        }

        $payload = $this->trackingData();

        $this->assertTrue($this->riderRow($payload, $stuck->id)['is_idle'],
            'Stationary rider with open deliveries must flag idle');
        $this->assertFalse($this->riderRow($payload, $moving->id)['is_idle']);
        $this->assertFalse($this->riderRow($payload, $noBills->id)['is_idle'],
            'Idle warning only applies while deliveries are open');
        // Silent and idle are mutually exclusive in the payload.
        $this->assertFalse($this->riderRow($payload, $stuck->id)['is_silent']);
    }
}

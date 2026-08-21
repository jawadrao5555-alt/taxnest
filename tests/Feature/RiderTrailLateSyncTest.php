<?php

namespace Tests\Feature;

use App\Models\User;
use App\Services\PosFeatureService;
use Carbon\Carbon;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

/**
 * Task #1357 — the live-tracking trail must never present a LATE upload as if
 * it had been live.
 *
 * A rider's phone buffers points while it is off-network and drains them
 * later. The owner reads the map as "where my rider was, live", so every
 * stretch that only reached the server afterwards has to say so — otherwise
 * the map quietly lies about a route it never watched in real time.
 *
 * Locked in this suite (GET /pos/riders/tracking/trail/{rider}):
 *  1. Late (offline-buffered) points carry the late flag (slot 4 = 1) AND the
 *     H:i they actually reached the server (slot 5); live points carry 0/null.
 *  2. The gap in front of a late stretch is marked is_offline_after with the
 *     stretch's sync time, so the pill can say "live nahi thi — itne baje sync
 *     hui".
 *  3. late_count / late_last_sync in the response match the seeded rows.
 *  4. A genuine offline GAP whose following points DID arrive live stays
 *     un-marked (no false "late" claim, no sync time).
 *  5. Pre-migration rows (is_offline NULL) still get classified through the
 *     created_at − recorded_at fallback — the flag must not vanish for history
 *     written before the column existed.
 *
 * Pattern: sqlite :memory: + minimal Schema::create, real HTTP GET through the
 * pos guard (PosAuth → CheckCompanyApproval → PosAdminOnly), same approach as
 * the other POS feature tests.
 */
class RiderTrailLateSyncTest extends TestCase
{
    private const OFFLINE = 1;
    private const LIVE = 0;

    private int $companyId;
    private int $riderId;
    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();

        // Freeze the clock: the trail defaults to "today", and the H:i strings
        // asserted below must not depend on when the suite happens to run.
        Carbon::setTestNow(Carbon::create(2026, 8, 21, 14, 0, 0, config('app.timezone')));

        User::flushScopeColumnCache();
        PosFeatureService::flushGateCaches();
        Schema::dropAllTables();

        $this->buildSchema();
        $this->seedCompanyAndAdmin();
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();

        parent::tearDown();
    }

    private function buildSchema(): void
    {
        Schema::create('companies', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('product_type')->nullable();
            $table->string('status')->default('approved');
            $table->string('company_status')->default('approved');
            $table->boolean('is_internal_account')->default(false);
            $table->boolean('restaurant_mode')->default(false);
            $table->boolean('pos_setup_completed')->default(true);
            $table->text('feature_flags')->nullable();
            $table->string('default_language')->nullable();
            $table->softDeletes();
            $table->timestamps();
        });

        Schema::create('users', function (Blueprint $table) {
            $table->id();
            $table->string('name')->nullable();
            $table->string('email')->nullable()->unique();
            $table->string('password')->nullable();
            $table->unsignedBigInteger('company_id')->nullable();
            $table->string('role')->nullable();
            $table->string('pos_role')->nullable();
            $table->text('pos_custom_access')->nullable();
            $table->boolean('is_active')->default(true);
            $table->unsignedBigInteger('default_branch_id')->nullable();
            $table->rememberToken();
            $table->timestamps();
        });

        // Touched by PosAuth (branch context + hazri heartbeat).
        Schema::create('branches', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('company_id');
            $table->string('name');
            $table->boolean('is_head_office')->default(false);
            $table->boolean('is_active')->default(true);
            $table->softDeletes();
            $table->timestamps();
        });

        Schema::create('branch_user', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('branch_id');
            $table->unsignedBigInteger('user_id');
            $table->timestamps();
        });

        Schema::create('pos_user_sessions', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('user_id');
            $table->timestamp('login_at')->nullable();
            $table->timestamp('logout_at')->nullable();
            $table->timestamp('last_activity_at')->nullable();
            $table->timestamps();
        });

        Schema::create('pricing_plans', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('product_type')->nullable();
            $table->decimal('price', 12, 2)->default(0);
            $table->boolean('is_active')->default(true);
            $table->boolean('riders_enabled')->default(true);
            $table->boolean('rider_tracking_enabled')->default(true);
            $table->timestamps();
        });

        Schema::create('subscriptions', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('company_id');
            $table->unsignedBigInteger('pricing_plan_id')->nullable();
            $table->string('status')->default('active');
            $table->boolean('is_active')->default(true);
            $table->boolean('active')->default(true);
            $table->timestamp('trial_ends_at')->nullable();
            $table->string('override_type')->default('none');
            $table->timestamp('override_until')->nullable();
            $table->timestamps();
        });

        Schema::create('pos_riders', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('company_id');
            $table->string('name');
            $table->boolean('is_active')->default(true);
            $table->boolean('on_duty')->default(true);
            $table->timestamp('duty_started_at')->nullable();
            $table->decimal('last_lat', 10, 7)->nullable();
            $table->decimal('last_lng', 10, 7)->nullable();
            $table->timestamp('last_located_at')->nullable();
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
            // true = arrived from the phone's offline buffer, false = live,
            // NULL = row written before the column existed.
            $table->boolean('is_offline')->nullable()->default(null);
            $table->timestamp('created_at')->nullable();
            $table->index(['company_id', 'rider_id', 'recorded_at']);
        });
    }

    private function seedCompanyAndAdmin(): void
    {
        // is_internal_account short-circuits the Unlimited plan gates the whole
        // tracking module sits behind.
        $this->companyId = (int) DB::table('companies')->insertGetId([
            'name'                => 'Trail Late Sync Co',
            'product_type'        => 'pos',
            'status'              => 'approved',
            'company_status'      => 'approved',
            'is_internal_account' => true,
            // The riders/tracking routes sit inside the restaurant.only group.
            'feature_flags'       => json_encode(['tables' => true, 'kitchen' => true, 'kot' => true, 'delivery' => true]),
            'created_at'          => now(),
            'updated_at'          => now(),
        ]);

        $planId = (int) DB::table('pricing_plans')->insertGetId([
            'name'                   => 'Unlimited',
            'product_type'           => 'pos',
            'riders_enabled'         => true,
            'rider_tracking_enabled' => true,
            'created_at'             => now(),
            'updated_at'             => now(),
        ]);

        DB::table('subscriptions')->insert([
            'company_id'      => $this->companyId,
            'pricing_plan_id' => $planId,
            'status'          => 'active',
            'is_active'       => true,
            'active'          => true,
            'created_at'      => now(),
            'updated_at'      => now(),
        ]);

        $adminId = (int) DB::table('users')->insertGetId([
            'name'       => 'Shop Admin',
            'email'      => 'admin@trail-late-sync.test',
            'password'   => Hash::make('Secret@12'),
            'company_id' => $this->companyId,
            'role'       => 'company_admin',
            'pos_role'   => 'pos_admin',
            'is_active'  => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $this->admin = User::find($adminId);

        $this->riderId = (int) DB::table('pos_riders')->insertGetId([
            'company_id' => $this->companyId,
            'name'       => 'Trail Rider',
            'is_active'  => true,
            'on_duty'    => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    /**
     * Seed one point. $recordedAt / $arrivedAt are H:i:s on the frozen day;
     * $isOffline may be 1 / 0 / null (pre-migration row).
     */
    private function point(string $recordedAt, string $arrivedAt, ?int $isOffline, float $lat = 31.5204, float $lng = 74.3587): void
    {
        $day = now()->format('Y-m-d');

        DB::table('pos_rider_locations')->insert([
            'company_id'  => $this->companyId,
            'rider_id'    => $this->riderId,
            'lat'         => $lat,
            'lng'         => $lng,
            'recorded_at' => "$day $recordedAt",
            'is_offline'  => $isOffline,
            'created_at'  => "$day $arrivedAt",
        ]);
    }

    private function trail(): array
    {
        $res = $this->actingAs($this->admin, 'pos')
            ->getJson('/pos/riders/tracking/trail/' . $this->riderId);

        $res->assertOk();
        $payload = $res->json();
        $this->assertTrue($payload['ok']);

        return $payload;
    }

    // ── 1-3: a late stretch is flagged, timed, counted ───────────────────────

    public function test_late_stretch_carries_the_late_flag_sync_time_gap_pill_and_totals(): void
    {
        // Live stretch — recorded and delivered in the same moment.
        $this->point('09:00:00', '09:00:00', self::LIVE);
        $this->point('09:02:00', '09:02:00', self::LIVE);
        $this->point('09:04:00', '09:04:00', self::LIVE);

        // Rider loses network; these three are buffered on the phone and only
        // reach the server after 11:15 (26-minute hole in front of them).
        $this->point('09:30:00', '11:15:00', self::OFFLINE);
        $this->point('09:32:00', '11:16:00', self::OFFLINE);
        $this->point('09:34:00', '11:17:00', self::OFFLINE);

        $payload = $this->trail();
        $points = $payload['points'];

        $this->assertCount(6, $points, 'All six seeded points must survive (no downsampling at this size)');

        // Live points: not late, no sync time.
        foreach ([0, 1, 2] as $i) {
            $this->assertSame(0, $points[$i][4], "Live point #$i must not be flagged late");
            $this->assertNull($points[$i][5], "Live point #$i must not carry a sync time");
        }

        // Late points: flagged AND stamped with the moment they reached us.
        $this->assertSame(1, $points[3][4]);
        $this->assertSame(1, $points[4][4]);
        $this->assertSame(1, $points[5][4]);
        $this->assertSame('11:15', $points[3][5]);
        $this->assertSame('11:16', $points[4][5]);
        $this->assertSame('11:17', $points[5][5]);

        // The recorded time still drives the map position/label — the late
        // stamp is additive, never a replacement.
        $this->assertSame('09:30', $points[3][2]);

        // The gap in front of the buffered stretch says it was not live, and
        // when it finally synced.
        $this->assertCount(1, $payload['gaps']);
        $gap = $payload['gaps'][0];
        $this->assertSame(2, $gap['after_idx'], 'Gap sits after the last live point');
        $this->assertSame(26, $gap['minutes']);
        $this->assertTrue($gap['is_offline_after']);
        $this->assertSame('11:15', $gap['synced_at']);

        // Legend totals must match the seeded rows.
        $this->assertSame(3, $payload['late_count']);
        $this->assertSame('11:17', $payload['late_last_sync']);
    }

    // ── 4: an offline-looking gap whose points DID arrive live ───────────────

    public function test_live_gap_is_not_reported_as_a_late_sync(): void
    {
        // GPS silence (rider parked indoors), but every point was delivered the
        // moment it was recorded. Nothing here may claim a late sync.
        $this->point('09:00:00', '09:00:00', self::LIVE);
        $this->point('09:02:00', '09:02:00', self::LIVE);
        $this->point('09:40:00', '09:40:00', self::LIVE);
        $this->point('09:42:00', '09:42:00', self::LIVE);

        $payload = $this->trail();

        foreach ($payload['points'] as $i => $p) {
            $this->assertSame(0, $p[4], "Point #$i arrived live and must not be flagged late");
            $this->assertNull($p[5], "Point #$i arrived live and must not carry a sync time");
        }

        $this->assertCount(1, $payload['gaps'], 'The 38-minute hole is still reported as a gap');
        $this->assertFalse($payload['gaps'][0]['is_offline_after']);
        $this->assertNull($payload['gaps'][0]['synced_at']);

        $this->assertSame(0, $payload['late_count']);
        $this->assertNull($payload['late_last_sync']);
    }

    // ── 5: pre-migration rows (is_offline NULL) keep the fallback ────────────

    public function test_pre_migration_rows_fall_back_to_the_arrival_lag_heuristic(): void
    {
        // Written before is_offline existed: only created_at − recorded_at can
        // tell live from late (threshold: 5 minutes).
        $this->point('09:00:00', '09:00:30', null); // 30s lag → live
        $this->point('09:02:00', '09:02:20', null); // 20s lag → live
        $this->point('09:30:00', '10:05:00', null); // 35m lag → late
        $this->point('09:32:00', '10:05:00', null); // 33m lag → late

        $payload = $this->trail();
        $points = $payload['points'];

        $this->assertSame(0, $points[0][4]);
        $this->assertSame(0, $points[1][4]);
        $this->assertSame(1, $points[2][4], 'A 35-minute arrival lag on a legacy row is a late upload');
        $this->assertSame(1, $points[3][4]);
        $this->assertSame('10:05', $points[2][5]);
        $this->assertSame('10:05', $points[3][5]);

        $this->assertCount(1, $payload['gaps']);
        $this->assertTrue($payload['gaps'][0]['is_offline_after']);
        $this->assertSame('10:05', $payload['gaps'][0]['synced_at']);

        $this->assertSame(2, $payload['late_count']);
        $this->assertSame('10:05', $payload['late_last_sync']);
    }
}

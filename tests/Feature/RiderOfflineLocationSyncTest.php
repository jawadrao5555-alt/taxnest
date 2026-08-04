<?php

namespace Tests\Feature;

use Tests\TestCase;
use App\Models\PosRider;
use Carbon\Carbon;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Schema;
use App\Http\Controllers\PosRiderTrackingController;
use Illuminate\Http\Request;

/**
 * Rider offline route buffering — appLocations invariants.
 *
 * Covers:
 *  1. Batch insert stores recorded_at from 'at' epoch-ms converted to PKT,
 *     not server arrival time.
 *  2. Replaying the same batch (offline re-upload after 401 re-login) inserts
 *     zero duplicate rows (insertOrIgnore + unique(rider_id, client_ts_ms)).
 *  3. last_located_at never regresses: an older replayed batch must not
 *     overwrite a fresher already-stored fix.
 *  4. NULL client_ts_ms (old APKs without `at`) is accepted and stored; a
 *     second identical NULL point is NOT deduplicated (NULLs are not unique).
 *
 * Pattern: SQLite :memory:, minimal Schema::create, controller invoked
 * directly — same approach as other rider invariant tests.
 */
class RiderOfflineLocationSyncTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        Schema::dropAllTables();

        // is_internal_account = true short-circuits PosFeatureService::planAllows
        // immediately — no subscriptions / pricing_plans lookup needed.
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

        // pricing_plans must exist because planAllows calls Schema::hasColumn on it
        // even for internal accounts (it checks early — this table is read safely).
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
            $table->boolean('is_active')->default(true);
            $table->boolean('on_duty')->default(true);
            $table->timestamp('duty_started_at')->nullable();
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
            $table->timestamp('created_at')->useCurrent();
            $table->unique(['rider_id', 'client_ts_ms'], 'prl_rider_client_ts_dedup');
            $table->index(['company_id', 'rider_id', 'recorded_at'], 'prl_company_rider_time');
        });
    }

    // ── fixtures ─────────────────────────────────────────────────────────────

    private function makeRiderAndToken(int $companyId = 1): array
    {
        // is_internal_account=1 → planAllows() returns true immediately for every
        // gate; no subscriptions table needed.
        DB::table('companies')->insert([
            'id' => $companyId, 'is_internal_account' => 1,
            'created_at' => now(), 'updated_at' => now(),
        ]);

        // pricing_plans row keeps Schema::hasColumn('pricing_plans', ...) happy.
        DB::table('pricing_plans')->insertGetId([
            'name' => 'Unlimited', 'product_type' => 'pos',
            'riders_enabled' => 1, 'rider_tracking_enabled' => 1,
            'created_at' => now(), 'updated_at' => now(),
        ]);

        // Clear static plan-gate cache between test instances.
        $reflector = new \ReflectionClass(\App\Services\PosFeatureService::class);
        $cache = $reflector->getProperty('planGateCache');
        $cache->setAccessible(true);
        $cache->setValue([]);

        $plain = '1|' . str_repeat('x', 48);
        $rider = PosRider::create([
            'id'         => 1,
            'company_id' => $companyId,
            'name'       => 'Test Rider',
            'is_active'  => true,
            'on_duty'    => true,
            'app_token'  => hash('sha256', $plain),
        ]);

        return [$rider, $plain];
    }

    private function callLocations(string $token, array $points): \Illuminate\Http\JsonResponse
    {
        $request = Request::create('/api/rider-app/v1/locations', 'POST',
            ['points' => $points], [], [], ['HTTP_AUTHORIZATION' => 'Bearer ' . $token]);
        $request->setJson(new \Symfony\Component\HttpFoundation\InputBag(['points' => $points]));

        // Bind controller via app() so service-container gates work.
        return app(PosRiderTrackingController::class)->appLocations($request);
    }

    // ── Test 1: epoch-ms stored as capture time in PKT ───────────────────────

    public function test_recorded_at_uses_client_capture_time_not_arrival_time(): void
    {
        [$rider, $token] = $this->makeRiderAndToken();

        // A point captured 10 minutes ago.
        $captureMs = (int) (now()->subMinutes(10)->getPreciseTimestamp(3));

        $resp = $this->callLocations($token, [
            ['lat' => 31.5204, 'lng' => 74.3587, 'acc' => 8, 'at' => $captureMs],
        ]);

        $this->assertEquals(200, $resp->status());
        $data = json_decode($resp->getContent(), true);
        $this->assertTrue($data['ok']);
        $this->assertEquals(1, $data['stored']);

        $row = DB::table('pos_rider_locations')->first();
        $this->assertNotNull($row);

        // recorded_at must reflect capture time, not now().
        $recordedAt   = Carbon::parse($row->recorded_at);
        $expectedAt   = Carbon::createFromTimestampMs($captureMs)
            ->setTimezone(config('app.timezone'));
        $diffSeconds  = abs($recordedAt->diffInSeconds($expectedAt));
        $this->assertLessThanOrEqual(2, $diffSeconds,
            "recorded_at ({$recordedAt}) should match client capture time ({$expectedAt}), diff={$diffSeconds}s");

        // client_ts_ms must be stored verbatim.
        $this->assertEquals($captureMs, (int) $row->client_ts_ms);
    }

    // ── Test 2: replay inserts zero duplicate rows ────────────────────────────

    public function test_replaying_same_batch_inserts_no_duplicates(): void
    {
        [$rider, $token] = $this->makeRiderAndToken();

        $ms1 = (int) (now()->subMinutes(5)->getPreciseTimestamp(3));
        $ms2 = (int) (now()->subMinutes(4)->getPreciseTimestamp(3));
        $points = [
            ['lat' => 31.520, 'lng' => 74.358, 'acc' => 10, 'at' => $ms1],
            ['lat' => 31.521, 'lng' => 74.359, 'acc' => 12, 'at' => $ms2],
        ];

        // First upload.
        $resp1 = $this->callLocations($token, $points);
        $this->assertEquals(200, $resp1->status());
        $this->assertEquals(2, json_decode($resp1->getContent(), true)['stored']);
        $this->assertEquals(2, DB::table('pos_rider_locations')->count());

        // Replay (same batch, simulating re-upload after 401 → re-login).
        $resp2 = $this->callLocations($token, $points);
        $this->assertEquals(200, $resp2->status());
        // stored may be 0 or 2 in the response (insertOrIgnore doesn't distinguish),
        // but the DB must still have exactly 2 rows.
        $this->assertEquals(2, DB::table('pos_rider_locations')->count(),
            'Replaying the same batch must not insert duplicate rows');
    }

    // ── Test 3: last_located_at never regresses ───────────────────────────────

    public function test_last_located_at_does_not_regress_on_old_batch_replay(): void
    {
        [$rider, $token] = $this->makeRiderAndToken();

        // First: upload a recent batch → sets last_located_at to T+recent.
        $recentMs = (int) (now()->subMinutes(2)->getPreciseTimestamp(3));
        $this->callLocations($token, [
            ['lat' => 31.520, 'lng' => 74.358, 'acc' => 5, 'at' => $recentMs],
        ]);

        $rider->refresh();
        $afterRecent = $rider->last_located_at->format('Y-m-d H:i:s');

        // Second: replay an older batch (offline points from 20 min ago).
        $olderMs1 = (int) (now()->subMinutes(22)->getPreciseTimestamp(3));
        $olderMs2 = (int) (now()->subMinutes(20)->getPreciseTimestamp(3));
        $this->callLocations($token, [
            ['lat' => 31.510, 'lng' => 74.348, 'acc' => 15, 'at' => $olderMs1],
            ['lat' => 31.511, 'lng' => 74.349, 'acc' => 14, 'at' => $olderMs2],
        ]);

        $rider->refresh();
        $afterOldBatch = $rider->last_located_at->format('Y-m-d H:i:s');

        $this->assertEquals($afterRecent, $afterOldBatch,
            'last_located_at must not regress when an older batch is uploaded');

        // last_lat/lng must also still reflect the recent fix, not the old one.
        $this->assertEquals(round(31.520, 7), round((float) $rider->last_lat, 7));
        $this->assertEquals(round(74.358, 7), round((float) $rider->last_lng, 7));
    }

    // ── Test 4: NULL client_ts_ms (old APK) accepted + not deduped ───────────

    public function test_null_client_ts_ms_old_apk_points_are_stored(): void
    {
        [$rider, $token] = $this->makeRiderAndToken();

        // Two points without `at` (old APK payload).
        $resp = $this->callLocations($token, [
            ['lat' => 31.522, 'lng' => 74.360],
            ['lat' => 31.523, 'lng' => 74.361],
        ]);

        $this->assertEquals(200, $resp->status());
        // Both stored — NULLs not considered duplicates of each other.
        $this->assertEquals(2, DB::table('pos_rider_locations')->count());
        $this->assertEquals(2, DB::table('pos_rider_locations')->whereNull('client_ts_ms')->count());
    }

    // ── Test 5: NULL stored → first real batch always updates last_located_at ─

    public function test_first_batch_updates_last_located_at_when_null_stored(): void
    {
        [$rider, $token] = $this->makeRiderAndToken();

        // Rider has no prior fix.
        $this->assertNull($rider->last_located_at);

        $ms = (int) (now()->subMinutes(1)->getPreciseTimestamp(3));
        $this->callLocations($token, [
            ['lat' => 31.525, 'lng' => 74.362, 'at' => $ms],
        ]);

        $rider->refresh();
        $this->assertNotNull($rider->last_located_at,
            'last_located_at must be set when previously NULL (first fix)');
    }
}

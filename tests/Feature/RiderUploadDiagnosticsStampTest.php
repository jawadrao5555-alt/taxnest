<?php

namespace Tests\Feature;

use App\Models\PosRider;
use App\Services\PosFeatureService;
use Carbon\Carbon;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

/**
 * Task #1357 — upload diagnostics stamped on the rider row.
 *
 * The live map card has to answer two owner questions that the trail alone
 * cannot: "did the phone reach us at all just now?" and "if it did, why is
 * nothing showing?". Both answers live on pos_riders:
 *
 *   last_upload_at   — when the phone last got through, EVEN when the points
 *                      inside were older than the fix we already hold (a
 *                      drained offline buffer must not move the rider on the
 *                      map, but it is still proof the phone spoke to us).
 *   last_reject_*    — why the server refused the batch: duty off, package
 *                      locked, or points beyond the accepted age window.
 *
 * Locked in this suite (POST /api/rider-app/v1/locations, the real app
 * endpoint with its bearer token):
 *  1. A stale offline drain batch stamps last_upload_at and leaves the
 *     denormalized position (last_lat/lng/last_located_at) untouched; a stale
 *     refusal reason from earlier is cleared once uploads land again.
 *  2. Off-duty fresh upload  → reason 'duty_off'    + reject time.
 *  3. Package-locked upload  → reason 'plan_locked' + reject time.
 *  4. Point beyond the window→ reason 'too_old'     + reject time.
 *
 * Pattern: sqlite :memory: + minimal Schema::create, HTTP POST through the
 * stateless rider-app route — same approach as RiderOfflineLocationSyncTest.
 */
class RiderUploadDiagnosticsStampTest extends TestCase
{
    private const ALLOWED_COMPANY = 1; // internal account → every plan gate open
    private const LOCKED_COMPANY = 2;  // real subscription without rider tracking

    protected function setUp(): void
    {
        parent::setUp();

        // Frozen clock: the stamps below are asserted to the second.
        Carbon::setTestNow(Carbon::create(2026, 8, 21, 14, 0, 0, config('app.timezone')));

        PosFeatureService::flushGateCaches();
        Schema::dropAllTables();

        Schema::create('companies', function (Blueprint $table) {
            $table->id();
            $table->string('name')->default('Test Co');
            $table->string('status')->default('active');
            $table->string('company_status')->default('active');
            $table->boolean('is_internal_account')->default(false);
            $table->timestamps();
            $table->softDeletes();
        });

        Schema::create('pricing_plans', function (Blueprint $table) {
            $table->id();
            $table->string('name')->default('Starter');
            $table->string('product_type')->default('pos');
            $table->boolean('is_active')->default(true);
            $table->boolean('riders_enabled')->default(true);
            $table->boolean('rider_tracking_enabled')->default(true);
            $table->timestamps();
        });

        Schema::create('subscriptions', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('company_id');
            $table->unsignedBigInteger('pricing_plan_id')->nullable();
            $table->boolean('active')->default(true);
            $table->timestamp('trial_ends_at')->nullable();
            $table->string('override_type')->default('none');
            $table->timestamp('override_until')->nullable();
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
            $table->timestamp('duty_auto_off_at')->nullable();
            // Task #1357 diagnostics columns.
            $table->timestamp('last_upload_at')->nullable();
            $table->string('last_reject_reason')->nullable();
            $table->timestamp('last_reject_at')->nullable();
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

        // Company whose plan carries rider tracking (internal shortcut).
        DB::table('companies')->insert([
            'id' => self::ALLOWED_COMPANY, 'name' => 'Tracking Co',
            'is_internal_account' => 1, 'created_at' => now(), 'updated_at' => now(),
        ]);

        // Company on a package WITHOUT rider tracking — the "package locked"
        // refusal the owner sees after a downgrade.
        DB::table('companies')->insert([
            'id' => self::LOCKED_COMPANY, 'name' => 'Downgraded Co',
            'is_internal_account' => 0, 'created_at' => now(), 'updated_at' => now(),
        ]);
        $lockedPlan = (int) DB::table('pricing_plans')->insertGetId([
            'name' => 'Starter', 'product_type' => 'pos',
            'riders_enabled' => 1, 'rider_tracking_enabled' => 0,
            'created_at' => now(), 'updated_at' => now(),
        ]);
        DB::table('subscriptions')->insert([
            'company_id' => self::LOCKED_COMPANY, 'pricing_plan_id' => $lockedPlan,
            'active' => 1, 'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();

        parent::tearDown();
    }

    // ── fixtures ─────────────────────────────────────────────────────────────

    /** @return array{0: PosRider, 1: string} rider + plaintext bearer token */
    private function makeRider(array $attrs = [], int $companyId = self::ALLOWED_COMPANY): array
    {
        $id = (int) (DB::table('pos_riders')->max('id') ?? 0) + 1;
        $plain = $id . '|' . str_repeat('t', 48);

        $rider = PosRider::create(array_merge([
            'id'         => $id,
            'company_id' => $companyId,
            'name'       => 'Test Rider',
            'is_active'  => true,
            'on_duty'    => true,
            'app_token'  => hash('sha256', $plain),
        ], $attrs));

        return [$rider, $plain];
    }

    private function upload(string $token, array $points)
    {
        return $this->postJson('/api/rider-app/v1/locations', ['points' => $points], [
            'Authorization' => 'Bearer ' . $token,
        ]);
    }

    private function msAgo(int $minutes): int
    {
        return (int) now()->subMinutes($minutes)->getPreciseTimestamp(3);
    }

    // ── 1: a stale drain batch still proves the phone reached us ─────────────

    public function test_stale_offline_batch_stamps_upload_time_without_moving_the_position(): void
    {
        [$rider, $token] = $this->makeRider([
            // Fresh fix already on the map, plus a refusal from earlier today.
            'last_lat'           => 31.5204,
            'last_lng'           => 74.3587,
            'last_located_at'    => now()->subMinutes(2),
            'last_reject_reason' => 'duty_off',
            'last_reject_at'     => now()->subHours(2),
        ]);

        $storedLocatedAt = $rider->last_located_at->format('Y-m-d H:i:s');

        // Phone drains its buffer: points recorded 40 and 38 minutes ago —
        // OLDER than the fix already stored.
        $res = $this->upload($token, [
            ['lat' => 31.4100, 'lng' => 74.2500, 'acc' => 20, 'at' => $this->msAgo(40)],
            ['lat' => 31.4110, 'lng' => 74.2510, 'acc' => 18, 'at' => $this->msAgo(38)],
        ]);

        $res->assertOk();
        $this->assertSame(2, $res->json('stored'));
        $this->assertSame(2, DB::table('pos_rider_locations')->count());

        $rider->refresh();

        // The upload time is the whole point: the phone DID reach us just now.
        $this->assertNotNull($rider->last_upload_at,
            'A drained offline batch must still stamp last_upload_at');
        $this->assertSame(now()->format('Y-m-d H:i:s'), $rider->last_upload_at->format('Y-m-d H:i:s'));

        // ...but the map position must NOT jump back to the buffered route.
        $this->assertSame($storedLocatedAt, $rider->last_located_at->format('Y-m-d H:i:s'),
            'A stale drain batch must not move last_located_at');
        $this->assertEqualsWithDelta(31.5204, (float) $rider->last_lat, 0.0000001);
        $this->assertEqualsWithDelta(74.3587, (float) $rider->last_lng, 0.0000001);

        // Uploads are landing again — the old reason must not keep accusing.
        $this->assertNull($rider->last_reject_reason,
            'A successful upload must clear a stale refusal reason');
        $this->assertNull($rider->last_reject_at);
    }

    // ── 2: duty off ──────────────────────────────────────────────────────────

    public function test_off_duty_fresh_upload_stamps_the_duty_off_reason(): void
    {
        // duty_started_at NULL keeps the late-night auto-off sweep out of the way.
        [$rider, $token] = $this->makeRider(['on_duty' => false, 'duty_started_at' => null]);

        $res = $this->upload($token, [
            ['lat' => 31.5204, 'lng' => 74.3587, 'acc' => 9, 'at' => $this->msAgo(0)],
        ]);

        // 409 is the app's stop signal; nothing is stored.
        $res->assertStatus(409);
        $this->assertSame('duty_off', $res->json('error'));
        $this->assertSame(0, DB::table('pos_rider_locations')->count());

        $rider->refresh();
        $this->assertSame('duty_off', $rider->last_reject_reason);
        $this->assertNotNull($rider->last_reject_at);
        $this->assertSame(now()->format('Y-m-d H:i:s'), $rider->last_reject_at->format('Y-m-d H:i:s'));
    }

    // ── 3: package locked ────────────────────────────────────────────────────

    public function test_package_locked_upload_stamps_the_plan_locked_reason(): void
    {
        [$rider, $token] = $this->makeRider([], self::LOCKED_COMPANY);

        $res = $this->upload($token, [
            ['lat' => 31.5204, 'lng' => 74.3587, 'acc' => 9, 'at' => $this->msAgo(0)],
        ]);

        $res->assertStatus(403);
        $this->assertSame('plan_locked', $res->json('error'));
        $this->assertSame(0, DB::table('pos_rider_locations')->count());

        $rider->refresh();
        $this->assertSame('plan_locked', $rider->last_reject_reason,
            'A package-locked refusal must explain itself on the live card');
        $this->assertNotNull($rider->last_reject_at);
        $this->assertSame(now()->format('Y-m-d H:i:s'), $rider->last_reject_at->format('Y-m-d H:i:s'));
    }

    // ── 4: point beyond the accepted age window ──────────────────────────────

    public function test_point_older_than_the_window_stamps_the_too_old_reason(): void
    {
        [$rider, $token] = $this->makeRider();

        // Recorded 8 days ago — past the 7-day buffer window.
        $res = $this->upload($token, [
            ['lat' => 31.5204, 'lng' => 74.3587, 'acc' => 25, 'at' => $this->msAgo(8 * 24 * 60)],
        ]);

        // Rider is on duty, so the batch is answered 200 — but nothing landed.
        $res->assertOk();
        $this->assertSame(0, $res->json('stored'));
        $this->assertSame(0, DB::table('pos_rider_locations')->count());

        $rider->refresh();
        $this->assertSame('too_old', $rider->last_reject_reason,
            'An owner staring at a blank map deserves "points too old", not silence');
        $this->assertNotNull($rider->last_reject_at);
        $this->assertSame(now()->format('Y-m-d H:i:s'), $rider->last_reject_at->format('Y-m-d H:i:s'));
    }
}

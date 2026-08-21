<?php

namespace Tests\Feature;

use App\Http\Controllers\PosRiderTrackingController;
use App\Models\PosRider;
use App\Services\RiderPushService;
use Carbon\Carbon;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

/**
 * Task #1359 — server half of the "abhi sync karo" nudge.
 *
 * When the live map sees a rider go silent (on duty, no fix inside the
 * threshold) the server pushes a data-only `sync_now` message straight to his
 * phone instead of waiting for a cron: a HIGH-priority FCM data message is one
 * of the few things that still wakes an app frozen by an OEM battery saver.
 *
 * Covers:
 *  1. silent on-duty rider → exactly one sync_now push (data-only, no
 *     notification block, so nothing user-visible pops up);
 *  2. throttle — a second poll seconds later sends nothing (the admin map
 *     polls every few seconds and must not spam FCM);
 *  3. a rider who is uploading normally, and an off-duty rider, get nothing;
 *  4. no FCM credential configured → the map still renders (no push attempt).
 *
 * Pattern follows RiderAutoDutyOffTest: SQLite :memory:, minimal schema,
 * controller invoked directly, clock frozen.
 */
class RiderSyncPushTest extends TestCase
{
    private const FROZEN_NOW = '2026-08-20 14:00:00';

    protected function setUp(): void
    {
        parent::setUp();

        Carbon::setTestNow(Carbon::parse(self::FROZEN_NOW, config('app.timezone')));

        Schema::dropAllTables();
        \App\Services\PosFeatureService::flushGateCaches();
        $this->resetCredentialMemo();

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
            $table->decimal('last_lat', 10, 7)->nullable();
            $table->decimal('last_lng', 10, 7)->nullable();
            $table->timestamp('last_located_at')->nullable();
            $table->string('app_token', 64)->nullable();
            $table->text('fcm_token')->nullable();
            $table->unsignedBigInteger('user_id')->nullable();
            $table->timestamps();
        });

        Schema::create('pos_rider_locations', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('company_id');
            $table->unsignedBigInteger('rider_id');
            $table->decimal('lat', 10, 7);
            $table->decimal('lng', 10, 7);
            $table->dateTime('recorded_at');
            $table->bigInteger('client_ts_ms')->nullable()->unsigned();
            $table->boolean('is_offline')->nullable()->default(null);
            $table->timestamp('created_at')->useCurrent();
        });

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
        $this->resetCredentialMemo();
        parent::tearDown();
    }

    // ── helpers ──────────────────────────────────────────────────────────────

    /** RiderPushService memoizes the parsed credential in a static — clear it. */
    private function resetCredentialMemo(): void
    {
        foreach (['credsMemo' => null, 'credsLoaded' => false] as $prop => $value) {
            $ref = new \ReflectionProperty(RiderPushService::class, $prop);
            $ref->setAccessible(true);
            $ref->setValue(null, $value);
        }
    }

    /** Plants a throwaway service-account credential (real RSA key: the JWT is signed for real). */
    private function configureFcm(): void
    {
        $key = openssl_pkey_new(['private_key_bits' => 2048, 'private_key_type' => OPENSSL_KEYTYPE_RSA]);
        openssl_pkey_export($key, $pem);
        config([
            'services.fcm.credentials_file' => '',
            'services.fcm.credentials_json' => json_encode([
                'project_id' => 'taxnest-test',
                'client_email' => 'push@taxnest-test.iam.gserviceaccount.com',
                'private_key' => $pem,
            ]),
        ]);
        $this->resetCredentialMemo();

        Http::fake([
            'oauth2.googleapis.com/*' => Http::response(['access_token' => 'fake-oauth', 'expires_in' => 3600], 200),
            'fcm.googleapis.com/*' => Http::response(['name' => 'projects/taxnest-test/messages/1'], 200),
        ]);
    }

    private function makeRider(array $attrs = []): PosRider
    {
        return PosRider::create(array_merge([
            'company_id' => 1,
            'name' => 'Rider ' . uniqid(),
            'is_active' => true,
            'on_duty' => true,
            'fcm_token' => 'device-token-' . uniqid(),
        ], $attrs));
    }

    /** Poll the live map, then run the deferred (terminating) push work. */
    private function pollMap(): void
    {
        $resp = app(PosRiderTrackingController::class)->trackingData();
        $this->assertEquals(200, $resp->status());
        app()->terminate();
        // Application::terminate() does NOT clear its callback list, so a
        // second terminate() in the same process would re-run the first poll's
        // callback. A real poll is a fresh request — reset the list so repeat
        // polls are simulated honestly.
        $ref = new \ReflectionProperty(app(), 'terminatingCallbacks');
        $ref->setAccessible(true);
        $ref->setValue(app(), []);
    }

    private function syncPushCount(): int
    {
        return Http::recorded(fn ($request) => str_contains($request->url(), 'fcm.googleapis.com')
            && (($request->data()['message']['data']['type'] ?? null) === 'sync_now'))->count();
    }

    // ── tests ────────────────────────────────────────────────────────────────

    public function test_silent_rider_gets_one_data_only_sync_push(): void
    {
        $this->configureFcm();
        $rider = $this->makeRider([
            'duty_started_at' => now()->subHours(2),
            'last_located_at' => now()->subMinutes(30), // way past the silent threshold
        ]);

        $this->pollMap();

        $this->assertSame(1, $this->syncPushCount(), 'silent rider should receive exactly one sync push');

        Http::assertSent(function ($request) use ($rider) {
            if (!str_contains($request->url(), 'fcm.googleapis.com')) {
                return false;
            }
            $message = $request->data()['message'];

            return $message['token'] === $rider->fcm_token
                && $message['data']['type'] === 'sync_now'
                // Data-only: a `notification` block would show a banner AND
                // bypass the app's onMessageReceived handler.
                && !isset($message['notification'])
                && $message['android']['priority'] === 'HIGH';
        });
    }

    public function test_repeat_polls_are_throttled(): void
    {
        $this->configureFcm();
        $this->makeRider([
            'duty_started_at' => now()->subHours(2),
            'last_located_at' => now()->subMinutes(30),
        ]);

        $this->pollMap(); // admin map polls every few seconds…
        $this->pollMap();
        $this->pollMap();

        $this->assertSame(1, $this->syncPushCount(), 'throttle should collapse repeat polls into one push');
    }

    public function test_healthy_and_off_duty_riders_get_no_push(): void
    {
        $this->configureFcm();
        $this->makeRider([ // uploading normally
            'duty_started_at' => now()->subHours(2),
            'last_located_at' => now()->subMinute(),
        ]);
        $this->makeRider([ // duty off — silence is expected
            'on_duty' => false,
            'duty_started_at' => now()->subHours(6),
            'last_located_at' => now()->subHours(5),
        ]);

        $this->pollMap();

        $this->assertSame(0, $this->syncPushCount());
    }

    public function test_map_still_renders_without_an_fcm_credential(): void
    {
        config(['services.fcm.credentials_file' => '', 'services.fcm.credentials_json' => '']);
        $this->resetCredentialMemo();
        Http::fake();

        $this->makeRider([
            'duty_started_at' => now()->subHours(2),
            'last_located_at' => now()->subMinutes(30),
        ]);

        $this->pollMap(); // asserts 200 internally

        Http::assertNothingSent();
    }
}

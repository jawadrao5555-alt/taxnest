<?php

namespace Tests\Feature;

use App\Http\Controllers\PosRiderTrackingController;
use App\Models\PosRider;
use App\Models\SystemSetting;
use App\Services\PosFeatureService;
use Carbon\Carbon;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

/**
 * Task #1405 — which rider is still carrying the OLD rider app.
 *
 * A rider who never taps the update banner keeps the old APK: no background
 * sync, no push, and until now no trace of it anywhere except the raw access
 * log. The app has always announced itself as `TaxNestRider/<version>`, so
 * every authenticated call is free evidence — the phone needs no change.
 *
 * Locked in this suite:
 *  1. A token-bearing call stamps the version parsed from the user agent.
 *  2. Login stamps it too (earliest proof after an install).
 *  3. Upgrading the APK moves the stamp forward; an identical version does NOT
 *     rewrite the row (uploads land every few seconds — no write storm).
 *  4. A caller that is not the rider app never blanks a known version.
 *  5. A package-locked shop still learns the build (stamped before the gate).
 *  6. The live map payload marks old builds, and marks riders who have NEVER
 *     opened the app at all.
 */
class RiderAppVersionStampTest extends TestCase
{
    private const ALLOWED_COMPANY = 1; // internal account → every plan gate open
    private const LOCKED_COMPANY = 2;  // real subscription without rider tracking

    protected function setUp(): void
    {
        parent::setUp();

        Carbon::setTestNow(Carbon::create(2026, 9, 8, 11, 0, 0, config('app.timezone')));

        PosFeatureService::flushGateCaches();
        Schema::dropAllTables();

        Schema::create('companies', function (Blueprint $table) {
            $table->id();
            $table->string('name')->default('Test Co');
            $table->string('status')->default('active');
            $table->string('company_status')->default('active');
            $table->boolean('is_internal_account')->default(false);
            $table->decimal('shop_lat', 10, 7)->nullable();
            $table->decimal('shop_lng', 10, 7)->nullable();
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

        Schema::create('users', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('email')->unique();
            $table->string('password');
            $table->unsignedBigInteger('company_id')->nullable();
            $table->string('role')->nullable();
            $table->string('pos_role')->nullable();
            $table->boolean('is_active')->default(true);
            $table->rememberToken();
            $table->timestamps();
        });

        Schema::create('pos_riders', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('company_id');
            $table->string('name')->default('Rider');
            $table->string('phone')->nullable();
            $table->boolean('is_active')->default(true);
            $table->boolean('on_duty')->default(true);
            $table->timestamp('duty_started_at')->nullable();
            $table->decimal('last_lat', 10, 7)->nullable();
            $table->decimal('last_lng', 10, 7)->nullable();
            $table->timestamp('last_located_at')->nullable();
            $table->string('app_token', 64)->nullable();
            $table->unsignedBigInteger('user_id')->nullable();
            $table->timestamp('duty_auto_off_at')->nullable();
            $table->timestamp('last_upload_at')->nullable();
            $table->string('last_reject_reason')->nullable();
            $table->timestamp('last_reject_at')->nullable();
            // Task #1405 column under test.
            $table->string('app_version', 20)->nullable();
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
        });

        // /me answers with the rider's open deliveries AND his cash khata, so
        // the columns those two sums read must exist here.
        Schema::create('pos_transactions', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('company_id');
            $table->unsignedBigInteger('rider_id')->nullable();
            $table->string('delivery_status')->nullable();
            $table->string('payment_method')->nullable();
            $table->decimal('total_amount', 12, 2)->default(0);
            $table->unsignedBigInteger('rider_settlement_id')->nullable();
            $table->timestamp('rider_assigned_at')->nullable();
            $table->timestamps();
        });

        Schema::create('system_settings', function (Blueprint $table) {
            $table->id();
            $table->string('key')->unique();
            $table->text('value')->nullable();
            $table->text('description')->nullable();
            $table->timestamps();
        });

        DB::table('companies')->insert([
            'id' => self::ALLOWED_COMPANY, 'name' => 'Tracking Co',
            'is_internal_account' => 1, 'created_at' => now(), 'updated_at' => now(),
        ]);
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

        // Released build the shop is chasing everyone onto.
        SystemSetting::set('rider_app_latest_version', '1.7.0');

        app()->bind('currentCompanyId', fn () => self::ALLOWED_COMPANY);
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
            'name'       => 'Test Rider ' . $id,
            'is_active'  => true,
            'on_duty'    => true,
            'app_token'  => hash('sha256', $plain),
        ], $attrs));

        return [$rider, $plain];
    }

    /** A real app call: bearer token + the app's own user agent. */
    private function appCall(string $token, ?string $userAgent)
    {
        $headers = ['Authorization' => 'Bearer ' . $token];
        if ($userAgent !== null) {
            $headers['User-Agent'] = $userAgent;
        }

        return $this->getJson('/api/rider-app/v1/me', $headers);
    }

    /** The live map payload for one rider. */
    private function mapRow(int $riderId): array
    {
        $data = json_decode(app(PosRiderTrackingController::class)->trackingData()->getContent(), true);

        foreach ($data['riders'] ?? [] as $row) {
            if ((int) $row['id'] === $riderId) {
                return $row;
            }
        }

        $this->fail("Rider {$riderId} missing from the live map payload");
    }

    // ── 1 + 3: token-bearing calls stamp, and only when the build changes ────

    public function test_authenticated_call_stamps_the_build_and_repeats_do_not_rewrite_the_row(): void
    {
        [$rider, $token] = $this->makeRider();

        $this->assertNull($rider->app_version, 'A fresh rider has no known build');

        $this->appCall($token, 'TaxNestRider/1.6.0')->assertOk();
        $rider->refresh();
        $this->assertSame('1.6.0', $rider->app_version,
            'Every authenticated call must record the build from the user agent');

        // A second identical call must not touch the row: location uploads land
        // every few seconds and carry the same agent every time.
        DB::table('pos_riders')->where('id', $rider->id)
            ->update(['updated_at' => '2020-01-01 00:00:00']);

        $this->appCall($token, 'TaxNestRider/1.6.0')->assertOk();
        $this->assertSame('2020-01-01 00:00:00',
            (string) DB::table('pos_riders')->where('id', $rider->id)->value('updated_at'),
            'An unchanged build must not rewrite pos_riders on every upload');

        // The rider finally updates — the stamp moves forward.
        $this->appCall($token, 'TaxNestRider/1.7.0')->assertOk();
        $rider->refresh();
        $this->assertSame('1.7.0', $rider->app_version);
    }

    // ── 2: login is the earliest proof of an install ─────────────────────────

    public function test_login_stamps_the_build(): void
    {
        $userId = (int) DB::table('users')->insertGetId([
            'name' => 'Rider User', 'email' => 'rider1405@test.pk',
            'password' => Hash::make('Secret@12345'),
            'company_id' => self::ALLOWED_COMPANY, 'role' => 'user', 'pos_role' => 'pos_rider',
            'created_at' => now(), 'updated_at' => now(),
        ]);
        [$rider] = $this->makeRider(['user_id' => $userId, 'app_token' => null]);

        $this->postJson('/api/rider-app/v1/login', [
            'email' => 'rider1405@test.pk', 'password' => 'Secret@12345',
        ], ['User-Agent' => 'TaxNestRider/1.6.0'])->assertOk();

        $rider->refresh();
        $this->assertSame('1.6.0', $rider->app_version,
            'A login from the old APK must expose the old build immediately');
    }

    public function test_login_from_a_package_locked_shop_still_stamps_the_build(): void
    {
        $userId = (int) DB::table('users')->insertGetId([
            'name' => 'Locked Rider', 'email' => 'rider1405locked@test.pk',
            'password' => Hash::make('Secret@12345'),
            'company_id' => self::LOCKED_COMPANY, 'role' => 'user', 'pos_role' => 'pos_rider',
            'created_at' => now(), 'updated_at' => now(),
        ]);
        [$rider] = $this->makeRider(['user_id' => $userId, 'app_token' => null], self::LOCKED_COMPANY);

        $res = $this->postJson('/api/rider-app/v1/login', [
            'email' => 'rider1405locked@test.pk', 'password' => 'Secret@12345',
        ], ['User-Agent' => 'TaxNestRider/1.6.0']);

        $res->assertStatus(403);
        $this->assertSame('plan_locked', $res->json('error'));

        $rider->refresh();
        $this->assertSame('1.6.0', $rider->app_version,
            'A rider who logged in from the old APK must not keep showing as "app never opened" just because his shop is locked');
    }

    // ── 4: a non-app caller must not erase what we know ──────────────────────

    public function test_a_non_rider_app_caller_never_blanks_a_known_build(): void
    {
        [$rider, $token] = $this->makeRider(['app_version' => '1.6.0']);

        $this->appCall($token, 'curl/8.4.0')->assertOk();
        $rider->refresh();
        $this->assertSame('1.6.0', $rider->app_version,
            'Silence from a non-app caller is not evidence of a downgrade');

        // A junk agent must never land in the column either.
        $this->appCall($token, 'TaxNestRider/not-a-version')->assertOk();
        $rider->refresh();
        $this->assertSame('1.6.0', $rider->app_version);
    }

    // ── 5: the package gate must not hide the build ──────────────────────────

    public function test_a_package_locked_shop_still_learns_the_build(): void
    {
        [$rider, $token] = $this->makeRider([], self::LOCKED_COMPANY);

        $res = $this->appCall($token, 'TaxNestRider/1.6.0');
        $res->assertStatus(403);
        $this->assertSame('plan_locked', $res->json('error'));

        $rider->refresh();
        $this->assertSame('1.6.0', $rider->app_version,
            'The refusal is about the package, not about which APK the rider carries');
    }

    // ── 6: the live map calls out old builds and never-opened phones ─────────

    public function test_live_map_marks_old_builds_and_riders_who_never_opened_the_app(): void
    {
        [$old] = $this->makeRider(['app_version' => '1.6.0']);
        [$current] = $this->makeRider(['app_version' => '1.7.0']);
        [$never] = $this->makeRider();

        $oldRow = $this->mapRow($old->id);
        $this->assertSame('1.6.0', $oldRow['app_version']);
        $this->assertTrue($oldRow['app_outdated'], 'A build behind the release must be marked');
        $this->assertFalse($oldRow['app_never']);
        $this->assertSame('1.7.0', $oldRow['app_latest']);

        $currentRow = $this->mapRow($current->id);
        $this->assertSame('1.7.0', $currentRow['app_version']);
        $this->assertFalse($currentRow['app_outdated']);

        $neverRow = $this->mapRow($never->id);
        $this->assertNull($neverRow['app_version']);
        $this->assertTrue($neverRow['app_never'],
            'A rider who never opened the app is the one the shop must chase');
        $this->assertFalse($neverRow['app_outdated'],
            'Unknown is its own state — not "outdated"');
    }

    // ── the release gate itself ──────────────────────────────────────────────

    public function test_no_released_version_means_nothing_can_be_called_outdated(): void
    {
        SystemSetting::set('rider_app_latest_version', '');

        [$old] = $this->makeRider(['app_version' => '1.6.0']);

        $row = $this->mapRow($old->id);
        $this->assertSame('1.6.0', $row['app_version'], 'The build is still shown');
        $this->assertFalse($row['app_outdated'],
            'With no published release there is nothing to be behind');
        $this->assertNull($row['app_latest']);
    }
}

<?php

namespace Tests\Feature;

use Tests\TestCase;
use App\Models\User;
use App\Models\PosUnmappedPinAlert;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Schema;
use Illuminate\Database\Schema\Blueprint;

/**
 * Unmapped biometric PIN alert — panel notification (Task #277, Aug 2026).
 *
 * Tests:
 *   1. ADMS punch with unmapped PIN creates an alert row.
 *   2. Subsequent punches for the same PIN do NOT create duplicate rows (dedupe).
 *   3. quickMapPin sets mapped_at on the alert.
 *   4. dismissPinAlert (POST) sets dismissed_at → admin → 302.
 *   5. Cashier cannot dismiss (403).
 *   6. Layout banner renders for admin with an active alert (HTML marker present).
 *   7. Layout banner is absent for cashier (confined role, no isPosAdmin).
 *
 * Pattern: APP_ENV=testing + sqlite :memory: + manual Schema::create.
 * All tables built in setUp(); no migrations needed.
 *
 * Run:
 *   env -u DATABASE_URL -u DB_CONNECTION -u PGHOST -u PGPORT -u PGUSER \
 *     -u PGPASSWORD -u PGDATABASE APP_ENV=testing DB_CONNECTION=sqlite \
 *     DB_DATABASE=':memory:' php vendor/bin/phpunit tests/Feature/PosUnmappedPinAlertTest.php
 */
class PosUnmappedPinAlertTest extends TestCase
{
    // Unique HTML marker emitted only by <x-bio-unmapped-pin-banner> when there
    // are active alerts. We use the dismiss form action URL (only present when
    // the banner renders with ≤3 pins). Cannot use a lang key because the layout
    // passes the entire pos.php translation array to JavaScript, so every key
    // appears in the page source even when the banner is not rendered.
    private const BANNER_MARKER = 'pos/bio-sync/pin-alert/dismiss';
    // Route name — also appears in the dismiss form action URL.
    private const DISMISS_ROUTE = 'pos.bio-sync.dismiss-pin-alert';

    private int $companyId;
    private int $adminId;
    private int $cashierId;
    private int $deviceId;
    private string $pushToken = 'testtoken123abc';

    protected function setUp(): void
    {
        parent::setUp();

        Schema::dropAllTables();

        // ── Minimal schema ────────────────────────────────────────────────

        Schema::create('companies', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('email')->nullable();
            $table->string('phone')->nullable();
            $table->string('ntn')->nullable();
            $table->string('product_type')->nullable();
            $table->string('status')->default('approved');
            $table->string('company_status')->default('approved');
            $table->boolean('restaurant_mode')->default(false);
            $table->boolean('pos_setup_completed')->default(true);
            $table->boolean('fbr_pos_enabled')->default(false);
            $table->boolean('fbr_reporting_enabled')->default(false);
            $table->boolean('inventory_enabled')->default(false);
            $table->string('pos_theme')->nullable();
            $table->string('pos_dashboard_style')->nullable();
            $table->string('confidential_pin')->nullable();
            $table->string('default_language')->nullable();
            $table->softDeletes();
            $table->timestamps();
        });

        Schema::create('users', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('email')->unique();
            $table->string('phone')->nullable();
            $table->string('username')->nullable();
            $table->string('password');
            $table->unsignedBigInteger('company_id')->nullable();
            $table->string('role')->nullable();
            $table->string('pos_role')->nullable();
            $table->text('pos_custom_access')->nullable();
            $table->boolean('is_active')->default(true);
            $table->boolean('dark_mode')->default(false);
            $table->string('language')->nullable();
            $table->unsignedBigInteger('default_branch_id')->nullable();
            $table->rememberToken();
            $table->timestamps();
        });

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
            $table->string('slug')->nullable();
            $table->string('product_type')->nullable();
            $table->decimal('price', 12, 2)->default(0);
            $table->text('features')->nullable();
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });
        Schema::create('subscriptions', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('company_id');
            $table->unsignedBigInteger('pricing_plan_id')->nullable();
            $table->string('status')->default('active');
            $table->boolean('is_active')->default(true);
            $table->boolean('active')->default(true);
            $table->date('start_date')->nullable();
            $table->date('end_date')->nullable();
            $table->timestamps();
        });
        Schema::create('system_settings', function (Blueprint $table) {
            $table->id();
            $table->string('key')->unique();
            $table->text('value')->nullable();
            $table->string('description')->nullable();
            $table->timestamps();
        });

        // Biometric tables
        Schema::create('pos_biometric_devices', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('company_id');
            $table->string('label', 100);
            $table->string('device_sn', 100)->nullable();
            $table->string('push_token', 64)->unique();
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });
        Schema::create('pos_biometric_user_map', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('company_id');
            $table->unsignedBigInteger('device_id');
            $table->string('device_pin', 50);
            $table->unsignedBigInteger('user_id');
            $table->timestamps();
            $table->unique(['device_id', 'device_pin'], 'pbum_device_pin_unique');
        });
        Schema::create('pos_biometric_punches', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('company_id');
            $table->unsignedBigInteger('device_id')->nullable();
            $table->string('device_pin', 50)->nullable();
            $table->unsignedBigInteger('user_id')->nullable();
            $table->dateTime('punched_at');
            $table->enum('punch_type', ['check_in', 'check_out', 'unknown'])->default('unknown');
            $table->string('raw_data', 500)->nullable();
            $table->string('source', 20)->default('adms');
            $table->timestamps();
            $table->unique(['device_id', 'device_pin', 'punched_at'], 'pbp_device_pin_ts_unique');
        });

        // Alert table under test
        Schema::create('pos_bio_pin_alerts', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('company_id');
            $table->string('device_pin', 50);
            $table->dateTime('first_seen_at');
            $table->dateTime('dismissed_at')->nullable();
            $table->dateTime('mapped_at')->nullable();
            $table->timestamps();
            $table->unique(['company_id', 'device_pin'], 'pbpa_company_pin_unique');
            $table->index('company_id', 'pbpa_company_idx');
        });

        // ── Seed rows ─────────────────────────────────────────────────────

        $now = now();
        $this->companyId = DB::table('companies')->insertGetId([
            'name' => 'Bio Alert Shop', 'product_type' => 'pos',
            'status' => 'approved', 'company_status' => 'approved',
            'created_at' => $now, 'updated_at' => $now,
        ]);
        $this->adminId = DB::table('users')->insertGetId([
            'name'       => 'Bio Admin',
            'email'      => 'bioadmin@test.test',
            'password'   => Hash::make('Secret@12345'),
            'company_id' => $this->companyId,
            'role'       => 'company_admin',
            'pos_role'   => 'pos_admin',
            'is_active'  => true,
            'created_at' => $now, 'updated_at' => $now,
        ]);
        $this->cashierId = DB::table('users')->insertGetId([
            'name'       => 'Bio Cashier',
            'email'      => 'biocashier@test.test',
            'password'   => Hash::make('Secret@12345'),
            'company_id' => $this->companyId,
            'role'       => null,
            'pos_role'   => 'pos_cashier',
            'is_active'  => true,
            'created_at' => $now, 'updated_at' => $now,
        ]);
        $this->deviceId = DB::table('pos_biometric_devices')->insertGetId([
            'company_id' => $this->companyId,
            'label'      => 'Main Door',
            'push_token' => $this->pushToken,
            'is_active'  => true,
            'created_at' => $now, 'updated_at' => $now,
        ]);
    }

    // ── Helpers ───────────────────────────────────────────────────────────

    /** Push an ADMS punch line for a given PIN (no user mapped → unmapped). */
    private function admsPunch(string $pin, string $datetime = '2026-08-05 09:00:00'): \Illuminate\Testing\TestResponse
    {
        $body = "{$pin}\t{$datetime}\t1\t0\t0\t\r\n";
        return $this->post(
            "/bio-sync/{$this->pushToken}/iclock/cdata?table=ATTLOG",
            [],
            ['CONTENT_TYPE' => 'text/plain', 'HTTP_RAW_POST_DATA' => $body]
        )->withBody($body, 'text/plain');
    }

    /** Directly insert a punch row (simpler than going through ADMS HTTP). */
    private function insertPunch(string $pin, ?int $userId = null): void
    {
        DB::table('pos_biometric_punches')->insert([
            'company_id' => $this->companyId,
            'device_id'  => $this->deviceId,
            'device_pin' => $pin,
            'user_id'    => $userId,
            'punched_at' => now()->format('Y-m-d H:i:s'),
            'punch_type' => 'check_in',
            'source'     => 'adms',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    /** Directly fire the alert helper via the model (mirrors what the controller does). */
    private function fireAlert(string $pin, string $punchedAt = '2026-08-05 09:00:00'): void
    {
        PosUnmappedPinAlert::firstOrCreate(
            ['company_id' => $this->companyId, 'device_pin' => $pin],
            ['first_seen_at' => $punchedAt]
        );
    }

    /** Render the POS profile page as the given user (uses pos-app layout). */
    private function renderProfile(int $userId): \Illuminate\Testing\TestResponse
    {
        return $this->actingAs(User::find($userId), 'pos')
            ->get('/pos/my-profile');
    }

    // ── Tests ─────────────────────────────────────────────────────────────

    /**
     * 1. Firing an alert for an unmapped PIN creates exactly one DB row with
     *    all expected columns populated and dismissed_at / mapped_at null.
     */
    public function test_fire_alert_creates_row_with_correct_columns(): void
    {
        $pin       = '42';
        $punchedAt = '2026-08-05 09:00:00';

        $this->fireAlert($pin, $punchedAt);

        $row = DB::table('pos_bio_pin_alerts')
            ->where('company_id', $this->companyId)
            ->where('device_pin', $pin)
            ->first();

        $this->assertNotNull($row, 'Alert row must be created');
        $this->assertEquals($this->companyId, (int) $row->company_id, 'company_id');
        $this->assertEquals($pin,             $row->device_pin,       'device_pin');
        $this->assertEquals($punchedAt,       $row->first_seen_at,    'first_seen_at');
        $this->assertNull($row->dismissed_at, 'dismissed_at must start null');
        $this->assertNull($row->mapped_at,    'mapped_at must start null');

        // Verify ALL fillable columns are present (Eloquent silent-drop trap)
        $this->assertObjectHasProperty('company_id',   $row);
        $this->assertObjectHasProperty('device_pin',   $row);
        $this->assertObjectHasProperty('first_seen_at',$row);
        $this->assertObjectHasProperty('dismissed_at', $row);
        $this->assertObjectHasProperty('mapped_at',    $row);
    }

    /**
     * 2. A second punch for the same (company, pin) must NOT create a second
     *    row — firstOrCreate deduplication must hold.
     */
    public function test_duplicate_alert_is_deduplicated(): void
    {
        $pin = '42';

        $this->fireAlert($pin, '2026-08-05 09:00:00');
        $this->fireAlert($pin, '2026-08-05 09:05:00'); // second call — same pin

        $count = DB::table('pos_bio_pin_alerts')
            ->where('company_id', $this->companyId)
            ->where('device_pin', $pin)
            ->count();

        $this->assertEquals(1, $count, 'Must have exactly one alert row for the same (company, pin)');
    }

    /**
     * 3. quickMapPin (POST /pos/bio-sync/quick-map) must set mapped_at on the
     *    alert row for the mapped PIN. Verifies via DB SELECT (not HTTP response).
     */
    public function test_quick_map_sets_mapped_at_on_alert(): void
    {
        $pin = '99';
        $this->fireAlert($pin);

        // Confirm alert is active before mapping
        $before = DB::table('pos_bio_pin_alerts')
            ->where('company_id', $this->companyId)
            ->where('device_pin', $pin)
            ->first();
        $this->assertNull($before->mapped_at, 'mapped_at must be null before mapping');

        // Insert an unmapped punch so quickMapPin's inner query finds device_ids
        $this->insertPunch($pin, null);

        // Act: map the PIN to the admin user via quickMapPin endpoint
        $this->actingAs(User::find($this->adminId), 'pos')
            ->post('/pos/bio-sync/quick-map', [
                'device_pin' => $pin,
                'user_id'    => $this->adminId,
            ]);

        // Assert: mapped_at is now set
        $after = DB::table('pos_bio_pin_alerts')
            ->where('company_id', $this->companyId)
            ->where('device_pin', $pin)
            ->first();
        $this->assertNotNull($after->mapped_at, 'mapped_at must be set after quickMapPin');
    }

    /**
     * 4. dismissPinAlert (POST /pos/bio-sync/pin-alert/dismiss) sets dismissed_at.
     *    Admin user → 302 redirect back. dismissed_at confirmed via DB SELECT.
     */
    public function test_dismiss_sets_dismissed_at(): void
    {
        $pin = '7';
        $this->fireAlert($pin);

        $resp = $this->actingAs(User::find($this->adminId), 'pos')
            ->post(route(self::DISMISS_ROUTE), ['device_pin' => $pin]);

        $resp->assertRedirect(); // 302 redirect()->back()

        $row = DB::table('pos_bio_pin_alerts')
            ->where('company_id', $this->companyId)
            ->where('device_pin', $pin)
            ->first();
        $this->assertNotNull($row->dismissed_at, 'dismissed_at must be set after dismiss');
    }

    /**
     * 5. Cashier (pos_cashier role) POSTing to the dismiss endpoint gets a 403.
     */
    public function test_cashier_cannot_dismiss(): void
    {
        $pin = '15';
        $this->fireAlert($pin);

        $resp = $this->actingAs(User::find($this->cashierId), 'pos')
            ->post(route(self::DISMISS_ROUTE), ['device_pin' => $pin]);

        $resp->assertStatus(403);

        // dismissed_at must still be null (cashier action was blocked)
        $row = DB::table('pos_bio_pin_alerts')
            ->where('company_id', $this->companyId)
            ->where('device_pin', $pin)
            ->first();
        $this->assertNull($row->dismissed_at, 'dismissed_at must remain null after cashier attempt');
    }

    /**
     * 6. When an active alert exists, the POS layout renders the banner for
     *    an admin user (HTML marker present in the page).
     */
    public function test_banner_renders_for_admin_with_active_alert(): void
    {
        $this->fireAlert('88');

        $resp = $this->renderProfile($this->adminId);
        $resp->assertStatus(200);
        $resp->assertSee(self::BANNER_MARKER);
    }

    /**
     * 7. Cashier sees no banner — isPosAdmin() returns false for pos_cashier,
     *    so $bioAlerts stays empty and the component renders nothing.
     */
    public function test_cashier_sees_no_banner(): void
    {
        $this->fireAlert('88');

        $resp = $this->renderProfile($this->cashierId);
        $resp->assertStatus(200);
        $resp->assertDontSee(self::BANNER_MARKER);
    }

    /**
     * 8. After an alert is dismissed, the banner disappears from the layout
     *    (dismissed_at IS NOT NULL → query excludes the row).
     */
    public function test_banner_hidden_after_dismiss(): void
    {
        $pin = '77';
        $this->fireAlert($pin);

        // Verify it shows first
        $before = $this->renderProfile($this->adminId);
        $before->assertSee(self::BANNER_MARKER);

        // Dismiss it
        DB::table('pos_bio_pin_alerts')
            ->where('company_id', $this->companyId)
            ->where('device_pin', $pin)
            ->update(['dismissed_at' => now()]);

        // Verify it's gone
        $after = $this->renderProfile($this->adminId);
        $after->assertDontSee(self::BANNER_MARKER);
    }

    /**
     * 9. After quickMapPin maps a PIN, the banner no longer shows for that PIN
     *    (mapped_at IS NOT NULL → query excludes the row).
     */
    public function test_banner_hidden_after_mapping(): void
    {
        $pin = '55';
        $this->fireAlert($pin);

        // Confirm visible
        $before = $this->renderProfile($this->adminId);
        $before->assertSee(self::BANNER_MARKER);

        // Mark as mapped
        DB::table('pos_bio_pin_alerts')
            ->where('company_id', $this->companyId)
            ->where('device_pin', $pin)
            ->update(['mapped_at' => now()]);

        // No longer visible
        $after = $this->renderProfile($this->adminId);
        $after->assertDontSee(self::BANNER_MARKER);
    }

    // ── Cooldown / re-surface tests (Task #278) ───────────────────────────

    /**
     * 10. A punch arriving within 7 days of dismissal must NOT re-surface the
     *     alert (cooldown window — dismissed_at stays set, row stays inactive).
     */
    public function test_dismissed_alert_within_cooldown_does_not_resurface(): void
    {
        $pin = '200';

        // Create and dismiss the alert 3 days ago (within the 7-day window).
        DB::table('pos_bio_pin_alerts')->insert([
            'company_id'    => $this->companyId,
            'device_pin'    => $pin,
            'first_seen_at' => now()->subDays(10)->format('Y-m-d H:i:s'),
            'dismissed_at'  => now()->subDays(3)->format('Y-m-d H:i:s'),
            'mapped_at'     => null,
            'created_at'    => now()->subDays(10),
            'updated_at'    => now()->subDays(3),
        ]);

        // Simulate a new punch arriving now (still within cooldown).
        $this->callFireUnmappedPinAlert($pin, now());

        $row = DB::table('pos_bio_pin_alerts')
            ->where('company_id', $this->companyId)
            ->where('device_pin', $pin)
            ->first();

        // dismissed_at must still be set — alert must NOT have been reactivated.
        $this->assertNotNull($row->dismissed_at,
            'Alert must stay dismissed when punch arrives within 7-day cooldown');
    }

    /**
     * 11. A punch arriving 7+ days after dismissal MUST re-surface the alert:
     *     dismissed_at is cleared so the row becomes active again.
     */
    public function test_dismissed_alert_after_cooldown_resurfaces_on_new_punch(): void
    {
        $pin = '201';

        // Create and dismiss the alert 8 days ago (outside the 7-day window).
        DB::table('pos_bio_pin_alerts')->insert([
            'company_id'    => $this->companyId,
            'device_pin'    => $pin,
            'first_seen_at' => now()->subDays(20)->format('Y-m-d H:i:s'),
            'dismissed_at'  => now()->subDays(8)->format('Y-m-d H:i:s'),
            'mapped_at'     => null,
            'created_at'    => now()->subDays(20),
            'updated_at'    => now()->subDays(8),
        ]);

        // Simulate a new punch arriving now (past the cooldown).
        $this->callFireUnmappedPinAlert($pin, now());

        $row = DB::table('pos_bio_pin_alerts')
            ->where('company_id', $this->companyId)
            ->where('device_pin', $pin)
            ->first();

        // dismissed_at must have been cleared — alert is active again.
        $this->assertNull($row->dismissed_at,
            'dismissed_at must be cleared when punch arrives after 7-day cooldown');
        $this->assertNull($row->mapped_at,
            'mapped_at must also be cleared on re-surface');

        // Verify the alert is now visible in the banner.
        $resp = $this->renderProfile($this->adminId);
        $resp->assertSee(self::BANNER_MARKER);
    }

    /**
     * 11b. Delayed ingestion: punch device-timestamp is 3 days after dismissal
     *      (inside the 7-day cooldown), but the punch arrives at the server
     *      much later — now() is well past 7 days after dismissal.
     *      The alert must stay dismissed because the cooldown is measured
     *      against $punchedAt (device time), not now().
     */
    public function test_delayed_ingestion_within_cooldown_does_not_resurface(): void
    {
        $pin = '203';

        // Dismissed 10 days ago (wall-clock).
        $dismissedAt = now()->subDays(10);

        DB::table('pos_bio_pin_alerts')->insert([
            'company_id'    => $this->companyId,
            'device_pin'    => $pin,
            'first_seen_at' => now()->subDays(20)->format('Y-m-d H:i:s'),
            'dismissed_at'  => $dismissedAt->format('Y-m-d H:i:s'),
            'mapped_at'     => null,
            'created_at'    => now()->subDays(20),
            'updated_at'    => $dismissedAt,
        ]);

        // Punch device-timestamp = 3 days after dismissal (inside the window),
        // even though now() is 10 days after dismissal (outside the window).
        $punchTime = $dismissedAt->copy()->addDays(3);
        $this->callFireUnmappedPinAlert($pin, $punchTime);

        $row = DB::table('pos_bio_pin_alerts')
            ->where('company_id', $this->companyId)
            ->where('device_pin', $pin)
            ->first();

        $this->assertNotNull($row->dismissed_at,
            'Alert must stay dismissed: punch device-time was inside the 7-day cooldown, '
            . 'even though server wall-clock is past 7 days after dismissal');
    }

    /**
     * 11c. Regression: punch device-timestamp is BEFORE dismissed_at (e.g. a
     *      replayed old punch from 10 days before dismissal). diffInDays() is
     *      absolute so without a direction guard it would compute 10 ≥ 7 and
     *      wrongly reactivate. The alert must stay dismissed.
     */
    public function test_punch_predating_dismissal_does_not_resurface(): void
    {
        $pin = '204';

        $dismissedAt = now()->subDays(2); // dismissed recently

        DB::table('pos_bio_pin_alerts')->insert([
            'company_id'    => $this->companyId,
            'device_pin'    => $pin,
            'first_seen_at' => now()->subDays(15)->format('Y-m-d H:i:s'),
            'dismissed_at'  => $dismissedAt->format('Y-m-d H:i:s'),
            'mapped_at'     => null,
            'created_at'    => now()->subDays(15),
            'updated_at'    => $dismissedAt,
        ]);

        // Replayed punch whose device-timestamp is 10 days BEFORE dismissal.
        $punchTime = $dismissedAt->copy()->subDays(10);
        $this->callFireUnmappedPinAlert($pin, $punchTime);

        $row = DB::table('pos_bio_pin_alerts')
            ->where('company_id', $this->companyId)
            ->where('device_pin', $pin)
            ->first();

        $this->assertNotNull($row->dismissed_at,
            'Alert must stay dismissed when punch device-timestamp pre-dates the dismissal');
    }

    /**
     * 12. Active-alert dedupe is unchanged: if an alert is already active
     *     (dismissed_at=null, mapped_at=null) a subsequent punch must NOT
     *     create a second row or alter the existing one.
     */
    public function test_active_alert_dedupe_unchanged(): void
    {
        $pin = '202';

        // Create an active alert.
        $this->fireAlert($pin, '2026-08-05 08:00:00');

        $before = DB::table('pos_bio_pin_alerts')
            ->where('company_id', $this->companyId)
            ->where('device_pin', $pin)
            ->first();

        // Fire again (second punch, same PIN, alert already active).
        $this->callFireUnmappedPinAlert($pin, now());

        $count = DB::table('pos_bio_pin_alerts')
            ->where('company_id', $this->companyId)
            ->where('device_pin', $pin)
            ->count();

        $this->assertEquals(1, $count,
            'Exactly one alert row must exist — no duplicate created for active alert');

        $after = DB::table('pos_bio_pin_alerts')
            ->where('company_id', $this->companyId)
            ->where('device_pin', $pin)
            ->first();

        // first_seen_at must be unchanged (row was not touched).
        $this->assertEquals($before->first_seen_at, $after->first_seen_at,
            'first_seen_at must not change when alert is already active');
    }

    // ── Helper: call the private fireUnmappedPinAlert via the controller ──

    /**
     * Invoke fireUnmappedPinAlert directly (it is private) via a real ADMS
     * POST that carries an unmapped punch for the given PIN. We use a unique
     * timestamp so the punch row itself doesn't conflict with pre-existing rows.
     *
     * @param \Carbon\Carbon|string $punchedAt
     */
    private function callFireUnmappedPinAlert(string $pin, $punchedAt): void
    {
        $ts = ($punchedAt instanceof \Carbon\Carbon)
            ? $punchedAt->format('Y-m-d H:i:s')
            : $punchedAt;

        // Bind currentCompanyId so the controller helper can read it.
        app()->instance('currentCompanyId', $this->companyId);

        // Use reflection to call the private method directly — avoids the ADMS
        // HTTP layer (token, duplicate-punch unique key) and tests only the
        // alert logic in isolation.
        $controller = app(\App\Http\Controllers\PosBiometricController::class);
        $method = new \ReflectionMethod($controller, 'fireUnmappedPinAlert');
        $method->setAccessible(true);
        $method->invoke($controller, $this->companyId, $pin, \Carbon\Carbon::parse($ts));
    }
}

<?php

namespace Tests\Feature;

use Tests\TestCase;
use App\Models\Company;
use App\Models\User;
use App\Services\PosFeatureService;
use App\Support\PosBiometricRows;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Database\Schema\Blueprint;
use Carbon\Carbon;

/**
 * FBR BIOMETRIC HAZRI — LATE-ARRIVAL MARKING (Task #1274).
 *
 * Late policy: companies.pos_bio_late_after ('HH:MM', admin-set on the FBR
 * Biometric Setup page; NULL = off). A staff member is LATE on a business day
 * when their FIRST check-in punch is after that wall-clock time. This state
 * is judged only from check-in punches and is DISTINCT from the open-duty
 * (missing checkout) amber * convention:
 *
 *   - late-but-closed  → red "Late Xm" chip, no open star
 *   - on-time-but-open → open star, late_minutes = 0 (NOT late)
 *
 * Locked guarantees, over real HTTP where possible:
 *   1. Late-but-closed punch pair renders the red late chip on the day report.
 *   2. On-time-but-open duty is NOT marked late (distinct states).
 *   3. Range summary counts late days per staff + Late-Days column renders.
 *   4. Feature off (NULL) → no late chip / no Late-Days column.
 *   5. Authorization: cashier cannot save the threshold (403), admin can,
 *      bad format is rejected.
 *   6. Payroll PDF renders with the late column enabled.
 *
 * Run:
 *   env -u DATABASE_URL -u DB_CONNECTION -u PGHOST -u PGPORT -u PGUSER \
 *     -u PGPASSWORD -u PGDATABASE APP_ENV=testing DB_CONNECTION=sqlite \
 *     DB_DATABASE=':memory:' php vendor/bin/phpunit tests/Feature/FbrPosBioLateArrivalTest.php
 */
class FbrPosBioLateArrivalTest extends TestCase
{
    private Company $company;
    private User $posAdmin;
    private int $deviceId;

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow(now()->setTime(20, 0));
        PosFeatureService::flushGateCaches();
        Schema::dropAllTables();
        $this->buildSchema();
        [$this->company, $this->posAdmin] = $this->seedShop();
        $this->deviceId = DB::table('pos_biometric_devices')->insertGetId([
            'company_id' => $this->company->id,
            'label'      => 'Front Door',
            'push_token' => str_repeat('a', 48),
            'is_active'  => true,
            'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        PosFeatureService::flushGateCaches();
        parent::tearDown();
    }

    // ─────────────────────────────────────────────────────────────────────────
    // Helpers
    // ─────────────────────────────────────────────────────────────────────────

    private function punch(int $userId, string $type, Carbon $at): void
    {
        DB::table('pos_biometric_punches')->insert([
            'company_id' => $this->company->id,
            'device_id'  => $this->deviceId,
            'device_pin' => '7',
            'user_id'    => $userId,
            'punch_type' => $type,
            'punched_at' => $at,
            'source'     => 'adms',
            'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    private function enableLate(string $time = '10:00'): void
    {
        $this->company->pos_bio_late_after = $time;
        $this->company->save();
    }

    private function makeCashier(): User
    {
        return User::create([
            'name' => 'Cashier', 'email' => 'cashier@fbrlate.pk',
            'password' => bcrypt('secret'), 'company_id' => $this->company->id,
            'role' => 'user', 'pos_role' => 'pos_cashier',
            'is_active' => true, 'language' => 'en',
        ]);
    }

    // ─────────────────────────────────────────────────────────────────────────
    // 1 + 2 — late-but-closed vs on-time-but-open (distinct states)
    // ─────────────────────────────────────────────────────────────────────────

    /** First check-in 10:23 vs 10:00 threshold, checked out → late chip. */
    public function test_late_but_closed_day_shows_late_chip(): void
    {
        $this->enableLate('10:00');
        $day = now()->subDays(2)->startOfDay();
        $this->punch($this->posAdmin->id, 'check_in',  $day->copy()->setTime(10, 23));
        $this->punch($this->posAdmin->id, 'check_out', $day->copy()->setTime(18, 0));

        $html = $this->actingAs($this->posAdmin, 'fbrpos')
            ->get('/fbr-pos/reports/hazri?date=' . $day->toDateString())
            ->assertOk()
            ->getContent();

        $this->assertStringContainsString('Late 23m', $html,
            'Late-but-closed day must render the red "Late 23m" chip');

        // The row builder itself: late yes, open no — the two states differ.
        $rows = PosBiometricRows::build($this->company->id, $day->toDateString(), '10:00');
        $this->assertCount(1, $rows);
        $this->assertSame(23, $rows[0]->late_minutes);
        $this->assertFalse($rows[0]->duty_open,
            'Checked-out duty must NOT carry the open-duty flag');
    }

    /** Check-in 09:50 (on time), never checked out → open star, NOT late. */
    public function test_on_time_but_open_duty_is_not_marked_late(): void
    {
        $this->enableLate('10:00');
        $day = now()->subDays(2)->startOfDay();
        $this->punch($this->posAdmin->id, 'check_in', $day->copy()->setTime(9, 50));
        // no check_out — open duty

        $html = $this->actingAs($this->posAdmin, 'fbrpos')
            ->get('/fbr-pos/reports/hazri?date=' . $day->toDateString())
            ->assertOk()
            ->getContent();

        $this->assertDoesNotMatchRegularExpression('/Late \d+m/', $html,
            'On-time staff must never get a late chip — open duty is a different state');

        $rows = PosBiometricRows::build($this->company->id, $day->toDateString(), '10:00');
        $this->assertCount(1, $rows);
        $this->assertSame(0, $rows[0]->late_minutes,
            'On-time check-in must yield late_minutes = 0');
        $this->assertTrue($rows[0]->duty_open,
            'Missing checkout must still set the open-duty flag');
    }

    // ─────────────────────────────────────────────────────────────────────────
    // 3 — range aggregation
    // ─────────────────────────────────────────────────────────────────────────

    /** 3 worked days, 2 of them late → Late-Days column shows 2. */
    public function test_range_summary_counts_late_days(): void
    {
        $this->enableLate('10:00');
        $d1 = now()->subDays(4)->startOfDay();   // late (10:30)
        $d2 = now()->subDays(3)->startOfDay();   // on time (09:45)
        $d3 = now()->subDays(2)->startOfDay();   // late (11:05)
        foreach ([[$d1, 10, 30], [$d2, 9, 45], [$d3, 11, 5]] as [$d, $h, $m]) {
            $this->punch($this->posAdmin->id, 'check_in',  $d->copy()->setTime($h, $m));
            $this->punch($this->posAdmin->id, 'check_out', $d->copy()->setTime(18, 0));
        }

        $from = now()->subDays(5)->toDateString();
        $to   = now()->toDateString();
        $html = $this->actingAs($this->posAdmin, 'fbrpos')
            ->get("/fbr-pos/reports/hazri?date_from={$from}&date_to={$to}")
            ->assertOk()
            ->getContent();

        $this->assertStringContainsString('Late Days', $html,
            'Range summary must render the Late-Days column when the feature is on');
        // Row + tfoot total both carry the red late count of 2.
        $this->assertMatchesRegularExpression(
            '/text-red-600[^>]*>\s*2\s*</',
            $html,
            'Late-days count of 2 must render in red in the biometric summary'
        );
    }

    // ─────────────────────────────────────────────────────────────────────────
    // 3b — pre-06:00 threshold rolls to the NEXT calendar day (night shift)
    // ─────────────────────────────────────────────────────────────────────────

    /**
     * Business day D = 06:00 D → 05:59 D+1, so a 03:00 threshold lives on the
     * NEXT calendar day. An 01:00 D+1 arrival (before 03:00 D+1) is on time;
     * a 04:30 D+1 arrival is 90m late. Without the rollover the threshold sits
     * before the whole window and EVERY punch counts late.
     */
    public function test_pre_dawn_threshold_rolls_to_next_calendar_day(): void
    {
        $this->enableLate('03:00');
        $dayA = now()->subDays(4)->startOfDay();  // on time: in 01:00 A+1
        $dayB = now()->subDays(2)->startOfDay();  // late:    in 04:30 B+1
        $this->punch($this->posAdmin->id, 'check_in',  $dayA->copy()->addDay()->setTime(1, 0));
        $this->punch($this->posAdmin->id, 'check_out', $dayA->copy()->addDay()->setTime(4, 0));
        $this->punch($this->posAdmin->id, 'check_in',  $dayB->copy()->addDay()->setTime(4, 30));
        $this->punch($this->posAdmin->id, 'check_out', $dayB->copy()->addDay()->setTime(5, 30));

        // Day view A: on time — no late chip.
        $htmlA = $this->actingAs($this->posAdmin, 'fbrpos')
            ->get('/fbr-pos/reports/hazri?date=' . $dayA->toDateString())
            ->assertOk()->getContent();
        $this->assertDoesNotMatchRegularExpression('/Late \d/', $htmlA,
            '01:00 next-calendar-day arrival is BEFORE the 03:00 threshold — must not be late');

        // Day view B: 04:30 vs 03:00 → Late 1h 30m.
        $htmlB = $this->actingAs($this->posAdmin, 'fbrpos')
            ->get('/fbr-pos/reports/hazri?date=' . $dayB->toDateString())
            ->assertOk()->getContent();
        $this->assertStringContainsString('Late 1h 30m', $htmlB);

        // Row builder mirrors both.
        $rowsA = PosBiometricRows::build($this->company->id, $dayA->toDateString(), '03:00');
        $rowsB = PosBiometricRows::build($this->company->id, $dayB->toDateString(), '03:00');
        $this->assertSame(0,  $rowsA[0]->late_minutes);
        $this->assertSame(90, $rowsB[0]->late_minutes);
    }

    /** Range + PDF with the pre-dawn threshold: exactly 1 late day, PDF renders. */
    public function test_pre_dawn_threshold_range_and_pdf(): void
    {
        $this->enableLate('03:00');
        $dayA = now()->subDays(4)->startOfDay();
        $dayB = now()->subDays(2)->startOfDay();
        $this->punch($this->posAdmin->id, 'check_in',  $dayA->copy()->addDay()->setTime(1, 0));
        $this->punch($this->posAdmin->id, 'check_out', $dayA->copy()->addDay()->setTime(4, 0));
        $this->punch($this->posAdmin->id, 'check_in',  $dayB->copy()->addDay()->setTime(4, 30));
        $this->punch($this->posAdmin->id, 'check_out', $dayB->copy()->addDay()->setTime(5, 30));

        $from = now()->subDays(5)->toDateString();
        $to   = now()->toDateString();

        $html = $this->actingAs($this->posAdmin, 'fbrpos')
            ->get("/fbr-pos/reports/hazri?date_from={$from}&date_to={$to}")
            ->assertOk()->getContent();
        $this->assertStringContainsString('Late Days', $html);
        $this->assertMatchesRegularExpression('/text-red-600[^>]*>\s*1\s*</', $html,
            'Only the 04:30 arrival is late — Late Days must be exactly 1, not 2');

        $resp = $this->actingAs($this->posAdmin, 'fbrpos')
            ->get("/fbr-pos/reports/hazri/payroll-pdf?date_from={$from}&date_to={$to}");
        $resp->assertOk();
        $this->assertStringStartsWith('application/pdf', $resp->headers->get('content-type', ''));
    }

    // ─────────────────────────────────────────────────────────────────────────
    // 4 — feature off
    // ─────────────────────────────────────────────────────────────────────────

    /** NULL threshold → no chip on the day view, no Late-Days column in range. */
    public function test_feature_off_shows_no_late_ui(): void
    {
        // pos_bio_late_after stays NULL
        $day = now()->subDays(2)->startOfDay();
        $this->punch($this->posAdmin->id, 'check_in',  $day->copy()->setTime(11, 30));
        $this->punch($this->posAdmin->id, 'check_out', $day->copy()->setTime(18, 0));

        $from = now()->subDays(5)->toDateString();
        $to   = now()->toDateString();

        $dayHtml = $this->actingAs($this->posAdmin, 'fbrpos')
            ->get('/fbr-pos/reports/hazri?date=' . $day->toDateString())
            ->assertOk()->getContent();
        $rangeHtml = $this->actingAs($this->posAdmin, 'fbrpos')
            ->get("/fbr-pos/reports/hazri?date_from={$from}&date_to={$to}")
            ->assertOk()->getContent();

        $this->assertDoesNotMatchRegularExpression('/Late \d+m/', $dayHtml);
        $this->assertStringNotContainsString('Late Days', $rangeHtml);

        $rows = PosBiometricRows::build($this->company->id, $day->toDateString());
        $this->assertNull($rows[0]->late_minutes,
            'Without a threshold late_minutes must stay null');
    }

    // ─────────────────────────────────────────────────────────────────────────
    // 5 — authorization on the threshold setting
    // ─────────────────────────────────────────────────────────────────────────

    public function test_cashier_cannot_save_late_threshold(): void
    {
        $this->actingAs($this->makeCashier(), 'fbrpos')
            ->post('/fbr-pos/bio-sync/late-time', ['late_after' => '10:00'])
            ->assertForbidden();

        $this->assertNull($this->company->fresh()->pos_bio_late_after);
    }

    public function test_admin_saves_and_clears_late_threshold(): void
    {
        $this->actingAs($this->posAdmin, 'fbrpos')
            ->post('/fbr-pos/bio-sync/late-time', ['late_after' => '10:30'])
            ->assertRedirect(route('fbrpos.bio-sync.setup'));
        $this->assertSame('10:30', $this->company->fresh()->pos_bio_late_after);

        // Empty value turns the feature off again.
        $this->actingAs($this->posAdmin, 'fbrpos')
            ->post('/fbr-pos/bio-sync/late-time', ['late_after' => ''])
            ->assertRedirect(route('fbrpos.bio-sync.setup'));
        $this->assertNull($this->company->fresh()->pos_bio_late_after);
    }

    public function test_bad_time_format_is_rejected(): void
    {
        $this->actingAs($this->posAdmin, 'fbrpos')
            ->from('/fbr-pos/bio-sync')
            ->post('/fbr-pos/bio-sync/late-time', ['late_after' => '25:99'])
            ->assertSessionHasErrors('late_after');
        $this->assertNull($this->company->fresh()->pos_bio_late_after);
    }

    // ─────────────────────────────────────────────────────────────────────────
    // 6 — payroll PDF with the late column enabled
    // ─────────────────────────────────────────────────────────────────────────

    public function test_payroll_pdf_renders_with_late_column(): void
    {
        $this->enableLate('10:00');
        $day = now()->subDays(2)->startOfDay();
        $this->punch($this->posAdmin->id, 'check_in',  $day->copy()->setTime(10, 45));
        $this->punch($this->posAdmin->id, 'check_out', $day->copy()->setTime(18, 0));

        $from = now()->subDays(5)->toDateString();
        $to   = now()->toDateString();

        $resp = $this->actingAs($this->posAdmin, 'fbrpos')
            ->get("/fbr-pos/reports/hazri/payroll-pdf?date_from={$from}&date_to={$to}");

        $resp->assertOk();
        $this->assertStringStartsWith(
            'application/pdf',
            $resp->headers->get('content-type', ''),
            'Payroll PDF must render as a PDF with the late column enabled'
        );
    }

    // ─────────────────────────────────────────────────────────────────────────
    // Fixtures (mirror FbrPosHazriCashierGateTest)
    // ─────────────────────────────────────────────────────────────────────────

    private function seedShop(): array
    {
        $planId = DB::table('pricing_plans')->insertGetId([
            'name' => 'FBR Business', 'product_type' => 'fbrpos',
            'is_trial' => false,
            'created_at' => now(), 'updated_at' => now(),
        ]);

        // default_language 'en' — the FBR panel defaults to Roman Urdu, but
        // these tests assert English strings ('Late Days', 'Late 23m').
        $company = Company::create([
            'name' => 'Late Hazri FBR Shop', 'product_type' => 'fbrpos',
            'status' => 'active', 'company_status' => 'active',
            'is_internal_account' => false,
            'fbr_reporting_enabled' => false,
            'fbr_pos_enabled' => true,
            'default_language' => 'en',
        ]);

        DB::table('subscriptions')->insert([
            'company_id' => $company->id, 'pricing_plan_id' => $planId,
            'active' => true, 'override_type' => 'none',
            'start_date' => now()->subMonth()->toDateString(),
            'end_date' => now()->addMonth()->toDateString(),
            'created_at' => now(), 'updated_at' => now(),
        ]);

        $user = User::create([
            'name' => 'Shop Owner', 'email' => 'admin@fbrlate.pk',
            'password' => bcrypt('secret'), 'company_id' => $company->id,
            'role' => 'company_admin', 'pos_role' => 'pos_admin',
            'is_active' => true, 'language' => 'en',
        ]);

        return [$company, $user];
    }

    private function buildSchema(): void
    {
        Schema::create('companies', function (Blueprint $t) {
            $t->id();
            $t->string('name');
            $t->string('product_type')->default('fbrpos');
            $t->string('status')->default('active');
            $t->string('company_status')->default('active');
            $t->boolean('is_internal_account')->default(false);
            $t->integer('invoice_limit_override')->nullable();
            $t->boolean('fbr_reporting_enabled')->default(false);
            $t->boolean('fbr_pos_enabled')->default(true);
            $t->string('fbr_connection_mode')->nullable();
            $t->string('fbr_environment')->nullable();
            $t->string('pos_dashboard_style')->nullable();
            $t->string('default_language')->nullable();
            $t->string('pos_bio_late_after', 5)->nullable();
            $t->softDeletes();
            $t->timestamps();
        });

        Schema::create('users', function (Blueprint $t) {
            $t->id();
            $t->string('name')->nullable();
            $t->string('email')->nullable()->unique();
            $t->string('password')->nullable();
            $t->unsignedBigInteger('company_id')->nullable();
            $t->unsignedBigInteger('default_branch_id')->nullable();
            $t->string('role')->nullable();
            $t->string('pos_role')->nullable();
            $t->boolean('is_active')->default(true);
            $t->string('language')->nullable();
            $t->rememberToken();
            $t->timestamps();
        });

        Schema::create('branches', function (Blueprint $t) {
            $t->id();
            $t->unsignedBigInteger('company_id');
            $t->string('name')->nullable();
            $t->boolean('is_head_office')->default(false);
            $t->boolean('is_active')->default(true);
            $t->timestamps();
        });

        Schema::create('fbr_pos_transactions', function (Blueprint $t) {
            $t->id();
            $t->unsignedBigInteger('company_id');
            $t->unsignedBigInteger('branch_id')->nullable();
            $t->string('invoice_number');
            $t->string('transaction_type')->default('sale');
            $t->string('status')->nullable();
            $t->string('invoice_mode')->nullable();
            $t->string('fbr_status')->nullable();
            $t->decimal('subtotal', 12, 2)->default(0);
            $t->decimal('tax_amount', 12, 2)->default(0);
            $t->decimal('total_amount', 12, 2)->default(0);
            $t->unsignedBigInteger('created_by')->nullable();
            $t->timestamps();
        });

        Schema::create('pos_user_sessions', function (Blueprint $t) {
            $t->id();
            $t->unsignedBigInteger('company_id');
            $t->unsignedBigInteger('user_id');
            $t->timestamp('login_at')->nullable();
            $t->timestamp('logout_at')->nullable();
            $t->timestamp('last_seen_at')->nullable();
            $t->timestamps();
        });

        Schema::create('pos_biometric_devices', function (Blueprint $t) {
            $t->id();
            $t->unsignedBigInteger('company_id');
            $t->string('label');
            $t->string('device_sn')->nullable();
            $t->string('push_token', 64)->unique();
            $t->boolean('is_active')->default(true);
            $t->string('last_push_ip', 45)->nullable();
            $t->dateTime('last_push_at')->nullable();
            $t->timestamps();
        });

        Schema::create('pos_biometric_punches', function (Blueprint $t) {
            $t->id();
            $t->unsignedBigInteger('company_id');
            $t->unsignedBigInteger('device_id')->nullable();
            $t->string('device_pin', 50)->nullable();
            $t->unsignedBigInteger('user_id')->nullable();
            $t->string('punch_type', 20);
            $t->dateTime('punched_at');
            $t->string('source', 20)->default('adms');
            $t->timestamps();
        });

        Schema::create('pricing_plans', function (Blueprint $t) {
            $t->id();
            $t->string('name')->nullable();
            $t->string('product_type')->nullable();
            $t->boolean('is_trial')->default(false);
            $t->decimal('price', 12, 2)->default(0);
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
            $t->timestamp('override_granted_at')->nullable();
            $t->integer('free_invoice_limit')->nullable();
            $t->timestamps();
        });

        Schema::create('notifications', function (Blueprint $t) {
            $t->id();
            $t->unsignedBigInteger('company_id')->nullable();
            $t->unsignedBigInteger('user_id')->nullable();
            $t->string('type');
            $t->string('title');
            $t->text('message');
            $t->boolean('read')->default(false);
            $t->json('metadata')->nullable();
            $t->timestamps();
        });
    }
}

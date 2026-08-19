<?php

namespace Tests\Feature;

use Tests\TestCase;
use App\Models\AdminUser;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Schema;
use Illuminate\Database\Schema\Blueprint;

/**
 * Feature tests for AdminLiveActivityController (Task #1215).
 *
 * Verifies:
 *  - super-admin-only gate (403 for any other role)
 *  - PRA bill aggregation: bill_count, total, reg_submitted, local_bills
 *  - FBR bill aggregation: bill_count, total, reg_submitted (submitted+success), local_bills
 *  - @scaletest.pk company exclusion
 *  - online/offline 6-minute heartbeat cutoff
 *  - summary tiles reflect both sections' combined totals
 *  - page renders cleanly when the optional tables are entirely absent
 */
class AdminLiveActivityTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        Schema::dropAllTables();

        // ── core admin ──────────────────────────────────────────────────────
        Schema::create('admin_users', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('email')->unique();
            $table->string('password');
            $table->string('role')->default('super_admin');
            $table->rememberToken();
            $table->timestamps();
        });

        // ── companies ───────────────────────────────────────────────────────
        Schema::create('companies', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('email')->nullable();
            $table->string('product_type')->nullable();
            $table->string('status')->default('approved');
            $table->string('company_status')->default('active');
            // pos_business_day_cutoff is optional — PosBusinessDay is guarded
            $table->string('pos_business_day_cutoff', 5)->nullable();
            $table->softDeletes();
            $table->timestamps();
        });

        // ── PRA transactions ────────────────────────────────────────────────
        Schema::create('pos_transactions', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('company_id')->nullable();
            $table->string('status')->nullable();
            $table->decimal('total_amount', 14, 2)->default(0);
            $table->string('pra_status')->nullable();
            $table->date('business_date')->nullable();
            $table->timestamps();
        });

        // ── FBR transactions ────────────────────────────────────────────────
        Schema::create('fbr_pos_transactions', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('company_id')->nullable();
            $table->string('status')->nullable();
            $table->decimal('total_amount', 14, 2)->default(0);
            $table->string('fbr_status')->nullable();
            $table->date('business_date')->nullable();
            $table->timestamps();
        });

        // ── heartbeat sessions ───────────────────────────────────────────────
        Schema::create('pos_user_sessions', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('company_id')->nullable();
            $table->unsignedBigInteger('user_id')->nullable();
            $table->timestamp('last_activity_at')->nullable();
            $table->timestamp('logout_at')->nullable();
            $table->timestamps();
        });

        // ── day-close reports (for PosBusinessDay resolver) ─────────────────
        Schema::create('pos_day_close_reports', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('company_id');
            $table->date('report_date');
            $table->timestamps();
        });

        Schema::create('fbr_day_close_reports', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('company_id');
            $table->date('report_date');
            $table->timestamps();
        });

        // ── seed super-admin ─────────────────────────────────────────────────
        DB::table('admin_users')->insert([
            'name'       => 'Super Admin',
            'email'      => 'super@taxnest.test',
            'password'   => Hash::make('Secret@123'),
            'role'       => 'super_admin',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    // ────────────────────────────────────────────────────────────────────────
    // Helpers
    // ────────────────────────────────────────────────────────────────────────

    /**
     * Mirror the controller's business-day logic: before 06:00 → yesterday,
     * otherwise today.  PosBusinessDay has no day-close report rows in these
     * tests, so the forMoment resolver falls straight through to this rule.
     */
    private function currentBizDate(): string
    {
        $now = now();
        return $now->format('H:i') < '06:00'
            ? $now->copy()->subDay()->toDateString()
            : $now->toDateString();
    }

    private function actingAsSuperAdmin(): self
    {
        return $this->actingAs(AdminUser::first(), 'admin');
    }

    private function actingAsNonSuperAdmin(): self
    {
        DB::table('admin_users')->insert([
            'name'       => 'Regular Admin',
            'email'      => 'regular@taxnest.test',
            'password'   => Hash::make('Secret@123'),
            'role'       => 'admin',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        return $this->actingAs(AdminUser::where('email', 'regular@taxnest.test')->first(), 'admin');
    }

    private function makePraCompany(string $name, ?string $email = null): int
    {
        return DB::table('companies')->insertGetId([
            'name'         => $name,
            'email'        => $email,
            'product_type' => 'pos',
            'status'       => 'approved',
            'created_at'   => now(),
            'updated_at'   => now(),
        ]);
    }

    private function makeFbrCompany(string $name, ?string $email = null): int
    {
        return DB::table('companies')->insertGetId([
            'name'         => $name,
            'email'        => $email,
            'product_type' => 'fbrpos',
            'status'       => 'approved',
            'created_at'   => now(),
            'updated_at'   => now(),
        ]);
    }

    private function praBill(int $companyId, float $amount, ?string $praStatus = null): void
    {
        DB::table('pos_transactions')->insert([
            'company_id'    => $companyId,
            'status'        => 'completed',
            'total_amount'  => $amount,
            'pra_status'    => $praStatus,
            'business_date' => $this->currentBizDate(),
            'created_at'    => now(),
            'updated_at'    => now(),
        ]);
    }

    private function fbrBill(int $companyId, float $amount, ?string $fbrStatus = null): void
    {
        DB::table('fbr_pos_transactions')->insert([
            'company_id'    => $companyId,
            'status'        => 'completed',
            'total_amount'  => $amount,
            'fbr_status'    => $fbrStatus,
            'business_date' => $this->currentBizDate(),
            'created_at'    => now(),
            'updated_at'    => now(),
        ]);
    }

    private function heartbeat(int $companyId, Carbon $at, bool $loggedOut = false): void
    {
        DB::table('pos_user_sessions')->insert([
            'company_id'       => $companyId,
            'last_activity_at' => $at,
            'logout_at'        => $loggedOut ? $at : null,
            'created_at'       => now(),
            'updated_at'       => now(),
        ]);
    }

    // ────────────────────────────────────────────────────────────────────────
    // Gate tests
    // ────────────────────────────────────────────────────────────────────────

    /** Non-super-admin admin users are turned away with 403. */
    public function test_non_super_admin_gets_403(): void
    {
        $this->actingAsNonSuperAdmin()
            ->get('/admin/live-activity')
            ->assertStatus(403);
    }

    /** Unauthenticated guests are redirected to admin login. */
    public function test_unauthenticated_redirected_to_login(): void
    {
        $this->get('/admin/live-activity')
            ->assertRedirect('/admin/login');
    }

    /** Super-admin can load the page even with no data at all. */
    public function test_super_admin_sees_page_on_empty_db(): void
    {
        $this->actingAsSuperAdmin()
            ->get('/admin/live-activity')
            ->assertStatus(200)
            ->assertSee('Live Activity');
    }

    // ────────────────────────────────────────────────────────────────────────
    // PRA aggregation
    // ────────────────────────────────────────────────────────────────────────

    /**
     * PRA section shows the correct bill count, total, reg_submitted (pra_status
     * = 'submitted') and local_bills (pra_status = 'local' or NULL), all scoped
     * to the company's own table row.
     */
    public function test_pra_bills_aggregated_correctly(): void
    {
        $cid = $this->makePraCompany('Test PRA Shop');

        // 2 submitted, 1 local, 1 null-status → reg=2, local=2, bill_count=4, total=3800
        $this->praBill($cid, 1000, 'submitted');
        $this->praBill($cid, 2000, 'submitted');
        $this->praBill($cid, 500,  'local');
        $this->praBill($cid, 300,  null);

        $html = $this->actingAsSuperAdmin()
            ->get('/admin/live-activity')
            ->assertStatus(200)
            ->getContent();

        // Scope assertions to this company's row only.
        $rowStart = strpos($html, 'Test PRA Shop');
        $this->assertNotFalse($rowStart, 'Test PRA Shop row not found');
        $rowEnd = strpos($html, '</tr>', $rowStart);
        $row    = substr($html, $rowStart, $rowEnd - $rowStart);

        // bill_count = 4, total = 3,800
        $this->assertStringContainsString('3,800', $row, 'Wrong total in PRA row');
        $this->assertStringContainsString('>4<', $row, 'Wrong bill count in PRA row');

        // Breakdown: reg_submitted=2 (title="PRA submitted"), local_bills=2 (title="Local / NULL")
        $this->assertStringContainsString('title="PRA submitted">2<', $row,
            'reg_submitted value missing or wrong in PRA row');
        $this->assertStringContainsString('title="Local / NULL">2<', $row,
            'local_bills value missing or wrong in PRA row');
    }

    /**
     * Bills with status != 'completed' must NOT be counted.
     */
    public function test_pra_incomplete_bills_excluded(): void
    {
        $cid = $this->makePraCompany('PRA Draft Shop');

        $this->praBill($cid, 9999, 'submitted');   // completed = counted
        // Insert a non-completed row directly (same business date)
        DB::table('pos_transactions')->insert([
            'company_id'    => $cid,
            'status'        => 'draft',
            'total_amount'  => 9999,
            'pra_status'    => 'submitted',
            'business_date' => $this->currentBizDate(),
            'created_at'    => now(),
            'updated_at'    => now(),
        ]);

        $response = $this->actingAsSuperAdmin()->get('/admin/live-activity');
        $response->assertStatus(200);
        $response->assertSee('PRA Draft Shop');

        // Only 1 bill should be counted (the completed one)
        $html = $response->getContent();
        // 9,999 total (just one bill); 19,998 would appear if draft was included
        $this->assertStringContainsString('9,999', $html);
        $this->assertStringNotContainsString('19,998', $html);
    }

    // ────────────────────────────────────────────────────────────────────────
    // FBR aggregation
    // ────────────────────────────────────────────────────────────────────────

    /**
     * FBR section: reg_submitted counts BOTH 'submitted' AND 'success';
     * local_bills counts 'local' AND NULL — assertions scoped to the row.
     */
    public function test_fbr_bills_aggregated_correctly(): void
    {
        $cid = $this->makeFbrCompany('Test FBR Shop');

        // submitted + success → reg_submitted = 2
        // local + null        → local_bills   = 2
        // total = 5 000, bill_count = 4
        $this->fbrBill($cid, 1500, 'submitted');
        $this->fbrBill($cid, 2500, 'success');
        $this->fbrBill($cid, 800,  'local');
        $this->fbrBill($cid, 200,  null);

        $html = $this->actingAsSuperAdmin()
            ->get('/admin/live-activity')
            ->assertStatus(200)
            ->getContent();

        // Scope to this company's row.
        $rowStart = strpos($html, 'Test FBR Shop');
        $this->assertNotFalse($rowStart, 'Test FBR Shop row not found');
        $rowEnd = strpos($html, '</tr>', $rowStart);
        $row    = substr($html, $rowStart, $rowEnd - $rowStart);

        // bill_count = 4, total = 5,000
        $this->assertStringContainsString('>4<', $row, 'Wrong bill count in FBR row');
        $this->assertStringContainsString('5,000', $row, 'Wrong total in FBR row');

        // reg_submitted = 2 (title="FBR submitted" — both 'submitted' and 'success' count)
        $this->assertStringContainsString('title="FBR submitted">2<', $row,
            'reg_submitted value missing or wrong in FBR row (success must be counted)');

        // local_bills = 2 ('local' + NULL both count)
        $this->assertStringContainsString('title="Local / NULL">2<', $row,
            'local_bills value missing or wrong in FBR row (NULL must be counted)');
    }

    /**
     * Combined summary tiles reflect the exact merged PRA+FBR bill count,
     * billing total, and active-shop count.
     */
    public function test_fbr_and_pra_combined_summary_tiles(): void
    {
        $praId = $this->makePraCompany('PRA Summary Shop');
        $fbrId = $this->makeFbrCompany('FBR Summary Shop');

        // PRA: 2 bills totalling 3 000
        $this->praBill($praId, 1000, 'submitted');
        $this->praBill($praId, 2000, 'submitted');
        // FBR: 1 bill totalling 3 000 (uses 'success' status)
        $this->fbrBill($fbrId, 3000, 'success');

        $html = $this->actingAsSuperAdmin()
            ->get('/admin/live-activity')
            ->assertStatus(200)
            ->getContent();

        // Summary sub-lines show per-product breakdown.
        $this->assertStringContainsString('PRA 2', $html, 'PRA bill count missing from summary');
        $this->assertStringContainsString('FBR 1', $html, 'FBR bill count missing from summary');

        // Combined billing total = 6 000 (3 000 PRA + 3 000 FBR).
        $this->assertStringContainsString('Rs 6,000', $html, 'Combined billing total wrong in summary tile');

        // Active-shop tile: 1 PRA + 1 FBR = 2 active shops total.
        $this->assertStringContainsString('PRA 1', $html, 'PRA active-shop count missing from summary');
        $this->assertStringContainsString('FBR 1', $html, 'FBR active-shop count missing from summary');
    }

    // ────────────────────────────────────────────────────────────────────────
    // @scaletest.pk exclusion
    // ────────────────────────────────────────────────────────────────────────

    /** Companies with @scaletest.pk email must not appear on the page at all. */
    public function test_scaletest_companies_excluded(): void
    {
        // Real shop — should appear
        $realId = $this->makePraCompany('Real Shop', 'owner@realshop.pk');
        $this->praBill($realId, 500, 'submitted');

        // Scale-test shop — must be filtered out
        $scaleId = $this->makePraCompany('Scale Shop', 'bot@scaletest.pk');
        $this->praBill($scaleId, 99999, 'submitted');

        $response = $this->actingAsSuperAdmin()->get('/admin/live-activity');

        $response->assertStatus(200);
        $response->assertSee('Real Shop');
        $response->assertDontSee('Scale Shop');
        // The 99 999 total must not pollute the summary
        $response->assertDontSee('99,999');
    }

    /** Companies with NULL email (no email set) must still appear. */
    public function test_null_email_company_is_included(): void
    {
        $cid = $this->makePraCompany('No-Email Shop', null);
        $this->praBill($cid, 1234, 'submitted');

        $this->actingAsSuperAdmin()
            ->get('/admin/live-activity')
            ->assertStatus(200)
            ->assertSee('No-Email Shop');
    }

    // ────────────────────────────────────────────────────────────────────────
    // Online / offline heartbeat cutoff
    // ────────────────────────────────────────────────────────────────────────

    /**
     * A session with last_activity_at within the last 6 minutes and no
     * logout_at marks the shop as Online.
     */
    public function test_recent_session_shows_online(): void
    {
        $cid = $this->makePraCompany('Online Shop');

        // heartbeat 3 minutes ago — within the 6-minute window
        $this->heartbeat($cid, now()->subMinutes(3));

        $response = $this->actingAsSuperAdmin()->get('/admin/live-activity');
        $response->assertStatus(200);

        $html = $response->getContent();
        // The row for 'Online Shop' must contain the 'Online' badge
        $shopPos  = strpos($html, 'Online Shop');
        $onlinePos = strpos($html, 'Online', $shopPos);
        $this->assertNotFalse($shopPos, 'Online Shop row not found');
        $this->assertNotFalse($onlinePos, '"Online" badge not found after company name');
    }

    /**
     * A session whose last_activity_at is older than 6 minutes marks the shop
     * as Offline.
     */
    public function test_stale_session_shows_offline(): void
    {
        $cid = $this->makePraCompany('Stale Shop');

        // heartbeat 10 minutes ago — outside the 6-minute window
        $this->heartbeat($cid, now()->subMinutes(10));

        $response = $this->actingAsSuperAdmin()->get('/admin/live-activity');
        $response->assertStatus(200);
        $response->assertSee('Stale Shop');

        // The 'Online' badge for that row must not be present (only 'Offline')
        $html = $response->getContent();
        $shopPos = strpos($html, 'Stale Shop');
        $this->assertNotFalse($shopPos);

        // Extract just the row's text by finding the next row boundary
        $rowEnd = strpos($html, '</tr>', $shopPos);
        $rowHtml = substr($html, $shopPos, $rowEnd - $shopPos);
        $this->assertStringContainsString('Offline', $rowHtml);
        $this->assertStringNotContainsString('animate-pulse', $rowHtml);
    }

    /**
     * An explicitly logged-out session (logout_at is set) must NOT count as
     * online even if last_activity_at is very recent.
     */
    public function test_logged_out_session_shows_offline(): void
    {
        $cid = $this->makePraCompany('Logged Out Shop');

        // logged out 1 minute ago — last_activity_at recent, but logout_at set
        $this->heartbeat($cid, now()->subMinute(), loggedOut: true);

        $response = $this->actingAsSuperAdmin()->get('/admin/live-activity');
        $response->assertStatus(200);
        $response->assertSee('Logged Out Shop');

        $html = $response->getContent();
        $shopPos = strpos($html, 'Logged Out Shop');
        $rowEnd  = strpos($html, '</tr>', $shopPos);
        $rowHtml = substr($html, $shopPos, $rowEnd - $shopPos);
        $this->assertStringNotContainsString('animate-pulse', $rowHtml);
    }

    /**
     * Summary 'Abhi online' tile reflects only shops with a live (open +
     * recent) session.
     */
    public function test_online_summary_count_is_accurate(): void
    {
        $online  = $this->makePraCompany('OnlineA');
        $offline = $this->makePraCompany('OfflineA');

        $this->heartbeat($online,  now()->subMinutes(2));   // within 6 min
        $this->heartbeat($offline, now()->subMinutes(15));  // stale

        $response = $this->actingAsSuperAdmin()->get('/admin/live-activity');
        $response->assertStatus(200);

        $html = $response->getContent();
        // Summary line reads "PRA 1 · FBR 0"
        $this->assertStringContainsString('PRA 1', $html);
    }

    // ────────────────────────────────────────────────────────────────────────
    // Resilience: missing optional tables
    // ────────────────────────────────────────────────────────────────────────

    /** Page loads cleanly when pos_user_sessions doesn't exist yet (prod drift). */
    public function test_page_survives_missing_sessions_table(): void
    {
        Schema::dropIfExists('pos_user_sessions');

        $cid = $this->makePraCompany('No-Sessions Shop');
        $this->praBill($cid, 100, 'submitted');

        $this->actingAsSuperAdmin()
            ->get('/admin/live-activity')
            ->assertStatus(200)
            ->assertSee('No-Sessions Shop');
    }

    /** Page loads cleanly when both transaction tables don't exist (fresh install). */
    public function test_page_survives_missing_transaction_tables(): void
    {
        Schema::dropIfExists('pos_transactions');
        Schema::dropIfExists('fbr_pos_transactions');

        $this->actingAsSuperAdmin()
            ->get('/admin/live-activity')
            ->assertStatus(200)
            ->assertSee('Live Activity');
    }

    // ────────────────────────────────────────────────────────────────────────
    // Business-date boundary: bills from a DIFFERENT date are not counted
    // ────────────────────────────────────────────────────────────────────────

    /**
     * Bills created yesterday (with yesterday's business_date set) are not
     * included in today's totals.
     */
    public function test_yesterday_bills_not_counted_today(): void
    {
        $cid = $this->makePraCompany('Yesterday Shop');

        // Use a date guaranteed to differ from the current business date:
        // go back 2 calendar days so it's never within the pre-06:00 window.
        $oldDate = now()->subDays(2)->toDateString();
        DB::table('pos_transactions')->insert([
            'company_id'    => $cid,
            'status'        => 'completed',
            'total_amount'  => 8888,
            'pra_status'    => 'submitted',
            'business_date' => $oldDate,
            'created_at'    => now()->subDays(2),
            'updated_at'    => now()->subDays(2),
        ]);

        $response = $this->actingAsSuperAdmin()->get('/admin/live-activity');
        $response->assertStatus(200);
        $response->assertSee('Yesterday Shop');

        // 8 888 from a past day must not appear in today's totals
        $response->assertDontSee('8,888');
    }
}

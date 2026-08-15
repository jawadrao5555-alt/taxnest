<?php

namespace Tests\Feature;

use Tests\TestCase;
use App\Models\Company;
use App\Models\User;
use App\Services\PosFeatureService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Database\Schema\Blueprint;
use Carbon\Carbon;

/**
 * FBR POS BULK DAY-CLOSE + EMPTY STRANDED-DAY GUARD (Task 519 — FBR mirror of
 * the PRA guard PosDayCloseBulkCloseTest / Task 516).
 *
 * Locked guarantees, over real HTTP:
 *
 *   1. POST /fbr-pos/day-close/close-all-prior closes EVERY stranded prior
 *      day in one request — each gets its own Z-report via performDayClose.
 *   2. 31+ pending days (detector pages at 30/query) still finish in ONE
 *      click — the endpoint re-queries until the backlog is exhausted.
 *   3. A prior day with no live transactions can close with a ZERO-figure
 *      report via the single POST path ($allowEmpty for prior days).
 *   4. TODAY with no transactions is still REJECTED (no fabricated zero
 *      Z-report for the current trading day).
 *   5. Closed days never reappear: after the bulk run the detector is empty
 *      and a second bulk POST reports nothing pending.
 *   6. AUTHORIZATION: a cashier without day-close rights cannot bulk-close.
 *
 * Pattern: sqlite :memory: + minimal Schema::create in setUp (schema mirrors
 * FbrPosDayCloseStrandedBannerTest, which drives the same helpers).
 *
 * Run:
 *   env -u DATABASE_URL -u DB_CONNECTION -u PGHOST -u PGPORT -u PGUSER \
 *     -u PGPASSWORD -u PGDATABASE APP_ENV=testing DB_CONNECTION=sqlite \
 *     DB_DATABASE=':memory:' php vendor/bin/phpunit tests/Feature/FbrPosDayCloseBulkCloseTest.php
 */
class FbrPosDayCloseBulkCloseTest extends TestCase
{
    private Company $company;
    private User $posAdmin;

    protected function setUp(): void
    {
        parent::setUp();
        // Mid-day freeze: fixture dates and the trading-day cutoff agree.
        Carbon::setTestNow(now()->setTime(12, 0));
        PosFeatureService::flushGateCaches();
        Schema::dropAllTables();
        $this->buildSchema();
        [$this->company, $this->posAdmin] = $this->seedShop();
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        PosFeatureService::flushGateCaches();
        parent::tearDown();
    }

    // ─────────────────────────────────────────────────────────────────────────
    // TESTS
    // ─────────────────────────────────────────────────────────────────────────

    /** 1 + 5: every stranded prior day closes in one POST; none reappear. */
    public function test_bulk_close_closes_every_pending_day(): void
    {
        $dayA = $this->strandDay(1, 'F-A');
        $dayB = $this->strandDay(2, 'F-B');
        $dayC = $this->strandDay(3, 'F-C');

        $response = $this->bulkClose();
        $response->assertRedirect(route('fbrpos.day-close'));
        $response->assertSessionHas('success');

        foreach ([$dayA, $dayB, $dayC] as $day) {
            $this->assertSame(1, DB::table('fbr_day_close_reports')
                ->where('company_id', $this->company->id)
                ->whereDate('report_date', $day)->count(), "day $day must have its own Z-report");
        }
        $this->assertSame(1, (int) DB::table('fbr_day_close_reports')->whereDate('report_date', $dayA)->value('total_invoices'));

        // Nothing reappears: banner list empty + second bulk POST = "none pending".
        $page = $this->actingAs($this->posAdmin, 'fbrpos')->get('/fbr-pos/day-close');
        $page->assertOk();
        $page->assertViewHas('unclosedPriorDays', fn ($days) => $days->isEmpty());

        $again = $this->bulkClose();
        $again->assertSessionHas('success', __('pos.dc_bulk_none_pending'));
        $this->assertSame(3, DB::table('fbr_day_close_reports')->where('company_id', $this->company->id)->count());
    }

    /** 2: 31+ pending days (over the detector's 30-row page) close in ONE request. */
    public function test_bulk_close_finishes_a_backlog_larger_than_thirty_days(): void
    {
        for ($i = 1; $i <= 35; $i++) {
            $this->strandDay($i, 'F-' . $i);
        }

        $this->bulkClose()->assertSessionHas('success');

        $this->assertSame(35, DB::table('fbr_day_close_reports')->where('company_id', $this->company->id)->count());

        // Chronological numbering: the OLDEST day must carry the FIRST report
        // number (oldest-first paging, mirrors the PRA convention).
        $oldest = now()->subDays(35)->toDateString();
        $this->assertSame('ZRPT-00001', DB::table('fbr_day_close_reports')
            ->whereDate('report_date', $oldest)->value('report_number'));
    }

    /** 3: single POST on an empty PRIOR day → zero-figure Z-report. */
    public function test_single_close_of_empty_prior_day_creates_zero_report(): void
    {
        $emptyDay = now()->subDays(2)->toDateString(); // no transactions at all

        $response = $this->actingAs($this->posAdmin, 'fbrpos')
            ->post('/fbr-pos/day-close', ['date' => $emptyDay]);
        $response->assertSessionHas('success');

        $row = DB::table('fbr_day_close_reports')
            ->where('company_id', $this->company->id)
            ->whereDate('report_date', $emptyDay)->first();
        $this->assertNotNull($row, 'empty prior day must close with a zero report');
        $this->assertSame(0, (int) $row->total_invoices);
        $this->assertSame(0.0, (float) $row->total_amount);
    }

    /** 4: TODAY with no transactions is still refused. */
    public function test_empty_today_close_is_still_refused(): void
    {
        $response = $this->actingAs($this->posAdmin, 'fbrpos')
            ->post('/fbr-pos/day-close', ['date' => now()->toDateString()]);
        $response->assertSessionHas('error', __('pos.dayclose_no_transactions'));
        $this->assertSame(0, DB::table('fbr_day_close_reports')->count());
    }

    /**
     * Task 694: rider-khata guarded bill counted ONCE across a multi-day bulk
     * run. FBR guarded bills STAY Local (no archive), so every later day's
     * ≤date delete wash re-selects the same bill — the flash must de-dupe.
     */
    public function test_bulk_close_counts_rider_guarded_bill_once(): void
    {
        // Company wash policy = delete pending provisionals at close.
        DB::table('companies')->where('id', $this->company->id)
            ->update(['pos_dayclose_provisional_action' => 'delete']);

        // Three stranded days with normal final bills.
        $this->strandDay(3, 'F-A');
        $this->strandDay(2, 'F-B');
        $this->strandDay(1, 'F-C');

        // ONE unsettled cash rider bill (Local provisional) on the OLDEST day.
        $at = now()->subDays(3)->setTime(15, 0);
        $guardedId = DB::table('fbr_pos_transactions')->insertGetId([
            'company_id' => $this->company->id,
            'invoice_number' => 'F-RIDER',
            'transaction_type' => 'sale',
            'status' => 'completed',
            'invoice_mode' => 'local',
            'fbr_status' => 'local',
            'subtotal' => 500,
            'total_amount' => 500,
            'payment_method' => 'cash',
            'rider_id' => 7,
            'rider_settlement_id' => null,
            'delivery_status' => 'delivered',
            'created_at' => $at,
            'updated_at' => $at,
        ]);

        $response = $this->bulkClose();
        $response->assertSessionHas('success');

        // All three days closed…
        $this->assertSame(3, DB::table('fbr_day_close_reports')->where('company_id', $this->company->id)->count());
        // …the guarded bill survives, still Local (khata trail intact)…
        $row = DB::table('fbr_pos_transactions')->where('id', $guardedId)->first();
        $this->assertNotNull($row, 'guarded rider bill must never be deleted');
        $this->assertSame('local', $row->invoice_mode);
        $this->assertSame('local', $row->fbr_status);
        // …and the flash reports it exactly ONCE (not once per closed day).
        $flash = session('success');
        $this->assertStringContainsString(__('pos.dayclose_bills_rider_guarded', ['count' => 1]), $flash);
        $this->assertStringNotContainsString(__('pos.dayclose_bills_rider_guarded', ['count' => 3]), $flash);
    }

    /**
     * Task 726: bulk-run flash counts stay honest across days. Deleted locals
     * SUM across days (rows are gone, so no double-count risk — but a per-day
     * reset regression would show only the LAST day's figure), while the
     * rider-guarded bill (re-selected by every later day's ≤date wash) shows
     * exactly ONCE.
     */
    public function test_bulk_close_flash_sums_deleted_across_days_and_counts_guarded_once(): void
    {
        // Company wash policy = delete pending provisionals at close.
        DB::table('companies')->where('id', $this->company->id)
            ->update(['pos_dayclose_provisional_action' => 'delete']);

        // Three stranded days with normal final bills.
        $this->strandDay(3, 'F-A');
        $this->strandDay(2, 'F-B');
        $this->strandDay(1, 'F-C');

        // Local delete-policy bills: TWO on the oldest day, ONE on the middle
        // day → the honest bulk flash must say 3 (a per-day reset bug says 1;
        // a missing-reset bug on a wash-skipping day could double-count).
        $localIds = [
            $this->localBill(3, 'L-A1'),
            $this->localBill(3, 'L-A2'),
            $this->localBill(2, 'L-B1'),
        ];

        // ONE unsettled cash rider bill on the oldest day — every later day's
        // ≤date wash re-selects it; the flash must de-dupe to 1.
        $at = now()->subDays(3)->setTime(16, 0);
        $guardedId = DB::table('fbr_pos_transactions')->insertGetId([
            'company_id' => $this->company->id,
            'invoice_number' => 'F-RIDER-726',
            'transaction_type' => 'sale',
            'status' => 'completed',
            'invoice_mode' => 'local',
            'fbr_status' => 'local',
            'subtotal' => 500,
            'total_amount' => 500,
            'payment_method' => 'cash',
            'rider_id' => 7,
            'rider_settlement_id' => null,
            'delivery_status' => 'delivered',
            'created_at' => $at,
            'updated_at' => $at,
        ]);

        $response = $this->bulkClose();
        $response->assertSessionHas('success');

        // All three days closed; the local bills are actually GONE…
        $this->assertSame(3, DB::table('fbr_day_close_reports')->where('company_id', $this->company->id)->count());
        $this->assertSame(0, DB::table('fbr_pos_transactions')->whereIn('id', $localIds)->count(),
            'delete-policy local bills must be removed by the wash');
        // …while the guarded rider bill survives, still Local.
        $this->assertNotNull(DB::table('fbr_pos_transactions')->where('id', $guardedId)->first());

        $flash = session('success');
        // Deleted count = TOTAL across days (2 + 1), not just the last day's.
        $this->assertStringContainsString(__('pos.dayclose_bills_deleted', ['count' => 3]), $flash);
        $this->assertStringNotContainsString(__('pos.dayclose_bills_deleted', ['count' => 1]), $flash);
        // Guarded bill exactly ONCE — not once per closed day.
        $this->assertStringContainsString(__('pos.dayclose_bills_rider_guarded', ['count' => 1]), $flash);
        $this->assertStringNotContainsString(__('pos.dayclose_bills_rider_guarded', ['count' => 2]), $flash);
        $this->assertStringNotContainsString(__('pos.dayclose_bills_rider_guarded', ['count' => 3]), $flash);
    }

    /** 6: cashier without day-close rights cannot bulk-close. */
    public function test_cashier_without_rights_cannot_bulk_close(): void
    {
        $this->strandDay(1, 'F-X');
        $cashier = User::create([
            'name' => 'Cashier', 'email' => 'cashier@fbrbulk.pk',
            'password' => bcrypt('secret'), 'company_id' => $this->company->id,
            'role' => 'user', 'pos_role' => 'pos_cashier',
            'is_active' => true,
        ]);

        $response = $this->actingAs($cashier, 'fbrpos')
            ->post('/fbr-pos/day-close/close-all-prior');
        $response->assertRedirect(route('fbrpos.dashboard'));
        $response->assertSessionHas('error', __('pos.custom_access_denied'));
        $this->assertSame(0, DB::table('fbr_day_close_reports')->count());
    }

    // ─────────────────────────────────────────────────────────────────────────
    // Fixtures (mirror FbrPosDayCloseStrandedBannerTest)
    // ─────────────────────────────────────────────────────────────────────────

    private function bulkClose()
    {
        return $this->actingAs($this->posAdmin, 'fbrpos')
            ->post('/fbr-pos/day-close/close-all-prior');
    }

    /** Insert a pending LOCAL provisional bill N days ago; returns its id. */
    private function localBill(int $daysAgo, string $invoice): int
    {
        $at = now()->subDays($daysAgo)->setTime(15, 0);

        return (int) DB::table('fbr_pos_transactions')->insertGetId([
            'company_id' => $this->company->id,
            'invoice_number' => $invoice,
            'transaction_type' => 'sale',
            'status' => 'completed',
            'invoice_mode' => 'local',
            'fbr_status' => 'local',
            'subtotal' => 200,
            'total_amount' => 200,
            'payment_method' => 'cash',
            'created_at' => $at,
            'updated_at' => $at,
        ]);
    }

    /** Insert a bill N days ago with no day-close report; returns Y-m-d. */
    private function strandDay(int $daysAgo, string $invoice): string
    {
        $at = now()->subDays($daysAgo)->setTime(14, 0);
        DB::table('fbr_pos_transactions')->insert([
            'company_id' => $this->company->id,
            'invoice_number' => $invoice,
            'transaction_type' => 'sale',
            'status' => 'completed',
            'invoice_mode' => 'fbr',
            'fbr_status' => null,
            'subtotal' => 100,
            'total_amount' => 100,
            'payment_method' => 'cash',
            'created_at' => $at,
            'updated_at' => $at,
        ]);

        return $at->toDateString();
    }

    private function seedShop(): array
    {
        $planId = DB::table('pricing_plans')->insertGetId([
            'name' => 'FBR Business', 'product_type' => 'fbrpos',
            'is_trial' => false,
            'created_at' => now(), 'updated_at' => now(),
        ]);

        $company = Company::create([
            'name' => 'FBR Bulk Close Shop', 'product_type' => 'fbrpos',
            'status' => 'active', 'company_status' => 'active',
            'is_internal_account' => false,
            'fbr_reporting_enabled' => false,
            'fbr_pos_enabled' => true,
        ]);

        DB::table('subscriptions')->insert([
            'company_id' => $company->id, 'pricing_plan_id' => $planId,
            'active' => true, 'override_type' => 'none',
            'start_date' => now()->subMonth()->toDateString(),
            'end_date' => now()->addMonth()->toDateString(),
            'created_at' => now(), 'updated_at' => now(),
        ]);

        $user = User::create([
            'name' => 'Admin', 'email' => 'admin@fbrbulk.pk',
            'password' => bcrypt('secret'), 'company_id' => $company->id,
            'role' => 'company_admin', 'pos_role' => 'pos_admin',
            'is_active' => true,
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
            $t->string('pos_dayclose_provisional_action')->nullable();
            $t->string('pos_dashboard_style')->nullable();
            $t->string('default_language')->nullable();
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
            $t->string('fbr_invoice_number')->nullable();
            $t->decimal('subtotal', 12, 2)->default(0);
            $t->decimal('discount_amount', 12, 2)->default(0);
            $t->decimal('tax_amount', 12, 2)->default(0);
            $t->decimal('fbr_service_charge', 12, 2)->nullable();
            $t->decimal('total_amount', 12, 2)->default(0);
            $t->string('payment_method')->nullable();
            $t->unsignedBigInteger('created_by')->nullable();
            // Rider-khata delete-guard columns (Tasks 690/694).
            $t->unsignedBigInteger('rider_id')->nullable();
            $t->unsignedBigInteger('rider_settlement_id')->nullable();
            $t->string('delivery_status')->nullable();
            $t->timestamps();
        });

        Schema::create('fbr_pos_transaction_items', function (Blueprint $t) {
            $t->id();
            $t->unsignedBigInteger('transaction_id');
            $t->unsignedBigInteger('product_id')->nullable();
            $t->string('item_name');
            $t->decimal('quantity', 12, 4)->default(1);
            $t->decimal('subtotal', 12, 2)->default(0);
            $t->decimal('tax_amount', 12, 2)->nullable();
            $t->decimal('item_discount', 12, 2)->nullable();
            $t->decimal('promotion_discount', 12, 2)->nullable();
            $t->timestamps();
        });

        Schema::create('fbr_day_close_reports', function (Blueprint $t) {
            $t->id();
            $t->unsignedBigInteger('company_id');
            $t->date('report_date');
            $t->string('report_number')->nullable();
            $t->integer('total_invoices')->default(0);
            $t->integer('fbr_invoices')->default(0);
            $t->integer('local_invoices')->default(0);
            $t->integer('failed_invoices')->default(0);
            $t->decimal('gross_sales', 14, 2)->default(0);
            $t->decimal('total_discount', 14, 2)->default(0);
            $t->decimal('net_sales', 14, 2)->default(0);
            $t->decimal('total_tax', 14, 2)->default(0);
            $t->decimal('total_fbr_fee', 14, 2)->nullable();
            $t->decimal('total_amount', 14, 2)->default(0);
            $t->decimal('cash_amount', 14, 2)->default(0);
            $t->decimal('card_amount', 14, 2)->default(0);
            $t->decimal('udhaar_amount', 14, 2)->default(0);
            $t->decimal('other_amount', 14, 2)->default(0);
            $t->string('first_invoice_number')->nullable();
            $t->string('last_invoice_number')->nullable();
            $t->timestamp('first_invoice_time')->nullable();
            $t->timestamp('last_invoice_time')->nullable();
            $t->unsignedBigInteger('closed_by')->nullable();
            $t->text('notes')->nullable();
            $t->string('hash')->nullable();
            $t->decimal('opening_float', 14, 2)->nullable();
            $t->decimal('counted_cash', 14, 2)->nullable();
            $t->decimal('expected_cash', 14, 2)->nullable();
            $t->decimal('cash_variance', 14, 2)->nullable();
            $t->timestamps();
            $t->unique(['company_id', 'report_date']);
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

        Schema::create('fbr_pos_logs', function (Blueprint $t) {
            $t->id();
            $t->unsignedBigInteger('company_id')->nullable();
            $t->unsignedBigInteger('transaction_id')->nullable();
            $t->text('request_payload')->nullable();
            $t->text('response_payload')->nullable();
            $t->string('response_code')->nullable();
            $t->string('status')->nullable();
            $t->text('error_message')->nullable();
            $t->timestamps();
        });

        // MySQL SUBSTRING_INDEX polyfill (atomic report_number counter).
        DB::connection()->getPdo()->sqliteCreateFunction('SUBSTRING_INDEX', function ($str, $delim, $count) {
            $parts = explode($delim, (string) $str);
            if ($count < 0) {
                $parts = array_slice($parts, $count);
            } else {
                $parts = array_slice($parts, 0, $count);
            }
            return implode($delim, $parts);
        });
    }
}

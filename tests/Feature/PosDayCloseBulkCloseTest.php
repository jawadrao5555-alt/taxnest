<?php

namespace Tests\Feature;

use Tests\TestCase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Carbon;

/**
 * BULK DAY-CLOSE + EMPTY STRANDED-DAY GUARD (Task 516).
 *
 * Shops that don't close daily pile up 20+ open days and had to close them
 * one by one ("close 10, day 9 appears" whack-a-mole). Locked guarantees:
 *
 *   1. POST /pos/day-close/close-all-prior closes EVERY stranded prior day
 *      in one request — each gets its own Z-report via performDayClose.
 *   2. 31+ pending days (detector pages at 30/query) still finish in ONE
 *      click — the endpoint re-queries until the backlog is exhausted.
 *   3. A stranded day whose bills were already ARCHIVED by a newer close's
 *      backlog wash closes with a ZERO-figure report (single POST path too)
 *      instead of erroring "no transactions" forever.
 *   4. An arbitrary past date with NO transaction history is still REJECTED
 *      (no fabricated zero Z-reports outside the stranded-day case).
 *   5. Closed days never reappear: after the bulk run the detector is empty
 *      and a second bulk POST reports nothing pending.
 *
 * Pattern: sqlite :memory: + minimal Schema::create in setUp (mirrors
 * PosDayCloseAutoFinalizeTest, which drives the same performDayClose path).
 */
class PosDayCloseBulkCloseTest extends TestCase
{
    private int $companyId;
    private \App\Models\User $admin;

    protected function setUp(): void
    {
        parent::setUp();
        // Mid-day freeze: fixture business_dates and PosBusinessDay::current()
        // agree (00:00–05:59 would shift the trading day to yesterday).
        Carbon::setTestNow(now()->setTime(12, 0));
        Schema::dropAllTables();
        $this->buildSchema();
        $this->companyId = $this->makeCompany();
        $this->admin = $this->makeAdmin($this->companyId);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    // ─────────────────────────────────────────────────────────────────────────
    // TESTS
    // ─────────────────────────────────────────────────────────────────────────

    /** 1 + 3 + 5: mixed backlog (live bills + archived-only day) closes fully in one POST. */
    public function test_bulk_close_closes_every_pending_day_including_archived_only(): void
    {
        $liveA = $this->strandDay(2, 'INV-A');                       // real bill
        $liveB = $this->strandDay(3, 'INV-B');                       // real bill
        $ghost = $this->strandDay(4, 'INV-C', ['is_archived' => true]); // archived-only (wash-emptied)

        $response = $this->bulkClose();
        $response->assertRedirect();
        $response->assertSessionHas('success');

        foreach ([$liveA, $liveB, $ghost] as $day) {
            $this->assertNotNull(
                DB::table('pos_day_close_reports')->where('company_id', $this->companyId)->whereDate('report_date', $day)->first(),
                "day $day must have its own Z-report"
            );
        }
        // Archived-only day → zero-figure report; live days count their bill.
        $this->assertSame(0, (int) DB::table('pos_day_close_reports')->whereDate('report_date', $ghost)->value('total_invoices'));
        $this->assertSame(1, (int) DB::table('pos_day_close_reports')->whereDate('report_date', $liveA)->value('total_invoices'));

        // Nothing reappears: detector empty + second bulk POST = "none pending".
        $this->assertTrue($this->pendingDays()->isEmpty(), 'no day may reappear as open');
        $again = $this->bulkClose();
        $again->assertSessionHas('success', __('pos.dc_bulk_none_pending'));
        $this->assertSame(3, DB::table('pos_day_close_reports')->where('company_id', $this->companyId)->count());
    }

    /** 2: 31+ pending days (over the detector's 30-row page) close in ONE request. */
    public function test_bulk_close_finishes_a_backlog_larger_than_thirty_days(): void
    {
        for ($i = 1; $i <= 35; $i++) {
            $this->strandDay($i, 'INV-' . $i);
        }
        $this->assertSame(30, $this->pendingDays()->count(), 'detector pages at 30');

        $this->bulkClose()->assertSessionHas('success');

        $this->assertSame(35, DB::table('pos_day_close_reports')->where('company_id', $this->companyId)->count());
        $this->assertTrue($this->pendingDays()->isEmpty());
    }

    /**
     * 2b: chronological attribution across the 30-date page boundary — LOCAL
     * bills (which the backlog wash sweeps with business_date <= close date)
     * must each be washed by THEIR OWN day's report, never a newer one. A
     * newest-first page order would let day-30's close steal days 31-35's
     * bills and leave those reports as artificial zeros.
     */
    public function test_bulk_close_attributes_each_local_bill_to_its_own_days_report(): void
    {
        for ($i = 1; $i <= 35; $i++) {
            $this->strandDay($i, 'L-' . $i, ['invoice_mode' => 'local', 'pra_status' => 'local']);
        }

        $this->bulkClose()->assertSessionHas('success');
        $this->assertSame(35, DB::table('pos_day_close_reports')->where('company_id', $this->companyId)->count());
        $this->assertTrue($this->pendingDays()->isEmpty());

        $reportDates = DB::table('pos_day_close_reports')
            ->get()
            ->keyBy('id')
            ->map(fn ($r) => \Carbon\Carbon::parse($r->report_date)->toDateString());
        foreach (DB::table('pos_transactions')->get() as $tx) {
            $this->assertTrue((bool) $tx->is_archived, "$tx->invoice_number must be washed");
            $this->assertSame(
                $tx->business_date,
                $reportDates[$tx->archived_by_report_id] ?? null,
                "$tx->invoice_number must be archived by its OWN day's report"
            );
        }
    }

    /** 3 (single path): archived-only stranded day closes via the normal POST too. */
    public function test_single_close_of_archived_only_stranded_day_creates_zero_report(): void
    {
        $ghost = $this->strandDay(2, 'INV-G', ['is_archived' => true]);

        $response = $this->actingAs($this->admin, 'pos')
            ->from('/pos/day-close?date=' . $ghost)
            ->post('/pos/day-close', ['date' => $ghost]);

        $response->assertSessionHas('success');
        $report = DB::table('pos_day_close_reports')->whereDate('report_date', $ghost)->first();
        $this->assertNotNull($report);
        $this->assertSame(0, (int) $report->total_invoices);
    }

    /** 4: a never-traded past date must NOT mint a zero Z-report. */
    public function test_arbitrary_empty_past_date_is_still_rejected(): void
    {
        $this->strandDay(2, 'INV-X'); // real backlog exists, but NOT on this date
        $neverTraded = now()->subDays(9)->toDateString();

        $response = $this->actingAs($this->admin, 'pos')
            ->from('/pos/day-close')
            ->post('/pos/day-close', ['date' => $neverTraded]);

        $response->assertSessionHas('error', __('pos.dayclose_no_transactions'));
        $this->assertNull(DB::table('pos_day_close_reports')->whereDate('report_date', $neverTraded)->first());
    }

    /**
     * Task 732 (PRA mirror of FBR Task 726): bulk-run flash counts stay honest
     * across days. Deleted locals SUM across days (a per-day reset regression
     * would show only the LAST day's figure), while the rider-guarded bill is
     * reported exactly ONCE. On PRA the guard ARCHIVES the bill (khata proof
     * survives), so later days' ≤date washes must never re-count it.
     */
    public function test_bulk_close_flash_sums_deleted_across_days_and_counts_guarded_once(): void
    {
        // Company wash policy = delete pending provisionals at close.
        DB::table('companies')->where('id', $this->companyId)
            ->update(['pos_dayclose_provisional_action' => 'delete']);

        // Three stranded days with normal PRA-mode bills (never washed).
        $this->strandDay(3, 'INV-A');
        $this->strandDay(2, 'INV-B');
        $this->strandDay(1, 'INV-C');

        // Local delete-policy provisionals: TWO on the oldest day, ONE on the
        // middle day → the honest bulk flash must say 3 (a per-day reset bug
        // says 1; a missing-reset bug could double-count).
        $this->strandDay(3, 'L-A1', ['invoice_mode' => 'local', 'pra_status' => 'local']);
        $this->strandDay(3, 'L-A2', ['invoice_mode' => 'local', 'pra_status' => 'local']);
        $this->strandDay(2, 'L-B1', ['invoice_mode' => 'local', 'pra_status' => 'local']);
        $localIds = DB::table('pos_transactions')
            ->whereIn('invoice_number', ['L-A1', 'L-A2', 'L-B1'])->pluck('id');

        // ONE unsettled cash rider bill (local provisional) on the OLDEST day —
        // the delete wash must spare it (archive instead) and count it ONCE.
        $this->strandDay(3, 'L-RIDER', [
            'invoice_mode' => 'local',
            'pra_status' => 'local',
            'rider_id' => 7,
            'rider_settlement_id' => null,
            'delivery_status' => 'delivered',
            'payment_method' => 'cash',
        ]);
        $guardedId = DB::table('pos_transactions')->where('invoice_number', 'L-RIDER')->value('id');

        $response = $this->bulkClose();
        $response->assertSessionHas('success');

        // All three days closed; the delete-policy locals are actually GONE…
        $this->assertSame(3, DB::table('pos_day_close_reports')->where('company_id', $this->companyId)->count());
        $this->assertSame(0, DB::table('pos_transactions')->whereIn('id', $localIds)->count(),
            'delete-policy local bills must be removed by the wash');
        // …while the guarded rider bill survives — ARCHIVED, never deleted
        // (khata trail intact; PRA guard archives, unlike FBR's stay-Local).
        $guarded = DB::table('pos_transactions')->where('id', $guardedId)->first();
        $this->assertNotNull($guarded, 'guarded rider bill must never be deleted');
        $this->assertTrue((bool) $guarded->is_archived, 'guarded rider bill must be archived instead');

        $flash = session('success');
        // Deleted count = TOTAL across days (2 + 1), not just the last day's.
        $this->assertStringContainsString(__('pos.dayclose_bills_deleted', ['count' => 3]), $flash);
        $this->assertStringNotContainsString(__('pos.dayclose_bills_deleted', ['count' => 1]), $flash);
        // Guarded bill exactly ONCE — not once per closed day.
        $this->assertStringContainsString(__('pos.dayclose_bills_rider_guarded', ['count' => 1]), $flash);
        $this->assertStringNotContainsString(__('pos.dayclose_bills_rider_guarded', ['count' => 2]), $flash);
        $this->assertStringNotContainsString(__('pos.dayclose_bills_rider_guarded', ['count' => 3]), $flash);
    }

    /** Today's close with zero bills stays strictly refused (allowEmpty is prior-days-only). */
    public function test_empty_today_close_is_still_refused(): void
    {
        $response = $this->actingAs($this->admin, 'pos')
            ->from('/pos/day-close')
            ->post('/pos/day-close', []);

        $response->assertSessionHas('error', __('pos.dayclose_no_transactions'));
        $this->assertSame(0, DB::table('pos_day_close_reports')->count());
    }

    // ─────────────────────────────────────────────────────────────────────────
    // Helpers
    // ─────────────────────────────────────────────────────────────────────────

    private function bulkClose()
    {
        return $this->actingAs($this->admin, 'pos')
            ->from('/pos/day-close')
            ->post('/pos/day-close/close-all-prior', []);
    }

    /** The stranded-day detector, exactly as the banner/bulk endpoint sees it. */
    private function pendingDays()
    {
        $controller = app(\App\Http\Controllers\PosController::class);
        $m = new \ReflectionMethod($controller, 'unclosedPriorBusinessDays');
        $m->setAccessible(true);

        return $m->invoke($controller, $this->companyId);
    }

    /** Seed one completed PRA-mode bill on a PRIOR business day; returns Y-m-d. */
    private function strandDay(int $daysAgo, string $invoice, array $attrs = []): string
    {
        $day = now()->subDays($daysAgo)->toDateString();
        DB::table('pos_transactions')->insert(array_merge([
            'company_id' => $this->companyId,
            'invoice_number' => $invoice,
            'business_date' => $day,
            'status' => 'completed',
            'invoice_mode' => 'pra',
            'pra_status' => 'offline',
            'pra_invoice_number' => null,
            'is_archived' => false,
            'subtotal' => 100,
            'discount_amount' => 0,
            'tax_amount' => 0,
            'total_amount' => 100,
            'payment_method' => 'cash',
            'created_at' => now()->subDays($daysAgo)->setTime(14, 0),
            'updated_at' => now()->subDays($daysAgo)->setTime(14, 0),
        ], $attrs));

        return $day;
    }

    private function makeCompany(): int
    {
        return (int) DB::table('companies')->insertGetId([
            'name' => 'Bulk Close Shop',
            'product_type' => 'pos',
            'status' => 'active',
            'is_internal_account' => false,
            'invoice_limit_override' => -1,
            'pra_reporting_enabled' => false,
            'pos_dayclose_provisional_action' => 'save',
            'pos_dayclose_final_local_action' => 'save',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    private function makeAdmin(int $companyId): \App\Models\User
    {
        $id = DB::table('users')->insertGetId([
            'name' => 'POS Admin',
            'email' => 'admin@bulkclose.test',
            'password' => bcrypt('Secret@12345'),
            'company_id' => $companyId,
            'role' => 'company_admin',
            'pos_role' => 'pos_admin',
            'is_active' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return \App\Models\User::find($id);
    }

    private function buildSchema(): void
    {
        Schema::create('companies', function (Blueprint $t) {
            $t->id();
            $t->string('name');
            $t->string('product_type')->nullable();
            $t->string('status')->nullable();
            $t->string('default_language')->nullable();
            $t->string('pos_dayclose_provisional_action')->nullable();
            $t->string('pos_dayclose_final_local_action')->nullable();
            $t->boolean('pos_customer_spend_persist')->default(true);
            $t->string('pos_business_day_cutoff')->nullable();
            $t->boolean('pos_auto_dayclose_24h')->default(false);
            $t->boolean('is_internal_account')->default(false);
            $t->integer('invoice_limit_override')->nullable();
            $t->boolean('pra_reporting_enabled')->default(false);
            $t->boolean('agent_enabled')->default(false);
            $t->string('pra_connection_mode')->nullable();
            $t->string('pra_environment')->nullable();
            $t->decimal('pos_tax_rate_cash', 8, 2)->nullable();
            $t->decimal('pos_tax_rate_card', 8, 2)->nullable();
            $t->softDeletes();
            $t->timestamps();
        });

        Schema::create('users', function (Blueprint $t) {
            $t->id();
            $t->string('name')->nullable();
            $t->string('email')->nullable()->unique();
            $t->string('password')->nullable();
            $t->unsignedBigInteger('company_id')->nullable();
            $t->string('role')->nullable();
            $t->string('pos_role')->nullable();
            $t->boolean('is_active')->default(true);
            $t->string('language')->nullable();
            $t->boolean('pra_reporting_enabled')->default(false);
            $t->rememberToken();
            $t->timestamps();
        });

        Schema::create('pos_transactions', function (Blueprint $t) {
            $t->id();
            $t->unsignedBigInteger('company_id');
            $t->string('invoice_number');
            $t->string('business_date')->nullable();
            $t->string('status');
            $t->string('invoice_mode')->nullable();
            $t->string('pra_status')->nullable();
            $t->string('pra_invoice_number')->nullable();
            $t->boolean('is_archived')->default(false);
            $t->timestamp('archived_at')->nullable();
            $t->unsignedBigInteger('archived_by_report_id')->nullable();
            $t->unsignedBigInteger('created_by')->nullable();
            $t->decimal('subtotal', 12, 2)->default(0);
            $t->decimal('discount_amount', 12, 2)->default(0);
            $t->decimal('tax_rate', 8, 2)->nullable();
            $t->decimal('tax_amount', 12, 2)->default(0);
            $t->decimal('total_amount', 12, 2)->default(0);
            $t->string('payment_method')->nullable();
            $t->boolean('tax_inclusive')->default(false);
            // Rider delete-guard columns (Task 732 flash-count tests).
            $t->unsignedBigInteger('rider_id')->nullable();
            $t->unsignedBigInteger('rider_settlement_id')->nullable();
            $t->string('delivery_status')->nullable();
            $t->timestamps();
            $t->unique(['company_id', 'invoice_number']);
        });

        Schema::create('pos_transaction_items', function (Blueprint $t) {
            $t->id();
            $t->unsignedBigInteger('transaction_id');
            $t->string('item_name')->nullable();
            $t->decimal('quantity', 12, 3)->default(1);
            $t->decimal('unit_price', 12, 2)->default(0);
            $t->decimal('subtotal', 12, 2)->default(0);
            $t->timestamps();
        });

        Schema::create('pos_payments', function (Blueprint $t) {
            $t->id();
            $t->unsignedBigInteger('transaction_id');
            $t->string('payment_method')->nullable();
            $t->decimal('amount', 12, 2)->default(0);
            $t->timestamps();
        });

        Schema::create('branches', function (Blueprint $t) {
            $t->id();
            $t->unsignedBigInteger('company_id');
            $t->string('name');
            $t->boolean('is_active')->default(true);
            $t->boolean('is_head_office')->default(false);
            $t->timestamps();
        });

        Schema::create('restaurant_orders', function (Blueprint $t) {
            $t->id();
            $t->unsignedBigInteger('company_id')->nullable();
            $t->unsignedBigInteger('transaction_id')->nullable();
            $t->unsignedBigInteger('created_by')->nullable();
            $t->timestamps();
        });

        Schema::create('pos_day_openings', function (Blueprint $t) {
            $t->id();
            $t->unsignedBigInteger('company_id');
            $t->date('business_date');
            $t->decimal('opening_cash', 15, 2)->default(0);
            $t->unsignedBigInteger('entered_by')->nullable();
            $t->timestamps();
            $t->unique(['company_id', 'business_date']);
        });

        Schema::create('pos_day_close_reports', function (Blueprint $t) {
            $t->id();
            $t->unsignedBigInteger('company_id');
            $t->date('report_date');
            $t->string('report_number')->nullable();
            $t->integer('deleted_final_count')->default(0);
            $t->integer('deleted_provisional_count')->default(0);
            $t->text('local_summary')->nullable();
            $t->text('rider_summary')->nullable();
            $t->integer('total_invoices')->default(0);
            $t->integer('pra_invoices')->default(0);
            $t->integer('local_invoices')->default(0);
            $t->integer('offline_invoices')->default(0);
            $t->decimal('gross_sales', 14, 2)->default(0);
            $t->decimal('total_discount', 14, 2)->default(0);
            $t->decimal('net_sales', 14, 2)->default(0);
            $t->decimal('total_tax', 14, 2)->default(0);
            $t->decimal('total_amount', 14, 2)->default(0);
            $t->decimal('cash_amount', 14, 2)->default(0);
            $t->decimal('card_amount', 14, 2)->default(0);
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
    }
}

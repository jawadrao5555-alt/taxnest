<?php

namespace Tests\Feature;

use Tests\TestCase;
use App\Http\Controllers\FbrPosController;
use App\Models\Company;
use App\Models\User;
use App\Services\PosFeatureService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Database\Schema\Blueprint;

/**
 * FBR DAY-CLOSE (Z-REPORT) RETURN-NETTING LOCK (Task 607 — FBR mirror of the
 * PRA lock PosDayCloseReturnNettingTest / Tasks 570/576; dashboard/report
 * tiles were locked in FbrPosDashboardReturnNettingTest / Task 591).
 *
 * Convention (identical to PRA):
 *   - Stored Z-report figures (performDayClose) and the day-close preview
 *     (dayCloseReport) NET returns out of gross/tax/total and the
 *     cash/card/udhaar/other buckets, keep bill counts SALES-only, and the
 *     fiscal serial range never edges on a credit note.
 *   - Cashier breakdown: SIGNED revenue/tax, sales-only counts.
 *   - getPendingDayCloses + the yesterday / last-week compareFor block are
 *     SIGNED with sales-only counts.
 *
 * Pattern: sqlite :memory: + minimal Schema::create in setUp (schema mirrors
 * FbrPosDashboardReturnNettingTest; numbers mirror the PRA netting day).
 *
 * Run:
 *   env -u DATABASE_URL -u DB_CONNECTION -u PGHOST -u PGPORT -u PGUSER \
 *     -u PGPASSWORD -u PGDATABASE APP_ENV=testing DB_CONNECTION=sqlite \
 *     DB_DATABASE=':memory:' php vendor/bin/phpunit tests/Feature/FbrPosDayCloseReturnNettingTest.php
 */
class FbrPosDayCloseReturnNettingTest extends TestCase
{
    private Company $company;
    private User $posAdmin;

    protected function setUp(): void
    {
        parent::setUp();
        PosFeatureService::flushGateCaches();
        Schema::dropAllTables();
        $this->buildSchema();
        [$this->company, $this->posAdmin] = $this->seedShop();
    }

    protected function tearDown(): void
    {
        Auth::guard('fbrpos')->logout();
        PosFeatureService::flushGateCaches();
        parent::tearDown();
    }

    private function callPrivate(object $obj, string $method, ...$args)
    {
        $ref = new \ReflectionMethod($obj, $method);
        $ref->setAccessible(true);
        return $ref->invoke($obj, ...$args);
    }

    // ── 1. performDayClose: stored Z-report netting ──────────────────────────

    public function test_perform_day_close_nets_returns_and_keeps_counts_sales_only(): void
    {
        $this->seedNettingDay();

        $report = $this->callPrivate(new FbrPosController(), 'performDayClose',
            $this->company->id, now()->toDateString(), $this->posAdmin->id);

        $this->assertNotNull($report);
        $row = DB::table('fbr_day_close_reports')->where('company_id', $this->company->id)->first();
        $this->assertNotNull($row);

        // Netted money figures.
        $this->assertSame(1300.0, (float) $row->gross_sales, 'gross = sales sub − return sub');
        $this->assertSame(80.0, (float) $row->total_discount);
        $this->assertSame(1220.0, (float) $row->net_sales);
        $this->assertSame(221.0, (float) $row->total_tax);
        $this->assertSame(1441.0, (float) $row->total_amount);

        // Counts stay SALES-only (returns carry FRET- numbers outside the range).
        $this->assertSame(2, (int) $row->total_invoices);
        $this->assertSame(2, (int) $row->fbr_invoices, 'submitted credit note must not count as an FBR bill');
        $this->assertSame('FPOS-0001', $row->first_invoice_number);
        $this->assertSame('FPOS-0002', $row->last_invoice_number, 'return must never be the serial-range edge');

        // Returns detail columns.
        $this->assertSame(1, (int) $row->returns_count);
        $this->assertSame(214.0, (float) $row->returns_amount);

        // Cash drawer: cash bucket = cash sales − cash refunds; card untouched.
        $this->assertSame(856.0, (float) $row->cash_amount, '1070 cash sales − 214 cash refund');
        $this->assertSame(585.0, (float) $row->card_amount);
        $this->assertSame(0.0, (float) $row->other_amount);
        $this->assertSame(0.0, (float) $row->udhaar_amount);
        // Conservation: buckets sum to the netted day total.
        $this->assertSame(
            (float) $row->total_amount,
            (float) $row->cash_amount + (float) $row->card_amount
            + (float) $row->other_amount + (float) $row->udhaar_amount
        );
    }

    // ── 2. dayCloseReport preview + cashier breakdown match the stored netting ─

    public function test_day_close_preview_stats_and_cashiers_net_returns(): void
    {
        $this->seedNettingDay();

        Auth::guard('fbrpos')->setUser($this->posAdmin);
        app()->instance('currentCompanyId', $this->company->id);
        $request = Request::create('/fbr-pos/day-close', 'GET', ['date' => now()->toDateString()]);

        $data = (new FbrPosController())->dayCloseReport($request)->getData();
        $stats = $data['stats'];

        $this->assertSame(2, (int) $stats->total_invoices);
        $this->assertSame(1300.0, (float) $stats->gross_sales);
        $this->assertSame(80.0, (float) $stats->total_discount);
        $this->assertSame(1220.0, (float) $stats->net_sales);
        $this->assertSame(221.0, (float) $stats->total_tax);
        $this->assertSame(1441.0, (float) $stats->total_amount);
        $this->assertSame(856.0, (float) $stats->cash_amount);
        $this->assertSame(585.0, (float) $stats->card_amount);
        $this->assertSame(0.0, (float) $stats->other_amount);
        $this->assertSame(1, (int) $stats->returns_count);
        $this->assertSame(214.0, (float) $stats->returns_amount);
        $this->assertSame('FPOS-0002', $stats->last_invoice->invoice_number, 'serial range stays sales-only');

        // Cashier breakdown: SIGNED revenue/tax, sales-only count.
        $cashiers = $data['cashierBreakdown'];
        $rowC = $cashiers['FBR Admin'];
        $this->assertSame(2, (int) $rowC->count);
        $this->assertSame(1441.0, (float) $rowC->revenue);
        $this->assertSame(221.0, (float) $rowC->tax);

        // Analytics follow the same convention (page/PDF/thermal all render
        // this object): netted products, signed hourly, sales-only health,
        // signed discounts, average = signed revenue / sale count.
        $an = $data['analytics'];
        $burger = $an->top_products['Burger'];
        $this->assertSame(3.0, (float) $burger->qty, 'returned Burger nets product qty');
        $this->assertSame(600.0, (float) $burger->revenue);
        $this->assertSame(102.0, (float) $burger->tax);
        $hour = (int) now()->format('G');
        $this->assertSame(2, (int) $an->hourly[$hour]->count, 'hourly count sales-only');
        $this->assertSame(1441.0, (float) $an->hourly[$hour]->revenue, 'hourly revenue signed');
        $this->assertSame(2, (int) $an->fbr_health->submitted, 'submitted credit note is not a submitted bill');
        $this->assertSame(80.0, (float) $an->discounts->bill_total, 'bill discount netted (100−20)');
        $this->assertSame(80.0, (float) $an->discounts->item_total, 'item discount netted (100−20)');
        $this->assertSame(720.5, (float) $an->avg_bill, '1441 / 2 sales');
    }

    // ── 3. compareFor (yesterday / last-week) is SIGNED with sales-only counts ─

    public function test_day_close_comparison_yesterday_is_netted(): void
    {
        $this->seedNettingDay();                     // today
        $this->seedNettingDay(now()->subDay(), 'Y'); // yesterday, same numbers

        Auth::guard('fbrpos')->setUser($this->posAdmin);
        app()->instance('currentCompanyId', $this->company->id);
        $request = Request::create('/fbr-pos/day-close', 'GET', ['date' => now()->toDateString()]);

        $data = (new FbrPosController())->dayCloseReport($request)->getData();
        $cmp = $data['analytics']->comparison;

        $this->assertSame(2, (int) $cmp->yesterday->invoices, 'credit note must not count as an invoice');
        $this->assertSame(1441.0, (float) $cmp->yesterday->revenue, 'yesterday revenue must be netted');
        $this->assertSame(221.0, (float) $cmp->yesterday->tax);
        // Both days identical after netting → 0% swing (gross today vs netted
        // yesterday would show a fake +14.8%).
        $this->assertSame(0.0, (float) $cmp->vs_yesterday_revenue_pct);
        $this->assertSame(0.0, (float) $cmp->vs_yesterday_invoices_pct);
    }

    // ── 4. PDF + thermal Z-report cashier breakdowns use the SAME signed helper ─

    public function test_pdf_and_thermal_cashier_breakdowns_are_netted(): void
    {
        $this->seedNettingDay();
        $report = $this->callPrivate(new FbrPosController(), 'performDayClose',
            $this->company->id, now()->toDateString(), $this->posAdmin->id);
        $this->assertNotNull($report);

        Auth::guard('fbrpos')->setUser($this->posAdmin);
        app()->instance('currentCompanyId', $this->company->id);

        // Thermal Z-report (view — getData without rendering).
        $thermal = (new FbrPosController())->dayCloseThermal($report->id)->getData();
        $rowT = $thermal['cashierBreakdown']['FBR Admin'];
        $this->assertSame(2, (int) $rowT->count, 'thermal: credit note must not count as a bill');
        $this->assertSame(1441.0, (float) $rowT->revenue, 'thermal: cashier revenue must be netted');
        $this->assertSame(221.0, (float) $rowT->tax);

        // A4 PDF path shares fbrCashierBreakdown() — lock the helper directly
        // on the same loaded set the PDF controller passes it.
        $transactions = \App\Models\FbrPosTransaction::where('company_id', $this->company->id)
            ->with('creator')->orderBy('created_at')->get();
        $breakdown = $this->callPrivate(new FbrPosController(), 'fbrCashierBreakdown', $transactions);
        $rowP = $breakdown['FBR Admin'];
        $this->assertSame(2, (int) $rowP->count);
        $this->assertSame(1441.0, (float) $rowP->revenue);
        $this->assertSame(221.0, (float) $rowP->tax);
    }

    // ── 5. return-only day: page still renders + closes with a NEGATIVE total ─

    public function test_return_only_day_renders_and_closes_with_negative_total(): void
    {
        // A credit note with NO sale that day (customer returned yesterday's
        // purchase). total_invoices (sales-only) = 0, but the day must still
        // render its Z-report and be closable.
        $this->makeTxn('FRET-9001', [
            'transaction_type' => 'return',
            'fbr_status' => 'submitted',
            'fbr_invoice_number' => 'FBR-RET-9001',
            'subtotal' => 200, 'discount_amount' => 20, 'tax_amount' => 34,
            'total_amount' => 214, 'payment_method' => 'cash',
        ]);

        // Page: full HTTP render — must NOT fall into the "no transactions"
        // empty state (the gate keys on the day's transactions, not the
        // sales-only invoice count), and the close-day form must be present.
        $response = $this->actingAs($this->posAdmin, 'fbrpos')
            ->get('/fbr-pos/day-close?date=' . now()->toDateString());
        $response->assertOk();
        $stats = $response->viewData('stats');
        $this->assertSame(0, (int) $stats->total_invoices, 'displayed count stays sales-only (zero)');
        $this->assertSame(-214.0, (float) $stats->total_amount);
        $this->assertSame(1, (int) $stats->returns_count);
        $this->assertNull($stats->first_invoice, 'serial range stays empty — a credit note never edges it');
        $response->assertSee(route('fbrpos.close-day'), false);
        $an = $response->viewData('analytics');
        $this->assertSame(0, (int) $an->fbr_health->submitted, 'return-only day: no submitted bills');
        $hour = (int) now()->format('G');
        $this->assertSame(0, (int) $an->hourly[$hour]->count);
        $this->assertSame(-214.0, (float) $an->hourly[$hour]->revenue, 'refund dents its hour');
        $this->assertSame(0.0, (float) $an->avg_bill, 'zero-sale guard');
        $response->assertDontSee(__('pos.no_transactions_for_date', ['date' => \Carbon\Carbon::parse(now()->toDateString())->format('d M Y')]));

        // Submit flow: actual POST close — stored Z-report carries the
        // negative netted total with zero sales-only invoices.
        $close = $this->actingAs($this->posAdmin, 'fbrpos')
            ->withSession(['_token' => 'test-token'])
            ->post('/fbr-pos/day-close', ['_token' => 'test-token', 'date' => now()->toDateString()]);
        $close->assertSessionHas('success');

        $row = DB::table('fbr_day_close_reports')->where('company_id', $this->company->id)->first();
        $this->assertNotNull($row, 'return-only day must be closable');
        $this->assertSame(0, (int) $row->total_invoices);
        $this->assertSame(-214.0, (float) $row->total_amount);
        $this->assertSame(-34.0, (float) $row->total_tax);
        $this->assertSame(-214.0, (float) $row->cash_amount, 'cash refund reduces the drawer');
        $this->assertSame(1, (int) $row->returns_count);
        $this->assertSame(214.0, (float) $row->returns_amount);
    }

    // ── 6. PDF/thermal udhaar display: signed derivation + honor negative stored ─

    public function test_pdf_thermal_udhaar_display_is_signed(): void
    {
        Auth::guard('fbrpos')->setUser($this->posAdmin);
        app()->instance('currentCompanyId', $this->company->id);
        $ctl = new FbrPosController();

        // Mixed day: udhaar sale (stored as 'credit') + REAL khata refund —
        // the return flow writes payment_method='khata' (FbrPosPhase2
        // processReturn) — both must land in the udhaar bucket: net 400.
        $this->makeTxn('FPOS-7001', [
            'subtotal' => 500, 'total_amount' => 500, 'payment_method' => 'credit',
        ]);
        $this->makeTxn('RET-7001', [
            'transaction_type' => 'return', 'fbr_status' => 'local',
            'subtotal' => 100, 'total_amount' => 100, 'payment_method' => 'khata',
        ]);
        $report = $this->callPrivate($ctl, 'performDayClose',
            $this->company->id, now()->toDateString(), $this->posAdmin->id);
        $this->assertSame(400.0, (float) $report->udhaar_amount, 'stored udhaar is netted');
        $thermal = $ctl->dayCloseThermal($report->id)->getData();
        $this->assertSame(400.0, (float) $thermal['displayUdhaar']);
        $this->assertSame(0.0, (float) $thermal['displayOther']);

        // Legacy fallback: zero the stored column → signed derivation kicks in.
        DB::table('fbr_day_close_reports')->where('id', $report->id)->update(['udhaar_amount' => 0]);
        $thermal = $ctl->dayCloseThermal($report->fresh()->id)->getData();
        $this->assertSame(400.0, (float) $thermal['displayUdhaar'], 'derived udhaar must be signed (500−100)');

        // Return-only khata-refund day (yesterday): stored NEGATIVE udhaar
        // must be honored verbatim by the print paths, not replaced by a
        // positive sum or dumped into "Other".
        $this->makeTxn('RET-7002', [
            'transaction_type' => 'return', 'fbr_status' => 'local',
            'subtotal' => 214, 'total_amount' => 214, 'payment_method' => 'khata',
            'created_at' => now()->subDay(), 'updated_at' => now()->subDay(),
        ]);
        $report2 = $this->callPrivate($ctl, 'performDayClose',
            $this->company->id, now()->subDay()->toDateString(), $this->posAdmin->id);
        $this->assertSame(-214.0, (float) $report2->udhaar_amount);
        $thermal2 = $ctl->dayCloseThermal($report2->id)->getData();
        $this->assertSame(-214.0, (float) $thermal2['displayUdhaar'], 'negative stored udhaar honored');
        $this->assertSame(0.0, (float) $thermal2['displayOther']);
        // PDF path shares fbrUdhaarDisplay() — lock the helper directly.
        $txns2 = \App\Models\FbrPosTransaction::where('company_id', $this->company->id)
            ->whereDate('created_at', now()->subDay()->toDateString())->get();
        [$u2, $o2] = $this->callPrivate($ctl, 'fbrUdhaarDisplay', \App\Models\FbrDayCloseReport::find($report2->id), $txns2);
        $this->assertSame(-214.0, (float) $u2);
        $this->assertSame(0.0, (float) $o2);

        // RENDERED output: the negative udhaar line must actually appear on
        // the thermal Z-report and the day-close page payment breakdowns.
        $rendered = $this->actingAs($this->posAdmin, 'fbrpos')
            ->get('/fbr-pos/day-close/' . $report2->id . '/thermal');
        $rendered->assertOk();
        $rendered->assertSee(__('pos.dc_udhaar'));
        $rendered->assertSee('-214.00');

        $page = $this->actingAs($this->posAdmin, 'fbrpos')
            ->get('/fbr-pos/day-close?date=' . now()->subDay()->toDateString());
        $page->assertOk();
        $page->assertSee(__('pos.dc_udhaar'));
        $page->assertSee('-214.00');

        // Mixed day rendered too: positive netted udhaar (500−100) visible.
        $renderedMixed = $this->actingAs($this->posAdmin, 'fbrpos')
            ->get('/fbr-pos/day-close/' . $report->id . '/thermal');
        $renderedMixed->assertOk();
        $renderedMixed->assertSee('400.00');
    }

    // ── 7. getPendingDayCloses: signed totals, sales-only counts ─────────────

    public function test_pending_day_closes_list_is_netted(): void
    {
        $this->seedNettingDay(now()->subDay(), 'Y'); // stranded yesterday

        $pending = $this->callPrivate(new FbrPosController(), 'getPendingDayCloses', $this->company->id, 10);

        $this->assertCount(1, $pending);
        $this->assertSame(now()->subDay()->toDateString(), $pending[0]['date']);
        $this->assertSame(2, $pending[0]['count'], 'credit note must not count as a bill');
        $this->assertSame(1441.0, $pending[0]['total'], 'stranded-day total must be netted');
    }

    // ── fixtures ─────────────────────────────────────────────────────────────

    /**
     * The canonical netting day (same numbers as the PRA/FBR dashboard locks):
     *   Sale A  — cash 1070, tax 170, discount 100.
     *   Sale B  — debit_card 585, tax 85.
     *   Return R — cash credit note 214 off Sale A (tax 34, discount 20,
     *              POSITIVE amounts).
     * Netted day: gross 1300, discount 80, net 1220, tax 221, total 1441,
     * cash 856 (1070−214), card 585. Sales-only count = 2.
     */
    private function seedNettingDay(?\DateTimeInterface $at = null, string $suffix = ''): array
    {
        $at = $at ?: now();
        $saleA = $this->makeTxn($suffix . 'FPOS-0001', [
            'subtotal' => 1000, 'discount_amount' => 100, 'tax_amount' => 170,
            'total_amount' => 1070, 'payment_method' => 'cash',
            'created_at' => $at, 'updated_at' => $at,
        ]);
        $saleB = $this->makeTxn($suffix . 'FPOS-0002', [
            'subtotal' => 500, 'tax_amount' => 85, 'total_amount' => 585,
            'payment_method' => 'debit_card',
            'created_at' => $at, 'updated_at' => $at,
        ]);
        $return = $this->makeTxn($suffix . 'FRET-0001', [
            'transaction_type' => 'return',
            'parent_transaction_id' => $saleA,
            'fbr_status' => 'submitted',
            'fbr_invoice_number' => 'FBR-RET-0001',
            'subtotal' => 200, 'discount_amount' => 20, 'tax_amount' => 34,
            'total_amount' => 214, 'payment_method' => 'cash',
            'created_at' => $at, 'updated_at' => $at,
        ]);

        // Item lines (analytics netting): Sale A = Burger x4 (800) + Chai x2
        // (200); Return = Burger x1 back (200, tax 34, discount 20).
        // Netted Burger: qty 3, revenue 600. Chai untouched: qty 2, 200.
        $this->makeItem($saleA, 'Burger', 4, 800, 136, 80);
        $this->makeItem($saleA, 'Chai', 2, 200, 34, 20);
        $this->makeItem($saleB, 'Lassi', 1, 500, 85, 0);
        $this->makeItem($return, 'Burger', 1, 200, 34, 20);

        return [$saleA, $saleB, $return];
    }

    private function makeItem(int $txnId, string $name, float $qty, float $subtotal, float $tax, float $discount): void
    {
        DB::table('fbr_pos_transaction_items')->insert([
            'transaction_id' => $txnId, 'item_name' => $name,
            'quantity' => $qty, 'subtotal' => $subtotal, 'tax_amount' => $tax,
            'item_discount' => $discount, 'promotion_discount' => 0,
            'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    private function makeTxn(string $number, array $attrs = []): int
    {
        return (int) DB::table('fbr_pos_transactions')->insertGetId(array_merge([
            'company_id' => $this->company->id,
            'invoice_number' => $number,
            'transaction_type' => 'sale',
            'status' => 'completed',
            'invoice_mode' => 'fbr',
            'fbr_status' => 'submitted',
            'subtotal' => 0, 'discount_amount' => 0, 'tax_amount' => 0, 'total_amount' => 0,
            'payment_method' => 'cash',
            'created_by' => $this->posAdmin->id,
            'created_at' => now(), 'updated_at' => now(),
        ], $attrs));
    }

    private function seedShop(): array
    {
        $planId = DB::table('pricing_plans')->insertGetId([
            'name' => 'FBR Business', 'product_type' => 'fbrpos',
            'is_trial' => false,
            'created_at' => now(), 'updated_at' => now(),
        ]);

        $company = Company::create([
            'name' => 'Netting FBR Shop', 'product_type' => 'fbrpos',
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
            'name' => 'FBR Admin', 'email' => 'admin@fbrdaynetting.pk',
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
            $t->boolean('agent_enabled')->default(false);
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
            $t->unsignedBigInteger('parent_transaction_id')->nullable();
            $t->string('status')->nullable();
            $t->string('invoice_mode')->nullable();
            $t->string('fbr_status')->nullable();
            $t->string('fbr_invoice_number')->nullable();
            $t->unsignedBigInteger('customer_id')->nullable();
            $t->string('customer_name')->nullable();
            $t->string('customer_phone')->nullable();
            $t->decimal('subtotal', 12, 2)->default(0);
            $t->decimal('discount_amount', 12, 2)->default(0);
            $t->decimal('tax_amount', 12, 2)->default(0);
            $t->decimal('fbr_service_charge', 12, 2)->nullable();
            $t->decimal('total_amount', 12, 2)->default(0);
            $t->string('payment_method')->nullable();
            $t->unsignedBigInteger('created_by')->nullable();
            $t->timestamps();
        });

        Schema::create('fbr_pos_transaction_items', function (Blueprint $t) {
            $t->id();
            $t->unsignedBigInteger('transaction_id');
            $t->unsignedBigInteger('product_id')->nullable();
            $t->string('item_name')->nullable();
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
            $t->decimal('total_fbr_fee', 14, 2)->default(0);
            $t->decimal('total_amount', 14, 2)->default(0);
            $t->decimal('cash_amount', 14, 2)->default(0);
            $t->decimal('card_amount', 14, 2)->default(0);
            $t->decimal('udhaar_amount', 14, 2)->default(0);
            $t->decimal('other_amount', 14, 2)->default(0);
            $t->integer('returns_count')->default(0);
            $t->decimal('returns_amount', 14, 2)->default(0);
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
            $t->text('rider_summary')->nullable();
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
    }
}

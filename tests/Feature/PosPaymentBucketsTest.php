<?php

namespace Tests\Feature;

use Tests\TestCase;
use App\Support\PosPaymentBuckets;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Database\Schema\Blueprint;

/**
 * PRA POS CASH/CARD/OTHER BUCKET LOCK (Task 202) — "hisaab kabhi ghalat na ho".
 *
 * Card sales are STORED as 'debit_card' (universal screen normalizes the UI's
 * "Card" choice before saving); historical rows may still carry 'card' and some
 * flows store 'credit_card'. Every aggregation splitting cash/card/other must
 * therefore use the FULL alias set — a future report/refactor that writes
 * ='card' would silently report Rs 0 card sales and dump them into "Other"
 * (exactly the Jul 2026 live incident).
 *
 * PosPaymentBuckets is now the single alias-set definition. These tests lock:
 *   1. The alias set + bucket mapping itself (qr_payment stays Other on purpose).
 *   2. Day-close PAGE stats (dayCloseReport view data) with mixed modes.
 *   3. The STORED Z-report row (performDayClose via POST /pos/day-close) —
 *      the durable record every PDF/admin surface reads.
 *   4. Legacy 'card' rows land in the card bucket, never Other (regression).
 *   5. Dashboard payment breakdown keeps per-method rows keyed by the STORED
 *      values (cash/debit_card/... with correct sums) — no silent collapse.
 *   6. Rider/day-close cash reconciliation: expected cash counts ONLY the cash
 *      bucket; card rider bills never enter cash_out/cash_in.
 *
 * Pattern: APP_ENV=testing + sqlite :memory: + minimal Schema::create
 * (same approach as PosDayCloseAutoFinalizeTest).
 */
class PosPaymentBucketsTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        Schema::dropAllTables();

        Schema::create('companies', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('product_type')->nullable();
            $table->string('status')->nullable();
            $table->string('default_language')->nullable();
            $table->string('pos_dayclose_provisional_action')->nullable();
            $table->string('pos_dayclose_final_local_action')->nullable();
            $table->boolean('pos_customer_spend_persist')->default(true);
            $table->string('pos_business_day_cutoff')->nullable();
            $table->boolean('pos_auto_dayclose_24h')->default(false);
            $table->boolean('is_internal_account')->default(false);
            $table->integer('invoice_limit_override')->nullable();
            $table->boolean('pra_reporting_enabled')->default(false);
            $table->boolean('agent_enabled')->default(false);
            $table->boolean('agent_submits_pra')->nullable();
            $table->string('pra_connection_mode')->nullable();
            $table->string('pra_environment')->nullable();
            $table->text('pra_production_token')->nullable();
            $table->string('pra_proxy_url')->nullable();
            $table->string('pra_pos_id')->nullable();
            $table->decimal('pos_tax_rate_cash', 8, 2)->nullable();
            $table->decimal('pos_tax_rate_card', 8, 2)->nullable();
            // Dashboard entry gate: without this flag the dashboard redirects
            // to the setup wizard instead of returning view data.
            $table->boolean('pos_setup_completed')->default(true);
            $table->string('pos_dashboard_style')->nullable();
            $table->softDeletes();
            $table->timestamps();
        });

        Schema::create('users', function (Blueprint $table) {
            $table->id();
            $table->string('name')->nullable();
            $table->string('email')->nullable()->unique();
            $table->string('password')->nullable();
            $table->unsignedBigInteger('company_id')->nullable();
            $table->string('role')->nullable();
            $table->string('pos_role')->nullable();
            $table->boolean('is_active')->default(true);
            $table->string('language')->nullable();
            $table->boolean('pra_reporting_enabled')->default(false);
            $table->rememberToken();
            $table->timestamps();
        });

        Schema::create('pos_transactions', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('company_id');
            $table->string('invoice_number');
            $table->string('business_date')->nullable();
            $table->string('status');
            $table->string('invoice_mode')->nullable();
            $table->string('pra_status')->nullable();
            $table->string('pra_invoice_number')->nullable();
            $table->string('pra_response_code')->nullable();
            $table->text('pra_qr_code')->nullable();
            $table->boolean('is_archived')->default(false);
            $table->timestamp('archived_at')->nullable();
            $table->unsignedBigInteger('archived_by_report_id')->nullable();
            $table->unsignedBigInteger('created_by')->nullable();
            $table->unsignedBigInteger('customer_id')->nullable();
            $table->string('customer_name')->nullable();
            $table->string('customer_phone')->nullable();
            $table->decimal('subtotal', 12, 2)->default(0);
            $table->decimal('discount_amount', 12, 2)->default(0);
            $table->decimal('tax_rate', 8, 2)->nullable();
            $table->decimal('tax_amount', 12, 2)->default(0);
            $table->decimal('exempt_amount', 12, 2)->nullable();
            $table->decimal('total_amount', 12, 2)->default(0);
            $table->string('payment_method')->nullable();
            $table->decimal('cash_received', 12, 2)->nullable();
            $table->decimal('change_due', 12, 2)->nullable();
            $table->string('submission_hash')->nullable();
            $table->boolean('tax_inclusive')->default(false);
            $table->decimal('tax_menu_rate', 8, 2)->nullable();
            // Delivery riders (khata + Z-report reconciliation).
            $table->unsignedBigInteger('rider_id')->nullable();
            $table->string('delivery_status')->nullable();
            $table->unsignedBigInteger('rider_settlement_id')->nullable();
            $table->timestamp('rider_settled_at')->nullable();
            $table->timestamps();
        });

        Schema::create('pos_transaction_items', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('transaction_id');
            $table->string('item_type')->nullable();
            $table->unsignedBigInteger('item_id')->nullable();
            $table->string('item_name');
            $table->decimal('quantity', 12, 3)->default(1);
            $table->decimal('unit_price', 12, 2)->default(0);
            $table->decimal('subtotal', 12, 2)->default(0);
            $table->boolean('is_tax_exempt')->default(false);
            $table->decimal('tax_rate', 8, 2)->nullable();
            $table->decimal('tax_amount', 12, 2)->nullable();
            $table->decimal('item_discount_amount', 12, 2)->nullable();
            $table->text('deal_snapshot')->nullable();
            $table->timestamps();
        });

        Schema::create('pos_payments', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('transaction_id');
            $table->string('payment_method')->nullable();
            $table->decimal('amount', 12, 2)->default(0);
            $table->string('reference_number')->nullable();
            $table->timestamps();
        });

        Schema::create('branches', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('company_id');
            $table->string('name');
            $table->boolean('is_active')->default(true);
            $table->boolean('is_head_office')->default(false);
            $table->timestamps();
        });

        Schema::create('pra_logs', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('company_id')->nullable();
            $table->unsignedBigInteger('transaction_id')->nullable();
            $table->text('request_payload')->nullable();
            $table->text('response_payload')->nullable();
            $table->string('response_code')->nullable();
            $table->string('status')->nullable();
            $table->timestamps();
        });

        Schema::create('pos_day_close_reports', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('company_id');
            $table->date('report_date');
            $table->string('report_number')->nullable();
            $table->integer('deleted_final_count')->default(0);
            $table->integer('deleted_provisional_count')->default(0);
            $table->text('local_summary')->nullable();
            $table->text('rider_summary')->nullable();
            $table->integer('total_invoices')->default(0);
            $table->integer('pra_invoices')->default(0);
            $table->integer('local_invoices')->default(0);
            $table->integer('offline_invoices')->default(0);
            $table->decimal('gross_sales', 14, 2)->default(0);
            $table->decimal('total_discount', 14, 2)->default(0);
            $table->decimal('net_sales', 14, 2)->default(0);
            $table->decimal('total_tax', 14, 2)->default(0);
            $table->decimal('total_amount', 14, 2)->default(0);
            $table->decimal('cash_amount', 14, 2)->default(0);
            $table->decimal('card_amount', 14, 2)->default(0);
            $table->decimal('other_amount', 14, 2)->default(0);
            $table->string('first_invoice_number')->nullable();
            $table->string('last_invoice_number')->nullable();
            $table->timestamp('first_invoice_time')->nullable();
            $table->timestamp('last_invoice_time')->nullable();
            $table->unsignedBigInteger('closed_by')->nullable();
            $table->text('notes')->nullable();
            $table->string('hash')->nullable();
            $table->decimal('opening_float', 14, 2)->nullable();
            $table->decimal('counted_cash', 14, 2)->nullable();
            $table->decimal('expected_cash', 14, 2)->nullable();
            $table->decimal('cash_variance', 14, 2)->nullable();
            $table->timestamps();
        });

        Schema::create('pos_riders', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('company_id');
            $table->string('name');
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });

        // dayCloseReport queries open restaurant orders unconditionally.
        Schema::create('restaurant_orders', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('company_id');
            $table->string('status')->nullable();
            $table->unsignedBigInteger('table_id')->nullable();
            $table->string('source')->nullable();
            $table->unsignedBigInteger('pos_transaction_id')->nullable();
            $table->unsignedBigInteger('created_by')->nullable();
            $table->timestamps();
        });

        Schema::create('pos_products', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('company_id');
            $table->string('name');
            $table->string('category')->nullable();
            $table->decimal('price', 12, 2)->default(0);
            $table->decimal('cost_price', 12, 2)->default(0);
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });

        Schema::create('notifications', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('company_id')->nullable();
            $table->unsignedBigInteger('user_id')->nullable();
            $table->string('type')->nullable();
            $table->string('title')->nullable();
            $table->text('message')->nullable();
            $table->boolean('read')->default(false);
            $table->text('metadata')->nullable();
            $table->timestamps();
        });

        // Day-close analytics checks the Restaurant plan gate → subscription
        // lookup (empty tables = no plan, gate simply stays off).
        Schema::create('subscriptions', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('company_id');
            $table->unsignedBigInteger('pricing_plan_id')->nullable();
            $table->boolean('active')->default(false);
            $table->timestamps();
        });

        Schema::create('pricing_plans', function (Blueprint $table) {
            $table->id();
            $table->string('name')->nullable();
            $table->boolean('restaurant_enabled')->default(false);
            $table->timestamps();
        });

        Schema::create('pos_day_openings', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('company_id');
            $table->date('business_date');
            $table->decimal('opening_cash', 14, 2)->default(0);
            $table->unsignedBigInteger('entered_by')->nullable();
            $table->text('notes')->nullable();
            $table->timestamps();
        });
    }

    // ── fixtures ────────────────────────────────────────────────────────────

    private function makeCompany(array $attrs = []): int
    {
        return (int) DB::table('companies')->insertGetId(array_merge([
            'name' => 'Bucket Test Co',
            'product_type' => 'pos',
            'status' => 'active',
            'is_internal_account' => false,
            'invoice_limit_override' => -1,
            'pra_reporting_enabled' => false,
            'agent_enabled' => false,
            'pra_connection_mode' => 'cloud',
            'pra_environment' => 'sandbox',
            'pos_tax_rate_cash' => 16.00,
            'pos_tax_rate_card' => 16.00,
            // Wash policies stay 'save' — bucket tests must not trigger the
            // finalize sweep (bills here are already FINAL).
            'pos_dayclose_provisional_action' => 'save',
            'pos_dayclose_final_local_action' => 'save',
            'pos_setup_completed' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ], $attrs));
    }

    private function makePosUser(int $companyId): \App\Models\User
    {
        $id = DB::table('users')->insertGetId([
            'name' => 'POS Admin',
            'email' => 'admin' . $companyId . '@taxnest.test',
            'password' => bcrypt('Secret@12345'),
            'company_id' => $companyId,
            'role' => 'company_admin',
            'is_active' => true,
            'language' => 'en',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return \App\Models\User::find($id);
    }

    /** A COMPLETED PRA-set final bill (the rows every report aggregates). */
    private function makeFinal(int $companyId, string $number, ?string $method, float $amount, array $attrs = []): int
    {
        $id = (int) DB::table('pos_transactions')->insertGetId(array_merge([
            'company_id' => $companyId,
            'invoice_number' => $number,
            'business_date' => now()->toDateString(),
            'status' => 'completed',
            'invoice_mode' => 'pra',
            'pra_status' => 'submitted',
            'pra_invoice_number' => 'PRA-' . $number,
            'is_archived' => false,
            'subtotal' => $amount,
            'discount_amount' => 0,
            'tax_rate' => 0,
            'tax_amount' => 0,
            'total_amount' => $amount,
            'payment_method' => $method,
            'tax_inclusive' => false,
            'created_at' => now(),
            'updated_at' => now(),
        ], $attrs));

        DB::table('pos_transaction_items')->insert([
            'transaction_id' => $id,
            'item_name' => 'Test Item',
            'quantity' => 1,
            'unit_price' => $amount,
            'subtotal' => $amount,
            'is_tax_exempt' => false,
            'tax_rate' => 0,
            'tax_amount' => 0,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return $id;
    }

    /**
     * The canonical mixed-mode day: every stored alias + the buckets they
     * MUST land in. cash 150 / card 300 (debit 200 + credit 75 + legacy 25) /
     * other 100 (qr 60 + null-method 40) — total 550.
     */
    private function seedMixedDay(int $companyId): void
    {
        $this->makeFinal($companyId, 'P-0001', 'cash', 100.00);
        $this->makeFinal($companyId, 'P-0002', 'cash', 50.00);
        // THE real stored value for card sales (universal screen normalizes).
        $this->makeFinal($companyId, 'P-0003', 'debit_card', 200.00);
        $this->makeFinal($companyId, 'P-0004', 'credit_card', 75.00);
        // Legacy rows saved before normalization still say 'card'.
        $this->makeFinal($companyId, 'P-0005', 'card', 25.00);
        // Owner rule: QR payments are OTHER, never card.
        $this->makeFinal($companyId, 'P-0006', 'qr_payment', 60.00);
        $this->makeFinal($companyId, 'P-0007', null, 40.00);
        // Provisional (local) bill must stay OUT of every PRA-day figure.
        $this->makeFinal($companyId, 'L-0001', 'cash', 999.00, [
            'invoice_mode' => 'local',
            'pra_status' => 'local',
            'pra_invoice_number' => null,
        ]);
    }

    private function closeDay(int $companyId, array $body = [])
    {
        return $this->actingAs($this->makePosUser($companyId), 'pos')
            ->from('/pos/day-close')
            ->post('/pos/day-close', array_merge(['date' => now()->toDateString()], $body));
    }

    private function report(int $companyId): object
    {
        $report = DB::table('pos_day_close_reports')->where('company_id', $companyId)->first();
        $this->assertNotNull($report, 'Z-report row must exist');

        return $report;
    }

    // ── 1. the alias set itself is locked ───────────────────────────────────

    public function test_card_alias_set_and_bucket_mapping_are_locked(): void
    {
        // The stored-value contract. Changing/shrinking this set silently
        // rebuckets live money — it must be a DELIBERATE, test-visible change.
        $this->assertSame('cash', PosPaymentBuckets::CASH);
        $this->assertSame(['card', 'debit_card', 'credit_card'], PosPaymentBuckets::CARD_ALIASES);
        $this->assertSame(['cash', 'card', 'debit_card', 'credit_card'], PosPaymentBuckets::cashOrCard());

        $this->assertSame('cash', PosPaymentBuckets::bucket('cash'));
        $this->assertSame('card', PosPaymentBuckets::bucket('debit_card'), 'universal-screen stored value');
        $this->assertSame('card', PosPaymentBuckets::bucket('credit_card'));
        $this->assertSame('card', PosPaymentBuckets::bucket('card'), 'legacy stored rows');
        $this->assertSame('other', PosPaymentBuckets::bucket('qr_payment'), 'owner rule: QR is Other, not card');
        $this->assertSame('other', PosPaymentBuckets::bucket('mixed'));
        $this->assertSame('other', PosPaymentBuckets::bucket('bank_transfer'));
        $this->assertSame('other', PosPaymentBuckets::bucket(null));
        $this->assertSame('other', PosPaymentBuckets::bucket(''));

        // split() sums by bucket and never drops a row.
        $split = PosPaymentBuckets::split(collect([
            (object) ['payment_method' => 'cash', 'total_amount' => 10],
            (object) ['payment_method' => 'debit_card', 'total_amount' => 20],
            (object) ['payment_method' => 'card', 'total_amount' => 5],
            (object) ['payment_method' => 'qr_payment', 'total_amount' => 7],
            (object) ['payment_method' => null, 'total_amount' => 3],
        ]));
        $this->assertSame(['cash' => 10.0, 'card' => 25.0, 'other' => 10.0], $split);
    }

    // ── 2. day-close PAGE stats (view data) ─────────────────────────────────

    public function test_day_close_page_stats_bucket_mixed_modes_with_full_alias_set(): void
    {
        $companyId = $this->makeCompany();
        $this->seedMixedDay($companyId);

        app()->instance('currentCompanyId', $companyId);
        $controller = app(\App\Http\Controllers\PosController::class);
        $request = \Illuminate\Http\Request::create('/pos/day-close', 'GET', ['date' => now()->toDateString()]);

        $stats = $controller->dayCloseReport($request)->getData()['stats'];

        $this->assertSame(150.0, (float) $stats->cash_amount);
        // ='card' matching would show 25 here (and 375 in Other) — the bug this locks out.
        $this->assertSame(300.0, (float) $stats->card_amount);
        $this->assertSame(100.0, (float) $stats->other_amount);
        $this->assertSame(550.0, (float) $stats->total_amount, 'local provisional must stay excluded');
        // Conservation: the three buckets must always sum to the day total.
        $this->assertSame(
            (float) $stats->total_amount,
            (float) $stats->cash_amount + (float) $stats->card_amount + (float) $stats->other_amount
        );
    }

    // ── 3. stored Z-report row (the durable record PDFs/admin read) ─────────

    public function test_z_report_stores_bucket_totals_with_full_alias_set(): void
    {
        $companyId = $this->makeCompany();
        $this->seedMixedDay($companyId);

        $this->closeDay($companyId)->assertSessionHas('success');

        $report = $this->report($companyId);
        $this->assertSame(150.0, (float) $report->cash_amount);
        $this->assertSame(300.0, (float) $report->card_amount);
        $this->assertSame(100.0, (float) $report->other_amount);
        $this->assertSame(550.0, (float) $report->total_amount);
        $this->assertSame(7, (int) $report->total_invoices, 'local provisional excluded');
    }

    // ── 4. legacy 'card' regression ──────────────────────────────────────────

    public function test_legacy_card_value_lands_in_card_bucket_never_other(): void
    {
        // Rows saved before the 'card' → 'debit_card' normalization still exist
        // on live. They must forever count as CARD money.
        $companyId = $this->makeCompany();
        $this->makeFinal($companyId, 'P-0001', 'cash', 100.00);
        $this->makeFinal($companyId, 'P-0002', 'card', 45.00);

        $this->closeDay($companyId)->assertSessionHas('success');

        $report = $this->report($companyId);
        $this->assertSame(100.0, (float) $report->cash_amount);
        $this->assertSame(45.0, (float) $report->card_amount);
        $this->assertSame(0.0, (float) $report->other_amount, "legacy 'card' must never fall into Other");
    }

    // ── 5. dashboard payment breakdown (per stored method, correct sums) ────

    public function test_dashboard_payment_breakdown_keeps_stored_methods_with_correct_sums(): void
    {
        $companyId = $this->makeCompany();
        $user = $this->makePosUser($companyId);

        // Stamp bills on the OPEN business day — exactly what the dashboard reads
        // (deterministic even when the suite runs between midnight and the cutoff).
        $biz = \App\Services\PosBusinessDay::current($companyId);
        $this->makeFinal($companyId, 'P-0001', 'cash', 100.00, ['business_date' => $biz]);
        $this->makeFinal($companyId, 'P-0002', 'cash', 50.00, ['business_date' => $biz]);
        $this->makeFinal($companyId, 'P-0003', 'debit_card', 200.00, ['business_date' => $biz]);
        $this->makeFinal($companyId, 'P-0004', 'credit_card', 75.00, ['business_date' => $biz]);
        $this->makeFinal($companyId, 'P-0005', 'card', 25.00, ['business_date' => $biz]);
        $this->makeFinal($companyId, 'P-0006', 'qr_payment', 60.00, ['business_date' => $biz]);
        $this->makeFinal($companyId, 'L-0001', 'cash', 999.00, [
            'business_date' => $biz,
            'invoice_mode' => 'local',
            'pra_status' => 'local',
            'pra_invoice_number' => null,
        ]);

        $this->actingAs($user, 'pos');
        app()->instance('currentCompanyId', $companyId);
        $controller = app(\App\Http\Controllers\PosController::class);
        $request = \Illuminate\Http\Request::create('/pos/dashboard', 'GET');

        $view = $controller->dashboard($request);
        $this->assertInstanceOf(\Illuminate\Contracts\View\View::class, $view, 'dashboard must not redirect to setup');

        $breakdown = collect($view->getData()['paymentBreakdown'])
            ->mapWithKeys(fn ($row) => [$row->payment_method => ['count' => (int) $row->count, 'total' => (float) $row->total]])
            ->all();

        // Rows are keyed by the STORED payment_method values — the dashboard shows
        // debit_card sales under their own row; nothing is collapsed into ='card'
        // and the local provisional stays out of every row.
        $this->assertSame(['count' => 2, 'total' => 150.0], $breakdown['cash']);
        $this->assertSame(['count' => 1, 'total' => 200.0], $breakdown['debit_card']);
        $this->assertSame(['count' => 1, 'total' => 75.0], $breakdown['credit_card']);
        $this->assertSame(['count' => 1, 'total' => 25.0], $breakdown['card']);
        $this->assertSame(['count' => 1, 'total' => 60.0], $breakdown['qr_payment']);
        $this->assertSame(
            ['card', 'cash', 'credit_card', 'debit_card', 'qr_payment'],
            collect(array_keys($breakdown))->sort()->values()->all()
        );
        // Every breakdown row maps into the right bucket via the shared helper.
        $this->assertSame('card', PosPaymentBuckets::bucket('debit_card'));
        $this->assertSame(510.0, array_sum(array_column($breakdown, 'total')), 'PRA-set day total (no local bill)');
    }

    // ── 6. rider/day-close cash reconciliation buckets ───────────────────────

    public function test_z_report_cash_reconciliation_counts_only_cash_bucket_rider_bills(): void
    {
        $companyId = $this->makeCompany();
        $riderAli = (int) DB::table('pos_riders')->insertGetId([
            'company_id' => $companyId, 'name' => 'Ali', 'created_at' => now(), 'updated_at' => now(),
        ]);
        $riderBilal = (int) DB::table('pos_riders')->insertGetId([
            'company_id' => $companyId, 'name' => 'Bilal', 'created_at' => now(), 'updated_at' => now(),
        ]);

        // Walk-in cash sale — in the drawer.
        $this->makeFinal($companyId, 'P-0001', 'cash', 500.00);
        // Rider CASH delivery, unsettled → cash is OUT with Ali (cash_out).
        $this->makeFinal($companyId, 'P-0002', 'cash', 300.00, [
            'rider_id' => $riderAli, 'delivery_status' => 'delivered',
        ]);
        // Rider CARD delivery (stored 'debit_card') — card money, must NEVER
        // enter cash_out/cash_in even though it rides with a rider.
        $this->makeFinal($companyId, 'P-0003', 'debit_card', 400.00, [
            'rider_id' => $riderAli, 'delivery_status' => 'delivered',
        ]);
        // Returned cash delivery — excluded from rider cash figures.
        $this->makeFinal($companyId, 'P-0004', 'cash', 90.00, [
            'rider_id' => $riderAli, 'delivery_status' => 'returned',
        ]);
        // Rider LOCAL cash bill, SETTLED today: per-rider operational rows count
        // it, but PRA-set day figures (cash_amount / cash_out) never do.
        $this->makeFinal($companyId, 'L-0001', 'cash', 999.00, [
            'invoice_mode' => 'local', 'pra_status' => 'local', 'pra_invoice_number' => null,
            'rider_id' => $riderAli, 'delivery_status' => 'delivered',
            'rider_settlement_id' => 8, 'rider_settled_at' => now(),
        ]);
        // YESTERDAY's cash delivery settled TODAY → cash came INTO the drawer
        // today without being today's sale (cash_in).
        $this->makeFinal($companyId, 'P-0005', 'cash', 120.00, [
            'business_date' => now()->subDay()->toDateString(),
            'rider_id' => $riderBilal, 'delivery_status' => 'delivered',
            'rider_settlement_id' => 7, 'rider_settled_at' => now(),
            'created_at' => now()->subDay(), 'updated_at' => now()->subDay(),
        ]);

        $this->closeDay($companyId, ['opening_float' => '1000', 'counted_cash' => '1200'])
            ->assertSessionHas('success');

        $report = $this->report($companyId);

        // Day buckets: cash 500+300+90 (returned bill is still the day's money),
        // card 400 — the rider card bill lands in CARD, never cash.
        $this->assertSame(890.0, (float) $report->cash_amount);
        $this->assertSame(400.0, (float) $report->card_amount);
        $this->assertSame(0.0, (float) $report->other_amount);

        // Reconciliation: expected = 1000 opening + 890 cash sales
        // − 300 unsettled rider CASH (card bill excluded, returned excluded,
        // local excluded) + 120 earlier-day cash settled today.
        $this->assertSame(1000.0, (float) $report->opening_float);
        $this->assertSame(1200.0, (float) $report->counted_cash);
        $this->assertSame(1710.0, (float) $report->expected_cash);
        $this->assertSame(-510.0, (float) $report->cash_variance);

        // Rider summary on the Z-report: PRA-set cash_out / cash_in only.
        $summary = json_decode($report->rider_summary, true);
        $this->assertTrue((bool) $summary['active']);
        $this->assertSame(300.0, (float) $summary['cash_out'], 'card + returned + local bills must stay out');
        $this->assertSame(120.0, (float) $summary['cash_in']);

        // Per-rider operational rows: only riders with bills TODAY (Ali).
        $this->assertCount(1, $summary['riders']);
        $ali = $summary['riders'][0];
        $this->assertSame('Ali', $ali['name']);
        $this->assertSame(4, (int) $ali['deliveries']);
        $this->assertSame(3, (int) $ali['delivered']);
        $this->assertSame(1, (int) $ali['returned']);
        // cash_total = ALL non-returned rider cash (300 PRA + 999 local settled);
        // the 400 card bill must never inflate rider cash.
        $this->assertSame(1299.0, (float) $ali['cash_total']);
        // cash_pending = unsettled, not-returned cash only (the 300).
        $this->assertSame(300.0, (float) $ali['cash_pending']);
    }
}

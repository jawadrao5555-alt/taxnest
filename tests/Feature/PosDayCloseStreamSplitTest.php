<?php

namespace Tests\Feature;

use App\Http\Controllers\PosController;
use App\Models\User;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

/**
 * Z-REPORT STREAM SPLIT + X-REPORT LOCK (Task 660, ZFC owner feedback).
 *
 * Task 660 restructured the day-close Z-Report family:
 *   - buildDayCloseStreamSplit partitions the day into PRA / Local / Exempt
 *     streams (classifier mirrors PosTransaction::applyStreamTab) with sale,
 *     tax, and cash/card/other per box; exempt_detail carries the exempt
 *     value + item-level breakdown; 'summary' amounts MIRROR the stored
 *     count-column predicates (fixes the "-" Amount cells in the PDF).
 *   - performDayClose FREEZES the split as stream_summary on the report row
 *     (the wash can delete reporting-OFF finals — recompute would undercount).
 *   - X-Report renders the same figures for a NOT-yet-closed day and must be
 *     strictly READ-ONLY: no report row, no wash/archive, no hash.
 *
 * A refactor that forgets a stream branch, stops freezing the split, or lets
 * the X-Report write anything would silently corrupt owner-facing figures —
 * these tests make that loud.
 *
 * Pattern: APP_ENV=testing + sqlite :memory: + minimal Schema::create
 * (PosDayCloseReturnNettingTest approach).
 */
class PosDayCloseStreamSplitTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        Schema::dropAllTables();
        User::flushScopeColumnCache();

        Schema::create('companies', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('product_type')->nullable();
            $table->string('status')->nullable();
            $table->string('pos_dayclose_provisional_action')->nullable();
            $table->string('pos_dayclose_final_local_action')->nullable();
            $table->boolean('pos_customer_spend_persist')->default(true);
            $table->string('pos_business_day_cutoff')->nullable();
            $table->boolean('is_internal_account')->default(false);
            $table->integer('invoice_limit_override')->nullable();
            $table->boolean('pra_reporting_enabled')->default(false);
            $table->string('pra_connection_mode')->nullable();
            $table->boolean('pos_setup_completed')->default(true);
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
            $table->string('pos_billing_scope')->nullable();
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });

        Schema::create('pos_transactions', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('company_id');
            $table->string('invoice_number');
            $table->string('transaction_type')->nullable()->default('sale');
            $table->unsignedBigInteger('parent_transaction_id')->nullable();
            $table->string('business_date')->nullable();
            $table->string('status');
            $table->string('invoice_mode')->nullable();
            $table->string('pra_status')->nullable();
            $table->string('pra_invoice_number')->nullable();
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
            $table->boolean('tax_inclusive')->default(false);
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
            $table->decimal('cost_price', 12, 2)->nullable();
            $table->decimal('subtotal', 12, 2)->default(0);
            $table->boolean('is_tax_exempt')->default(false);
            $table->decimal('tax_rate', 8, 2)->nullable();
            $table->decimal('tax_amount', 12, 2)->nullable();
            $table->decimal('item_discount_amount', 12, 2)->nullable();
            $table->timestamps();
        });

        Schema::create('pos_payments', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('transaction_id');
            $table->string('payment_method')->nullable();
            $table->decimal('amount', 12, 2)->default(0);
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
            // Task 660: frozen PRA/Local/Exempt split.
            $table->text('stream_summary')->nullable();
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
            $table->integer('returns_count')->default(0);
            $table->decimal('returns_amount', 14, 2)->default(0);
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
            $table->timestamps();
        });

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
            $table->timestamps();
        });
    }

    protected function tearDown(): void
    {
        Auth::guard('pos')->logout();
        parent::tearDown();
    }

    // ── fixtures ────────────────────────────────────────────────────────────

    private function makeCompany(array $attrs = []): int
    {
        return (int) DB::table('companies')->insertGetId(array_merge([
            'name' => 'Stream Split Test Co',
            'product_type' => 'pos',
            'status' => 'active',
            'pos_dayclose_provisional_action' => 'save',
            'pos_dayclose_final_local_action' => 'save',
            'pos_setup_completed' => true,
            'created_at' => now(), 'updated_at' => now(),
        ], $attrs));
    }

    private function makePosUser(int $companyId): User
    {
        $id = DB::table('users')->insertGetId([
            'name' => 'POS Admin',
            'email' => 'admin' . $companyId . '@taxnest.test',
            'company_id' => $companyId,
            'role' => 'user',
            'pos_role' => 'pos_admin',
            'is_active' => true,
            'created_at' => now(), 'updated_at' => now(),
        ]);

        return User::find($id);
    }

    private function makeTxn(int $companyId, string $number, array $attrs = [], array $items = []): int
    {
        $id = (int) DB::table('pos_transactions')->insertGetId(array_merge([
            'company_id' => $companyId,
            'invoice_number' => $number,
            'transaction_type' => 'sale',
            'business_date' => now()->toDateString(),
            'status' => 'completed',
            'invoice_mode' => 'pra',
            'pra_status' => 'submitted',
            'pra_invoice_number' => 'PRA-' . $number,
            'subtotal' => 0, 'discount_amount' => 0, 'tax_amount' => 0, 'total_amount' => 0,
            'payment_method' => 'cash',
            'created_at' => now(), 'updated_at' => now(),
        ], $attrs));

        foreach ($items as $item) {
            DB::table('pos_transaction_items')->insert(array_merge([
                'transaction_id' => $id,
                'item_type' => 'product',
                'quantity' => 1,
                'created_at' => now(), 'updated_at' => now(),
            ], $item));
        }

        return $id;
    }

    /**
     * The canonical mixed-stream day:
     *   PRA sale     — submitted, cash, total 1170 (tax 170).
     *   PRA sale     — submitted, debit_card, total 585 (tax 85).
     *   Local sale   — pra_status NULL, cash, total 300 (tax 0).
     *   Offline sale — pra_status 'offline', cash, total 234 (tax 34).
     *   Exempt bill  — pra_status exempt_internal, cash, total 400,
     *                  exempt_amount 400, items Doodh ×2 + Bread ×1.
     *   PRA return   — cash refund 117 (tax 17) against the first PRA sale.
     * Stream boxes: pra {count 3, sales 1872, tax 272, cash 1287, card 585},
     * local {1, 300, 0, cash 300}, exempt {1, 400, 0, cash 400}.
     * Summary: pra_submitted 1755 (returns NOT netted — mirrors count
     * predicate), local 300, offline 234.
     */
    private function seedMixedDay(int $companyId, ?int $cashierId = null): array
    {
        $praA = $this->makeTxn($companyId, 'P-0001', [
            'subtotal' => 1000, 'tax_amount' => 170, 'total_amount' => 1170,
            'payment_method' => 'cash', 'created_by' => $cashierId,
        ], [
            ['item_id' => 601, 'item_name' => 'Burger', 'quantity' => 4, 'unit_price' => 250,
             'subtotal' => 1000, 'tax_rate' => 17, 'tax_amount' => 170],
        ]);
        $this->makeTxn($companyId, 'P-0002', [
            'subtotal' => 500, 'tax_amount' => 85, 'total_amount' => 585,
            'payment_method' => 'debit_card', 'created_by' => $cashierId,
        ], [
            ['item_id' => 602, 'item_name' => 'Chai', 'quantity' => 5, 'unit_price' => 100,
             'subtotal' => 500, 'tax_rate' => 17, 'tax_amount' => 85],
        ]);
        $localId = $this->makeTxn($companyId, 'P-0003', [
            'pra_status' => null, 'pra_invoice_number' => null,
            'subtotal' => 300, 'tax_amount' => 0, 'total_amount' => 300,
            'payment_method' => 'cash', 'created_by' => $cashierId,
        ], [
            ['item_id' => 603, 'item_name' => 'Fries', 'quantity' => 2, 'unit_price' => 150,
             'subtotal' => 300],
        ]);
        $this->makeTxn($companyId, 'P-0004', [
            'pra_status' => 'offline', 'pra_invoice_number' => null,
            'subtotal' => 200, 'tax_amount' => 34, 'total_amount' => 234,
            'payment_method' => 'cash', 'created_by' => $cashierId,
        ], [
            ['item_id' => 601, 'item_name' => 'Burger', 'quantity' => 1, 'unit_price' => 200,
             'subtotal' => 200, 'tax_rate' => 17, 'tax_amount' => 34],
        ]);
        $this->makeTxn($companyId, 'P-0005', [
            'pra_status' => \App\Models\PosTransaction::EXEMPT_INTERNAL,
            'pra_invoice_number' => null,
            'subtotal' => 400, 'tax_amount' => 0, 'total_amount' => 400,
            'exempt_amount' => 400,
            'payment_method' => 'cash', 'created_by' => $cashierId,
        ], [
            ['item_id' => 604, 'item_name' => 'Doodh', 'quantity' => 2, 'unit_price' => 150,
             'subtotal' => 300, 'is_tax_exempt' => true],
            ['item_id' => 605, 'item_name' => 'Bread', 'quantity' => 1, 'unit_price' => 100,
             'subtotal' => 100, 'is_tax_exempt' => true],
        ]);
        $this->makeTxn($companyId, 'RET-0001', [
            'transaction_type' => 'return', 'parent_transaction_id' => $praA,
            'pra_status' => 'pending', 'pra_invoice_number' => null,
            'subtotal' => 100, 'tax_amount' => 17, 'total_amount' => 117,
            'payment_method' => 'cash', 'created_by' => $cashierId,
        ], [
            ['item_id' => 601, 'item_name' => 'Burger', 'quantity' => 1, 'unit_price' => 250,
             'subtotal' => 100, 'tax_rate' => 17, 'tax_amount' => 17],
        ]);

        return [$praA, $localId];
    }

    // ── 1. performDayClose freezes a correct stream_summary ─────────────────

    public function test_day_close_freezes_stream_summary_with_correct_boxes(): void
    {
        $companyId = $this->makeCompany();
        $this->seedMixedDay($companyId);

        $result = (new PosController())->performDayClose($companyId, now()->toDateString(), null);
        $this->assertSame('created', $result['status']);

        $row = DB::table('pos_day_close_reports')->where('company_id', $companyId)->first();
        $this->assertNotNull($row);
        $split = json_decode($row->stream_summary, true);
        $this->assertIsArray($split, 'stream_summary must be FROZEN at close time');

        // PRA box: submitted×2 + offline + pending return (netted).
        $this->assertSame(3, $split['pra']['count']);
        $this->assertSame(1872.0, (float) $split['pra']['sales'], '1170+585+234 − 117 return');
        $this->assertSame(272.0, (float) $split['pra']['tax'], '170+85+34 − 17 refund tax');
        $this->assertSame(1287.0, (float) $split['pra']['cash'], '1170+234 − 117 cash refund');
        $this->assertSame(585.0, (float) $split['pra']['card']);
        $this->assertSame(0.0, (float) $split['pra']['other']);

        // Local box: the reporting-OFF final only.
        $this->assertSame(1, $split['local']['count']);
        $this->assertSame(300.0, (float) $split['local']['sales']);
        $this->assertSame(0.0, (float) $split['local']['tax']);
        $this->assertSame(300.0, (float) $split['local']['cash']);

        // Exempt box + detail.
        $this->assertSame(1, $split['exempt']['count']);
        $this->assertSame(400.0, (float) $split['exempt']['sales']);
        $this->assertSame(400.0, (float) $split['exempt_detail']['value']);
        $items = collect($split['exempt_detail']['items'])->keyBy('name');
        $this->assertSame(2.0, (float) $items['Doodh']['qty']);
        $this->assertSame(300.0, (float) $items['Doodh']['amount']);
        $this->assertSame(100.0, (float) $items['Bread']['amount']);

        // Streams tile the day: box sales sum = report total.
        $this->assertSame(
            (float) $row->total_amount,
            (float) $split['pra']['sales'] + (float) $split['local']['sales'] + (float) $split['exempt']['sales']
        );

        // Invoice Summary amounts (the old "-" cells) mirror count predicates.
        $this->assertSame(1755.0, (float) $split['summary']['pra_submitted'], 'submitted sales only, not netted');
        $this->assertSame(300.0, (float) $split['summary']['local']);
        $this->assertSame(234.0, (float) $split['summary']['offline']);
        $this->assertSame((int) $row->pra_invoices, 2, 'stored count column unchanged');
    }

    // ── 2. X-Report: same figures, strictly read-only ────────────────────────

    public function test_x_report_is_read_only_and_matches_stream_split(): void
    {
        $companyId = $this->makeCompany();
        $user = $this->makePosUser($companyId);
        $this->seedMixedDay($companyId, $user->id);

        Auth::guard('pos')->setUser($user);
        app()->instance('currentCompanyId', $companyId);
        $request = Request::create('/pos/day-close/x-report/thermal', 'GET', ['date' => now()->toDateString()]);

        $view = (new PosController())->dayCloseXReportThermal($request);
        $data = $view->getData();

        $this->assertTrue($data['isXReport']);
        $report = $data['report'];
        $this->assertStringStartsWith('X-', $report->report_number);
        $this->assertNull($report->hash ?? null, 'X-Report must carry NO integrity hash');

        // Same stream figures as the Z path (shared builder).
        $split = $data['streamSplit'];
        $this->assertSame(1872.0, (float) $split['pra']['sales']);
        $this->assertSame(300.0, (float) $split['local']['sales']);
        $this->assertSame(400.0, (float) $split['exempt']['sales']);
        $this->assertSame(1755.0, (float) $split['summary']['pra_submitted']);

        // READ-ONLY: no report row, nothing archived, nothing deleted.
        $this->assertSame(0, DB::table('pos_day_close_reports')->count(), 'X-Report must NEVER create a day-close row');
        $this->assertSame(0, DB::table('pos_transactions')->where('is_archived', true)->count());
        $this->assertSame(6, DB::table('pos_transactions')->count(), 'no wash — every bill survives');
    }

    // ── 3. X-Report refuses an already-closed day ────────────────────────────

    public function test_x_report_routes_refuse_an_already_closed_day(): void
    {
        $companyId = $this->makeCompany();
        $user = $this->makePosUser($companyId);
        $this->seedMixedDay($companyId, $user->id);

        (new PosController())->performDayClose($companyId, now()->toDateString(), null);
        $before = DB::table('pos_transactions')->orderBy('id')->get()->map(fn ($t) => (array) $t)->all();

        Auth::guard('pos')->setUser($user);
        app()->instance('currentCompanyId', $companyId);
        $request = Request::create('/pos/day-close/x-report/thermal', 'GET', ['date' => now()->toDateString()]);

        // A closed day has a Z-Report (frozen + hashed) — a PROVISIONAL print
        // over it would be a fiscal lie. Both routes must redirect, not render.
        foreach (['dayCloseXReportThermal', 'dayCloseXReportPdf'] as $method) {
            $response = (new PosController())->{$method}($request);
            $this->assertInstanceOf(
                \Illuminate\Http\RedirectResponse::class,
                $response,
                "$method must REDIRECT for an already-closed day, never render"
            );
        }

        // Strictly read-only even when refused: nothing changed anywhere.
        $this->assertSame(1, DB::table('pos_day_close_reports')->count());
        $after = DB::table('pos_transactions')->orderBy('id')->get()->map(fn ($t) => (array) $t)->all();
        $this->assertSame($before, $after, 'refused X-Report must not touch any transaction row');
    }

    // ── 4. Backward compat: old report without stream_summary recomputes ─────

    public function test_old_report_without_stream_summary_falls_back_to_recompute(): void
    {
        $companyId = $this->makeCompany();
        $user = $this->makePosUser($companyId);
        $this->seedMixedDay($companyId, $user->id);

        // Close, then simulate a PRE-Task-660 report row (no frozen split).
        (new PosController())->performDayClose($companyId, now()->toDateString(), null);
        DB::table('pos_day_close_reports')->where('company_id', $companyId)->update(['stream_summary' => null]);

        Auth::guard('pos')->setUser($user);
        app()->instance('currentCompanyId', $companyId);
        $request = Request::create('/pos/day-close', 'GET', ['date' => now()->toDateString()]);

        $data = (new PosController())->dayCloseReport($request)->getData();
        $split = $data['streamSplit'];
        $this->assertIsArray($split, 'old reports must recompute the split live (graceful fallback)');
        // PRA/exempt streams recompute exactly (never washed).
        $this->assertSame(1872.0, (float) $split['pra']['sales']);
        $this->assertSame(400.0, (float) $split['exempt']['sales']);
        // KNOWN LIMITATION (the very reason Task 660 freezes stream_summary):
        // the wash archived the reporting-OFF final, and the recompute set is
        // hide_archived-scoped — a pre-660 report undercounts the Local box.
        // The page must still render (no 500), just with the reduced figure.
        $this->assertSame(0.0, (float) $split['local']['sales'], 'washed local final invisible to live recompute');
    }
}

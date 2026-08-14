<?php

namespace Tests\Feature;

use App\Http\Controllers\FbrPosController;
use App\Http\Controllers\PosController;
use App\Models\User;
use App\Services\PosFeatureService;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

/**
 * TAX REPORT CREDIT-NOTE FILTER + NETTING LOCK (Task 695).
 *
 * Return rows are stored POSITIVE with transaction_type='return' and
 * status='completed' — before Task 695 the tax report summed them as sales,
 * OVERSTATING the monthly tax figures the owner files. Locked here:
 *   - All-bills view: summary figures are SIGNED (returns subtract), invoice
 *     count = sale bills only, credit-note count/refunded amount shown
 *     alongside (nothing hidden).
 *   - bill_type=sales excludes returns; bill_type=returns lists only credit
 *     notes with POSITIVE (refunded) figures.
 *   - Item-level path (tax-rate filter) nets returns through their return
 *     transaction join.
 *   - CSV export honors the filter and uses the same signed math.
 *   - FBR POS tax report mirrors all of the above (monthly totals + rate-wise
 *     breakdown netted, credit-note line, bill-type filter).
 *
 * Pattern: sqlite :memory: + minimal Schema::create in setUp (schema mirrors
 * PosDashboardReturnNettingTest; numbers mirror the canonical netting day).
 *
 * Run:
 *   env -u DATABASE_URL -u DB_CONNECTION -u PGHOST -u PGPORT -u PGUSER \
 *     -u PGPASSWORD -u PGDATABASE APP_ENV=testing DB_CONNECTION=sqlite \
 *     DB_DATABASE=':memory:' php vendor/bin/phpunit tests/Feature/PosTaxReportCreditNoteNettingTest.php
 */
class PosTaxReportCreditNoteNettingTest extends TestCase
{
    private int $companyId;
    private User $posAdmin;

    protected function setUp(): void
    {
        parent::setUp();
        PosFeatureService::flushGateCaches();
        Schema::dropAllTables();
        User::flushScopeColumnCache();
        $this->buildSchema();
        $this->companyId = $this->makeCompany();
        $this->posAdmin = $this->makePosUser($this->companyId);
        $this->seedNettingDay($this->companyId);
        $this->seedFbrNettingMonth($this->companyId);

        Auth::guard('pos')->setUser($this->posAdmin);
        app()->instance('currentCompanyId', $this->companyId);
    }

    protected function tearDown(): void
    {
        Auth::guard('pos')->logout();
        PosFeatureService::flushGateCaches();
        parent::tearDown();
    }

    private function buildSchema(): void
    {
        Schema::create('companies', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('product_type')->nullable();
            $table->string('status')->nullable();
            $table->boolean('is_internal_account')->default(false);
            $table->boolean('pra_reporting_enabled')->default(false);
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
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });

        Schema::create('pos_transactions', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('company_id');
            $table->unsignedBigInteger('terminal_id')->nullable();
            $table->string('invoice_number');
            $table->string('transaction_type')->nullable()->default('sale');
            $table->unsignedBigInteger('parent_transaction_id')->nullable();
            $table->string('status');
            $table->string('invoice_mode')->nullable();
            $table->string('pra_status')->nullable();
            $table->string('pra_invoice_number')->nullable();
            $table->boolean('is_archived')->default(false);
            $table->unsignedBigInteger('created_by')->nullable();
            $table->string('customer_name')->nullable();
            $table->decimal('subtotal', 12, 2)->default(0);
            $table->decimal('discount_amount', 12, 2)->default(0);
            $table->decimal('tax_rate', 8, 2)->nullable();
            $table->decimal('tax_amount', 12, 2)->default(0);
            $table->decimal('exempt_amount', 12, 2)->nullable();
            $table->decimal('total_amount', 12, 2)->default(0);
            $table->string('payment_method')->nullable();
            $table->boolean('tax_inclusive')->default(false);
            $table->timestamps();
        });

        Schema::create('pos_transaction_items', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('transaction_id');
            $table->string('item_name');
            $table->decimal('quantity', 12, 3)->default(1);
            $table->decimal('subtotal', 12, 2)->default(0);
            $table->boolean('is_tax_exempt')->default(false);
            $table->decimal('tax_rate', 8, 2)->nullable();
            $table->decimal('tax_amount', 12, 2)->nullable();
            $table->timestamps();
        });

        Schema::create('pos_terminals', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('company_id');
            $table->string('terminal_name')->nullable();
            $table->timestamps();
        });

        Schema::create('pos_tax_rules', function (Blueprint $table) {
            $table->id();
            $table->string('payment_method')->nullable();
            $table->decimal('tax_rate', 8, 2)->default(0);
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });

        Schema::create('fbr_pos_transactions', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('company_id');
            $table->string('invoice_number');
            $table->string('transaction_type')->nullable()->default('sale');
            $table->unsignedBigInteger('parent_transaction_id')->nullable();
            $table->string('status')->nullable();
            $table->string('fbr_status')->nullable();
            $table->decimal('subtotal', 12, 2)->default(0);
            $table->decimal('tax_rate', 8, 2)->nullable();
            $table->decimal('tax_amount', 12, 2)->default(0);
            $table->decimal('fbr_service_charge', 12, 2)->default(0);
            $table->decimal('total_amount', 12, 2)->default(0);
            $table->timestamps();
        });
    }

    private function makeCompany(): int
    {
        return (int) DB::table('companies')->insertGetId([
            'name' => 'Tax Report Netting Co',
            'product_type' => 'pos',
            'status' => 'active',
            // internal account → planAllows() short-circuits, CSV planGate passes
            'is_internal_account' => true,
            'created_at' => now(), 'updated_at' => now(),
        ]);
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
                'quantity' => 1,
                'created_at' => now(), 'updated_at' => now(),
            ], $item));
        }

        return $id;
    }

    /**
     * Canonical netting day (same numbers as PosDashboardReturnNettingTest):
     *   Sale A — 1000 sub, 100 disc, 170 tax, 1070 total (Burger, 17%).
     *   Sale B — 500 sub, 85 tax, 585 total (Chai, 17%).
     *   Return R — POSITIVE 200 sub, 20 disc share, 34 tax, 214 refund.
     * Netted: sales 1441, tax 221, taxable 1220, invoices 2.
     */
    private function seedNettingDay(int $companyId): void
    {
        $saleA = $this->makeTxn($companyId, 'P-0001', [
            'subtotal' => 1000, 'discount_amount' => 100, 'tax_rate' => 17,
            'tax_amount' => 170, 'total_amount' => 1070,
        ], [
            ['item_name' => 'Burger', 'quantity' => 4, 'subtotal' => 1000,
             'tax_rate' => 17, 'tax_amount' => 170],
        ]);
        $this->makeTxn($companyId, 'P-0002', [
            'subtotal' => 500, 'tax_rate' => 17, 'tax_amount' => 85,
            'total_amount' => 585, 'payment_method' => 'debit_card',
        ], [
            ['item_name' => 'Chai', 'quantity' => 5, 'subtotal' => 500,
             'tax_rate' => 17, 'tax_amount' => 85],
        ]);
        $this->makeTxn($companyId, 'RET-0001', [
            'transaction_type' => 'return',
            'parent_transaction_id' => $saleA,
            'subtotal' => 200, 'discount_amount' => 20, 'tax_rate' => 17,
            'tax_amount' => 34, 'total_amount' => 214,
        ], [
            ['item_name' => 'Burger', 'quantity' => 2, 'subtotal' => 200,
             'tax_rate' => 17, 'tax_amount' => 34],
        ]);
    }

    /** FBR mirror month: sale 1000/170, sale 500/85, return 200/34 (positive). */
    private function seedFbrNettingMonth(int $companyId): void
    {
        $row = fn (string $num, array $attrs) => array_merge([
            'company_id' => $companyId,
            'invoice_number' => $num,
            'transaction_type' => 'sale',
            'status' => 'completed',
            'fbr_status' => 'submitted',
            'tax_rate' => 18,
            'fbr_service_charge' => 0,
            'created_at' => now(), 'updated_at' => now(),
        ], $attrs);

        DB::table('fbr_pos_transactions')->insert([
            $row('F-0001', ['subtotal' => 1000, 'tax_amount' => 170, 'fbr_service_charge' => 1, 'total_amount' => 1171]),
            $row('F-0002', ['subtotal' => 500, 'tax_amount' => 85, 'fbr_service_charge' => 1, 'total_amount' => 586]),
            $row('FRET-0001', ['transaction_type' => 'return', 'subtotal' => 200, 'tax_amount' => 34, 'total_amount' => 234]),
        ]);
    }

    private function praTaxReport(array $query = []): array
    {
        $view = (new PosController())->taxReports(Request::create('/pos/tax-reports', 'GET', $query));

        return $view->getData();
    }

    // ── 1. PRA all-bills view: signed summary + credit-note line ────────────

    public function test_pra_summary_nets_returns_and_exposes_credit_note_stats(): void
    {
        $data = $this->praTaxReport();
        $summary = $data['summary'];

        $this->assertSame(2, (int) $summary->total_invoices, 'credit note must not count as a bill');
        $this->assertSame(1441.0, (float) $summary->total_sales, '1070 + 585 − 214 refund');
        $this->assertSame(221.0, (float) $summary->total_tax, '170 + 85 − 34 return tax');
        $this->assertSame(1220.0, (float) $summary->total_taxable, '900 + 500 − 180');
        $this->assertSame(80.0, (float) $summary->total_discount, '100 − 20 return share');
        $this->assertSame(1, (int) $summary->return_count);
        $this->assertSame(214.0, (float) $summary->return_amount);
        $this->assertSame(34.0, (float) $summary->return_tax);
        $this->assertSame(3, $data['transactions']->total(), 'list still shows every bill incl. the credit note');
    }

    // ── 2. bill_type filter: sales-only / credit-notes-only ─────────────────

    public function test_bill_type_sales_excludes_credit_notes(): void
    {
        $data = $this->praTaxReport(['bill_type' => 'sales']);
        $summary = $data['summary'];

        $this->assertSame(2, $data['transactions']->total());
        $this->assertSame(1655.0, (float) $summary->total_sales, '1070 + 585, no netting needed');
        $this->assertSame(255.0, (float) $summary->total_tax);
        $this->assertSame(0, (int) $summary->return_count);
    }

    public function test_bill_type_returns_lists_only_credit_notes_with_positive_refund_figures(): void
    {
        $data = $this->praTaxReport(['bill_type' => 'returns']);
        $summary = $data['summary'];

        $this->assertSame(1, $data['transactions']->total());
        $this->assertSame('RET-0001', $data['transactions']->first()->invoice_number);
        $this->assertSame(1, (int) $summary->total_invoices);
        $this->assertSame(214.0, (float) $summary->total_sales, 'refunded figures stay POSITIVE');
        $this->assertSame(34.0, (float) $summary->total_tax);
        $this->assertSame(1, (int) $summary->return_count);
        $this->assertSame('returns', $data['billTypeFilter']);
    }

    // ── 3. item-level path (tax-rate filter) nets through the return join ───

    public function test_tax_rate_filter_summary_nets_return_items(): void
    {
        $data = $this->praTaxReport(['tax_rate' => '17']);
        $summary = $data['summary'];

        $this->assertSame(2, (int) $summary->total_invoices, 'sale bills only');
        $this->assertSame(1300.0, (float) $summary->total_sales, '1000 + 500 − 200 return items');
        $this->assertSame(221.0, (float) $summary->total_tax, '170 + 85 − 34');
        $this->assertSame(1, (int) $summary->return_count);
        $this->assertSame(234.0, (float) $summary->return_amount, '200 base + 34 tax refunded');
    }

    public function test_tax_rate_filter_with_returns_only_stays_positive(): void
    {
        $data = $this->praTaxReport(['tax_rate' => '17', 'bill_type' => 'returns']);
        $summary = $data['summary'];

        $this->assertSame(1, (int) $summary->total_invoices);
        $this->assertSame(200.0, (float) $summary->total_sales);
        $this->assertSame(34.0, (float) $summary->total_tax);
    }

    // ── 4. CSV export: same filter + signed math as the screen ──────────────

    private function csvExport(array $query = []): string
    {
        $response = (new PosController())->exportTaxReportCsv(
            Request::create('/pos/tax-reports/csv', 'GET', $query)
        );
        ob_start();
        $response->sendContent();

        return (string) ob_get_clean();
    }

    public function test_csv_export_totals_are_netted_and_credit_notes_reported(): void
    {
        $csv = $this->csvExport();

        $this->assertStringContainsString('"Total Sales Amount (PKR)",1441.00', $csv);
        $this->assertStringContainsString('"Total Tax Amount (PKR)",221.00', $csv);
        $this->assertStringContainsString('"Total Invoices",2', $csv);
        $this->assertStringContainsString('"Credit Notes (count)",1', $csv);
        $this->assertStringContainsString('"Credit Notes Refunded (PKR)",214.00', $csv);
        // The credit-note row itself is marked and signed negative
        $this->assertStringContainsString('Credit Note', $csv);
        $this->assertStringContainsString('-214.00', $csv);
    }

    public function test_csv_export_honors_returns_only_filter(): void
    {
        $csv = $this->csvExport(['bill_type' => 'returns']);

        $this->assertStringContainsString('RET-0001', $csv);
        $this->assertStringNotContainsString('P-0001', $csv);
        $this->assertStringContainsString('"Total Sales Amount (PKR)",214.00', $csv);
        $this->assertStringContainsString('Credit Notes Only', $csv);
    }

    // ── 5. FBR POS tax report parity ─────────────────────────────────────────

    private function fbrTaxReport(array $query = []): array
    {
        $view = (new FbrPosController())->taxReports(Request::create('/fbr-pos/tax-reports', 'GET', $query));

        return $view->getData();
    }

    public function test_fbr_monthly_totals_and_rate_breakdown_are_netted(): void
    {
        $data = $this->fbrTaxReport();
        $monthly = $data['monthlyTax'];

        $this->assertSame(2, (int) $monthly->invoice_count, 'credit note must not count as a bill');
        $this->assertSame(1300.0, (float) $monthly->total_sales, '1000 + 500 − 200');
        $this->assertSame(221.0, (float) $monthly->total_tax, '170 + 85 − 34');
        $this->assertSame(1, (int) $monthly->return_count);
        $this->assertSame(234.0, (float) $monthly->return_amount);

        $rate = collect($data['taxByRate'])->firstWhere('tax_rate', 18);
        $this->assertNotNull($rate);
        $this->assertSame(2, (int) $rate->count);
        $this->assertSame(1300.0, (float) $rate->sales_total);
        $this->assertSame(221.0, (float) $rate->tax_total);
    }

    public function test_fbr_returns_only_filter_shows_positive_refunds(): void
    {
        $data = $this->fbrTaxReport(['bill_type' => 'returns']);
        $monthly = $data['monthlyTax'];

        $this->assertSame(1, (int) $monthly->invoice_count);
        $this->assertSame(200.0, (float) $monthly->total_sales);
        $this->assertSame(34.0, (float) $monthly->total_tax);
        $this->assertSame('returns', $data['billTypeFilter']);

        $rate = collect($data['taxByRate'])->firstWhere('tax_rate', 18);
        $this->assertSame(200.0, (float) $rate->sales_total, 'rate-wise stays POSITIVE in credit-notes-only view');
    }

    public function test_fbr_sales_only_filter_excludes_returns(): void
    {
        $data = $this->fbrTaxReport(['bill_type' => 'sales']);
        $monthly = $data['monthlyTax'];

        $this->assertSame(2, (int) $monthly->invoice_count);
        $this->assertSame(1500.0, (float) $monthly->total_sales);
        $this->assertSame(0, (int) $monthly->return_count);
    }
}

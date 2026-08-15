<?php

namespace Tests\Feature;

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
 * SALES/TAX REPORT PAYMENT-METHOD FILTER — EXPORT PARITY LOCK (Task 771).
 *
 * Card sales are STORED as 'debit_card' (universal screen normalizes), but
 * legacy rows still carry 'card'. The Debit Card filter therefore matches
 * whereIn(['debit_card','card']) — fixed in buildTaxReportQuery alongside the
 * Transactions patch. The report SCREEN, the CSV export and the printed PDF
 * all flow through that ONE builder; these tests lock that a future
 * export-only builder (or a refactor back to ='debit_card') can never make an
 * exported file silently drop legacy 'card' rows the screen shows.
 *
 * Locked here:
 *   1. Screen: Debit Card filter surfaces BOTH 'debit_card' and legacy 'card'
 *      rows; other methods stay exact-match.
 *   2. CSV export: same rows (and row COUNT) as the screen for the same filter.
 *   3. PDF view data (buildTaxReportPdfData — the exact array the printed copy
 *      renders): same rows + summary totals as the screen.
 *
 * Pattern: sqlite :memory: + minimal Schema::create in setUp (schema mirrors
 * PosTaxReportCreditNoteNettingTest).
 *
 * Run:
 *   env -u DATABASE_URL -u DB_CONNECTION -u PGHOST -u PGPORT -u PGUSER \
 *     -u PGPASSWORD -u PGDATABASE APP_ENV=testing DB_CONNECTION=sqlite \
 *     DB_DATABASE=':memory:' php vendor/bin/phpunit tests/Feature/PosTaxReportPaymentFilterExportParityTest.php
 */
class PosTaxReportPaymentFilterExportParityTest extends TestCase
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
        $this->seedMixedMethods($this->companyId);

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
    }

    private function makeCompany(): int
    {
        return (int) DB::table('companies')->insertGetId([
            'name' => 'Payment Filter Parity Co',
            'product_type' => 'pos',
            'status' => 'active',
            // internal account → planAllows() short-circuits, CSV/PDF planGate passes
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

    private function makeTxn(int $companyId, string $number, ?string $method, float $amount): int
    {
        $id = (int) DB::table('pos_transactions')->insertGetId([
            'company_id' => $companyId,
            'invoice_number' => $number,
            'transaction_type' => 'sale',
            'status' => 'completed',
            'invoice_mode' => 'pra',
            'pra_status' => 'submitted',
            'pra_invoice_number' => 'PRA-' . $number,
            'subtotal' => $amount,
            'discount_amount' => 0,
            'tax_rate' => 17,
            'tax_amount' => round($amount * 0.17, 2),
            'total_amount' => round($amount * 1.17, 2),
            'payment_method' => $method,
            'created_at' => now(), 'updated_at' => now(),
        ]);

        DB::table('pos_transaction_items')->insert([
            'transaction_id' => $id,
            'item_name' => 'Item ' . $number,
            'quantity' => 1,
            'subtotal' => $amount,
            'tax_rate' => 17,
            'tax_amount' => round($amount * 0.17, 2),
            'created_at' => now(), 'updated_at' => now(),
        ]);

        return $id;
    }

    /**
     * The canonical mixed-method day: one bill per stored alias.
     * Debit Card filter MUST return exactly {P-DEBIT, P-LEGACY} — 2 rows.
     */
    private function seedMixedMethods(int $companyId): void
    {
        $this->makeTxn($companyId, 'P-CASH', 'cash', 100.00);
        // THE real stored value for card sales (universal screen normalizes).
        $this->makeTxn($companyId, 'P-DEBIT', 'debit_card', 200.00);
        // Legacy rows saved before normalization still say 'card'.
        $this->makeTxn($companyId, 'P-LEGACY', 'card', 50.00);
        $this->makeTxn($companyId, 'P-CREDIT', 'credit_card', 75.00);
        $this->makeTxn($companyId, 'P-QR', 'qr_payment', 60.00);
    }

    // ── surface helpers ──────────────────────────────────────────────────────

    private function screenData(array $query = []): array
    {
        $view = (new PosController())->taxReports(Request::create('/pos/tax-reports', 'GET', $query));

        return $view->getData();
    }

    private function csvExport(array $query = []): string
    {
        $response = (new PosController())->exportTaxReportCsv(
            Request::create('/pos/tax-reports/csv', 'GET', $query)
        );
        ob_start();
        $response->sendContent();

        return (string) ob_get_clean();
    }

    /** How many bill DATA rows the CSV holds (P-xxx invoice-number lines). */
    private function csvRowCount(string $csv): int
    {
        return count(array_filter(
            explode("\n", $csv),
            fn ($line) => str_starts_with($line, 'P-') || str_starts_with($line, '"P-')
        ));
    }

    private function pdfViewData(array $query = []): array
    {
        $controller = new PosController();
        $method = new \ReflectionMethod($controller, 'buildTaxReportPdfData');
        $method->setAccessible(true);
        [$data, $filename] = $method->invoke(
            $controller,
            Request::create('/pos/tax-reports/pdf', 'GET', $query)
        );
        $this->assertStringEndsWith('.pdf', $filename);

        return $data;
    }

    // ── 1. screen: debit_card filter includes legacy 'card' rows ────────────

    public function test_screen_debit_card_filter_includes_legacy_card_rows(): void
    {
        $data = $this->screenData(['payment_method' => 'debit_card']);

        $this->assertSame(2, $data['transactions']->total(), 'debit_card + legacy card');
        $numbers = collect($data['transactions']->items())->pluck('invoice_number')->sort()->values()->all();
        $this->assertSame(['P-DEBIT', 'P-LEGACY'], $numbers);
    }

    public function test_screen_other_methods_stay_exact_match(): void
    {
        // cash must never pull card rows in…
        $cash = $this->screenData(['payment_method' => 'cash']);
        $this->assertSame(1, $cash['transactions']->total());
        $this->assertSame('P-CASH', $cash['transactions']->first()->invoice_number);

        // …and credit_card stays its own bucket (only debit_card aliases 'card').
        $credit = $this->screenData(['payment_method' => 'credit_card']);
        $this->assertSame(1, $credit['transactions']->total());
        $this->assertSame('P-CREDIT', $credit['transactions']->first()->invoice_number);
    }

    // ── 2. CSV export: same rows as the screen for the same filter ──────────

    public function test_csv_export_debit_card_filter_matches_screen_rows(): void
    {
        $csv = $this->csvExport(['payment_method' => 'debit_card']);

        // Both the normalized and the legacy row are present…
        $this->assertStringContainsString('P-DEBIT', $csv);
        $this->assertStringContainsString('P-LEGACY', $csv, "legacy 'card' row must never be silently dropped from the CSV");
        // …no other method leaks in…
        $this->assertStringNotContainsString('P-CASH', $csv);
        $this->assertStringNotContainsString('P-CREDIT', $csv);
        $this->assertStringNotContainsString('P-QR', $csv);

        // …and the row COUNT equals the screen's for the same filter.
        $screenCount = $this->screenData(['payment_method' => 'debit_card'])['transactions']->total();
        $this->assertSame($screenCount, $this->csvRowCount($csv), 'CSV row count drifted from the on-screen list');

        // Summary totals cover BOTH rows: 200 + 50 = 250 subtotal.
        $this->assertStringContainsString('"Total Invoices",2', $csv);
        $this->assertStringContainsString('"Total Sales Amount (PKR)",292.50', $csv, '234.00 + 58.50');
    }

    public function test_csv_export_exact_match_methods_stay_isolated(): void
    {
        $csv = $this->csvExport(['payment_method' => 'credit_card']);

        $this->assertStringContainsString('P-CREDIT', $csv);
        $this->assertStringNotContainsString('P-LEGACY', $csv, "legacy 'card' belongs to Debit Card, not Credit Card");
        $this->assertStringNotContainsString('P-DEBIT', $csv);
        $this->assertSame(1, $this->csvRowCount($csv));
    }

    // ── 3. PDF view data: same rows + totals as the screen ──────────────────

    public function test_pdf_debit_card_filter_matches_screen_rows_and_totals(): void
    {
        $query = ['payment_method' => 'debit_card'];
        $pdf = $this->pdfViewData($query);
        $screen = $this->screenData($query);

        $this->assertCount(2, $pdf['transactions'], 'PDF must list debit_card + legacy card rows');
        $numbers = $pdf['transactions']->pluck('invoice_number')->sort()->values()->all();
        $this->assertSame(['P-DEBIT', 'P-LEGACY'], $numbers);
        $this->assertSame(
            $screen['transactions']->total(),
            $pdf['transactions']->count(),
            'PDF row count drifted from the on-screen list'
        );

        // Summary figures cover BOTH rows and match the screen exactly.
        foreach (['total_invoices', 'total_sales', 'total_tax', 'total_taxable'] as $field) {
            $this->assertEqualsWithDelta(
                (float) ($screen['summary']->{$field} ?? 0),
                (float) ($pdf['summary']->{$field} ?? 0),
                0.001,
                "PDF summary '{$field}' drifted from the screen for the Debit Card filter"
            );
        }
        $this->assertSame(2, (int) $pdf['summary']->total_invoices);
        $this->assertSame(292.5, (float) $pdf['summary']->total_sales, '234.00 + 58.50 — legacy row included');
    }

    public function test_pdf_exact_match_methods_stay_isolated(): void
    {
        $pdf = $this->pdfViewData(['payment_method' => 'cash']);

        $this->assertCount(1, $pdf['transactions']);
        $this->assertSame('P-CASH', $pdf['transactions']->first()->invoice_number);
        $this->assertSame(117.0, (float) $pdf['summary']->total_sales);
    }
}

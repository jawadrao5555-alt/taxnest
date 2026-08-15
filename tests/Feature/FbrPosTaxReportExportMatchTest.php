<?php

namespace Tests\Feature;

use App\Http\Controllers\FbrPosController;
use App\Services\PosFeatureService;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

/**
 * FBR TAX REPORT EXPORT DATA-MATCH LOCK (Task 701).
 *
 * Task 698 wired the FBR tax-report screen AND its CSV/PDF exports through ONE
 * shared query helper (FbrPosController::fbrTaxReportData) so downloads always
 * match the on-screen netted figures. This test seeds a returns-heavy month
 * (sales + transaction_type='return' credit notes, two tax rates, mixed FBR
 * statuses) and proves end-to-end, for bill_type = '' / 'sales' / 'returns':
 *   - The CSV summary lines equal the taxReports view data EXACTLY
 *     (monthly summary, credit-note lines, submission status, rate-wise rows).
 *   - Signed netting invariants hold: returns subtract in the all-bills view,
 *     invoice counts stay sales-only, and the credit-notes-only view stays
 *     POSITIVE (refunded amounts) — guarding future fbrReturnNettingExprs edits.
 *
 * Pattern: sqlite :memory: + minimal Schema::create in setUp (mirrors
 * PosTaxReportCreditNoteNettingTest). Controller invoked directly — the
 * FbrPlanGate null-user convention lets CLI/test invocations pass the
 * reports_enabled gate, same as production web routes behind FbrPosAuth.
 *
 * Run:
 *   env -u DATABASE_URL -u DB_CONNECTION -u PGHOST -u PGPORT -u PGUSER \
 *     -u PGPASSWORD -u PGDATABASE APP_ENV=testing DB_CONNECTION=sqlite \
 *     DB_DATABASE=':memory:' php vendor/bin/phpunit tests/Feature/FbrPosTaxReportExportMatchTest.php
 */
class FbrPosTaxReportExportMatchTest extends TestCase
{
    private int $companyId;

    protected function setUp(): void
    {
        parent::setUp();
        // Month boundary safety: fbrTaxReportData filters on now()->year/month;
        // freeze mid-month so seeds created "now" can never straddle a month
        // rollover mid-test.
        \Carbon\Carbon::setTestNow(now()->day(15)->setTime(12, 0));
        PosFeatureService::flushGateCaches();
        Schema::dropAllTables();
        $this->buildSchema();
        $this->companyId = (int) DB::table('companies')->insertGetId([
            'name' => 'FBR Export Match Co',
            'product_type' => 'fbrpos',
            'status' => 'active',
            'created_at' => now(), 'updated_at' => now(),
        ]);
        $this->seedReturnsMonth();
        app()->instance('currentCompanyId', $this->companyId);
    }

    protected function tearDown(): void
    {
        \Carbon\Carbon::setTestNow();
        PosFeatureService::flushGateCaches();
        parent::tearDown();
    }

    private function buildSchema(): void
    {
        Schema::create('companies', function (Blueprint $t) {
            $t->id();
            $t->string('name');
            $t->string('product_type')->nullable();
            $t->string('status')->nullable();
            $t->boolean('is_internal_account')->default(false);
            $t->softDeletes();
            $t->timestamps();
        });

        Schema::create('fbr_pos_transactions', function (Blueprint $t) {
            $t->id();
            $t->unsignedBigInteger('company_id');
            $t->string('invoice_number');
            $t->string('transaction_type')->nullable()->default('sale');
            $t->unsignedBigInteger('parent_transaction_id')->nullable();
            $t->string('status')->nullable();
            $t->string('invoice_mode')->nullable();
            $t->string('fbr_status')->nullable();
            $t->decimal('subtotal', 12, 2)->default(0);
            $t->decimal('tax_rate', 8, 2)->nullable();
            $t->decimal('tax_amount', 12, 2)->default(0);
            $t->decimal('fbr_service_charge', 12, 2)->default(0);
            $t->decimal('total_amount', 12, 2)->default(0);
            $t->timestamps();
        });
    }

    /**
     * Returns-heavy current month, two tax rates, mixed FBR statuses:
     *   18% — Sale S1 1000/180 (submitted, fee 1), Sale S2 500/90 (pending, fee 1),
     *         Return R1 POSITIVE 200/36 off S1 (submitted).
     *   16% — Sale S3 800/128 (failed), Return R2 POSITIVE 300/48 off S3 (local).
     * Netted (all bills): sales 1800, tax 314, fee 2, invoices 3,
     *   credit notes 2 / refunded 584 / tax reversed 84.
     * Sales-only: sales 2300, tax 398. Returns-only (POSITIVE): sales 500, tax 84.
     */
    private function seedReturnsMonth(): void
    {
        $row = fn (string $num, array $attrs) => array_merge([
            'company_id' => $this->companyId,
            'invoice_number' => $num,
            'transaction_type' => 'sale',
            'status' => 'completed',
            'invoice_mode' => 'fbr',
            'fbr_status' => 'submitted',
            'fbr_service_charge' => 0,
            'created_at' => now(), 'updated_at' => now(),
        ], $attrs);

        $s1 = DB::table('fbr_pos_transactions')->insertGetId(
            $row('FX-0001', ['subtotal' => 1000, 'tax_rate' => 18, 'tax_amount' => 180, 'fbr_service_charge' => 1, 'total_amount' => 1181])
        );
        // Row-by-row inserts: sqlite multi-row insert demands identical key
        // sets, and these rows intentionally vary (parent_transaction_id etc.).
        DB::table('fbr_pos_transactions')->insert($row('FX-0002', ['subtotal' => 500, 'tax_rate' => 18, 'tax_amount' => 90, 'fbr_service_charge' => 1, 'fbr_status' => 'pending', 'total_amount' => 591]));
        DB::table('fbr_pos_transactions')->insert($row('FX-0003', ['subtotal' => 800, 'tax_rate' => 16, 'tax_amount' => 128, 'fbr_status' => 'failed', 'total_amount' => 928]));
        DB::table('fbr_pos_transactions')->insert($row('FXRET-0001', ['transaction_type' => 'return', 'parent_transaction_id' => $s1,
            'subtotal' => 200, 'tax_rate' => 18, 'tax_amount' => 36, 'total_amount' => 236]));
        DB::table('fbr_pos_transactions')->insert($row('FXRET-0002', ['transaction_type' => 'return',
            'subtotal' => 300, 'tax_rate' => 16, 'tax_amount' => 48, 'fbr_status' => 'local', 'total_amount' => 348]));
    }

    // ─────────────────────────────────────────────────────────────────────────
    // Helpers: screen data + CSV capture
    // ─────────────────────────────────────────────────────────────────────────

    private function screenData(array $query = []): array
    {
        return (new FbrPosController())
            ->taxReports(Request::create('/fbr-pos/tax-reports', 'GET', $query))
            ->getData();
    }

    private function csvExport(array $query = []): string
    {
        $response = (new FbrPosController())->exportTaxReportCsv(
            Request::create('/fbr-pos/tax-reports/csv', 'GET', $query)
        );
        ob_start();
        $response->sendContent();

        return (string) ob_get_clean();
    }

    /**
     * Core data-match assertion: every summary/status/rate line the CSV prints
     * must equal the taxReports view data for the SAME filters — formatted
     * exactly the way exportTaxReportCsv writes it.
     */
    private function assertCsvMatchesScreen(array $query): void
    {
        $data = $this->screenData($query);
        $csv = $this->csvExport($query);
        $ctx = 'filters ' . json_encode($query);

        $monthly = $data['monthlyTax'];
        $fbrStats = $data['fbrStats'];
        $money = fn ($v) => number_format((float) ($v ?? 0), 2, '.', '');

        $this->assertTrue($data['billTypeReady'], "transaction_type column must be live — {$ctx}");

        $expectedLines = [
            '"Total Sales excl. Tax (PKR)",' . $money($monthly->total_sales),
            '"Total Tax Collected (PKR)",' . $money($monthly->total_tax),
            '"FBR POS Fee Collected (PKR)",' . $money($monthly->total_pos_fee),
            '"Total Invoices",' . (int) $monthly->invoice_count,
            '"Credit Notes (count)",' . (int) $monthly->return_count,
            '"Credit Notes Refunded (PKR)",' . $money($monthly->return_amount),
            '"Credit Notes Tax Reversed (PKR)",' . $money($monthly->return_tax),
            'Submitted,' . (int) $fbrStats->submitted,
            'Pending,' . (int) $fbrStats->pending,
            'Failed,' . (int) $fbrStats->failed,
            '"Local (Offline)",' . (int) $fbrStats->local_count,
        ];
        foreach ($expectedLines as $line) {
            $this->assertStringContainsString($line . "\n", $csv, "CSV line drifted from screen data ({$ctx}): {$line}");
        }

        // Rate-wise breakdown rows, one per tax_rate, same signed figures.
        foreach ($data['taxByRate'] as $rate) {
            $rateLine = $money($rate->tax_rate) . ',' . (int) $rate->count . ','
                . $money($rate->sales_total) . ',' . $money($rate->tax_total);
            $this->assertStringContainsString($rateLine . "\n", $csv, "CSV rate row drifted from screen data ({$ctx}): {$rateLine}");
        }
    }

    // ── 1. All bills: CSV == screen, and the netting is actually signed ─────

    public function test_all_bills_csv_matches_screen_with_signed_netting(): void
    {
        $this->assertCsvMatchesScreen([]);

        // Absolute lock — parity alone would pass if BOTH paths broke together.
        $monthly = $this->screenData()['monthlyTax'];
        $this->assertSame(3, (int) $monthly->invoice_count, 'credit notes must not count as bills');
        $this->assertSame(1800.0, (float) $monthly->total_sales, '1000+500+800 − 200 − 300');
        $this->assertSame(314.0, (float) $monthly->total_tax, '180+90+128 − 36 − 48');
        $this->assertSame(2.0, (float) $monthly->total_pos_fee, 'returns carry no fee here');
        $this->assertSame(2, (int) $monthly->return_count);
        $this->assertSame(584.0, (float) $monthly->return_amount, '236 + 348 refunded');
        $this->assertSame(84.0, (float) $monthly->return_tax, '36 + 48 reversed');

        $rates = collect($this->screenData()['taxByRate'])->keyBy(fn ($r) => (float) $r->tax_rate);
        $this->assertSame(1300.0, (float) $rates[18.0]->sales_total, '1000+500 − 200');
        $this->assertSame(234.0, (float) $rates[18.0]->tax_total, '180+90 − 36');
        $this->assertSame(2, (int) $rates[18.0]->count);
        $this->assertSame(1, (int) $rates[18.0]->return_count);
        $this->assertSame(500.0, (float) $rates[16.0]->sales_total, '800 − 300');
        $this->assertSame(80.0, (float) $rates[16.0]->tax_total, '128 − 48');
        $this->assertSame(1, (int) $rates[16.0]->count);

        $this->assertStringContainsString('"Bill Type","All Bills"', $this->csvExport());
    }

    // ── 2. Sales only: CSV == screen, returns fully excluded ────────────────

    public function test_sales_only_csv_matches_screen_and_excludes_returns(): void
    {
        $this->assertCsvMatchesScreen(['bill_type' => 'sales']);

        $data = $this->screenData(['bill_type' => 'sales']);
        $monthly = $data['monthlyTax'];
        $this->assertSame('sales', $data['billTypeFilter']);
        $this->assertSame(3, (int) $monthly->invoice_count);
        $this->assertSame(2300.0, (float) $monthly->total_sales, 'gross sales, nothing netted');
        $this->assertSame(398.0, (float) $monthly->total_tax);
        $this->assertSame(0, (int) $monthly->return_count);
        $this->assertSame(0.0, (float) $monthly->return_amount);

        $csv = $this->csvExport(['bill_type' => 'sales']);
        $this->assertStringContainsString('"Bill Type","Sales Only"', $csv);
        $this->assertStringContainsString('"Credit Notes (count)",0', $csv);
    }

    // ── 3. Credit notes only: CSV == screen, figures stay POSITIVE ──────────

    public function test_returns_only_csv_matches_screen_and_stays_positive(): void
    {
        $this->assertCsvMatchesScreen(['bill_type' => 'returns']);

        $data = $this->screenData(['bill_type' => 'returns']);
        $monthly = $data['monthlyTax'];
        $this->assertSame('returns', $data['billTypeFilter']);
        $this->assertSame(2, (int) $monthly->invoice_count, 'credit-notes-only counts the notes themselves');
        $this->assertSame(500.0, (float) $monthly->total_sales, '200 + 300 refunded base — POSITIVE');
        $this->assertSame(84.0, (float) $monthly->total_tax, '36 + 48 — POSITIVE');
        $this->assertSame(2, (int) $monthly->return_count);

        $rates = collect($data['taxByRate'])->keyBy(fn ($r) => (float) $r->tax_rate);
        $this->assertSame(200.0, (float) $rates[18.0]->sales_total, 'rate-wise stays POSITIVE too');
        $this->assertSame(300.0, (float) $rates[16.0]->sales_total);

        $csv = $this->csvExport(['bill_type' => 'returns']);
        $this->assertStringContainsString('Credit Notes Only', $csv);
        $this->assertStringNotContainsString('-200.00', $csv, 'no negative figures in the refund view');

        // Submission-status boxes are filter-scoped: R1 submitted + R2 local.
        $fbrStats = $data['fbrStats'];
        $this->assertSame(1, (int) $fbrStats->submitted);
        $this->assertSame(1, (int) $fbrStats->local_count);
        $this->assertSame(0, (int) $fbrStats->pending);
    }

    // ── 4. Invalid bill_type falls back to All Bills (same CSV) ─────────────

    public function test_invalid_bill_type_falls_back_to_all_bills(): void
    {
        $data = $this->screenData(['bill_type' => 'bogus']);
        $this->assertSame('', $data['billTypeFilter']);
        $this->assertSame(1800.0, (float) $data['monthlyTax']->total_sales);
        $this->assertCsvMatchesScreen(['bill_type' => 'bogus']);
    }
}

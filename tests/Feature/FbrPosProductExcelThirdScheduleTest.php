<?php

namespace Tests\Feature;

use Tests\TestCase;
use App\Models\Company;
use App\Models\Product;
use App\Http\Controllers\FbrPosController;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Schema;

/**
 * FBR POS product Excel export/import round-trip (Task: FBR mirror of the PRA
 * bulk product Excel feature) — incl. the Third Schedule (Yes/No) column.
 *
 * Proves that:
 *   A. Export/template includes "Third Schedule (Yes/No)" with correct values,
 *      and barcodes/SKUs survive as strings.
 *   B. Import reads the column and sets is_third_schedule; Third Schedule implies
 *      tax_type=exempt and default_tax_rate=0.
 *   C. Full round-trip export → import preserves the flag, and a blank cell
 *      leaves an existing product's flag untouched.
 *
 * Pattern: APP_ENV=testing + sqlite :memory: + minimal Schema::create
 * (mirrors PosProductExcelThirdScheduleTest).
 */
class FbrPosProductExcelThirdScheduleTest extends TestCase
{
    protected int $companyId;

    protected function setUp(): void
    {
        parent::setUp();

        Schema::dropAllTables();

        Schema::create('companies', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->softDeletes();
            $table->timestamps();
        });

        Schema::create('products', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('company_id');
            $table->string('name');
            $table->decimal('default_price', 12, 2)->default(0);
            $table->decimal('default_tax_rate', 8, 2)->default(0);
            $table->string('tax_type')->default('taxable');
            $table->decimal('mrp', 12, 2)->nullable(); // Task 1276: required for Third Schedule rows
            $table->boolean('is_third_schedule')->default(false); // The column under test
            $table->boolean('is_active')->default(true);
            $table->boolean('show_on_sale')->default(true);
            $table->boolean('is_price_editable')->default(false);
            $table->string('hs_code')->nullable();
            $table->string('sku')->nullable();
            $table->string('barcode')->nullable();
            $table->string('uom')->nullable();
            $table->timestamps();
        });

        $company = Company::create(['name' => 'FBR Excel Third Schedule Shop']);
        $this->companyId = $company->id;

        app()->bind('currentCompanyId', fn () => $this->companyId);
    }

    // ─────────────────────────────────────────────────────────────────────────
    // Helpers
    // ─────────────────────────────────────────────────────────────────────────

    /** Run downloadProductTemplate() and return the streamed .xlsx bytes. */
    private function exportXlsxBytes(): string
    {
        $response = (new FbrPosController())->downloadProductTemplate();
        ob_start();
        $response->sendContent();
        return (string) ob_get_clean();
    }

    /** Load xlsx bytes into a plain rows array (row 0 = header). */
    private function xlsxRows(string $bytes): array
    {
        $tmp = tempnam(sys_get_temp_dir(), 'ftx') . '.xlsx';
        file_put_contents($tmp, $bytes);
        try {
            $spreadsheet = \PhpOffice\PhpSpreadsheet\IOFactory::load($tmp);
            return $spreadsheet->getActiveSheet()->toArray(null, true, false, false);
        } finally {
            @unlink($tmp);
        }
    }

    /** Build an .xlsx upload from header + rows and run importProducts(). */
    private function importRows(array $header, array $rows): void
    {
        $spreadsheet = new \PhpOffice\PhpSpreadsheet\Spreadsheet();
        $sheet = $spreadsheet->getActiveSheet();
        $sheet->fromArray($header, null, 'A1');
        $r = 2;
        foreach ($rows as $row) {
            $sheet->fromArray($row, null, 'A' . $r++);
        }
        $tmp = tempnam(sys_get_temp_dir(), 'fti') . '.xlsx';
        (new \PhpOffice\PhpSpreadsheet\Writer\Xlsx($spreadsheet))->save($tmp);

        $this->importFile($tmp);
    }

    /** Run importProducts() against an existing .xlsx file on disk. */
    private function importFile(string $path): void
    {
        $upload = new UploadedFile($path, 'products.xlsx', 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet', null, true);

        $req = Request::create('/fbr-pos/products/import', 'POST');
        $req->files->set('csv_file', $upload);
        $req->setLaravelSession(app('session.store'));
        app()->instance('request', $req);

        (new FbrPosController())->importProducts($req);
    }

    // ─────────────────────────────────────────────────────────────────────────
    // Test A — export includes the column with correct values
    // ─────────────────────────────────────────────────────────────────────────

    public function test_export_includes_third_schedule_column(): void
    {
        Product::create([
            'company_id' => $this->companyId, 'name' => 'Milk Pack 1L', 'default_price' => 320,
            'default_tax_rate' => 0, 'tax_type' => 'exempt', 'is_third_schedule' => true,
            'barcode' => '8964000112345', 'mrp' => 340,
        ]);
        Product::create([
            'company_id' => $this->companyId, 'name' => 'Rooh Afza', 'default_price' => 550,
            'default_tax_rate' => 18, 'tax_type' => 'taxable', 'is_third_schedule' => false,
        ]);

        $rows = $this->xlsxRows($this->exportXlsxBytes());

        $this->assertSame('Third Schedule (Yes/No)', $rows[0][8], 'Column I header must be the Third Schedule flag');
        $this->assertSame('MRP (Retail Price)', $rows[0][9], 'Column J header must be MRP (Task 1276)');

        $byName = [];
        foreach (array_slice($rows, 1) as $row) {
            $byName[$row[0]] = $row;
        }
        $this->assertSame('Yes', $byName['Milk Pack 1L'][8]);
        $this->assertSame('No', $byName['Rooh Afza'][8]);
        // MRP exported for the Third Schedule product, blank when unset.
        $this->assertEquals(340.0, (float) $byName['Milk Pack 1L'][9]);
        $this->assertSame('', (string) $byName['Rooh Afza'][9]);
        // Barcode must survive as a plain digit STRING (never 8.964E+12).
        $this->assertSame('8964000112345', (string) $byName['Milk Pack 1L'][4]);
    }

    public function test_blank_template_has_third_schedule_header(): void
    {
        // No products → sample template. Header must still carry the columns.
        $rows = $this->xlsxRows($this->exportXlsxBytes());
        $this->assertSame('Third Schedule (Yes/No)', $rows[0][8]);
        $this->assertSame('MRP (Retail Price)', $rows[0][9]);
    }

    // ─────────────────────────────────────────────────────────────────────────
    // Test B — import sets the flag and enforces third-schedule ⇒ exempt/0-tax
    // ─────────────────────────────────────────────────────────────────────────

    public function test_import_sets_flag_and_forces_exempt_zero_tax(): void
    {
        $header = ['Name', 'Price', 'HS Code', 'SKU', 'Barcode', 'Tax Rate %', 'Unit (UOM)', 'Tax Exempt (Yes/No)', 'Third Schedule (Yes/No)', 'MRP (Retail Price)'];
        $this->importRows($header, [
            // Third Schedule Yes (needs MRP since Task 1276), even with a
            // non-zero tax rate written → flag on, exempt, 0 tax
            ['Surf 1kg', 350, '3402.2000', 'SRF-1', '', 18, 'KG', 'No', 'Yes', 370],
            // Plain taxable product stays untouched by the new columns
            ['Basmati Rice', 400, '1006.3010', 'RIC-1', '', 18, 'KG', 'No', 'No', ''],
            // Custom-rate product
            ['Juice Pack', 150, '', 'JCE-1', '', 5, 'U', 'No', 'No', ''],
        ]);

        $surf = Product::where('company_id', $this->companyId)->where('name', 'Surf 1kg')->first();
        $this->assertNotNull($surf);
        $this->assertEquals(1, (int) $surf->is_third_schedule);
        $this->assertSame('exempt', $surf->tax_type, 'Third Schedule must imply tax_type exempt');
        $this->assertEquals(0.0, (float) $surf->default_tax_rate, 'Third Schedule must imply 0 tax');
        $this->assertEquals(370.0, (float) $surf->mrp, 'MRP column must persist on import');

        $rice = Product::where('company_id', $this->companyId)->where('name', 'Basmati Rice')->first();
        $this->assertEquals(0, (int) $rice->is_third_schedule);
        $this->assertSame('taxable', $rice->tax_type);
        $this->assertEquals(18.0, (float) $rice->default_tax_rate);
        // HS code typed into a NUMERIC Excel cell: decimals survive (never
        // truncated to "1006"); the trailing zero is lost by Excel itself, which
        // is why real exports write HS codes as explicit strings (see round-trip test).
        $this->assertSame('1006.301', (string) $rice->hs_code);

        $juice = Product::where('company_id', $this->companyId)->where('name', 'Juice Pack')->first();
        $this->assertSame('custom', $juice->tax_type);
        $this->assertEquals(5.0, (float) $juice->default_tax_rate);
    }

    public function test_import_blank_cell_leaves_existing_flag_untouched(): void
    {
        Product::create([
            'company_id' => $this->companyId, 'name' => 'Ghee 1kg', 'default_price' => 900,
            'sku' => 'GHE-1', 'default_tax_rate' => 0, 'tax_type' => 'exempt', 'is_third_schedule' => true,
        ]);

        $header = ['Name', 'Price', 'HS Code', 'SKU', 'Barcode', 'Tax Rate %', 'Unit (UOM)', 'Tax Exempt (Yes/No)', 'Third Schedule (Yes/No)'];
        $this->importRows($header, [
            ['Ghee 1kg', 950, '', 'GHE-1', '', '', '', '', ''], // price change only, TS cell blank
        ]);

        $ghee = Product::where('company_id', $this->companyId)->where('sku', 'GHE-1')->first();
        $this->assertEquals(950.0, (float) $ghee->default_price);
        $this->assertEquals(1, (int) $ghee->is_third_schedule, 'Blank Third Schedule cell must not clear the flag');
        $this->assertSame('exempt', $ghee->tax_type, 'Blank tax cells must not change tax_type');
    }

    public function test_import_explicit_no_clears_existing_flag(): void
    {
        Product::create([
            'company_id' => $this->companyId, 'name' => 'Tea Pack', 'default_price' => 600,
            'sku' => 'TEA-1', 'default_tax_rate' => 0, 'tax_type' => 'exempt', 'is_third_schedule' => true,
        ]);

        $header = ['Name', 'Price', 'HS Code', 'SKU', 'Barcode', 'Tax Rate %', 'Unit (UOM)', 'Tax Exempt (Yes/No)', 'Third Schedule (Yes/No)'];
        $this->importRows($header, [
            ['Tea Pack', 600, '', 'TEA-1', '', 17, '', 'No', 'No'],
        ]);

        $tea = Product::where('company_id', $this->companyId)->where('sku', 'TEA-1')->first();
        $this->assertEquals(0, (int) $tea->is_third_schedule);
        $this->assertSame('custom', $tea->tax_type);
        $this->assertEquals(17.0, (float) $tea->default_tax_rate);
    }

    // ─────────────────────────────────────────────────────────────────────────
    // Test C — full round-trip preserves the flag
    // ─────────────────────────────────────────────────────────────────────────

    public function test_round_trip_export_import_preserves_flag(): void
    {
        Product::create([
            'company_id' => $this->companyId, 'name' => 'Milk Pack 1L', 'default_price' => 320,
            'sku' => 'MLK-1', 'barcode' => '8964000112345', 'mrp' => 340,
            'default_tax_rate' => 0, 'tax_type' => 'exempt', 'is_third_schedule' => true,
        ]);
        Product::create([
            'company_id' => $this->companyId, 'name' => 'Rooh Afza', 'default_price' => 550,
            'sku' => 'RAF-1', 'hs_code' => '2106.9090',
            'default_tax_rate' => 18, 'tax_type' => 'taxable', 'is_third_schedule' => false,
        ]);

        $bytes = $this->exportXlsxBytes();

        // Wipe the catalog, then re-import the exported file untouched.
        Product::where('company_id', $this->companyId)->delete();

        $tmp = tempnam(sys_get_temp_dir(), 'ftr') . '.xlsx';
        file_put_contents($tmp, $bytes);
        $this->importFile($tmp);

        $milk = Product::where('company_id', $this->companyId)->where('sku', 'MLK-1')->first();
        $rooh = Product::where('company_id', $this->companyId)->where('sku', 'RAF-1')->first();
        $this->assertNotNull($milk);
        $this->assertNotNull($rooh);
        $this->assertEquals(1, (int) $milk->is_third_schedule, 'Round-trip must preserve Yes');
        $this->assertSame('exempt', $milk->tax_type);
        $this->assertEquals(0.0, (float) $milk->default_tax_rate);
        $this->assertEquals(340.0, (float) $milk->mrp, 'Round-trip must preserve a positive MRP (Task 1276)');
        $this->assertSame('8964000112345', (string) $milk->barcode, 'Barcode must round-trip as a string');
        $this->assertEquals(0, (int) $rooh->is_third_schedule, 'Round-trip must preserve No');
        $this->assertSame('taxable', $rooh->tax_type);
        $this->assertEquals(18.0, (float) $rooh->default_tax_rate);
        $this->assertSame('2106.9090', (string) $rooh->hs_code, 'HS code must round-trip intact');
    }
}

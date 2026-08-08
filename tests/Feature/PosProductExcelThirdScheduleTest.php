<?php

namespace Tests\Feature;

use Tests\TestCase;
use App\Models\Company;
use App\Models\PosProduct;
use App\Http\Controllers\PosController;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Schema;

/**
 * Third Schedule flag in the product Excel export/import round-trip (Task: bulk audits).
 *
 * Proves that:
 *   A. Export/template includes a "Third Schedule (Yes/No)" column with correct Yes/No values.
 *   B. Import reads the column and sets is_third_schedule; Third Schedule implies
 *      is_tax_exempt=true and tax_rate=0.
 *   C. Full round-trip export → import preserves the flag (both directions), and a
 *      blank cell leaves an existing product's flag untouched.
 *
 * Pattern: APP_ENV=testing + sqlite :memory: + minimal Schema::create (see
 * PosThirdScheduleBillingTest).
 */
class PosProductExcelThirdScheduleTest extends TestCase
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

        Schema::create('pos_products', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('company_id');
            $table->string('name');
            $table->decimal('price', 12, 2)->default(0);
            $table->decimal('tax_rate', 8, 2)->default(0);
            $table->boolean('is_tax_exempt')->default(false);
            $table->boolean('is_third_schedule')->default(false); // The column under test
            $table->boolean('is_active')->default(true);
            $table->boolean('show_on_sale')->default(true);
            $table->string('description')->nullable();
            $table->string('category')->nullable();
            $table->string('sku')->nullable();
            $table->string('barcode')->nullable();
            $table->string('uom')->nullable();
            $table->integer('stock_quantity')->nullable();
            $table->timestamps();
        });

        $company = Company::create(['name' => 'Excel Third Schedule Shop']);
        $this->companyId = $company->id;

        app()->bind('currentCompanyId', fn () => $this->companyId);
    }

    // ─────────────────────────────────────────────────────────────────────────
    // Helpers
    // ─────────────────────────────────────────────────────────────────────────

    /** Run downloadProductTemplate() and return the streamed .xlsx bytes. */
    private function exportXlsxBytes(): string
    {
        $response = (new PosController())->downloadProductTemplate();
        ob_start();
        $response->sendContent();
        return (string) ob_get_clean();
    }

    /** Load xlsx bytes into a plain rows array (row 0 = header). */
    private function xlsxRows(string $bytes): array
    {
        $tmp = tempnam(sys_get_temp_dir(), 'tsx') . '.xlsx';
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
        $tmp = tempnam(sys_get_temp_dir(), 'tsi') . '.xlsx';
        (new \PhpOffice\PhpSpreadsheet\Writer\Xlsx($spreadsheet))->save($tmp);

        $this->importFile($tmp);
    }

    /** Run importProducts() against an existing .xlsx file on disk. */
    private function importFile(string $path): void
    {
        $upload = new UploadedFile($path, 'products.xlsx', 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet', null, true);

        $req = Request::create('/pos/products/import', 'POST');
        $req->files->set('csv_file', $upload);
        $req->setLaravelSession(app('session.store'));
        app()->instance('request', $req);

        (new PosController())->importProducts($req);
    }

    // ─────────────────────────────────────────────────────────────────────────
    // Test A — export includes the column with correct values
    // ─────────────────────────────────────────────────────────────────────────

    public function test_export_includes_third_schedule_column(): void
    {
        PosProduct::create([
            'company_id' => $this->companyId, 'name' => 'Milk Pack 1L', 'price' => 320,
            'tax_rate' => 0, 'is_tax_exempt' => true, 'is_third_schedule' => true,
        ]);
        PosProduct::create([
            'company_id' => $this->companyId, 'name' => 'Rooh Afza', 'price' => 550,
            'tax_rate' => 16, 'is_tax_exempt' => false, 'is_third_schedule' => false,
        ]);

        $rows = $this->xlsxRows($this->exportXlsxBytes());

        $this->assertSame('Third Schedule (Yes/No)', $rows[0][9], 'Column J header must be the Third Schedule flag');

        $byName = [];
        foreach (array_slice($rows, 1) as $row) {
            $byName[$row[0]] = $row;
        }
        $this->assertSame('Yes', $byName['Milk Pack 1L'][9]);
        $this->assertSame('No', $byName['Rooh Afza'][9]);
    }

    public function test_blank_template_has_third_schedule_header(): void
    {
        // No products → sample template. Header must still carry the column.
        $rows = $this->xlsxRows($this->exportXlsxBytes());
        $this->assertSame('Third Schedule (Yes/No)', $rows[0][9]);
    }

    // ─────────────────────────────────────────────────────────────────────────
    // Test B — import sets the flag and enforces third-schedule ⇒ exempt/0-tax
    // ─────────────────────────────────────────────────────────────────────────

    public function test_import_sets_flag_and_forces_exempt_zero_tax(): void
    {
        $header = ['Name', 'Price', 'Description', 'Category', 'SKU', 'Barcode', 'Tax Rate %', 'Unit (UOM)', 'Tax Exempt (Yes/No)', 'Third Schedule (Yes/No)'];
        $this->importRows($header, [
            // Third Schedule Yes, even with a non-zero tax rate written → flag on, exempt, 0 tax
            ['Sugar 1kg', 180, '', '', 'SUG-1', '', 18, 'KG', 'No', 'Yes'],
            // Plain taxable product stays untouched by the new column
            ['Basmati Rice', 400, '', '', 'RIC-1', '', 16, 'KG', 'No', 'No'],
        ]);

        $sugar = PosProduct::where('company_id', $this->companyId)->where('name', 'Sugar 1kg')->first();
        $this->assertNotNull($sugar);
        $this->assertEquals(1, (int) $sugar->is_third_schedule);
        $this->assertEquals(1, (int) $sugar->is_tax_exempt, 'Third Schedule must imply tax exempt');
        $this->assertEquals(0.0, (float) $sugar->tax_rate, 'Third Schedule must imply tax_rate 0');

        $rice = PosProduct::where('company_id', $this->companyId)->where('name', 'Basmati Rice')->first();
        $this->assertEquals(0, (int) $rice->is_third_schedule);
        $this->assertEquals(0, (int) $rice->is_tax_exempt);
        $this->assertEquals(16.0, (float) $rice->tax_rate);
    }

    public function test_import_blank_cell_leaves_existing_flag_untouched(): void
    {
        PosProduct::create([
            'company_id' => $this->companyId, 'name' => 'Ghee 1kg', 'price' => 900,
            'sku' => 'GHE-1', 'tax_rate' => 0, 'is_tax_exempt' => true, 'is_third_schedule' => true,
        ]);

        $header = ['Name', 'Price', 'Description', 'Category', 'SKU', 'Barcode', 'Tax Rate %', 'Unit (UOM)', 'Tax Exempt (Yes/No)', 'Third Schedule (Yes/No)'];
        $this->importRows($header, [
            ['Ghee 1kg', 950, '', '', 'GHE-1', '', '', '', '', ''], // price change only, TS cell blank
        ]);

        $ghee = PosProduct::where('company_id', $this->companyId)->where('sku', 'GHE-1')->first();
        $this->assertEquals(950.0, (float) $ghee->price);
        $this->assertEquals(1, (int) $ghee->is_third_schedule, 'Blank Third Schedule cell must not clear the flag');
    }

    public function test_import_explicit_no_clears_existing_flag(): void
    {
        PosProduct::create([
            'company_id' => $this->companyId, 'name' => 'Tea Pack', 'price' => 600,
            'sku' => 'TEA-1', 'tax_rate' => 0, 'is_tax_exempt' => true, 'is_third_schedule' => true,
        ]);

        $header = ['Name', 'Price', 'Description', 'Category', 'SKU', 'Barcode', 'Tax Rate %', 'Unit (UOM)', 'Tax Exempt (Yes/No)', 'Third Schedule (Yes/No)'];
        $this->importRows($header, [
            ['Tea Pack', 600, '', '', 'TEA-1', '', 17, '', 'No', 'No'],
        ]);

        $tea = PosProduct::where('company_id', $this->companyId)->where('sku', 'TEA-1')->first();
        $this->assertEquals(0, (int) $tea->is_third_schedule);
        $this->assertEquals(0, (int) $tea->is_tax_exempt);
        $this->assertEquals(17.0, (float) $tea->tax_rate);
    }

    // ─────────────────────────────────────────────────────────────────────────
    // Test C — full round-trip preserves the flag
    // ─────────────────────────────────────────────────────────────────────────

    public function test_round_trip_export_import_preserves_flag(): void
    {
        PosProduct::create([
            'company_id' => $this->companyId, 'name' => 'Milk Pack 1L', 'price' => 320,
            'sku' => 'MLK-1', 'tax_rate' => 0, 'is_tax_exempt' => true, 'is_third_schedule' => true,
        ]);
        PosProduct::create([
            'company_id' => $this->companyId, 'name' => 'Rooh Afza', 'price' => 550,
            'sku' => 'RAF-1', 'tax_rate' => 16, 'is_tax_exempt' => false, 'is_third_schedule' => false,
        ]);

        $bytes = $this->exportXlsxBytes();

        // Wipe the catalog, then re-import the exported file untouched.
        PosProduct::where('company_id', $this->companyId)->delete();

        $tmp = tempnam(sys_get_temp_dir(), 'tsr') . '.xlsx';
        file_put_contents($tmp, $bytes);
        $this->importFile($tmp);

        $milk = PosProduct::where('company_id', $this->companyId)->where('sku', 'MLK-1')->first();
        $rooh = PosProduct::where('company_id', $this->companyId)->where('sku', 'RAF-1')->first();
        $this->assertNotNull($milk);
        $this->assertNotNull($rooh);
        $this->assertEquals(1, (int) $milk->is_third_schedule, 'Round-trip must preserve Yes');
        $this->assertEquals(1, (int) $milk->is_tax_exempt);
        $this->assertEquals(0.0, (float) $milk->tax_rate);
        $this->assertEquals(0, (int) $rooh->is_third_schedule, 'Round-trip must preserve No');
        $this->assertEquals(16.0, (float) $rooh->tax_rate);
    }
}

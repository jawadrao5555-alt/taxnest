<?php

namespace Tests\Feature;

use App\Http\Controllers\PosInventoryMasterController;
use App\Models\Company;
use App\Models\Ingredient;
use App\Models\IngredientMovement;
use App\Models\IngredientStock;
use App\Models\InventoryMovement;
use App\Models\PosProduct;
use App\Models\ProductRecipe;
use App\Services\PosFeatureService;
use App\Services\PosInventoryMasterExcelService;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

/**
 * Phase 1 — NestPOS Inventory Master Excel (PRODUCT / INGREDIENT / RECIPE).
 *
 * Master import must never post stock or ledger rows. Processing order is
 * INGREDIENT → PRODUCT → RECIPE even when the file is written in another order.
 */
class PosInventoryMasterExcelTest extends TestCase
{
    protected int $companyId;
    protected int $otherCompanyId;

    protected function setUp(): void
    {
        parent::setUp();

        Schema::dropAllTables();
        PosFeatureService::flushGateCaches();
        PosFeatureService::assumeExtrasColumn(false);

        Schema::create('companies', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->boolean('is_internal_account')->default(false);
            $table->text('feature_flags')->nullable();
            $table->boolean('inventory_enabled')->default(true);
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
            $table->boolean('is_third_schedule')->default(false);
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

        Schema::create('ingredients', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('company_id');
            $table->string('code')->nullable();
            $table->string('name');
            $table->string('unit', 20);
            $table->string('base_unit', 20)->nullable();
            $table->decimal('conversion_factor', 15, 4)->default(1);
            $table->decimal('cost_per_unit', 15, 2)->default(0);
            $table->decimal('current_stock', 15, 4)->default(0);
            $table->decimal('min_stock_level', 15, 4)->default(0);
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });

        Schema::create('product_recipes', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('company_id');
            $table->unsignedBigInteger('product_id');
            $table->unsignedBigInteger('ingredient_id');
            $table->decimal('quantity_needed', 10, 4);
            $table->unsignedInteger('recipe_version')->default(1);
            $table->boolean('is_active')->default(true);
            $table->timestamps();
            $table->unique(['product_id', 'ingredient_id']);
        });

        Schema::create('ingredient_stocks', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('company_id');
            $table->unsignedBigInteger('ingredient_id');
            $table->unsignedBigInteger('branch_id')->nullable();
            $table->decimal('quantity', 15, 4)->default(0);
            $table->timestamps();
        });

        Schema::create('ingredient_movements', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('company_id');
            $table->unsignedBigInteger('ingredient_id');
            $table->unsignedBigInteger('branch_id')->nullable();
            $table->string('type');
            $table->decimal('quantity', 15, 4)->default(0);
            $table->timestamps();
        });

        Schema::create('inventory_movements', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('company_id');
            $table->unsignedBigInteger('product_id')->nullable();
            $table->string('type');
            $table->decimal('quantity', 15, 4)->default(0);
            $table->timestamps();
        });

        $company = Company::create([
            'name' => 'Master Shop',
            'is_internal_account' => true,
            'feature_flags' => ['inventory' => true, 'recipes' => true],
        ]);
        $this->companyId = $company->id;
        $other = Company::create([
            'name' => 'Other Shop',
            'is_internal_account' => true,
            'feature_flags' => ['inventory' => true, 'recipes' => true],
        ]);
        $this->otherCompanyId = $other->id;

        app()->bind('currentCompanyId', fn () => $this->companyId);
    }

    private const HEADER = [
        'Row Type', 'Product Name', 'Product Code', 'Price', 'Category', 'Description',
        'Tax Rate %', 'Unit (UOM)', 'Tax Exempt', 'Third Schedule',
        'Ingredient Name', 'Ingredient Code', 'Ingredient Unit', 'Cost per Unit',
        'Min Stock', 'Quantity Needed', 'Active',
    ];

    private function importRows(array $header, array $rows, ?int $companyId = null): array
    {
        $spreadsheet = new \PhpOffice\PhpSpreadsheet\Spreadsheet();
        $sheet = $spreadsheet->getActiveSheet();
        $sheet->setTitle(PosInventoryMasterExcelService::SHEET_NAME);
        $sheet->fromArray($header, null, 'A1');
        $r = 2;
        foreach ($rows as $row) {
            $sheet->fromArray($row, null, 'A' . $r++);
        }
        $tmp = tempnam(sys_get_temp_dir(), 'mst') . '.xlsx';
        (new \PhpOffice\PhpSpreadsheet\Writer\Xlsx($spreadsheet))->save($tmp);

        $cid = $companyId ?? $this->companyId;
        return (new PosInventoryMasterExcelService())->import($tmp, $cid, Company::find($cid));
    }

    private function importViaController(string $path): Request
    {
        $upload = new UploadedFile($path, 'nestpos_inventory_master.xlsx', 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet', null, true);
        $req = Request::create('/pos/inventory-master/import', 'POST');
        $req->files->set('excel_file', $upload);
        $req->setLaravelSession(app('session.store'));
        app()->instance('request', $req);
        (new PosInventoryMasterController(new PosInventoryMasterExcelService()))->import($req);
        return $req;
    }

    private function product(array $over = []): PosProduct
    {
        return PosProduct::create(array_merge([
            'company_id' => $this->companyId,
            'name' => 'Coke 500ml',
            'price' => 80,
            'sku' => 'DK-500',
            'stock_quantity' => 12,
        ], $over));
    }

    private function ingredient(array $over = []): Ingredient
    {
        return Ingredient::create(array_merge([
            'company_id' => $this->companyId,
            'name' => 'Basmati Rice',
            'code' => 'RICE-25',
            'unit' => 'kg',
            'cost_per_unit' => 300,
            'current_stock' => 0,
            'min_stock_level' => 50,
        ], $over));
    }

    public function test_product_create(): void
    {
        $result = $this->importRows(self::HEADER, [
            ['PRODUCT', 'Coke 500ml', 'DK-500', 80, 'Drinks', '', 0, 'NOS', 'No', 'No', '', '', '', '', '', '', 'Yes'],
        ]);

        $this->assertTrue($result['ok']);
        $p = PosProduct::where('company_id', $this->companyId)->where('name', 'Coke 500ml')->first();
        $this->assertNotNull($p);
        $this->assertEquals(80.0, (float) $p->price);
        $this->assertSame('DK-500', $p->sku);
        $this->assertNull($p->stock_quantity);
        $this->assertSame(0, ProductRecipe::count());
    }

    public function test_ingredient_create(): void
    {
        $result = $this->importRows(self::HEADER, [
            ['INGREDIENT', '', '', '', '', '', '', '', '', '', 'Basmati Rice', 'RICE-25', 'kg', 300, 50, '', 'Yes'],
        ]);

        $this->assertTrue($result['ok']);
        $ing = Ingredient::where('company_id', $this->companyId)->where('name', 'Basmati Rice')->first();
        $this->assertNotNull($ing);
        $this->assertSame('kg', $ing->unit);
        $this->assertSame('RICE-25', $ing->code);
        $this->assertEquals(0.0, (float) $ing->current_stock);
        $this->assertEquals(300.0, (float) $ing->cost_per_unit);
        $this->assertEquals(50.0, (float) $ing->min_stock_level);
    }

    public function test_recipe_create(): void
    {
        $this->product(['name' => 'Chicken Biryani', 'sku' => 'BRY-001', 'price' => 450]);
        $this->ingredient();

        $result = $this->importRows(self::HEADER, [
            ['RECIPE', 'Chicken Biryani', 'BRY-001', '', '', '', '', '', '', '', 'Basmati Rice', 'RICE-25', 'kg', '', '', 0.25, 'Yes'],
        ]);

        $this->assertTrue($result['ok']);
        $this->assertSame(1, ProductRecipe::where('company_id', $this->companyId)->count());
        $this->assertEquals(0.25, (float) ProductRecipe::first()->quantity_needed);
        $this->assertEquals(0.0, (float) Ingredient::first()->current_stock);
    }

    public function test_processing_order_ingredient_then_product_then_recipe_even_if_file_reversed(): void
    {
        $result = $this->importRows(self::HEADER, [
            ['RECIPE', 'Chicken Biryani', 'BRY-001', '', '', '', '', '', '', '', 'Basmati Rice', 'RICE-25', 'kg', '', '', 0.25, ''],
            ['PRODUCT', 'Chicken Biryani', 'BRY-001', 450, 'Rice', '', '', '', '', '', '', '', '', '', '', '', ''],
            ['INGREDIENT', '', '', '', '', '', '', '', '', '', 'Basmati Rice', 'RICE-25', 'kg', 300, 50, '', ''],
        ]);

        $this->assertTrue($result['ok'], $result['message']);
        $this->assertSame(1, Ingredient::where('company_id', $this->companyId)->count());
        $this->assertSame(1, PosProduct::where('company_id', $this->companyId)->count());
        $this->assertSame(1, ProductRecipe::where('company_id', $this->companyId)->count());
        $ing = Ingredient::first();
        $this->assertEquals(300.0, (float) $ing->cost_per_unit, 'INGREDIENT pass must land cost before RECIPE can auto-create at 0');
        $this->assertEquals(0.25, (float) ProductRecipe::first()->quantity_needed);
    }

    public function test_product_with_no_recipe_is_valid(): void
    {
        $this->importRows(self::HEADER, [
            ['PRODUCT', 'Coke 500ml', 'DK-500', 80, 'Drinks', '', '', '', '', '', '', '', '', '', '', '', ''],
            ['INGREDIENT', '', '', '', '', '', '', '', '', '', 'Basmati Rice', 'RICE-25', 'kg', 300, 50, '', ''],
        ]);

        $coke = PosProduct::where('name', 'Coke 500ml')->first();
        $this->assertNotNull($coke);
        $this->assertSame(0, ProductRecipe::where('product_id', $coke->id)->count());
    }

    public function test_safe_reimport_is_idempotent_for_master_data(): void
    {
        $rows = [
            ['INGREDIENT', '', '', '', '', '', '', '', '', '', 'Basmati Rice', 'RICE-25', 'kg', 300, 50, '', ''],
            ['PRODUCT', 'Chicken Biryani', 'BRY-001', 450, 'Rice', '', '', '', '', '', '', '', '', '', '', '', ''],
            ['PRODUCT', 'Coke 500ml', 'DK-500', 80, 'Drinks', '', '', '', '', '', '', '', '', '', '', '', ''],
            ['RECIPE', 'Chicken Biryani', 'BRY-001', '', '', '', '', '', '', '', 'Basmati Rice', 'RICE-25', 'kg', '', '', 0.25, ''],
        ];
        $this->importRows(self::HEADER, $rows);
        $result = $this->importRows(self::HEADER, $rows);

        $this->assertTrue($result['ok']);
        $this->assertSame(1, Ingredient::where('company_id', $this->companyId)->count());
        $this->assertSame(2, PosProduct::where('company_id', $this->companyId)->count());
        $this->assertSame(1, ProductRecipe::where('company_id', $this->companyId)->count());
        $this->assertSame(0, IngredientMovement::count());
        $this->assertSame(0, InventoryMovement::count());
        $this->assertEquals(0.0, (float) Ingredient::first()->current_stock);
    }

    public function test_duplicate_recipe_updates_quantity_instead_of_creating_a_second_line(): void
    {
        $p = $this->product(['name' => 'Chicken Biryani', 'sku' => 'BRY-001']);
        $i = $this->ingredient();
        ProductRecipe::create([
            'company_id' => $this->companyId,
            'product_id' => $p->id,
            'ingredient_id' => $i->id,
            'quantity_needed' => 0.10,
        ]);

        $this->importRows(self::HEADER, [
            ['RECIPE', 'Chicken Biryani', 'BRY-001', '', '', '', '', '', '', '', 'Basmati Rice', 'RICE-25', 'kg', '', '', 0.25, ''],
        ]);

        $this->assertSame(1, ProductRecipe::count());
        $this->assertEquals(0.25, (float) ProductRecipe::first()->quantity_needed);
        $this->assertSame(2, (int) ProductRecipe::first()->recipe_version);
    }

    public function test_ingredient_unit_change_invalid_when_bom_exists(): void
    {
        $p = $this->product(['name' => 'Chicken Biryani', 'sku' => 'BRY-001']);
        $i = $this->ingredient(['cost_per_unit' => 300, 'min_stock_level' => 50]);
        ProductRecipe::create([
            'company_id' => $this->companyId,
            'product_id' => $p->id,
            'ingredient_id' => $i->id,
            'quantity_needed' => 0.25,
        ]);

        $result = $this->importRows(self::HEADER, [
            ['INGREDIENT', '', '', '', '', '', '', '', '', '', 'Basmati Rice', 'RICE-25', 'g', 999, 1, '', ''],
        ]);

        $this->assertFalse($result['ok']);
        $this->assertStringContainsString('used in a recipe', implode(' ', $result['errors']));
        $i->refresh();
        $this->assertSame('kg', $i->unit);
        $this->assertEquals(300.0, (float) $i->cost_per_unit, 'No other field from the invalid row may be written');
        $this->assertEquals(50.0, (float) $i->min_stock_level);
    }

    public function test_ingredient_unit_change_invalid_when_company_stock_exists(): void
    {
        $i = $this->ingredient(['current_stock' => 12.5, 'cost_per_unit' => 300]);

        $result = $this->importRows(self::HEADER, [
            ['INGREDIENT', '', '', '', '', '', '', '', '', '', 'Basmati Rice', 'RICE-25', 'g', 1, '', '', ''],
        ]);

        $this->assertFalse($result['ok']);
        $this->assertStringContainsString('already has stock', implode(' ', $result['errors']));
        $this->assertSame('kg', $i->fresh()->unit);
        $this->assertEquals(300.0, (float) $i->fresh()->cost_per_unit);
        $this->assertEquals(12.5, (float) $i->fresh()->current_stock);
    }

    public function test_ingredient_unit_change_invalid_when_branch_stock_exists(): void
    {
        $i = $this->ingredient(['current_stock' => 0, 'cost_per_unit' => 300]);
        IngredientStock::create([
            'company_id' => $this->companyId,
            'ingredient_id' => $i->id,
            'branch_id' => null,
            'quantity' => 8,
        ]);

        $result = $this->importRows(self::HEADER, [
            ['INGREDIENT', '', '', '', '', '', '', '', '', '', 'Basmati Rice', 'RICE-25', 'g', 1, '', '', ''],
        ]);

        $this->assertStringContainsString('already has stock', implode(' ', $result['errors']));
        $this->assertSame('kg', $i->fresh()->unit);
        $this->assertEquals(300.0, (float) $i->fresh()->cost_per_unit);
    }

    public function test_company_isolation_never_matches_foreign_catalog(): void
    {
        PosProduct::create(['company_id' => $this->otherCompanyId, 'name' => 'Foreign Pizza', 'price' => 1, 'sku' => 'PZ-001']);
        Ingredient::create(['company_id' => $this->otherCompanyId, 'name' => 'Foreign Dough', 'code' => 'ING-DGH', 'unit' => 'g', 'current_stock' => 9]);

        $result = $this->importRows(self::HEADER, [
            ['RECIPE', 'Foreign Pizza', 'PZ-001', '', '', '', '', '', '', '', 'Foreign Dough', 'ING-DGH', 'g', '', '', 350, ''],
        ]);

        $this->assertSame(0, ProductRecipe::count());
        $this->assertSame(0, PosProduct::where('company_id', $this->companyId)->count());
        $this->assertSame(0, Ingredient::where('company_id', $this->companyId)->count());
        $this->assertEquals(9.0, (float) Ingredient::where('company_id', $this->otherCompanyId)->first()->current_stock);
        $this->assertStringContainsString('not found', implode(' ', $result['errors']));
    }

    public function test_master_import_never_changes_stock_quantities(): void
    {
        $p = $this->product(['stock_quantity' => 24]);
        $i = $this->ingredient(['current_stock' => 40]);
        IngredientStock::create([
            'company_id' => $this->companyId,
            'ingredient_id' => $i->id,
            'quantity' => 40,
        ]);

        $this->importRows(self::HEADER, [
            ['PRODUCT', 'Coke 500ml', 'DK-500', 90, 'Drinks', '', '', '', '', '', '', '', '', '', '', '', ''],
            ['INGREDIENT', '', '', '', '', '', '', '', '', '', 'Basmati Rice', 'RICE-25', 'kg', 310, 60, '', ''],
            ['RECIPE', 'Coke 500ml', 'DK-500', '', '', '', '', '', '', '', 'Basmati Rice', 'RICE-25', 'kg', '', '', 0.01, ''],
        ]);

        $this->assertEquals(24, (int) $p->fresh()->stock_quantity);
        $this->assertEquals(40.0, (float) $i->fresh()->current_stock);
        $this->assertEquals(40.0, (float) IngredientStock::first()->quantity);
        $this->assertEquals(90.0, (float) $p->fresh()->price);
        $this->assertEquals(310.0, (float) $i->fresh()->cost_per_unit);
    }

    public function test_master_import_never_creates_inventory_or_ingredient_movements(): void
    {
        $this->importRows(self::HEADER, [
            ['INGREDIENT', '', '', '', '', '', '', '', '', '', 'Basmati Rice', 'RICE-25', 'kg', 300, 50, '', ''],
            ['PRODUCT', 'Chicken Biryani', 'BRY-001', 450, '', '', '', '', '', '', '', '', '', '', '', '', ''],
            ['RECIPE', 'Chicken Biryani', 'BRY-001', '', '', '', '', '', '', '', 'Basmati Rice', 'RICE-25', 'kg', '', '', 0.25, ''],
        ]);

        $this->assertSame(0, IngredientMovement::count());
        $this->assertSame(0, InventoryMovement::count());
        $this->assertEquals(0.0, (float) Ingredient::first()->current_stock);
        $this->assertNull(PosProduct::first()->stock_quantity);
    }

    public function test_row_validation_reports_row_number_type_and_reason(): void
    {
        $result = $this->importRows(self::HEADER, [
            ['PRODUCT', '', 'X', 'abc', '', '', '', '', '', '', '', '', '', '', '', '', ''],
            ['INGREDIENT', '', '', '', '', '', '', '', '', '', 'Salt', 'Z', 'stones', '', '', '', ''],
            ['RECIPE', 'Missing Dish', '', '', '', '', '', '', '', '', 'Ghost', '', 'kg', '', '', 0, ''],
            ['STOCKIN', 'Nope', '', 1, '', '', '', '', '', '', '', '', '', '', '', '', ''],
        ]);

        $blob = implode("\n", $result['errors']);
        $this->assertStringContainsString('Row 2 [PRODUCT]', $blob);
        $this->assertStringContainsString('Product Name is required', $blob);
        $this->assertStringContainsString('Row 3 [INGREDIENT]', $blob);
        $this->assertStringContainsString('not allowed', $blob);
        $this->assertStringContainsString('Row 4 [RECIPE]', $blob);
        $this->assertStringContainsString('Quantity Needed', $blob);
        $this->assertStringContainsString('Row 5 [UNKNOWN]', $blob);
        $this->assertStringContainsString('PRODUCT, INGREDIENT, or RECIPE', $blob);
        $this->assertSame(0, PosProduct::count());
        $this->assertSame(0, Ingredient::count());
    }

    public function test_excel_text_codes_and_scientific_notation(): void
    {
        $result = $this->importRows(self::HEADER, [
            ['PRODUCT', 'Long Barcode Drink', 8.90123456789E+12, 50, '', '', '', '', '', '', '', '', '', '', '', '', ''],
            ['INGREDIENT', '', '', '', '', '', '', '', '', '', 'Oil', 8.9E+12, 'ltr', 1, '', '', ''],
        ]);

        $this->assertTrue($result['ok'], $result['message']);
        $p = PosProduct::where('name', 'Long Barcode Drink')->first();
        $this->assertNotNull($p);
        $this->assertSame('8901234567890', $p->sku);
        $ing = Ingredient::where('name', 'Oil')->first();
        $this->assertSame('8900000000000', $ing->code);
    }

    public function test_aliases_are_accepted(): void
    {
        $header = ['Type', 'Item Name', 'SKU', 'Sale Price', 'Group', 'Details', 'Tax', 'UOM', 'Exempt', 'Third', 'Ingredient', 'Ing Code', 'Kitchen Unit', 'Cost', 'Minimum Stock', 'Qty Needed', 'Status'];
        $result = $this->importRows($header, [
            ['product', 'Coke 500ml', 'DK-500', 80, 'Drinks', '', 0, 'NOS', 'No', 'No', '', '', '', '', '', '', 'Yes'],
            ['ingredient', '', '', '', '', '', '', '', '', '', 'Basmati Rice', 'RICE-25', 'kg', 300, 50, '', 'Yes'],
        ]);

        $this->assertTrue($result['ok'], $result['message']);
        $this->assertSame(1, PosProduct::count());
        $this->assertSame(1, Ingredient::count());
    }

    public function test_recipes_off_still_imports_products_and_skips_kitchen_rows(): void
    {
        Company::where('id', $this->companyId)->update(['feature_flags' => ['inventory' => true, 'recipes' => false]]);
        PosFeatureService::flushGateCaches();

        $result = $this->importRows(self::HEADER, [
            ['INGREDIENT', '', '', '', '', '', '', '', '', '', 'Basmati Rice', 'RICE-25', 'kg', 300, 50, '', ''],
            ['PRODUCT', 'Coke 500ml', 'DK-500', 80, 'Drinks', '', '', '', '', '', '', '', '', '', '', '', ''],
            ['RECIPE', 'Coke 500ml', 'DK-500', '', '', '', '', '', '', '', 'Basmati Rice', 'RICE-25', 'kg', '', '', 0.25, ''],
        ]);

        $this->assertTrue($result['ok'], $result['message']);
        $this->assertSame(1, PosProduct::count());
        $this->assertSame(0, Ingredient::count());
        $this->assertSame(0, ProductRecipe::count());
        $this->assertStringContainsString('Recipes module is OFF', $result['message']);
    }

    public function test_template_download_has_master_sheet_text_codes_dropdown_and_required_samples(): void
    {
        $response = (new PosInventoryMasterExcelService())->streamTemplate();
        ob_start();
        $response->sendContent();
        $bytes = (string) ob_get_clean();

        $tmp = tempnam(sys_get_temp_dir(), 'tpl') . '.xlsx';
        file_put_contents($tmp, $bytes);
        $ss = \PhpOffice\PhpSpreadsheet\IOFactory::load($tmp);
        $this->assertSame('Master', $ss->getSheet(0)->getTitle());
        $sheet = $ss->getSheetByName('Master');
        $this->assertSame('Row Type', $sheet->getCell('A1')->getValue());
        $this->assertSame('Quantity Needed', $sheet->getCell('P1')->getValue());
        $this->assertTrue($sheet->getFreezePane() === 'A2' || $sheet->getFreezePane() === 'A2');
        $this->assertSame(\PhpOffice\PhpSpreadsheet\Style\NumberFormat::FORMAT_TEXT, $sheet->getStyle('C2')->getNumberFormat()->getFormatCode());
        $this->assertSame(\PhpOffice\PhpSpreadsheet\Style\NumberFormat::FORMAT_TEXT, $sheet->getStyle('L2')->getNumberFormat()->getFormatCode());

        $blob = '';
        foreach ($sheet->toArray(null, true, false, false) as $row) {
            $blob .= implode('|', array_map(fn ($c) => (string) $c, $row)) . "\n";
        }
        $this->assertStringContainsString('Misal: Coke 500ml', $blob);
        $this->assertStringContainsString('Misal: Basmati Rice', $blob);
        $this->assertStringContainsString('0.25', $blob);
        $this->assertStringContainsString('Misal: Chicken Biryani', $blob);

        $dv = $sheet->getCell('A2')->getDataValidation();
        $this->assertSame(\PhpOffice\PhpSpreadsheet\Cell\DataValidation::TYPE_LIST, $dv->getType());
        $this->assertStringContainsString('PRODUCT', $dv->getFormula1());

        $controller = new PosInventoryMasterController(new PosInventoryMasterExcelService());
        $this->importViaController($tmp);
        $this->assertSame(0, PosProduct::count(), 'Untouched template samples must not become catalog rows');
        $this->assertSame(0, Ingredient::count());
        $this->assertStringContainsString('sample', strtolower((string) session('success') . session('error')));
    }

    public function test_controller_import_path_writes_master_data_without_stock(): void
    {
        $spreadsheet = new \PhpOffice\PhpSpreadsheet\Spreadsheet();
        $sheet = $spreadsheet->getActiveSheet();
        $sheet->setTitle('Master');
        $sheet->fromArray(self::HEADER, null, 'A1');
        $sheet->fromArray(['INGREDIENT', '', '', '', '', '', '', '', '', '', 'Basmati Rice', 'RICE-25', 'kg', 300, 50, '', ''], null, 'A2');
        $sheet->fromArray(['PRODUCT', 'Coke 500ml', 'DK-500', 80, 'Drinks', '', '', '', '', '', '', '', '', '', '', '', ''], null, 'A3');
        $sheet->fromArray(['RECIPE', 'Coke 500ml', 'DK-500', '', '', '', '', '', '', '', 'Basmati Rice', 'RICE-25', 'kg', '', '', 0.1, ''], null, 'A4');
        $tmp = tempnam(sys_get_temp_dir(), 'ctl') . '.xlsx';
        (new \PhpOffice\PhpSpreadsheet\Writer\Xlsx($spreadsheet))->save($tmp);

        $this->importViaController($tmp);

        $this->assertNotNull(session('success'));
        $this->assertSame(1, PosProduct::count());
        $this->assertSame(1, Ingredient::count());
        $this->assertSame(1, ProductRecipe::count());
        $this->assertEquals(0.0, (float) Ingredient::first()->current_stock);
        $this->assertSame(0, IngredientMovement::count());
        $this->assertSame(0, InventoryMovement::count());
    }

    public function test_recipe_does_not_write_cost_on_existing_ingredient(): void
    {
        $this->product(['name' => 'Chicken Biryani', 'sku' => 'BRY-001']);
        $this->ingredient(['cost_per_unit' => 300]);

        $this->importRows(self::HEADER, [
            ['RECIPE', 'Chicken Biryani', 'BRY-001', '', '', '', '', '', '', '', 'Basmati Rice', 'RICE-25', 'kg', 1, '', 0.25, ''],
        ]);

        $this->assertEquals(300.0, (float) Ingredient::first()->cost_per_unit);
        $this->assertEquals(0.25, (float) ProductRecipe::first()->quantity_needed);
    }

    public function test_in_file_duplicate_product_rows_last_write_wins(): void
    {
        $result = $this->importRows(self::HEADER, [
            ['PRODUCT', 'Coke 500ml', 'DK-500', 80, 'Drinks', 'first', '', '', '', '', '', '', '', '', '', '', ''],
            ['PRODUCT', 'Coke 500ml', 'DK-500', 95, 'Soda', 'second', '', '', '', '', '', '', '', '', '', '', ''],
        ]);

        $this->assertTrue($result['ok'], $result['message']);
        $this->assertSame(1, PosProduct::where('company_id', $this->companyId)->count());
        $p = PosProduct::first();
        $this->assertEquals(95.0, (float) $p->price);
        $this->assertSame('Soda', $p->category);
        $this->assertSame('second', $p->description);
        $this->assertNull($p->stock_quantity);
        $this->assertSame(0, InventoryMovement::count());
        $this->assertSame(0, IngredientMovement::count());
    }

    public function test_in_file_duplicate_ingredient_rows_last_write_wins(): void
    {
        $result = $this->importRows(self::HEADER, [
            ['INGREDIENT', '', '', '', '', '', '', '', '', '', 'Basmati Rice', 'RICE-25', 'kg', 300, 50, '', ''],
            ['INGREDIENT', '', '', '', '', '', '', '', '', '', 'Basmati Rice', 'RICE-25', 'kg', 310, 60, '', ''],
        ]);

        $this->assertTrue($result['ok'], $result['message']);
        $this->assertSame(1, Ingredient::where('company_id', $this->companyId)->count());
        $ing = Ingredient::first();
        $this->assertEquals(310.0, (float) $ing->cost_per_unit);
        $this->assertEquals(60.0, (float) $ing->min_stock_level);
        $this->assertEquals(0.0, (float) $ing->current_stock);
        $this->assertSame(0, InventoryMovement::count());
        $this->assertSame(0, IngredientMovement::count());
    }

    public function test_in_file_duplicate_product_then_recipe_uses_real_product_id(): void
    {
        $result = $this->importRows(self::HEADER, [
            ['PRODUCT', 'Chicken Biryani', 'BRY-001', 400, 'Rice', 'first', '', '', '', '', '', '', '', '', '', '', ''],
            ['PRODUCT', 'Chicken Biryani', 'BRY-001', 450, 'Rice', 'second', '', '', '', '', '', '', '', '', '', '', ''],
            ['INGREDIENT', '', '', '', '', '', '', '', '', '', 'Basmati Rice', 'RICE-25', 'kg', 300, 50, '', ''],
            ['RECIPE', 'Chicken Biryani', 'BRY-001', '', '', '', '', '', '', '', 'Basmati Rice', 'RICE-25', 'kg', '', '', 0.25, ''],
        ]);

        $this->assertTrue($result['ok'], $result['message']);
        $this->assertSame(1, PosProduct::where('company_id', $this->companyId)->count());
        $p = PosProduct::first();
        $this->assertEquals(450.0, (float) $p->price);
        $this->assertSame('second', $p->description);
        $this->assertGreaterThan(0, (int) $p->id);
        $this->assertSame(1, ProductRecipe::where('company_id', $this->companyId)->count());
        $recipe = ProductRecipe::first();
        $this->assertSame((int) $p->id, (int) $recipe->product_id);
        $this->assertEquals(0.25, (float) $recipe->quantity_needed);
        $this->assertNull($p->stock_quantity);
        $this->assertEquals(0.0, (float) Ingredient::first()->current_stock);
        $this->assertSame(0, InventoryMovement::count());
        $this->assertSame(0, IngredientMovement::count());
    }

    public function test_in_file_duplicate_ingredient_then_recipe_uses_real_ingredient_id(): void
    {
        $result = $this->importRows(self::HEADER, [
            ['INGREDIENT', '', '', '', '', '', '', '', '', '', 'Basmati Rice', 'RICE-25', 'kg', 300, 50, '', ''],
            ['INGREDIENT', '', '', '', '', '', '', '', '', '', 'Basmati Rice', 'RICE-25', 'kg', 310, 60, '', ''],
            ['PRODUCT', 'Chicken Biryani', 'BRY-001', 450, 'Rice', '', '', '', '', '', '', '', '', '', '', '', ''],
            ['RECIPE', 'Chicken Biryani', 'BRY-001', '', '', '', '', '', '', '', 'Basmati Rice', 'RICE-25', 'kg', '', '', 0.25, ''],
        ]);

        $this->assertTrue($result['ok'], $result['message']);
        $this->assertSame(1, Ingredient::where('company_id', $this->companyId)->count());
        $ing = Ingredient::first();
        $this->assertEquals(310.0, (float) $ing->cost_per_unit);
        $this->assertGreaterThan(0, (int) $ing->id);
        $this->assertSame(1, ProductRecipe::where('company_id', $this->companyId)->count());
        $recipe = ProductRecipe::first();
        $this->assertSame((int) $ing->id, (int) $recipe->ingredient_id);
        $this->assertEquals(0.25, (float) $recipe->quantity_needed);
        $this->assertEquals(0.0, (float) $ing->current_stock);
        $this->assertNull(PosProduct::first()->stock_quantity);
        $this->assertSame(0, InventoryMovement::count());
        $this->assertSame(0, IngredientMovement::count());
    }
}

<?php

namespace Tests\Feature;

use App\Http\Controllers\PosInventoryMasterController;
use App\Http\Middleware\ReadOnlyImpersonation;
use App\Models\Company;
use App\Models\AdminUser;
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
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Schema;
use PhpOffice\PhpSpreadsheet\Cell\DataType;
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
            $table->decimal('cost_price', 12, 2)->nullable();
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
            $table->decimal('low_stock_threshold', 15, 4)->nullable();
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
            $table->string('category')->nullable();
            $table->string('supplier')->nullable();
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
            $table->decimal('waste_percent', 8, 2)->default(0);
            $table->string('notes')->nullable();
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

    public function test_template_download_has_exact_menu_workbook_and_deterministic_samples(): void
    {
        $response = (new PosInventoryMasterExcelService())->streamTemplate();
        ob_start();
        $response->sendContent();
        $bytes = (string) ob_get_clean();

        $tmp = tempnam(sys_get_temp_dir(), 'tpl') . '.xlsx';
        file_put_contents($tmp, $bytes);
        $ss = \PhpOffice\PhpSpreadsheet\IOFactory::load($tmp);
        $this->assertSame(['Start Here', 'Products', 'Ingredients', 'Recipes', 'Lists'],
            array_map(fn ($sheet) => $sheet->getTitle(), $ss->getAllSheets()));
        $this->assertSame('Product Name', $ss->getSheetByName('Products')->getCell('A1')->getValue());
        $this->assertSame('Ingredient Name', $ss->getSheetByName('Ingredients')->getCell('A1')->getValue());
        $this->assertSame('Waste %', $ss->getSheetByName('Recipes')->getCell('E1')->getValue());
        $this->assertSame('Beverages', $ss->getSheetByName('Products')->getCell('B2')->getValue());
        $this->assertSame('Recipe', $ss->getSheetByName('Products')->getCell('C2')->getValue());
        $this->assertNull($ss->getSheetByName('Products')->getCell('E2')->getValue());
        $this->assertSame('cup', $ss->getSheetByName('Products')->getCell('H2')->getValue());
        $this->assertSame('cup', $ss->getSheetByName('Lists')->getCell('B3')->getValue());
        $this->assertSame('Misal: Milk', $ss->getSheetByName('Ingredients')->getCell('A3')->getValue());
        $this->assertSame('Misal: Water', $ss->getSheetByName('Ingredients')->getCell('A5')->getValue());
        $this->assertSame(1000, $ss->getSheetByName('Ingredients')->getCell('F2')->getValue());
        $this->assertSame(180, $ss->getSheetByName('Ingredients')->getCell('F3')->getValue());
        $this->assertSame(200, $ss->getSheetByName('Ingredients')->getCell('F4')->getValue());
        $this->assertSame(20, $ss->getSheetByName('Ingredients')->getCell('F5')->getValue());
        $this->assertStringContainsString('never imported', $ss->getSheetByName('Start Here')->getCell('A5')->getValue());
        $this->assertTrue($ss->getSheetByName('Lists')->getProtection()->getSheet());
        $this->assertSame(2, $ss->getSheetByName('Products')->getHighestRow());
        $this->assertSame(5, $ss->getSheetByName('Ingredients')->getHighestRow());
        $this->assertSame(5, $ss->getSheetByName('Recipes')->getHighestRow());

        $preview = (new PosInventoryMasterExcelService())->preview($tmp, $this->companyId, 'create_only', 1);
        $this->assertTrue($preview['ok'], json_encode($preview));
        $this->assertSame([['product'=>'Plain Tea', 'cost'=>23.6]], $preview['recipe_costs']);
        $this->assertSame(0, PosProduct::count(), 'Untouched template samples must not become catalog rows');
        $this->assertSame(0, Ingredient::count());
    }

    public function test_template_and_current_export_have_distinct_routes_actions_and_filenames(): void
    {
        $templateRoute = Route::getRoutes()->getByName('pos.inventory-master.template');
        $exportRoute = Route::getRoutes()->getByName('pos.inventory-master.export');

        $this->assertNotNull($templateRoute);
        $this->assertNotNull($exportRoute);
        $this->assertSame(['GET', 'HEAD'], $templateRoute->methods());
        $this->assertSame(['GET', 'HEAD'], $exportRoute->methods());
        $this->assertSame('pos/inventory-master/template', $templateRoute->uri());
        $this->assertSame('pos/inventory-master/export', $exportRoute->uri());
        $this->assertNotSame($templateRoute->getActionName(), $exportRoute->getActionName());

        $blade = file_get_contents(resource_path('views/pos/inventory/master.blade.php'));
        $this->assertStringContainsString("route('pos.inventory-master.template')", $blade);
        $this->assertStringContainsString("route('pos.inventory-master.export')", $blade);
        $this->assertStringContainsString("__('pos.inventory_master_download')", $blade);
        $this->assertStringContainsString("__('pos.inventory_master_export')", $blade);

        $template = (new PosInventoryMasterExcelService())->streamTemplate();
        $export = (new PosInventoryMasterExcelService())->exportWorkbook($this->companyId);
        $this->assertStringContainsString(PosInventoryMasterExcelService::WORKBOOK_FILENAME, (string) $template->headers->get('content-disposition'));
        $this->assertStringContainsString(PosInventoryMasterExcelService::EXPORT_FILENAME, (string) $export->headers->get('content-disposition'));
    }

    public function test_current_export_contains_only_requested_tenant_catalog_and_round_trips_in_update_mode(): void
    {
        $product = $this->product([
            'name' => 'Chicken Biryani',
            'category' => 'Rice',
            'price' => 450,
            'cost_price' => 175,
            'sku' => '0001234567890123',
            'barcode' => '9988776655443',
            'uom' => 'pcs',
            'tax_rate' => 16,
            'low_stock_threshold' => 4,
            'description' => '=house-special',
        ]);
        $ingredient = $this->ingredient([
            'name' => 'Basmati Rice',
            'code' => 'RICE-25',
            'base_unit' => 'kg',
            'unit' => 'g',
            'conversion_factor' => 1000,
            'cost_per_unit' => 0.3,
            'category' => 'Kitchen',
            'supplier' => 'Local Supplier',
        ]);
        ProductRecipe::create([
            'company_id' => $this->companyId,
            'product_id' => $product->id,
            'ingredient_id' => $ingredient->id,
            'quantity_needed' => 250,
            'waste_percent' => 5,
            'notes' => 'Per plate',
            'is_active' => true,
        ]);

        $foreignProduct = PosProduct::create([
            'company_id' => $this->otherCompanyId,
            'name' => 'Foreign Product',
            'price' => 999,
            'sku' => 'FOREIGN-SKU',
        ]);
        $foreignIngredient = Ingredient::create([
            'company_id' => $this->otherCompanyId,
            'name' => 'Foreign Ingredient',
            'code' => 'FOREIGN-ING',
            'unit' => 'kg',
            'cost_per_unit' => 1,
        ]);
        ProductRecipe::create([
            'company_id' => $this->otherCompanyId,
            'product_id' => $foreignProduct->id,
            'ingredient_id' => $foreignIngredient->id,
            'quantity_needed' => 1,
            'is_active' => true,
        ]);

        $service = new PosInventoryMasterExcelService();
        $spreadsheet = $service->buildExportWorkbook($this->companyId);
        $products = $spreadsheet->getSheetByName('Products');
        $ingredients = $spreadsheet->getSheetByName('Ingredients');
        $recipes = $spreadsheet->getSheetByName('Recipes');

        $this->assertSame('Chicken Biryani', $products->getCell('A2')->getValue());
        $this->assertSame('0001234567890123', $products->getCell('F2')->getValue());
        $this->assertSame(DataType::TYPE_STRING, $products->getCell('F2')->getDataType());
        $this->assertSame('9988776655443', $products->getCell('G2')->getValue());
        $this->assertSame(DataType::TYPE_STRING, $products->getCell('G2')->getDataType());
        $this->assertSame('=house-special', $products->getCell('N2')->getValue());
        $this->assertSame(DataType::TYPE_STRING, $products->getCell('N2')->getDataType());
        $this->assertSame('Basmati Rice', $ingredients->getCell('A2')->getValue());
        $this->assertSame('kg', $ingredients->getCell('C2')->getValue());
        $this->assertSame('g', $ingredients->getCell('D2')->getValue());
        $this->assertEquals(1000, $ingredients->getCell('E2')->getValue());
        $this->assertEquals(300, $ingredients->getCell('F2')->getValue());
        $this->assertSame('Chicken Biryani', $recipes->getCell('A2')->getValue());
        $this->assertSame('Basmati Rice', $recipes->getCell('B2')->getValue());
        $this->assertEquals(250, $recipes->getCell('C2')->getValue());
        $this->assertSame('g', $recipes->getCell('D2')->getValue());
        $this->assertEquals(5, $recipes->getCell('E2')->getValue());
        $this->assertSame('Per plate', $recipes->getCell('F2')->getValue());

        foreach (['Products', 'Ingredients', 'Recipes'] as $sheetName) {
            $rows = $spreadsheet->getSheetByName($sheetName)->toArray(null, true, false, false);
            $json = json_encode($rows, JSON_UNESCAPED_UNICODE);
            $this->assertStringNotContainsString('Misal:', $json);
            $this->assertStringNotContainsString('Foreign Product', $json);
            $this->assertStringNotContainsString('Foreign Ingredient', $json);
            $this->assertStringNotContainsString('FOREIGN-SKU', $json);
        }

        $path = tempnam(sys_get_temp_dir(), 'export') . '.xlsx';
        (new \PhpOffice\PhpSpreadsheet\Writer\Xlsx($spreadsheet))->save($path);
        $preview = $service->preview($path, $this->companyId, 'update_existing', 1);
        $this->assertTrue($preview['ok'], json_encode($preview));
        $this->assertSame([], $preview['errors']);
        $this->assertStringNotContainsString('ambiguous', strtolower(json_encode($preview)));
        $confirmed = $service->confirm($preview['token'], $this->companyId, true, 1);
        $this->assertTrue($confirmed['ok'], json_encode($confirmed));
        $this->assertSame(2, PosProduct::count(), 'Preview must remain zero-write');
        $this->assertSame(2, Ingredient::count(), 'Preview must remain zero-write');
        $this->assertSame(2, ProductRecipe::count(), 'Preview must remain zero-write');
    }

    public function test_exported_ids_make_duplicate_names_round_trip_without_ambiguity(): void
    {
        $firstProduct = $this->product(['name' => 'Family Deal', 'sku' => 'DEAL-A']);
        $secondProduct = $this->product(['name' => 'Family Deal', 'sku' => 'DEAL-B']);
        $firstIngredient = $this->ingredient(['name' => 'Sauce', 'code' => 'SAUCE-A', 'unit' => 'g']);
        $secondIngredient = $this->ingredient(['name' => 'Sauce', 'code' => 'SAUCE-B', 'unit' => 'g']);
        ProductRecipe::create([
            'company_id' => $this->companyId,
            'product_id' => $firstProduct->id,
            'ingredient_id' => $firstIngredient->id,
            'quantity_needed' => 10,
        ]);
        ProductRecipe::create([
            'company_id' => $this->companyId,
            'product_id' => $secondProduct->id,
            'ingredient_id' => $secondIngredient->id,
            'quantity_needed' => 20,
        ]);

        $service = new PosInventoryMasterExcelService();
        $spreadsheet = $service->buildExportWorkbook($this->companyId);
        $this->assertSame((string) $firstProduct->id, $spreadsheet->getSheetByName('Products')->getCell('O2')->getValue());
        $this->assertSame((string) $secondProduct->id, $spreadsheet->getSheetByName('Products')->getCell('O3')->getValue());
        $this->assertSame((string) $firstIngredient->id, $spreadsheet->getSheetByName('Ingredients')->getCell('L2')->getValue());
        $this->assertSame((string) $secondIngredient->id, $spreadsheet->getSheetByName('Ingredients')->getCell('L3')->getValue());
        $this->assertSame((string) $firstProduct->id, $spreadsheet->getSheetByName('Recipes')->getCell('I2')->getValue());
        $this->assertSame((string) $secondProduct->id, $spreadsheet->getSheetByName('Recipes')->getCell('I3')->getValue());

        $path = tempnam(sys_get_temp_dir(), 'duplicate-names') . '.xlsx';
        (new \PhpOffice\PhpSpreadsheet\Writer\Xlsx($spreadsheet))->save($path);
        $preview = $service->preview($path, $this->companyId, 'update_existing', 1);
        $this->assertTrue($preview['ok'], json_encode($preview));
        $this->assertSame([], $preview['errors']);
        $this->assertStringNotContainsString('ambiguous', strtolower(json_encode($preview)));
    }

    public function test_malformed_foreign_and_inactive_recipes_do_not_affect_current_export(): void
    {
        $localProduct = $this->product(['name' => 'Local Product', 'sku' => 'LOCAL-P']);
        $localIngredient = $this->ingredient(['name' => 'Local Ingredient', 'code' => 'LOCAL-I']);
        $foreignIngredient = Ingredient::create([
            'company_id' => $this->otherCompanyId,
            'name' => 'Foreign Ingredient',
            'code' => 'FOREIGN-I',
            'unit' => 'kg',
            'cost_per_unit' => 1,
        ]);
        ProductRecipe::create([
            'company_id' => $this->companyId,
            'product_id' => $localProduct->id,
            'ingredient_id' => $foreignIngredient->id,
            'quantity_needed' => 1,
            'is_active' => true,
        ]);
        ProductRecipe::create([
            'company_id' => $this->companyId,
            'product_id' => $localProduct->id,
            'ingredient_id' => $localIngredient->id,
            'quantity_needed' => 2,
            'is_active' => false,
        ]);

        $spreadsheet = (new PosInventoryMasterExcelService())->buildExportWorkbook($this->companyId);
        $this->assertSame('Resale', $spreadsheet->getSheetByName('Products')->getCell('C2')->getValue());
        $this->assertNull($spreadsheet->getSheetByName('Recipes')->getCell('A2')->getValue());
        $serialized = json_encode($spreadsheet->getSheetByName('Recipes')->toArray());
        $this->assertStringNotContainsString('Foreign Ingredient', $serialized);
        $this->assertStringNotContainsString('Local Ingredient', $serialized);
    }

    public function test_existing_legacy_ingredient_units_round_trip_by_scoped_id(): void
    {
        $product = $this->product(['name' => 'Legacy Scoop Product', 'sku' => 'LEGACY-P']);
        $ingredient = $this->ingredient([
            'name' => 'Legacy Powder',
            'code' => 'LEGACY-I',
            'base_unit' => 'bag',
            'unit' => 'scoop',
            'conversion_factor' => 50,
            'cost_per_unit' => 2,
        ]);
        ProductRecipe::create([
            'company_id' => $this->companyId,
            'product_id' => $product->id,
            'ingredient_id' => $ingredient->id,
            'quantity_needed' => 1,
        ]);

        $service = new PosInventoryMasterExcelService();
        $spreadsheet = $service->buildExportWorkbook($this->companyId);
        $this->assertSame('bag', $spreadsheet->getSheetByName('Ingredients')->getCell('C2')->getValue());
        $this->assertSame('scoop', $spreadsheet->getSheetByName('Ingredients')->getCell('D2')->getValue());
        $this->assertSame('scoop', $spreadsheet->getSheetByName('Recipes')->getCell('D2')->getValue());

        $path = tempnam(sys_get_temp_dir(), 'legacy-unit') . '.xlsx';
        (new \PhpOffice\PhpSpreadsheet\Writer\Xlsx($spreadsheet))->save($path);
        $preview = $service->preview($path, $this->companyId, 'update_existing', 1);
        $this->assertTrue($preview['ok'], json_encode($preview));
        $this->assertSame([], $preview['errors']);
    }

    public function test_zero_price_and_nullable_fields_round_trip_without_catalog_writes(): void
    {
        $product = $this->product([
            'name' => 'Open Price Item',
            'price' => 0,
            'category' => null,
            'uom' => null,
            'description' => null,
        ]);
        $ingredient = $this->ingredient([
            'name' => 'Optional Ingredient',
            'code' => 'OPTIONAL-I',
            'category' => null,
            'supplier' => null,
        ]);
        ProductRecipe::create([
            'company_id' => $this->companyId,
            'product_id' => $product->id,
            'ingredient_id' => $ingredient->id,
            'quantity_needed' => 1,
        ]);

        $service = new PosInventoryMasterExcelService();
        $spreadsheet = $service->buildExportWorkbook($this->companyId);
        $path = tempnam(sys_get_temp_dir(), 'zero-price') . '.xlsx';
        (new \PhpOffice\PhpSpreadsheet\Writer\Xlsx($spreadsheet))->save($path);
        $preview = $service->preview($path, $this->companyId, 'update_existing', 1);
        $this->assertTrue($preview['ok'], json_encode($preview));
        $this->assertSame([], $preview['errors']);
        $this->assertSame(0, $preview['updates']);

        $confirmed = $service->confirm($preview['token'], $this->companyId, true, 1);
        $this->assertTrue($confirmed['ok'], json_encode($confirmed));
        $product->refresh();
        $ingredient->refresh();
        $this->assertEquals(0, $product->price);
        $this->assertNull($product->category);
        $this->assertNull($product->uom);
        $this->assertNull($product->description);
        $this->assertNull($ingredient->category);
        $this->assertNull($ingredient->supplier);
    }

    public function test_direct_view_as_and_manage_as_get_exports_are_allowed_and_zero_write(): void
    {
        $this->product(['name' => 'Mode Product', 'sku' => 'MODE-P']);
        $before = [
            'products' => PosProduct::orderBy('id')->get()->toJson(),
            'ingredients' => Ingredient::orderBy('id')->get()->toJson(),
            'recipes' => ProductRecipe::orderBy('id')->get()->toJson(),
            'ingredient_stocks' => IngredientStock::orderBy('id')->get()->toJson(),
            'ingredient_movements' => IngredientMovement::orderBy('id')->get()->toJson(),
            'inventory_movements' => InventoryMovement::orderBy('id')->get()->toJson(),
        ];

        foreach ([
            'direct' => null,
            'view-as' => ['admin_id' => 1, 'company_id' => $this->companyId, 'guard' => 'pos', 'readonly' => true],
            'manage-as' => ['admin_id' => 1, 'company_id' => $this->companyId, 'guard' => 'pos', 'readonly' => false],
        ] as $mode => $impersonation) {
            if ($impersonation === null) {
                auth('admin')->logout();
            } else {
                $admin = new AdminUser(['name' => 'Test Admin', 'email' => 'admin@example.test', 'role' => 'super_admin']);
                $admin->id = 1;
                auth('admin')->setUser($admin);
            }
            $request = Request::create('/pos/inventory-master/export', 'GET');
            $request->setLaravelSession(app('session.store'));
            $request->session()->forget('impersonation');
            if ($impersonation !== null) {
                $request->session()->put('impersonation', $impersonation);
            }
            app()->instance('request', $request);

            $response = (new ReadOnlyImpersonation())->handle(
                $request,
                fn () => (new PosInventoryMasterController(new PosInventoryMasterExcelService()))->export()
            );
            $this->assertSame(200, $response->getStatusCode(), $mode);
            $this->assertSame($impersonation, $request->session()->get('impersonation'), $mode);
            $this->assertStringContainsString(
                PosInventoryMasterExcelService::EXPORT_FILENAME,
                (string) $response->headers->get('content-disposition'),
                $mode
            );
            ob_start();
            $response->sendContent();
            $bytes = (string) ob_get_clean();
            $this->assertStringStartsWith("PK\x03\x04", $bytes, $mode);
        }

        $after = [
            'products' => PosProduct::orderBy('id')->get()->toJson(),
            'ingredients' => Ingredient::orderBy('id')->get()->toJson(),
            'recipes' => ProductRecipe::orderBy('id')->get()->toJson(),
            'ingredient_stocks' => IngredientStock::orderBy('id')->get()->toJson(),
            'ingredient_movements' => IngredientMovement::orderBy('id')->get()->toJson(),
            'inventory_movements' => InventoryMovement::orderBy('id')->get()->toJson(),
        ];
        $this->assertSame($before, $after);
    }

    public function test_empty_company_export_is_valid_header_only_workbook_without_samples(): void
    {
        PosProduct::query()->delete();
        Ingredient::query()->delete();
        ProductRecipe::query()->delete();

        $spreadsheet = (new PosInventoryMasterExcelService())->buildExportWorkbook($this->companyId);
        $this->assertSame(['Start Here', 'Products', 'Ingredients', 'Recipes', 'Lists'], $spreadsheet->getSheetNames());
        foreach ([
            'Products' => 'N',
            'Ingredients' => 'J',
            'Recipes' => 'F',
        ] as $sheetName => $lastColumn) {
            $sheet = $spreadsheet->getSheetByName($sheetName);
            $this->assertNotSame('', trim((string) $sheet->getCell('A1')->getValue()));
            $this->assertSame([], array_values(array_filter(
                $sheet->rangeToArray("A2:{$lastColumn}5", null, true, true, false),
                fn (array $row): bool => count(array_filter($row, fn ($value) => $value !== null && $value !== '')) > 0
            )));
        }
    }

    public function test_menu_workbook_rejects_actual_formula_cells_but_allows_formula_like_text(): void
    {
        $service = new PosInventoryMasterExcelService();
        $spreadsheet = $service->buildWorkbook();
        $spreadsheet->getSheetByName('Products')->setCellValue('A2', 'Safe Text Product');
        $spreadsheet->getSheetByName('Products')->setCellValue('C2', 'Resale');
        $spreadsheet->getSheetByName('Products')->setCellValue('D2', 100);
        $spreadsheet->getSheetByName('Products')->setCellValueExplicit('N2', '=literal description', DataType::TYPE_STRING);
        $path = tempnam(sys_get_temp_dir(), 'safe-text') . '.xlsx';
        (new \PhpOffice\PhpSpreadsheet\Writer\Xlsx($spreadsheet))->save($path);
        $safePreview = $service->preview($path, $this->companyId, 'create_only', 1);
        $this->assertTrue($safePreview['ok'], json_encode($safePreview));

        $spreadsheet->getSheetByName('Products')->setCellValue('N2', '=1+1');
        $formulaPath = tempnam(sys_get_temp_dir(), 'formula') . '.xlsx';
        (new \PhpOffice\PhpSpreadsheet\Writer\Xlsx($spreadsheet))->save($formulaPath);
        $formulaPreview = $service->preview($formulaPath, $this->companyId, 'create_only', 1);
        $this->assertFalse($formulaPreview['ok']);
        $this->assertStringContainsString('security validation', json_encode($formulaPreview));
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

    public function test_in_file_duplicate_product_rows_are_reported_and_first_row_is_kept(): void
    {
        $result = $this->importRows(self::HEADER, [
            ['PRODUCT', 'Coke 500ml', 'DK-500', 80, 'Drinks', 'first', '', '', '', '', '', '', '', '', '', '', ''],
            ['PRODUCT', 'Coke 500ml', 'DK-500', 95, 'Soda', 'second', '', '', '', '', '', '', '', '', '', '', ''],
        ]);

        $this->assertTrue($result['ok'], $result['message']);
        $this->assertSame(1, PosProduct::where('company_id', $this->companyId)->count());
        $p = PosProduct::first();
        $this->assertEquals(80.0, (float) $p->price);
        $this->assertSame('Drinks', $p->category);
        $this->assertSame('first', $p->description);
        $this->assertSame(1, $result['counts']['rows_skipped']);
        $this->assertStringContainsString('row 2', strtolower(implode(' ', $result['errors'])));
        $this->assertNull($p->stock_quantity);
        $this->assertSame(0, InventoryMovement::count());
        $this->assertSame(0, IngredientMovement::count());
    }

    public function test_in_file_duplicate_ingredient_rows_are_reported_and_first_row_is_kept(): void
    {
        $result = $this->importRows(self::HEADER, [
            ['INGREDIENT', '', '', '', '', '', '', '', '', '', 'Basmati Rice', 'RICE-25', 'kg', 300, 50, '', ''],
            ['INGREDIENT', '', '', '', '', '', '', '', '', '', 'Basmati Rice', 'RICE-25', 'kg', 310, 60, '', ''],
        ]);

        $this->assertTrue($result['ok'], $result['message']);
        $this->assertSame(1, Ingredient::where('company_id', $this->companyId)->count());
        $ing = Ingredient::first();
        $this->assertEquals(300.0, (float) $ing->cost_per_unit);
        $this->assertEquals(50.0, (float) $ing->min_stock_level);
        $this->assertSame(1, $result['counts']['rows_skipped']);
        $this->assertStringContainsString('row 2', strtolower(implode(' ', $result['errors'])));
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
        $this->assertEquals(400.0, (float) $p->price);
        $this->assertSame('first', $p->description);
        $this->assertSame(1, $result['counts']['rows_skipped']);
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
        $this->assertEquals(300.0, (float) $ing->cost_per_unit);
        $this->assertSame(1, $result['counts']['rows_skipped']);
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

    public function test_in_file_duplicate_recipe_rows_are_reported_and_first_quantity_is_kept(): void
    {
        $result = $this->importRows(self::HEADER, [
            ['PRODUCT', 'Chicken Biryani', 'BRY-001', 450, 'Rice', '', '', '', '', '', '', '', '', '', '', '', ''],
            ['INGREDIENT', '', '', '', '', '', '', '', '', '', 'Basmati Rice', 'RICE-25', 'kg', 300, 50, '', ''],
            ['RECIPE', 'Chicken Biryani', 'BRY-001', '', '', '', '', '', '', '', 'Basmati Rice', 'RICE-25', 'kg', '', '', 0.25, ''],
            ['RECIPE', ' chicken   biryani ', 'bry-001', '', '', '', '', '', '', '', ' BASMATI RICE ', 'rice-25', 'KG', '', '', 0.50, ''],
        ]);

        $this->assertTrue($result['ok'], $result['message']);
        $this->assertSame(1, ProductRecipe::where('company_id', $this->companyId)->count());
        $this->assertEquals(0.25, (float) ProductRecipe::first()->quantity_needed);
        $this->assertSame(1, $result['counts']['rows_skipped']);
        $this->assertStringContainsString('row 4', strtolower(implode(' ', $result['errors'])));
    }

    public function test_menu_preview_is_zero_write_and_confirm_resolves_same_file_dependencies(): void
    {
        $spreadsheet = (new PosInventoryMasterExcelService())->buildWorkbook();
        $spreadsheet->getSheetByName('Products')->fromArray([
            ['Tea', 'Beverages', 'Recipe', 100, 40, 'TEA-1', 'BC-1', 'pcs', 0, 'Yes', 'No', '', '', '', ''],
        ], null, 'A2');
        $spreadsheet->getSheetByName('Ingredients')->fromArray([
            ['Tea Leaves', 'Kitchen', 'kg', 'g', 1000, 500, '', 0, 'Supplier', 'Yes', '', ''],
        ], null, 'A2');
        $spreadsheet->getSheetByName('Recipes')->fromArray([
            ['Tea', 'Tea Leaves', 2, 'g', 5, 'Brew loss', 'TEA-1', '', '', ''],
        ], null, 'A2');
        $path = tempnam(sys_get_temp_dir(), 'menu') . '.xlsx';
        (new \PhpOffice\PhpSpreadsheet\Writer\Xlsx($spreadsheet))->save($path);

        $service = new PosInventoryMasterExcelService();
        $preview = $service->preview($path, $this->companyId, 'create_only', 1);
        $this->assertTrue($preview['ok'], json_encode($preview));
        $this->assertSame(0, PosProduct::count());
        $this->assertSame(0, Ingredient::count());
        $result = $service->confirm($preview['token'], $this->companyId, true, 1);
        $this->assertTrue($result['ok'], json_encode($result));
        $this->assertSame(1, PosProduct::count());
        $this->assertSame(1, Ingredient::count());
        $this->assertSame(1, ProductRecipe::count());
    }
}

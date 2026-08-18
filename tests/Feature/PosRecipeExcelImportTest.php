<?php

namespace Tests\Feature;

use App\Http\Controllers\IngredientController;
use App\Models\Company;
use App\Models\Ingredient;
use App\Models\PosProduct;
use App\Models\ProductRecipe;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

/**
 * Task 1162 — Recipes Excel bulk upload.
 *
 * - Products matched company-scoped by code (barcode → SKU) then name.
 * - Missing ingredients are created on the fly (name+unit).
 * - Duplicate product+ingredient rows UPDATE quantity instead of erroring.
 * - Bad rows are skipped with a reason and never abort the file.
 * - Untouched template sample rows never become real recipes.
 *
 * Pattern: APP_ENV=testing + sqlite :memory: + minimal Schema::create.
 */
class PosRecipeExcelImportTest extends TestCase
{
    protected int $companyId;
    protected int $otherCompanyId;

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
            $table->string('sku')->nullable();
            $table->string('barcode')->nullable();
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });

        Schema::create('ingredients', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('company_id');
            $table->string('name');
            $table->string('unit', 20);
            $table->decimal('cost_per_unit', 15, 2)->default(0);
            $table->decimal('current_stock', 15, 2)->default(0);
            $table->decimal('min_stock_level', 15, 2)->default(0);
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });

        Schema::create('product_recipes', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('company_id');
            $table->unsignedBigInteger('product_id');
            $table->unsignedBigInteger('ingredient_id');
            $table->decimal('quantity_needed', 10, 4);
            $table->timestamps();
            $table->unique(['product_id', 'ingredient_id']);
        });

        $company = Company::create(['name' => 'Recipe Shop']);
        $this->companyId = $company->id;
        $other = Company::create(['name' => 'Other Shop']);
        $this->otherCompanyId = $other->id;

        app()->bind('currentCompanyId', fn () => $this->companyId);
    }

    /** Build an .xlsx upload and run importRecipes(). */
    private function importRows(array $header, array $rows): Request
    {
        $spreadsheet = new \PhpOffice\PhpSpreadsheet\Spreadsheet();
        $sheet = $spreadsheet->getActiveSheet();
        $sheet->fromArray($header, null, 'A1');
        $r = 2;
        foreach ($rows as $row) {
            $sheet->fromArray($row, null, 'A' . $r++);
        }
        $tmp = tempnam(sys_get_temp_dir(), 'rcp') . '.xlsx';
        (new \PhpOffice\PhpSpreadsheet\Writer\Xlsx($spreadsheet))->save($tmp);

        $upload = new UploadedFile($tmp, 'recipes.xlsx', 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet', null, true);

        $req = Request::create('/pos/restaurant/recipes/import', 'POST');
        $req->files->set('excel_file', $upload);
        $req->setLaravelSession(app('session.store'));
        app()->instance('request', $req);

        (new IngredientController())->importRecipes($req);

        return $req;
    }

    private const HEADER = ['Product Name', 'Product Code (SKU/Barcode)', 'Ingredient Name', 'Unit', 'Quantity Needed', 'Cost per Unit (optional)'];

    public function test_import_creates_ingredients_and_recipes(): void
    {
        $pizza = PosProduct::create(['company_id' => $this->companyId, 'name' => 'ZFC Special Large', 'price' => 1200]);

        $this->importRows(self::HEADER, [
            ['ZFC Special Large', '', 'Pizza Dough', 'g', 350, 0.15],
            ['ZFC Special Large', '', 'Mozzarella', 'g', 120, 1.2],
        ]);

        $this->assertSame(2, Ingredient::where('company_id', $this->companyId)->count());
        $this->assertSame(2, ProductRecipe::where('company_id', $this->companyId)->where('product_id', $pizza->id)->count());

        $dough = Ingredient::where('name', 'Pizza Dough')->first();
        $this->assertSame('g', $dough->unit);
        $this->assertEquals(0.15, (float) $dough->cost_per_unit);
        $this->assertEquals(350.0, (float) ProductRecipe::where('ingredient_id', $dough->id)->first()->quantity_needed);

        $this->assertStringContainsString('2 nayi recipe rows add hui', (string) session('success'));
        $this->assertStringContainsString('2 naye ingredients bane', (string) session('success'));
    }

    public function test_duplicate_product_ingredient_updates_quantity(): void
    {
        $burger = PosProduct::create(['company_id' => $this->companyId, 'name' => 'Beef Burger', 'price' => 500]);
        $bun = Ingredient::create(['company_id' => $this->companyId, 'name' => 'Big Bun', 'unit' => 'pcs', 'cost_per_unit' => 20]);
        ProductRecipe::create(['company_id' => $this->companyId, 'product_id' => $burger->id, 'ingredient_id' => $bun->id, 'quantity_needed' => 1]);

        $this->importRows(self::HEADER, [
            ['Beef Burger', '', 'Big Bun', 'pcs', 2, ''],
        ]);

        $this->assertSame(1, ProductRecipe::where('company_id', $this->companyId)->count(), 'Duplicate must update, not create');
        $this->assertEquals(2.0, (float) ProductRecipe::first()->quantity_needed);
        $this->assertStringContainsString('1 update hui', (string) session('success'));
    }

    public function test_product_matched_by_sku_and_barcode_company_scoped(): void
    {
        $mine = PosProduct::create(['company_id' => $this->companyId, 'name' => 'Shawarma', 'price' => 300, 'sku' => 'SHW-1', 'barcode' => '8901234567890']);
        // Same code in ANOTHER company must never match.
        PosProduct::create(['company_id' => $this->otherCompanyId, 'name' => 'Foreign', 'price' => 1, 'sku' => 'FR-9']);

        $this->importRows(self::HEADER, [
            ['', 'SHW-1', 'Pita Bread', 'pcs', 1, 10],
            ['', '8901234567890', 'Garlic Sauce', 'g', 30, 0.5],
            ['', 'FR-9', 'Wrong Company', 'pcs', 1, ''],
        ]);

        $this->assertSame(2, ProductRecipe::where('product_id', $mine->id)->count());
        $this->assertSame(0, ProductRecipe::where('company_id', $this->otherCompanyId)->count());
        $this->assertStringContainsString('1 rows skip hui', (string) session('success'));
        $this->assertStringContainsString("'FR-9' nahi mila", (string) session('success'));
    }

    public function test_bad_rows_skip_with_reason_but_never_abort_file(): void
    {
        $ok = PosProduct::create(['company_id' => $this->companyId, 'name' => 'Fries', 'price' => 150]);

        $this->importRows(self::HEADER, [
            ['Fries', '', 'Potato', 'kg', 0.3, 80],          // good
            ['Nahi Milta', '', 'Potato', 'kg', 1, ''],        // unknown product
            ['Fries', '', '', 'kg', 1, ''],                    // blank ingredient
            ['Fries', '', 'Salt', 'g', 'abc', ''],             // bad qty
        ]);

        $this->assertSame(1, ProductRecipe::where('product_id', $ok->id)->count());
        $flash = (string) session('success');
        $this->assertStringContainsString('1 nayi recipe rows add hui', $flash);
        $this->assertStringContainsString('3 rows skip hui', $flash);
        $this->assertStringContainsString('miqdaar samajh nahi aayi', $flash);
    }

    public function test_untouched_template_sample_rows_skipped_by_marker_only(): void
    {
        PosProduct::create(['company_id' => $this->companyId, 'name' => 'Chicken Burger', 'price' => 450]);

        $this->importRows(self::HEADER, [
            ['Misal: Chicken Burger', '', 'Bun', 'pcs', 1, 15],   // template sample (explicit marker) — skipped
            ['Chicken Burger', '', 'Bun', 'pcs', 2, 15],           // real row — imports
        ]);

        $this->assertSame(1, ProductRecipe::where('company_id', $this->companyId)->count());
        $this->assertEquals(2.0, (float) ProductRecipe::first()->quantity_needed);
        $this->assertStringContainsString('1 template sample rows chhori gayi', (string) session('success'));
    }

    public function test_real_rows_matching_template_example_values_always_import(): void
    {
        // Regression (completion review): a REAL recipe whose values happen to
        // equal a template example (Chicken Burger + Bun, qty 1) must never be
        // inferred as sample data — creates on first upload, updates on re-upload.
        $burger = PosProduct::create(['company_id' => $this->companyId, 'name' => 'Chicken Burger', 'price' => 450]);
        $pizza = PosProduct::create(['company_id' => $this->companyId, 'name' => 'Zinger Large Pizza', 'price' => 1500]);

        $this->importRows(self::HEADER, [
            ['Chicken Burger', '', 'Bun', 'pcs', 1, 15],
            ['Chicken Burger', '', 'Chicken Patty', 'pcs', 1, 60],
            ['Chicken Burger', '', 'Mayo Sauce', 'g', 20, 0.4],
            ['Zinger Large Pizza', '', 'Pizza Dough', 'g', 350, 0.15],
            ['Zinger Large Pizza', '', 'Cheese', 'g', 120, 1.2],
        ]);

        $this->assertSame(3, ProductRecipe::where('product_id', $burger->id)->count());
        $this->assertSame(2, ProductRecipe::where('product_id', $pizza->id)->count());
        $this->assertStringContainsString('5 nayi recipe rows add hui', (string) session('success'));

        // Existing Chicken Burger/Bun recipe: a qty-1 row must UPDATE it, not vanish.
        $bun = Ingredient::where('name', 'Bun')->first();
        ProductRecipe::where('product_id', $burger->id)->where('ingredient_id', $bun->id)->update(['quantity_needed' => 3]);
        session()->forget('success');

        $this->importRows(self::HEADER, [
            ['Chicken Burger', '', 'Bun', 'pcs', 1, 15],
        ]);

        $this->assertEquals(1.0, (float) ProductRecipe::where('product_id', $burger->id)->where('ingredient_id', $bun->id)->first()->quantity_needed);
        $this->assertStringContainsString('1 update hui', (string) session('success'));
    }

    public function test_existing_ingredient_reused_by_name_and_unit(): void
    {
        PosProduct::create(['company_id' => $this->companyId, 'name' => 'Karahi', 'price' => 900]);
        Ingredient::create(['company_id' => $this->companyId, 'name' => 'Chicken', 'unit' => 'kg', 'cost_per_unit' => 550]);

        $this->importRows(self::HEADER, [
            ['Karahi', '', 'chicken', 'KG', 0.75, ''],   // case-insensitive name+unit match
        ]);

        $this->assertSame(1, Ingredient::where('company_id', $this->companyId)->count(), 'Must reuse, not duplicate');
        $this->assertSame(1, ProductRecipe::where('company_id', $this->companyId)->count());
    }

    public function test_empty_file_and_missing_columns_rejected(): void
    {
        $this->importRows(self::HEADER, []);
        $this->assertStringContainsString('File khali hai', (string) session('error'));

        session()->forget('error');

        $this->importRows(['Foo', 'Bar'], [['a', 'b']]);
        $this->assertStringContainsString('columns nahi mile', (string) session('error'));
    }
}

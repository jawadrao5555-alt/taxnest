<?php

namespace Tests\Feature;

use App\Http\Controllers\PosStockInController;
use App\Models\Company;
use App\Models\Ingredient;
use App\Models\IngredientMovement;
use App\Models\InventoryMovement;
use App\Models\InventoryStock;
use App\Models\PosProduct;
use App\Models\PosStockInBatch;
use App\Models\PosStockInLine;
use App\Models\User;
use App\Services\BranchStockService;
use App\Services\PosFeatureService;
use App\Services\PosInventoryMasterExcelService;
use App\Services\PosStockInExcelService;
use App\Services\PosStockInService;
use App\Services\RecipeInventoryService;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Schema;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Tests\TestCase;

/**
 * NestPOS Stock-In Excel Phase 2a — single-shop receiving.
 * Master Excel remains catalog-only. No second ledger.
 */
class PosStockInExcelTest extends TestCase
{
    protected int $companyId;

    protected function setUp(): void
    {
        parent::setUp();

        Schema::dropAllTables();
        PosFeatureService::flushGateCaches();
        PosFeatureService::assumeExtrasColumn(false);
        BranchStockService::flushMemo();

        Schema::create('companies', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->boolean('is_internal_account')->default(false);
            $table->text('feature_flags')->nullable();
            $table->boolean('inventory_enabled')->default(true);
            $table->softDeletes();
            $table->timestamps();
        });

        Schema::create('users', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('company_id')->nullable();
            $table->string('name')->nullable();
            $table->string('email')->nullable();
            $table->string('password')->nullable();
            $table->string('pos_role')->nullable();
            $table->string('role')->nullable();
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
            $table->decimal('cost_per_unit', 15, 2)->default(0);
            $table->decimal('current_stock', 15, 4)->default(0);
            $table->decimal('min_stock_level', 15, 4)->default(0);
            $table->boolean('is_active')->default(true);
            $table->timestamps();
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
            $table->decimal('balance_after', 15, 4)->default(0);
            $table->string('reference_type')->nullable();
            $table->unsignedBigInteger('reference_id')->nullable();
            $table->string('reference_number')->nullable();
            $table->text('snapshot')->nullable();
            $table->unsignedBigInteger('created_by')->nullable();
            $table->timestamps();
        });

        Schema::create('inventory_stocks', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('company_id');
            $table->unsignedBigInteger('product_id');
            $table->unsignedBigInteger('branch_id')->nullable();
            $table->decimal('quantity', 15, 2)->default(0);
            $table->decimal('min_stock_level', 15, 2)->default(0);
            $table->decimal('avg_purchase_price', 15, 2)->default(0);
            $table->decimal('last_purchase_price', 15, 2)->default(0);
            $table->timestamps();
        });

        Schema::create('inventory_movements', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('company_id');
            $table->unsignedBigInteger('product_id')->nullable();
            $table->unsignedBigInteger('branch_id')->nullable();
            $table->string('type');
            $table->decimal('quantity', 15, 4)->default(0);
            $table->decimal('unit_price', 15, 2)->default(0);
            $table->decimal('balance_after', 15, 4)->default(0);
            $table->string('reference_type')->nullable();
            $table->unsignedBigInteger('reference_id')->nullable();
            $table->string('reference_number')->nullable();
            $table->text('notes')->nullable();
            $table->unsignedBigInteger('created_by')->nullable();
            $table->timestamps();
        });

        Schema::create('pos_stock_in_batches', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('company_id');
            $table->unsignedBigInteger('created_by')->nullable();
            $table->unsignedBigInteger('branch_id')->nullable();
            $table->string('reference');
            $table->boolean('update_cost')->default(false);
            $table->boolean('save_supplier_code')->default(false);
            $table->string('status', 20)->default('open');
            $table->string('original_filename')->nullable();
            $table->unsignedInteger('line_count')->default(0);
            $table->timestamp('posted_at')->nullable();
            $table->timestamps();
        });

        Schema::create('pos_stock_in_lines', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('batch_id');
            $table->unsignedInteger('source_row_no');
            $table->string('line_type', 20);
            $table->string('supplier_item_code')->nullable();
            $table->string('supplier_item_name')->nullable();
            $table->string('nestpos_code_raw')->nullable();
            $table->decimal('qty', 15, 4)->default(0);
            $table->string('unit', 30)->nullable();
            $table->decimal('rate', 15, 4)->nullable();
            $table->unsignedBigInteger('branch_id')->nullable();
            $table->string('reference_override')->nullable();
            $table->string('notes')->nullable();
            $table->string('match_status', 30);
            $table->unsignedBigInteger('matched_ingredient_id')->nullable();
            $table->unsignedBigInteger('matched_product_id')->nullable();
            $table->string('invalid_reason')->nullable();
            $table->boolean('selected')->default(false);
            $table->unsignedBigInteger('collapsed_into_line_id')->nullable();
            $table->timestamp('posted_at')->nullable();
            $table->unsignedBigInteger('movement_id')->nullable();
            $table->string('movement_table', 40)->nullable();
            $table->timestamps();
        });

        $company = Company::create([
            'name' => 'Stock-In Shop',
            'is_internal_account' => true,
            'inventory_enabled' => true,
            'feature_flags' => ['inventory' => true, 'recipes' => true],
        ]);
        $this->companyId = $company->id;
        app()->bind('currentCompanyId', fn () => $this->companyId);
    }

    private function company(): Company
    {
        return Company::find($this->companyId);
    }

    private function service(): PosStockInService
    {
        return new PosStockInService(new PosStockInExcelService());
    }

    private function product(array $over = []): PosProduct
    {
        return PosProduct::create(array_merge([
            'company_id' => $this->companyId,
            'name' => 'Coke 500ml',
            'price' => 80,
            'sku' => 'DK-500',
            'barcode' => '628100000001',
            'uom' => 'NOS',
            'stock_quantity' => 0,
            'is_active' => true,
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
            'current_stock' => 10,
            'min_stock_level' => 50,
        ], $over));
    }

    private function xlsx(array $rows): UploadedFile
    {
        $spreadsheet = new \PhpOffice\PhpSpreadsheet\Spreadsheet();
        $sheet = $spreadsheet->getActiveSheet();
        $sheet->setTitle(PosStockInExcelService::SHEET_NAME);
        $sheet->fromArray(PosStockInExcelService::HEADERS, null, 'A1');
        $r = 2;
        foreach ($rows as $row) {
            $sheet->fromArray($row, null, 'A' . $r++);
        }
        $tmp = tempnam(sys_get_temp_dir(), 'si') . '.xlsx';
        (new \PhpOffice\PhpSpreadsheet\Writer\Xlsx($spreadsheet))->save($tmp);

        return new UploadedFile($tmp, 'nestpos_stock_in.xlsx', 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet', null, true);
    }

    private function stage(array $rows, string $ref = 'INV-88', bool $updateCost = false, bool $saveCode = false): PosStockInBatch
    {
        return $this->service()->stageUpload($this->company(), $this->xlsx($rows), 1, $ref, $updateCost, $saveCode);
    }

    public function test_ingredient_code_matching(): void
    {
        $ing = $this->ingredient();
        $batch = $this->stage([
            ['INGREDIENT', 'RICE-25', 'RICE-25', 'Basmati Rice', 5, 'kg', 300, '', 'INV-88', '', ''],
        ]);
        $line = $batch->lines->first();
        $this->assertSame(PosStockInLine::MATCHED, $line->match_status);
        $this->assertSame((int) $ing->id, (int) $line->matched_ingredient_id);

        $this->service()->post($batch, $this->company(), 1);
        $this->assertEquals(15.0, (float) $ing->fresh()->current_stock);
    }

    public function test_ingredient_name_plus_unit_fallback(): void
    {
        $ing = $this->ingredient();
        $batch = $this->stage([
            ['INGREDIENT', '', 'X', 'Basmati Rice', 3, 'kg', 300, '', 'INV-88', '', ''],
        ]);
        $this->assertSame(PosStockInLine::MATCHED, $batch->lines->first()->match_status);
        $this->assertSame((int) $ing->id, (int) $batch->lines->first()->matched_ingredient_id);
        $this->service()->post($batch, $this->company(), 1);
        $this->assertEquals(13.0, (float) $ing->fresh()->current_stock);
    }

    public function test_unit_mismatch_does_not_match(): void
    {
        $this->ingredient();
        $batch = $this->stage([
            ['INGREDIENT', 'RICE-25', 'RICE-25', 'Basmati Rice', 5, 'g', 300, '', 'INV-88', '', ''],
        ]);
        $this->assertSame(PosStockInLine::INVALID, $batch->lines->first()->match_status);
        try {
            $this->service()->post($batch, $this->company(), 1);
            $this->fail('unit mismatch must not post');
        } catch (ValidationException $e) {
            $this->assertNotEmpty($e->errors());
        }
        $this->assertEquals(10.0, (float) Ingredient::first()->current_stock);
    }

    public function test_item_barcode_sku_matching(): void
    {
        $p = $this->product();
        $batch = $this->stage([
            ['ITEM', '628100000001', '', 'Coke 500ml', 24, 'NOS', 55, '', 'INV-88', '', ''],
        ]);
        $this->assertSame(PosStockInLine::MATCHED, $batch->lines->first()->match_status);
        $this->assertSame((int) $p->id, (int) $batch->lines->first()->matched_product_id);
        $this->service()->post($batch, $this->company(), 1);
        $this->assertEquals(24.0, (float) InventoryStock::first()->quantity);
    }

    public function test_item_exact_name_fallback(): void
    {
        $p = $this->product(['barcode' => null, 'sku' => 'OTHER']);
        $batch = $this->stage([
            ['ITEM', '', '', 'Coke 500ml', 12, 'NOS', 55, '', 'INV-88', '', ''],
        ]);
        $this->assertSame((int) $p->id, (int) $batch->lines->first()->matched_product_id);
        $this->service()->post($batch, $this->company(), 1);
        $this->assertEquals(12.0, (float) InventoryStock::first()->quantity);
    }

    public function test_unmatched_ingredient_does_not_create_ingredient(): void
    {
        $this->ingredient();
        $before = Ingredient::count();
        $batch = $this->stage([
            ['INGREDIENT', 'TOM-99', 'TOM-99', 'Tomato Sauce', 4, 'kg', 100, '', 'INV-88', '', ''],
        ]);
        $this->assertSame(PosStockInLine::NEEDS_CLEARANCE, $batch->lines->first()->match_status);
        $this->assertSame($before, Ingredient::count());
        $this->assertSame(0, IngredientMovement::count());
    }

    public function test_unmatched_item_does_not_post(): void
    {
        $this->product();
        $batch = $this->stage([
            ['ITEM', 'NO-SUCH', '', 'Mystery Drink', 6, 'NOS', 10, '', 'INV-88', '', ''],
        ]);
        $this->assertSame(PosStockInLine::NEEDS_CLEARANCE, $batch->lines->first()->match_status);
        try {
            $this->service()->post($batch, $this->company(), 1);
            $this->fail('unmatched item must not post');
        } catch (ValidationException $e) {
            $this->assertNotEmpty($e->errors());
        }
        $this->assertSame(0, InventoryMovement::count());
        $this->assertSame(0, InventoryStock::count());
    }

    public function test_in_file_sum_same_ingredient_reference(): void
    {
        $ing = $this->ingredient();
        $batch = $this->stage([
            ['INGREDIENT', 'RICE-25', 'RICE-25', 'Basmati Rice', 500, 'kg', 300, '', 'INV-88', '', ''],
            ['INGREDIENT', 'RICE-25', 'RICE-25', 'Basmati Rice', 200, 'kg', 300, '', 'INV-88', '', ''],
        ]);
        $this->service()->post($batch, $this->company(), 1);
        $this->assertEquals(710.0, (float) $ing->fresh()->current_stock);
        $this->assertSame(1, IngredientMovement::count());
        $this->assertEquals(700.0, (float) IngredientMovement::first()->quantity);
        $this->assertNotNull($batch->fresh()->lines->firstWhere('collapsed_into_line_id', '!=', null));
    }

    public function test_duplicate_reference_is_idempotent(): void
    {
        $ing = $this->ingredient();
        $batch = $this->stage([
            ['INGREDIENT', 'RICE-25', 'RICE-25', 'Basmati Rice', 5, 'kg', 300, '', 'INV-88', '', ''],
        ]);
        $this->service()->post($batch, $this->company(), 1);
        $this->assertEquals(15.0, (float) $ing->fresh()->current_stock);

        $again = $this->stage([
            ['INGREDIENT', 'RICE-25', 'RICE-25', 'Basmati Rice', 5, 'kg', 300, '', 'INV-88', '', ''],
        ]);
        $result = $this->service()->post($again, $this->company(), 1);
        $this->assertSame(0, $result['posted']);
        $this->assertSame(1, $result['already']);
        $this->assertEquals(15.0, (float) $ing->fresh()->current_stock);
        $this->assertSame(1, IngredientMovement::count());
    }

    public function test_transaction_rollback_leaves_no_partial_stock(): void
    {
        $a = $this->product(['name' => 'Coke 500ml', 'sku' => 'DK-500', 'barcode' => '111']);
        $b = $this->product(['name' => 'Sprite 500ml', 'sku' => 'SP-500', 'barcode' => '222']);
        $batch = $this->stage([
            ['ITEM', '111', '', 'Coke 500ml', 10, 'NOS', 55, '', 'INV-88', '', ''],
            ['ITEM', '222', '', 'Sprite 500ml', 8, 'NOS', 50, '', 'INV-88', '', ''],
        ]);

        $calls = 0;
        InventoryMovement::creating(function () use (&$calls) {
            $calls++;
            if ($calls >= 2) {
                throw new \RuntimeException('forced post failure');
            }
        });

        try {
            $this->service()->post($batch, $this->company(), 1);
            $this->fail('expected failure');
        } catch (\RuntimeException $e) {
            $this->assertSame('forced post failure', $e->getMessage());
        }

        $this->assertSame(0, InventoryMovement::count());
        $this->assertEquals(0.0, (float) (InventoryStock::where('product_id', $a->id)->value('quantity') ?? 0));
        $this->assertEquals(0.0, (float) (InventoryStock::where('product_id', $b->id)->value('quantity') ?? 0));
        $this->assertTrue($batch->fresh()->lines->every(fn ($line) => $line->posted_at === null));
        $this->assertSame(PosStockInBatch::STATUS_OPEN, $batch->fresh()->status);
    }

    public function test_persistent_staging_survives_new_session(): void
    {
        $this->ingredient();
        $batch = $this->stage([
            ['INGREDIENT', 'RICE-25', 'RICE-25', 'Basmati Rice', 2, 'kg', 300, '', 'INV-88', '', ''],
        ]);
        $id = $batch->id;
        $this->flushSession();
        $reloaded = PosStockInBatch::query()->with('lines')->find($id);
        $this->assertNotNull($reloaded);
        $this->assertSame(PosStockInBatch::STATUS_OPEN, $reloaded->status);
        $this->assertSame(PosStockInLine::MATCHED, $reloaded->lines->first()->match_status);
        $this->assertSame('INV-88', $reloaded->reference);
    }

    public function test_recipes_disabled_shop_can_post_valid_item_rows(): void
    {
        Company::where('id', $this->companyId)->update(['feature_flags' => ['inventory' => true, 'recipes' => false]]);
        PosFeatureService::flushGateCaches();
        $ingCount = Ingredient::count();
        $p = $this->product();
        $batch = $this->stage([
            ['INGREDIENT', 'RICE-25', 'RICE-25', 'Basmati Rice', 5, 'kg', 300, '', 'INV-88', '', ''],
            ['ITEM', 'DK-500', '', 'Coke 500ml', 24, 'NOS', 55, '', 'INV-88', '', ''],
        ]);
        $ingLine = $batch->lines->firstWhere('line_type', 'INGREDIENT');
        $itemLine = $batch->lines->firstWhere('line_type', 'ITEM');
        $this->assertSame(PosStockInLine::SKIPPED_GATE, $ingLine->match_status);
        $this->assertSame(PosStockInLine::MATCHED, $itemLine->match_status);
        $this->assertSame($ingCount, Ingredient::count());
        $this->service()->post($batch, $this->company(), 1);
        $this->assertEquals(24.0, (float) InventoryStock::where('product_id', $p->id)->value('quantity'));
        $this->assertSame(0, IngredientMovement::count());
    }

    public function test_feature_gated_invalid_rows_do_not_post(): void
    {
        Company::where('id', $this->companyId)->update(['feature_flags' => ['inventory' => true, 'recipes' => false]]);
        PosFeatureService::flushGateCaches();
        $ing = $this->ingredient();
        $stock = (float) $ing->current_stock;
        $batch = $this->stage([
            ['INGREDIENT', 'RICE-25', 'RICE-25', 'Basmati Rice', 9, 'kg', 300, '', 'INV-88', '', ''],
        ]);
        $this->assertSame(PosStockInLine::SKIPPED_GATE, $batch->lines->first()->match_status);
        try {
            $this->service()->post($batch, $this->company(), 1);
            $this->fail('gated file must not post');
        } catch (ValidationException $e) {
            $this->assertNotEmpty($e->errors());
        }
        $this->assertEquals($stock, (float) $ing->fresh()->current_stock);
        $this->assertSame(0, IngredientMovement::count());
    }

    public function test_cashier_cannot_post_stock_in(): void
    {
        $this->ingredient();
        $batch = $this->stage([
            ['INGREDIENT', 'RICE-25', 'RICE-25', 'Basmati Rice', 1, 'kg', 300, '', 'INV-88', '', ''],
        ]);
        $cashier = User::create([
            'company_id' => $this->companyId,
            'name' => 'Cashier',
            'email' => 'cashier@example.test',
            'password' => 'x',
            'pos_role' => 'pos_cashier',
            'role' => 'pos_user',
        ]);
        $this->actingAs($cashier, 'pos');
        $controller = new PosStockInController($this->service(), new PosStockInExcelService());
        try {
            $controller->post(Request::create('/pos/inventory/stock-in/'.$batch->id.'/post', 'POST', [
                'selected' => [$batch->lines->first()->id],
            ]), $batch->id);
            $this->fail('cashier must be refused');
        } catch (HttpException $e) {
            $this->assertSame(403, $e->getStatusCode());
        }
        $this->assertSame(0, IngredientMovement::count());
        $this->assertEquals(10.0, (float) Ingredient::first()->current_stock);
    }

    public function test_multi_branch_company_is_refused_safely(): void
    {
        Schema::create('branches', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('company_id');
            $table->string('name');
            $table->boolean('is_head_office')->default(false);
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });
        \Illuminate\Support\Facades\DB::table('branches')->insert([
            'company_id' => $this->companyId,
            'name' => 'Gulberg',
            'is_head_office' => 1,
            'is_active' => 1,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        BranchStockService::flushMemo();
        $this->ingredient();
        $this->assertTrue(BranchStockService::isMultiBranch($this->companyId));
        try {
            $this->stage([
                ['INGREDIENT', 'RICE-25', 'RICE-25', 'Basmati Rice', 1, 'kg', 300, '', 'INV-88', '', ''],
            ]);
            $this->fail('multi-branch must refuse staging');
        } catch (ValidationException $e) {
            $this->assertStringContainsString('single-shop', strtolower($e->getMessage() . json_encode($e->errors())));
        }
        $this->assertSame(0, PosStockInBatch::count());
        $this->assertSame(0, IngredientMovement::count());
        $this->assertEquals(10.0, (float) Ingredient::first()->current_stock);
    }

    public function test_stock_in_writes_reference_type_stock_in(): void
    {
        $this->ingredient();
        $p = $this->product();
        $batch = $this->stage([
            ['INGREDIENT', 'RICE-25', 'RICE-25', 'Basmati Rice', 2, 'kg', 300, '', 'INV-88', '', ''],
            ['ITEM', 'DK-500', '', 'Coke 500ml', 6, 'NOS', 55, '', 'INV-88', '', ''],
        ]);
        $this->service()->post($batch, $this->company(), 1);
        $this->assertSame('stock_in', IngredientMovement::first()->reference_type);
        $this->assertSame('INV-88', IngredientMovement::first()->reference_number);
        $this->assertSame('ingredient_adjustment', IngredientMovement::first()->type);
        $this->assertSame('stock_in', InventoryMovement::first()->reference_type);
        $this->assertSame('INV-88', InventoryMovement::first()->reference_number);
        $this->assertSame(InventoryMovement::TYPE_ADJUSTMENT_IN, InventoryMovement::first()->type);
        $this->assertNotSame(InventoryMovement::TYPE_PURCHASE, InventoryMovement::first()->type);
    }

    public function test_existing_inventory_and_ingredient_movement_behavior_remains_intact(): void
    {
        $ing = $this->ingredient();
        RecipeInventoryService::adjustIngredientStock($this->companyId, (int) $ing->id, 4, 1, null, 'Manual kitchen stock adjustment');
        $this->assertSame('pos_transaction', IngredientMovement::first()->reference_type);
        $manualQty = (float) IngredientMovement::first()->quantity;

        $p = $this->product();
        InventoryMovement::create([
            'company_id' => $this->companyId,
            'product_id' => $p->id,
            'type' => InventoryMovement::TYPE_ADJUSTMENT_IN,
            'quantity' => 3,
            'balance_after' => 3,
            'reference_type' => 'adjustment',
            'notes' => 'manual add',
        ]);

        $other = $this->product(['name' => 'Sprite 500ml', 'sku' => 'SP-500', 'barcode' => '999']);
        $batch = $this->stage([
            ['INGREDIENT', 'RICE-25', 'RICE-25', 'Basmati Rice', 1, 'kg', 300, '', 'SI-1', '', ''],
            ['ITEM', '999', '', 'Sprite 500ml', 2, 'NOS', 40, '', 'SI-1', '', ''],
        ]);
        $this->service()->post($batch, $this->company(), 1);

        $this->assertSame('pos_transaction', IngredientMovement::orderBy('id')->first()->reference_type);
        $this->assertEquals($manualQty, (float) IngredientMovement::orderBy('id')->first()->quantity);
        $this->assertSame('adjustment', InventoryMovement::where('reference_type', 'adjustment')->first()->reference_type);
        $this->assertSame(3.0, (float) InventoryMovement::where('reference_type', 'adjustment')->first()->quantity);
        $this->assertSame(1, InventoryMovement::where('reference_type', 'stock_in')->count());
        $this->assertSame(1, IngredientMovement::where('reference_type', 'stock_in')->count());
    }

    public function test_master_excel_remains_stock_neutral(): void
    {
        Schema::create('product_recipes', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('company_id');
            $table->unsignedBigInteger('product_id');
            $table->unsignedBigInteger('ingredient_id');
            $table->decimal('quantity_needed', 10, 4);
            $table->unsignedInteger('recipe_version')->default(1);
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });
        $p = $this->product(['stock_quantity' => 24]);
        $i = $this->ingredient(['current_stock' => 40]);
        $header = [
            'Row Type', 'Product Name', 'Product Code', 'Price', 'Category', 'Description',
            'Tax Rate %', 'Unit (UOM)', 'Tax Exempt', 'Third Schedule',
            'Ingredient Name', 'Ingredient Code', 'Ingredient Unit', 'Cost per Unit',
            'Min Stock', 'Quantity Needed', 'Active',
        ];
        $spreadsheet = new \PhpOffice\PhpSpreadsheet\Spreadsheet();
        $sheet = $spreadsheet->getActiveSheet();
        $sheet->setTitle(PosInventoryMasterExcelService::SHEET_NAME);
        $sheet->fromArray($header, null, 'A1');
        $sheet->fromArray(['PRODUCT', 'Coke 500ml', 'DK-500', 90, 'Drinks', '', '', '', '', '', '', '', '', '', '', '', ''], null, 'A2');
        $sheet->fromArray(['INGREDIENT', '', '', '', '', '', '', '', '', '', 'Basmati Rice', 'RICE-25', 'kg', 310, 60, '', ''], null, 'A3');
        $tmp = tempnam(sys_get_temp_dir(), 'mst') . '.xlsx';
        (new \PhpOffice\PhpSpreadsheet\Writer\Xlsx($spreadsheet))->save($tmp);
        (new PosInventoryMasterExcelService())->import($tmp, $this->companyId, $this->company());

        $this->assertEquals(24, (int) $p->fresh()->stock_quantity);
        $this->assertEquals(40.0, (float) $i->fresh()->current_stock);
        $this->assertSame(0, IngredientMovement::count());
        $this->assertSame(0, InventoryMovement::count());
    }

    public function test_cost_and_supplier_code_defaults_are_off(): void
    {
        $ing = $this->ingredient(['code' => null, 'cost_per_unit' => 300]);
        $batch = $this->stage([
            ['INGREDIENT', '', 'SUP-99', 'Basmati Rice', 2, 'kg', 999, '', 'INV-88', '', ''],
        ], 'INV-88', false, false);
        $this->assertFalse($batch->update_cost);
        $this->assertFalse($batch->save_supplier_code);
        $this->service()->post($batch, $this->company(), 1);
        $this->assertEquals(300.0, (float) $ing->fresh()->cost_per_unit);
        $this->assertTrue($ing->fresh()->code === null || $ing->fresh()->code === '');
    }

    public function test_template_has_stockin_sheet_and_skips_misal_samples(): void
    {
        $response = (new PosStockInExcelService())->streamTemplate();
        ob_start();
        $response->sendContent();
        $bytes = (string) ob_get_clean();
        $tmp = tempnam(sys_get_temp_dir(), 'sit') . '.xlsx';
        file_put_contents($tmp, $bytes);
        $ss = \PhpOffice\PhpSpreadsheet\IOFactory::load($tmp);
        $this->assertSame('StockIn', $ss->getSheet(0)->getTitle());
        $parsed = (new PosStockInExcelService())->parseFile($tmp);
        $this->assertFalse($parsed['ok']);
        $this->assertNotEmpty($parsed['fatal']);
    }
}

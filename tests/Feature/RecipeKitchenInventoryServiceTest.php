<?php

namespace Tests\Feature;

use App\Models\Company;
use App\Models\Ingredient;
use App\Models\InventoryMovement;
use App\Models\PosProduct;
use App\Models\PosTransaction;
use App\Models\ProductRecipe;
use App\Services\BranchStockService;
use App\Services\RecipeInventoryService;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class RecipeKitchenInventoryServiceTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        Schema::dropAllTables();

        Schema::create('companies', function (Blueprint $t) {
            $t->id();
            $t->string('name');
            $t->boolean('inventory_enabled')->default(true);
            $t->softDeletes();
            $t->timestamps();
        });
        Schema::create('pos_products', function (Blueprint $t) {
            $t->id();
            $t->unsignedBigInteger('company_id');
            $t->string('name');
            $t->timestamps();
        });
        Schema::create('ingredients', function (Blueprint $t) {
            $t->id();
            $t->unsignedBigInteger('company_id');
            $t->string('name');
            $t->string('unit', 20);
            $t->decimal('cost_per_unit', 15, 2)->default(0);
            $t->decimal('current_stock', 15, 4)->default(0);
            $t->decimal('min_stock_level', 15, 4)->default(0);
            $t->boolean('is_active')->default(true);
            $t->timestamps();
        });
        Schema::create('product_recipes', function (Blueprint $t) {
            $t->id();
            $t->unsignedBigInteger('company_id');
            $t->unsignedBigInteger('product_id');
            $t->unsignedBigInteger('ingredient_id');
            $t->decimal('quantity_needed', 10, 4);
            $t->unsignedInteger('recipe_version')->default(1);
            $t->boolean('is_active')->default(true);
            $t->timestamps();
        });
        Schema::create('inventory_movements', function (Blueprint $t) {
            $t->id();
            $t->unsignedBigInteger('company_id');
            $t->unsignedBigInteger('product_id');
            $t->unsignedBigInteger('branch_id')->nullable();
            $t->string('type', 30);
            $t->decimal('quantity', 15, 4);
            $t->decimal('unit_price', 15, 2)->default(0);
            $t->decimal('total_price', 15, 2)->default(0);
            $t->decimal('balance_after', 15, 4)->default(0);
            $t->string('reference_type')->nullable();
            $t->unsignedBigInteger('reference_id')->nullable();
            $t->string('reference_number')->nullable();
            $t->text('notes')->nullable();
            $t->unsignedBigInteger('created_by')->nullable();
            $t->timestamps();
        });
        Schema::create('ingredient_movements', function (Blueprint $t) {
            $t->id();
            $t->unsignedBigInteger('company_id');
            $t->unsignedBigInteger('ingredient_id');
            $t->unsignedBigInteger('branch_id')->nullable();
            $t->string('type');
            $t->decimal('quantity', 15, 4);
            $t->decimal('balance_after', 15, 4);
            $t->string('reference_type')->nullable();
            $t->unsignedBigInteger('reference_id')->nullable();
            $t->string('reference_number')->nullable();
            $t->json('snapshot')->nullable();
            $t->unsignedBigInteger('created_by')->nullable();
            $t->timestamps();
        });
        Schema::create('recipe_consumptions', function (Blueprint $t) {
            $t->id();
            $t->unsignedBigInteger('company_id');
            $t->unsignedBigInteger('transaction_id');
            $t->unsignedBigInteger('ingredient_id');
            $t->unsignedBigInteger('branch_id')->nullable();
            $t->decimal('quantity', 15, 4);
            $t->json('components')->nullable();
            $t->json('snapshot')->nullable();
            $t->string('invoice_number')->nullable();
            $t->timestamp('reversed_at')->nullable();
            $t->timestamps();
            $t->unique(['company_id', 'transaction_id', 'ingredient_id']);
        });
        Schema::create('prepared_returns', function (Blueprint $t) {
            $t->id();
            $t->unsignedBigInteger('company_id');
            $t->unsignedBigInteger('branch_id')->nullable();
            $t->unsignedBigInteger('product_id');
            $t->unsignedBigInteger('return_transaction_id');
            $t->decimal('quantity', 15, 4);
            $t->decimal('remaining_quantity', 15, 4);
            $t->decimal('consumed_quantity', 15, 4)->default(0);
            $t->string('status')->default('available');
            $t->timestamp('expires_at')->nullable();
            $t->unsignedBigInteger('consumed_by_transaction_id')->nullable();
            $t->timestamp('consumed_at')->nullable();
            $t->unsignedBigInteger('created_by')->nullable();
            $t->timestamps();
        });
        Schema::create('pos_transactions', function (Blueprint $t) {
            $t->id();
            $t->unsignedBigInteger('company_id');
            $t->string('invoice_number');
            $t->string('status')->default('completed');
            $t->string('transaction_type')->default('sale');
            $t->unsignedBigInteger('pra_dependency_transaction_id')->nullable();
            $t->timestamps();
        });
        BranchStockService::flushMemo();
    }

    public function test_repeated_ingredients_are_combined_and_sale_is_idempotent(): void
    {
        $company = Company::create(['name' => 'Kitchen', 'inventory_enabled' => true]);
        $ingredient = Ingredient::create([
            'company_id' => $company->id, 'name' => 'Dough', 'unit' => 'kg',
            'cost_per_unit' => 10, 'current_stock' => 10, 'min_stock_level' => 0,
        ]);
        $one = PosProduct::create(['company_id' => $company->id, 'name' => 'Pizza']);
        $two = PosProduct::create(['company_id' => $company->id, 'name' => 'Calzone']);
        ProductRecipe::create(['company_id' => $company->id, 'product_id' => $one->id, 'ingredient_id' => $ingredient->id, 'quantity_needed' => .25]);
        ProductRecipe::create(['company_id' => $company->id, 'product_id' => $two->id, 'ingredient_id' => $ingredient->id, 'quantity_needed' => .5]);

        RecipeInventoryService::consumeForInvoice($company->id, [
            ['type' => 'product', 'item_id' => $one->id, 'quantity' => 2],
            ['type' => 'product', 'item_id' => $two->id, 'quantity' => 1],
        ], 101, 'P101');
        RecipeInventoryService::consumeForInvoice($company->id, [
            ['type' => 'product', 'item_id' => $one->id, 'quantity' => 2],
        ], 101, 'P101');

        $this->assertSame('9.0000', Ingredient::find($ingredient->id)->current_stock);
        $this->assertSame(1, InventoryMovement::where('type', 'recipe_sale')->where('reference_id', 101)->count());
        $this->assertEquals(1.0, (float) \DB::table('recipe_consumptions')->where('transaction_id', 101)->value('quantity'));
    }

    public function test_deal_component_consumes_frozen_recipe_not_later_live_recipe(): void
    {
        $company = Company::create(['name' => 'Frozen Deal', 'inventory_enabled' => true]);
        $a = Ingredient::create(['company_id' => $company->id, 'name' => 'A', 'unit' => 'kg', 'current_stock' => 10]);
        $b = Ingredient::create(['company_id' => $company->id, 'name' => 'B', 'unit' => 'kg', 'current_stock' => 10]);
        $product = PosProduct::create(['company_id' => $company->id, 'name' => 'Meal']);
        ProductRecipe::create([
            'company_id' => $company->id, 'product_id' => $product->id,
            'ingredient_id' => $b->id, 'quantity_needed' => 3, 'recipe_version' => 2,
        ]);

        RecipeInventoryService::consumeForInvoice($company->id, [[
            'type' => 'product', 'item_id' => $product->id, 'quantity' => 2,
            '_deal_derived' => true, 'deal_id' => 44, 'mode' => 'recipe', 'has_recipe' => true,
            'recipe_snapshot' => [[
                'stock_id' => 'ingredient-' . $a->id, 'quantity' => 1,
                'company_id' => $company->id, 'recipe_version' => 1,
            ]],
        ]], 144, 'P144');

        $this->assertSame('8.0000', Ingredient::find($a->id)->current_stock);
        $this->assertSame('10.0000', Ingredient::find($b->id)->current_stock);
        $snapshot = json_decode((string) \DB::table('recipe_consumptions')->where('transaction_id', 144)->value('snapshot'), true);
        $this->assertSame($a->id, $snapshot[0]['ingredient_id']);
        $this->assertSame(44, $snapshot[0]['deal_id']);
    }

    public function test_deal_component_frozen_direct_mode_ignores_later_live_recipe(): void
    {
        $company = Company::create(['name' => 'Frozen Direct', 'inventory_enabled' => true]);
        $ingredient = Ingredient::create(['company_id' => $company->id, 'name' => 'A', 'unit' => 'kg', 'current_stock' => 10]);
        $product = PosProduct::create(['company_id' => $company->id, 'name' => 'Meal']);
        ProductRecipe::create([
            'company_id' => $company->id, 'product_id' => $product->id,
            'ingredient_id' => $ingredient->id, 'quantity_needed' => 1,
        ]);
        $this->assertFalse(RecipeInventoryService::itemUsesRecipe($company->id, [
            'item_id' => $product->id, '_deal_derived' => true,
            'mode' => 'direct', 'has_recipe' => false, 'recipe_snapshot' => [],
        ]));
    }

    public function test_snapshot_is_used_for_partial_normal_return_and_recipe_change_is_safe(): void
    {
        $company = Company::create(['name' => 'Snapshot', 'inventory_enabled' => true]);
        $ingredient = Ingredient::create([
            'company_id' => $company->id, 'name' => 'Sauce', 'unit' => 'ltr',
            'cost_per_unit' => 5, 'current_stock' => 10, 'min_stock_level' => 0,
        ]);
        $product = PosProduct::create(['company_id' => $company->id, 'name' => 'Burger']);
        $recipe = ProductRecipe::create([
            'company_id' => $company->id, 'product_id' => $product->id,
            'ingredient_id' => $ingredient->id, 'quantity_needed' => .25, 'recipe_version' => 1,
        ]);
        RecipeInventoryService::consumeForInvoice($company->id, [
            ['type' => 'product', 'item_id' => $product->id, 'quantity' => 2],
        ], 102, 'P102');
        $recipe->update(['quantity_needed' => .75, 'recipe_version' => 2]);

        RecipeInventoryService::restoreForReturn(
            $company->id, 102, 202,
            [['item_id' => $product->id, 'quantity' => 1, 'disposition' => 'normal_restock']]
        );
        $this->assertSame('9.7500', Ingredient::find($ingredient->id)->current_stock);
        RecipeInventoryService::consumeForInvoice($company->id, [
            ['type' => 'product', 'item_id' => $product->id, 'quantity' => 1],
        ], 103, 'P103');
        $this->assertSame('9.0000', Ingredient::find($ingredient->id)->current_stock);
    }

    public function test_prepared_cooked_return_is_consumed_once_before_fresh_recipe_stock(): void
    {
        $company = Company::create(['name' => 'Prepared', 'inventory_enabled' => true]);
        $ingredient = Ingredient::create([
            'company_id' => $company->id, 'name' => 'Patty', 'unit' => 'pcs',
            'cost_per_unit' => 20, 'current_stock' => 10, 'min_stock_level' => 0,
        ]);
        $product = PosProduct::create(['company_id' => $company->id, 'name' => 'Burger']);
        ProductRecipe::create([
            'company_id' => $company->id, 'product_id' => $product->id,
            'ingredient_id' => $ingredient->id, 'quantity_needed' => 1,
        ]);
        \DB::table('pos_transactions')->insert([
            'id' => 104, 'company_id' => $company->id, 'invoice_number' => 'P104',
            'status' => 'completed', 'transaction_type' => 'sale',
            'created_at' => now(), 'updated_at' => now(),
        ]);
        RecipeInventoryService::recordPreparedReturns(
            $company->id, 301, null,
            [['item_id' => $product->id, 'quantity' => 1, 'disposition' => 'cooked_resaleable']]
        );
        RecipeInventoryService::consumeForInvoice($company->id, [
            ['type' => 'product', 'item_id' => $product->id, 'quantity' => 2],
        ], 104, 'P104');

        $this->assertSame('9.0000', Ingredient::find($ingredient->id)->current_stock);
        $this->assertSame('consumed', \DB::table('prepared_returns')->value('status'));
        $this->assertSame(301, (int) PosTransaction::query()->whereKey(104)->value('pra_dependency_transaction_id'));
    }
}
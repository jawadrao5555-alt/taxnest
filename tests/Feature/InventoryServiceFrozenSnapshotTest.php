<?php

namespace Tests\Feature;

use App\Services\InventoryService;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

class InventoryServiceFrozenSnapshotTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        Schema::dropAllTables();
        Schema::create('pos_products', function (Blueprint $t) {
            $t->id(); $t->unsignedBigInteger('company_id'); $t->string('name'); $t->timestamps();
        });
        Schema::create('ingredients', function (Blueprint $t) {
            $t->id(); $t->unsignedBigInteger('company_id'); $t->string('name');
            $t->decimal('current_stock', 12, 4)->default(0); $t->decimal('cost_per_unit', 12, 4)->default(0); $t->timestamps();
        });
        Schema::create('inventory_stocks', function (Blueprint $t) {
            $t->id(); $t->unsignedBigInteger('company_id'); $t->unsignedBigInteger('product_id'); $t->unsignedBigInteger('branch_id')->nullable();
            $t->decimal('quantity', 12, 4)->default(0); $t->decimal('min_stock_level', 12, 4)->default(0);
            $t->decimal('avg_purchase_price', 12, 2)->default(0); $t->decimal('last_purchase_price', 12, 2)->default(0); $t->timestamps();
        });
        Schema::create('inventory_movements', function (Blueprint $t) {
            $t->id(); $t->unsignedBigInteger('company_id'); $t->unsignedBigInteger('product_id'); $t->unsignedBigInteger('branch_id')->nullable();
            $t->string('type'); $t->decimal('quantity', 12, 4); $t->decimal('unit_price', 12, 2); $t->decimal('total_price', 12, 2);
            $t->decimal('balance_after', 12, 4); $t->string('reference_type')->nullable(); $t->unsignedBigInteger('reference_id')->nullable();
            $t->string('reference_number')->nullable(); $t->text('notes')->nullable(); $t->unsignedBigInteger('created_by')->nullable(); $t->timestamps();
        });
    }

    private function sale(int $productId, array $recipe = []): array
    {
        return ['items' => [[
            'type' => 'product', 'item_id' => $productId, 'quantity' => 1,
            'tax_facts' => ['company_id' => 1, 'is_tax_exempt' => false, 'is_third_schedule' => false],
            'mode' => $recipe ? 'recipe' : 'direct', 'has_recipe' => (bool) $recipe, 'recipe_snapshot' => $recipe,
        ]]];
    }

    public function test_deleted_signed_product_is_skipped_without_orphan_inventory_rows(): void
    {
        $result = InventoryService::projectCanonicalSale(1, 1, $this->sale(99), 1, 'L-1');
        $this->assertSame('product_deleted', $result['skipped']['products'][99]);
        $this->assertSame(0, DB::table('inventory_stocks')->count());
        $this->assertSame(0, DB::table('inventory_movements')->count());
    }

    public function test_deleted_signed_recipe_ingredient_is_skipped_without_partial_projection(): void
    {
        DB::table('pos_products')->insert(['id' => 10, 'company_id' => 1, 'name' => 'Meal', 'created_at' => now(), 'updated_at' => now()]);
        $result = InventoryService::projectCanonicalSale(1, 1, $this->sale(10, [[
            'stock_id' => 'ingredient-99', 'quantity' => 2, 'company_id' => 1,
        ]]), 2, 'L-2');
        $this->assertSame('recipe_ingredient_deleted', $result['skipped']['products'][10]);
        $this->assertSame(0, DB::table('inventory_stocks')->count());
        $this->assertSame(0, DB::table('inventory_movements')->count());
    }

    public function test_existing_foreign_product_or_ingredient_is_rejected(): void
    {
        DB::table('pos_products')->insert(['id' => 20, 'company_id' => 2, 'name' => 'Foreign', 'created_at' => now(), 'updated_at' => now()]);
        try {
            InventoryService::canonicalConsumptions(1, $this->sale(20));
            $this->fail('foreign product must reject');
        } catch (ValidationException) {
            $this->addToAssertionCount(1);
        }
        DB::table('pos_products')->insert(['id' => 10, 'company_id' => 1, 'name' => 'Meal', 'created_at' => now(), 'updated_at' => now()]);
        DB::table('ingredients')->insert(['id' => 20, 'company_id' => 2, 'name' => 'Foreign ingredient', 'created_at' => now(), 'updated_at' => now()]);
        $this->expectException(ValidationException::class);
        InventoryService::canonicalConsumptions(1, $this->sale(10, [[
            'stock_id' => 'ingredient-20', 'quantity' => 1, 'company_id' => 1,
        ]]));
    }

    public function test_ingredient_deleted_after_canonicalization_skips_the_entire_recipe(): void
    {
        DB::table('pos_products')->insert(['id' => 10, 'company_id' => 1, 'name' => 'Meal', 'created_at' => now(), 'updated_at' => now()]);
        foreach ([31 => 'Rice', 32 => 'Sauce'] as $id => $name) {
            DB::table('ingredients')->insert([
                'id' => $id, 'company_id' => 1, 'name' => $name, 'current_stock' => 10,
                'created_at' => now(), 'updated_at' => now(),
            ]);
        }
        $consumptions = InventoryService::canonicalConsumptions(1, $this->sale(10, [
            ['stock_id' => 'ingredient-31', 'quantity' => 1, 'company_id' => 1],
            ['stock_id' => 'ingredient-32', 'quantity' => 2, 'company_id' => 1],
        ]));
        DB::table('ingredients')->where('id', 32)->delete();

        [$filtered, $skipped] = InventoryService::filterConsumptionsForLockedSources(
            $consumptions, [10], DB::table('ingredients')->pluck('id')->map(fn ($id) => (int) $id)->all()
        );

        $this->assertSame([], $filtered['ingredients']);
        $this->assertSame('recipe_ingredient_deleted_during_projection', $skipped['products'][10]);
        $this->assertSame(10.0, (float) DB::table('ingredients')->where('id', 31)->value('current_stock'));
        $this->assertSame(0, DB::table('inventory_movements')->count());
    }
}
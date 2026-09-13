<?php

namespace Tests\Feature;

use App\Http\Controllers\PosController;
use App\Models\Company;
use App\Models\Ingredient;
use App\Models\PosProduct;
use App\Models\ProductRecipe;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

class PosUnifiedProductRecipeIntegrityTest extends TestCase
{
    use RefreshDatabase;

    private Company $company;

    protected function setUp(): void
    {
        parent::setUp();

        $this->company = Company::create([
            'name' => 'Fictional Recipe QA',
            'ntn' => 'QA-' . uniqid(),
            'product_type' => 'pos',
            'business_category' => 'restaurant',
            'inventory_enabled' => false,
        ]);
        app()->instance('currentCompanyId', $this->company->id);
    }

    public function test_malformed_recipe_json_cannot_silently_create_a_product_without_its_recipe(): void
    {
        $this->expectException(ValidationException::class);

        try {
            $this->store(['ingredients_json' => '{broken']);
        } finally {
            $this->assertDatabaseMissing('pos_products', [
                'company_id' => $this->company->id,
                'name' => 'QA Pizza',
            ]);
        }
    }

    public function test_new_ingredient_alias_is_normalized_and_recipe_is_saved_atomically(): void
    {
        $this->store(['ingredients_json' => json_encode([[
            'mode' => 'new',
            'ingredient_id' => '',
            'new_name' => 'Pizza Dough',
            'new_unit' => 'KGS',
            'new_cost' => '250.50',
            'quantity_needed' => '0.3500',
        ]], JSON_THROW_ON_ERROR)]);

        $productId = (int) \DB::table('pos_products')->where('company_id', $this->company->id)->value('id');
        $ingredient = Ingredient::where('company_id', $this->company->id)->firstOrFail();

        $this->assertSame('kg', $ingredient->unit);
        $this->assertDatabaseHas('product_recipes', [
            'company_id' => $this->company->id,
            'product_id' => $productId,
            'ingredient_id' => $ingredient->id,
            'quantity_needed' => 0.3500,
        ]);
    }

    public function test_foreign_ingredient_id_rejects_the_whole_product(): void
    {
        $foreign = Company::create(['name' => 'Other QA', 'ntn' => 'OTHER-' . uniqid()]);
        $ingredient = Ingredient::create([
            'company_id' => $foreign->id,
            'name' => 'Foreign Flour',
            'unit' => 'kg',
            'cost_per_unit' => 1,
            'current_stock' => 0,
            'min_stock_level' => 0,
        ]);

        try {
            $this->store(['ingredients_json' => json_encode([[
                'ingredient_id' => $ingredient->id,
                'quantity_needed' => 1,
            ]], JSON_THROW_ON_ERROR)]);
            $this->fail('Cross-tenant ingredient must be rejected.');
        } catch (ValidationException) {
            $this->assertDatabaseMissing('pos_products', ['company_id' => $this->company->id, 'name' => 'QA Pizza']);
        }
    }

    public function test_duplicate_ingredient_rows_reject_the_whole_product(): void
    {
        $ingredient = Ingredient::create([
            'company_id' => $this->company->id,
            'name' => 'Cheese',
            'unit' => 'g',
            'cost_per_unit' => 2,
            'current_stock' => 0,
            'min_stock_level' => 0,
        ]);

        $rows = [
            ['ingredient_id' => $ingredient->id, 'quantity_needed' => 50],
            ['ingredient_id' => $ingredient->id, 'quantity_needed' => 60],
        ];

        try {
            $this->store(['ingredients_json' => json_encode($rows, JSON_THROW_ON_ERROR)]);
            $this->fail('Duplicate ingredient rows must be rejected.');
        } catch (ValidationException) {
            $this->assertDatabaseMissing('pos_products', ['company_id' => $this->company->id, 'name' => 'QA Pizza']);
        }
    }

    public function test_partial_or_unsupported_new_ingredient_rejects_the_whole_product(): void
    {
        try {
            $this->store(['ingredients_json' => json_encode([[
                'new_name' => 'Mystery Powder',
                'new_unit' => 'crate',
                'quantity_needed' => 1,
            ]], JSON_THROW_ON_ERROR)]);
            $this->fail('Unsupported recipe unit must be rejected.');
        } catch (ValidationException) {
            $this->assertDatabaseMissing('pos_products', ['company_id' => $this->company->id, 'name' => 'QA Pizza']);
        }
    }

    public function test_blank_starter_row_still_allows_a_product_without_recipe(): void
    {
        $this->store(['ingredients_json' => json_encode([[
            'ingredient_id' => '',
            'new_name' => '',
            'new_unit' => 'KGS',
            'new_cost' => '',
            'quantity_needed' => '',
        ]], JSON_THROW_ON_ERROR)]);

        $this->assertDatabaseHas('pos_products', ['company_id' => $this->company->id, 'name' => 'QA Pizza']);
        $this->assertDatabaseCount('product_recipes', 0);
    }

    public function test_product_edit_replaces_recipe_in_the_same_form(): void
    {
        $product = PosProduct::create([
            'company_id' => $this->company->id,
            'name' => 'Old Pizza',
            'price' => 1000,
            'uom' => 'NOS',
        ]);
        $old = Ingredient::create(['company_id' => $this->company->id, 'name' => 'Old Cheese', 'unit' => 'g', 'cost_per_unit' => 1, 'current_stock' => 0, 'min_stock_level' => 0]);
        $keep = Ingredient::create(['company_id' => $this->company->id, 'name' => 'Flour', 'unit' => 'kg', 'cost_per_unit' => 100, 'current_stock' => 0, 'min_stock_level' => 0]);
        ProductRecipe::create(['company_id' => $this->company->id, 'product_id' => $product->id, 'ingredient_id' => $old->id, 'quantity_needed' => 50]);

        $request = Request::create('/pos/products/' . $product->id, 'PUT', [
            'name' => 'Updated Pizza',
            'price' => '1200',
            'uom' => 'NOS',
            'show_on_sale' => '1',
            'ingredients_json' => json_encode([[
                'ingredient_id' => $keep->id,
                'quantity_needed' => '0.25',
            ]], JSON_THROW_ON_ERROR),
        ]);
        $request->setLaravelSession(app('session.store'));
        app()->instance('request', $request);

        (new PosController())->updateProduct($request, $product->id);

        $this->assertDatabaseHas('pos_products', ['id' => $product->id, 'name' => 'Updated Pizza']);
        $this->assertDatabaseMissing('product_recipes', ['product_id' => $product->id, 'ingredient_id' => $old->id]);
        $this->assertDatabaseHas('product_recipes', [
            'product_id' => $product->id,
            'ingredient_id' => $keep->id,
            'quantity_needed' => 0.25,
        ]);
    }

    public function test_malformed_recipe_on_edit_does_not_update_the_product(): void
    {
        $product = PosProduct::create([
            'company_id' => $this->company->id,
            'name' => 'Original Pizza',
            'price' => 1000,
            'uom' => 'NOS',
        ]);

        $request = Request::create('/pos/products/' . $product->id, 'PUT', [
            'name' => 'Should Not Save',
            'price' => '1200',
            'uom' => 'NOS',
            'ingredients_json' => '{broken',
        ]);
        $request->setLaravelSession(app('session.store'));
        app()->instance('request', $request);

        try {
            (new PosController())->updateProduct($request, $product->id);
            $this->fail('Malformed edit recipe must be rejected.');
        } catch (ValidationException) {
            $this->assertDatabaseHas('pos_products', [
                'id' => $product->id,
                'name' => 'Original Pizza',
                'price' => 1000,
            ]);
        }
    }

    public function test_restaurant_product_page_renders_recipe_editors_for_add_and_edit(): void
    {
        $this->company->forceFill([
            'status' => 'approved',
            'company_status' => 'active',
            'is_internal_account' => true,
            'onboarding_completed' => true,
            'pos_setup_completed' => true,
            'feature_flags' => ['inventory' => true, 'recipes' => true, 'restaurant' => true],
        ])->save();
        $user = User::create([
            'company_id' => $this->company->id,
            'name' => 'Fictional Recipe Owner',
            'email' => 'recipe-owner-'.uniqid().'@example.test',
            'password' => 'local-only',
            'role' => 'company_admin',
            'pos_role' => 'pos_admin',
            'is_active' => true,
            'product_type' => 'pos',
        ]);
        $ingredient = Ingredient::create([
            'company_id' => $this->company->id,
            'name' => 'QA Flour',
            'unit' => 'kg',
            'cost_per_unit' => 100,
            'current_stock' => 0,
            'min_stock_level' => 0,
        ]);
        $product = PosProduct::create([
            'company_id' => $this->company->id,
            'name' => 'QA Pizza',
            'price' => 1200,
            'uom' => 'NOS',
        ]);
        ProductRecipe::create([
            'company_id' => $this->company->id,
            'product_id' => $product->id,
            'ingredient_id' => $ingredient->id,
            'quantity_needed' => 0.25,
        ]);

        $response = $this->actingAs($user, 'pos')->get('/pos/products');

        $response->assertOk()
            ->assertSee('name="ingredients_json"', false)
            ->assertSee('QA Flour')
            ->assertSee('0.25');
        $this->assertSame(2, substr_count($response->getContent(), 'name="ingredients_json"'));
    }

    private function store(array $overrides = []): void
    {
        $request = Request::create('/pos/products', 'POST', array_merge([
            'name' => 'QA Pizza',
            'price' => '1200',
            'show_on_sale' => '1',
        ], $overrides));
        $request->setLaravelSession(app('session.store'));
        app()->instance('request', $request);

        (new PosController())->storeProduct($request);
    }
}

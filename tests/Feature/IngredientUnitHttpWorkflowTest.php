<?php

namespace Tests\Feature;

use App\Models\Company;
use App\Models\Ingredient;
use App\Models\PosProduct;
use App\Models\ProductRecipe;
use App\Models\User;
use App\Services\PosFeatureService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class IngredientUnitHttpWorkflowTest extends TestCase
{
    use RefreshDatabase;

    private function kitchen(): array
    {
        $company = Company::create([
            'name' => 'Synthetic Unit Kitchen', 'ntn' => uniqid('UNIT-'),
            'email' => uniqid('unit-', true).'@test.invalid', 'product_type' => 'pos',
            'status' => 'approved', 'company_status' => 'active', 'is_internal_account' => true,
            'business_category' => 'restaurant', 'pos_type' => 'restaurant',
            'restaurant_mode' => true, 'pos_integration_mode' => 'pra', 'pos_setup_completed' => true,
            'feature_flags' => array_merge(PosFeatureService::defaultsForCategory('restaurant'), ['recipes' => true]),
        ]);
        $owner = User::create([
            'company_id' => $company->id, 'product_type' => 'pos',
            'name' => 'Kitchen Owner', 'email' => uniqid('unit-owner-', true).'@test.invalid',
            'password' => Hash::make('UnitTest!12345'), 'role' => 'company_admin',
            'pos_role' => 'pos_admin', 'is_active' => true,
        ]);
        PosFeatureService::flushGateCaches();
        return [$company, $owner];
    }

    private function ingredient(Company $company, float $stock = 0): Ingredient
    {
        return Ingredient::create([
            'company_id' => $company->id, 'name' => 'Chicken', 'unit' => 'pcs',
            'base_unit' => 'pcs', 'conversion_factor' => 1, 'current_stock' => $stock,
            'cost_per_unit' => 100, 'min_stock_level' => 0, 'is_active' => true,
        ]);
    }

    private function payload(string $unit = ' Kg ', string $base = 'Kg'): array
    {
        return ['name' => 'Chicken', 'unit' => $unit, 'base_unit' => $base,
            'conversion_factor' => '1', 'cost_per_unit' => '100', 'min_stock_level' => '0', 'is_active' => '1'];
    }

    public function test_unused_display_cased_edit_is_persisted_and_visible_after_actual_http_redirect(): void
    {
        [$company, $owner] = $this->kitchen();
        $ingredient = $this->ingredient($company);
        $this->actingAs($owner, 'pos')->from('/pos/restaurant/ingredients')
            ->put('/pos/restaurant/ingredients/'.$ingredient->id, $this->payload())
            ->assertRedirect('/pos/restaurant/ingredients')->assertSessionHasNoErrors()
            ->assertSessionHas('success');
        $this->assertSame('kg', $ingredient->fresh()->unit);
        $this->assertSame('kg', $ingredient->fresh()->base_unit);
        $this->assertEquals(0, (float) $ingredient->fresh()->current_stock);
        $this->get('/pos/restaurant/ingredients')->assertOk()->assertSee('0.0000 kg')
            ->assertSee('select name="unit"', false)->assertSee('value="kg"', false);
    }

    public function test_stocked_edit_returns_visible_reason_and_preserves_quantity_cost_and_unit(): void
    {
        [$company, $owner] = $this->kitchen();
        $ingredient = $this->ingredient($company, 50);
        $this->actingAs($owner, 'pos')->from('/pos/restaurant/ingredients')
            ->put('/pos/restaurant/ingredients/'.$ingredient->id, $this->payload())
            ->assertRedirect('/pos/restaurant/ingredients')
            ->assertSessionHas('error', fn ($message) => str_contains($message, 'stock ya recipes'));
        $this->get('/pos/restaurant/ingredients')->assertOk()
            ->assertSee('stock ya recipes')->assertSee('50.0000 pcs');
        $this->assertSame('pcs', $ingredient->fresh()->unit);
        $this->assertSame('pcs', $ingredient->fresh()->base_unit);
        $this->assertEquals(50, (float) $ingredient->fresh()->current_stock);
        $this->assertEquals(100, (float) $ingredient->fresh()->cost_per_unit);
    }

    public function test_invalid_unit_is_a_visible_validation_failure_not_a_successful_no_op(): void
    {
        [$company, $owner] = $this->kitchen();
        $ingredient = $this->ingredient($company);
        $this->actingAs($owner, 'pos')->from('/pos/restaurant/ingredients')
            ->put('/pos/restaurant/ingredients/'.$ingredient->id, $this->payload('stones', 'stones'))
            ->assertRedirect('/pos/restaurant/ingredients')->assertSessionHasErrors(['unit', 'base_unit'])
            ->assertSessionMissing('success');
        $this->get('/pos/restaurant/ingredients')->assertOk()
            ->assertSee('role="alert"', false)->assertSee('Ingredient save nahi hua:');
        $this->assertSame('pcs', $ingredient->fresh()->unit);
    }

    public function test_recipe_link_blocks_unit_change_even_with_zero_stock(): void
    {
        [$company, $owner] = $this->kitchen();
        $ingredient = $this->ingredient($company);
        $product = PosProduct::create(['company_id' => $company->id, 'name' => 'Synthetic Dish', 'price' => 100, 'is_active' => true]);
        $recipe = ProductRecipe::create(['company_id' => $company->id, 'product_id' => $product->id,
            'ingredient_id' => $ingredient->id, 'quantity_needed' => 2, 'is_active' => true]);
        $this->actingAs($owner, 'pos')->from('/pos/restaurant/ingredients')
            ->put('/pos/restaurant/ingredients/'.$ingredient->id, $this->payload())
            ->assertRedirect('/pos/restaurant/ingredients')->assertSessionHas('error');
        $this->assertSame('pcs', $ingredient->fresh()->unit);
        $this->assertEquals(2, (float) $recipe->fresh()->quantity_needed);
    }

    public function test_other_tenant_ingredient_cannot_be_edited_and_same_unit_metadata_edit_still_works(): void
    {
        [$mine, $owner] = $this->kitchen();
        [$theirs] = $this->kitchen();
        $foreign = $this->ingredient($theirs, 50);
        $mineIngredient = $this->ingredient($mine, 50);
        // JSON clients receive 404; the native HTML panel's central missing-row
        // handler redirects to its Dashboard. Assert both exact contracts.
        $this->actingAs($owner, 'pos')
            ->putJson('/pos/restaurant/ingredients/'.$foreign->id, $this->payload('pcs', 'pcs'))
            ->assertNotFound()->assertJson(['error' => 'Resource not found.']);
        $this->put('/pos/restaurant/ingredients/'.$foreign->id, $this->payload('pcs', 'pcs'))
            ->assertRedirect('/pos/dashboard')->assertSessionHas('error', fn ($message) => str_contains($message, 'not found'))
            ->assertSessionMissing('success');
        $this->assertEquals(50, (float) $foreign->fresh()->current_stock);
        $this->assertSame('pcs', $foreign->fresh()->unit);
        $payload = array_merge($this->payload('pcs', 'pcs'), ['name' => 'Chicken Revised', 'cost_per_unit' => '125']);
        $this->from('/pos/restaurant/ingredients')->put('/pos/restaurant/ingredients/'.$mineIngredient->id, $payload)
            ->assertRedirect('/pos/restaurant/ingredients')->assertSessionHas('success');
        $this->assertSame('Chicken Revised', $mineIngredient->fresh()->name);
        $this->assertEquals(125, (float) $mineIngredient->fresh()->cost_per_unit);
        $this->assertEquals(50, (float) $mineIngredient->fresh()->current_stock);
    }
}

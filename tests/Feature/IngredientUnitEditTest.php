<?php

namespace Tests\Feature;

use App\Http\Controllers\IngredientController;
use App\Models\Company;
use App\Models\Ingredient;
use App\Models\IngredientStock;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

class IngredientUnitEditTest extends TestCase
{
    use RefreshDatabase;

    private function edit(Ingredient $ingredient, string $unit, string $baseUnit): \Illuminate\Http\RedirectResponse
    {
        app()->instance('currentCompanyId', $ingredient->company_id);
        return (new IngredientController)->update(Request::create('/pos/restaurant/ingredients/'.$ingredient->id, 'PUT', [
            'name' => $ingredient->name,
            'unit' => $unit,
            'base_unit' => $baseUnit,
            'conversion_factor' => '1',
            'cost_per_unit' => '100',
            'min_stock_level' => '0',
        ]), $ingredient->id);
    }

    public function test_display_cased_unit_is_normalized_when_unused_ingredient_is_edited(): void
    {
        $company = Company::create(['name' => 'Kitchen', 'ntn' => uniqid('KT-')]);
        $ingredient = Ingredient::create(['company_id' => $company->id, 'name' => 'Chicken', 'unit' => 'pcs', 'current_stock' => 0]);

        $this->edit($ingredient, ' Kg ', 'Kg');

        $this->assertSame('kg', $ingredient->fresh()->unit);
        $this->assertSame('kg', $ingredient->fresh()->base_unit);
    }

    public function test_stocked_ingredient_cannot_be_relabelled_as_a_different_unit(): void
    {
        $company = Company::create(['name' => 'Kitchen', 'ntn' => uniqid('KT-')]);
        $ingredient = Ingredient::create(['company_id' => $company->id, 'name' => 'Chicken', 'unit' => 'pcs', 'current_stock' => 50]);

        $this->edit($ingredient, 'Kg', 'Kg');

        $this->assertStringContainsString('stock ya recipes', (string) session('error'));
        $this->assertSame('pcs', $ingredient->fresh()->unit);
        $this->assertEquals(50, (float) $ingredient->fresh()->current_stock);
    }

    public function test_invalid_unit_is_rejected_and_existing_unit_is_preserved(): void
    {
        $company = Company::create(['name' => 'Kitchen', 'ntn' => uniqid('KT-')]);
        $ingredient = Ingredient::create(['company_id' => $company->id, 'name' => 'Chicken', 'unit' => 'pcs']);

        try {
            $this->edit($ingredient, 'stones', 'stones');
            $this->fail('Invalid unit must fail validation');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('unit', $exception->errors());
        }
        $this->assertSame('pcs', $ingredient->fresh()->unit);
    }

    public function test_branch_stock_blocks_unit_change_even_when_company_stock_is_zero(): void
    {
        $company = Company::create(['name' => 'Kitchen', 'ntn' => uniqid('KT-')]);
        $ingredient = Ingredient::create(['company_id' => $company->id, 'name' => 'Chicken', 'unit' => 'pcs', 'current_stock' => 0]);
        IngredientStock::create(['company_id' => $company->id, 'ingredient_id' => $ingredient->id, 'quantity' => 50]);

        $this->edit($ingredient, 'Kg', 'Kg');

        $this->assertStringContainsString('stock ya recipes', (string) session('error'));
        $this->assertSame('pcs', $ingredient->fresh()->unit);
    }
}

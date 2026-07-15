<?php

namespace App\Http\Controllers;

use App\Models\Ingredient;
use App\Models\ProductRecipe;
use App\Models\PosProduct;
use Illuminate\Http\Request;

class IngredientController extends Controller
{
    public function index()
    {
        $companyId = app('currentCompanyId');

        $ingredients = Ingredient::where('company_id', $companyId)
            ->orderBy('name')
            ->get();

        return view('pos.restaurant.ingredients', compact('ingredients'));
    }

    public function store(Request $request)
    {
        $companyId = app('currentCompanyId');

        $request->validate([
            'name' => 'required|string|max:255',
            'unit' => 'required|string|max:20',
            'cost_per_unit' => 'required|numeric|min:0',
            'current_stock' => 'required|numeric|min:0',
            'min_stock_level' => 'required|numeric|min:0',
        ]);

        Ingredient::create([
            'company_id' => $companyId,
            'name' => $request->name,
            'unit' => $request->unit,
            'cost_per_unit' => $request->cost_per_unit,
            'current_stock' => $request->current_stock,
            'min_stock_level' => $request->min_stock_level,
        ]);

        return back()->with('success', "Ingredient \"{$request->name}\" added.");
    }

    public function update(Request $request, $id)
    {
        $companyId = app('currentCompanyId');

        $request->validate([
            'name' => 'required|string|max:255',
            'unit' => 'required|string|max:20',
            'cost_per_unit' => 'required|numeric|min:0',
            'min_stock_level' => 'required|numeric|min:0',
        ]);

        $ingredient = Ingredient::where('company_id', $companyId)->findOrFail($id);

        $ingredient->update([
            'name' => $request->name,
            'unit' => $request->unit,
            'cost_per_unit' => $request->cost_per_unit,
            'min_stock_level' => $request->min_stock_level,
            'is_active' => $request->boolean('is_active', true),
        ]);

        return back()->with('success', "Ingredient updated.");
    }

    public function adjustStock(Request $request, $id)
    {
        $companyId = app('currentCompanyId');

        $request->validate([
            'adjustment' => 'required|numeric',
            'reason' => 'required|string|max:255',
        ]);

        $ingredient = Ingredient::where('company_id', $companyId)->findOrFail($id);
        $newStock = $ingredient->current_stock + (float)$request->adjustment;
        if ($newStock < 0) {
            return back()->with('error', 'Stock cannot go below zero.');
        }

        $ingredient->update(['current_stock' => $newStock]);

        return back()->with('success', "Stock adjusted. New: {$newStock} {$ingredient->unit}");
    }

    public function destroy($id)
    {
        $companyId = app('currentCompanyId');
        $ingredient = Ingredient::where('company_id', $companyId)->findOrFail($id);

        $recipesUsing = ProductRecipe::where('ingredient_id', $id)->count();
        if ($recipesUsing > 0) {
            return back()->with('error', "Cannot delete ingredient used in {$recipesUsing} recipe(s). Remove from recipes first.");
        }

        $ingredient->delete();
        return back()->with('success', "Ingredient deleted.");
    }

    public function recipes()
    {
        $companyId = app('currentCompanyId');

        $products = PosProduct::where('company_id', $companyId)
            ->where('is_active', true)
            ->orderBy('name')
            ->get();

        $ingredients = Ingredient::where('company_id', $companyId)
            ->where('is_active', true)
            ->orderBy('name')
            ->get();

        $recipes = ProductRecipe::where('company_id', $companyId)
            ->with(['product', 'ingredient'])
            ->get()
            ->groupBy('product_id');

        return view('pos.restaurant.recipes', compact('products', 'ingredients', 'recipes'));
    }

    public function storeRecipe(Request $request)
    {
        $companyId = app('currentCompanyId');

        // MULTI-INGREDIENT FLOW (owner request Jul 2026): one form submits MANY ingredient
        // rows for a product. Each row picks an existing ingredient OR creates one inline.
        $request->validate([
            'product_id' => 'required|integer',
            'ingredients' => 'required|array|min:1',
            'ingredients.*.quantity_needed' => 'required|numeric|min:0.0001',
            'ingredients.*.ingredient_id' => 'nullable|integer',
            'ingredients.*.new_name' => 'nullable|string|max:120',
            'ingredients.*.new_unit' => 'nullable|string|max:20',
            'ingredients.*.new_cost' => 'nullable|numeric|min:0',
        ]);

        $product = PosProduct::where('company_id', $companyId)->where('id', $request->product_id)->first();
        if (!$product) {
            return back()->with('error', 'Invalid product.');
        }

        $added = [];
        $skipped = [];

        foreach ($request->input('ingredients', []) as $row) {
            $ingredient = null;

            if (!empty($row['ingredient_id'])) {
                $ingredient = Ingredient::where('company_id', $companyId)->where('id', $row['ingredient_id'])->first();
            } elseif (!empty($row['new_name'])) {
                $name = trim($row['new_name']);
                $unit = trim($row['new_unit'] ?? '') ?: 'pcs';
                // Reuse existing ingredient if name+unit matches (avoid duplicates)
                $ingredient = Ingredient::where('company_id', $companyId)
                    ->whereRaw('LOWER(name) = ?', [strtolower($name)])
                    ->where('unit', $unit)
                    ->first();
                if (!$ingredient) {
                    $ingredient = Ingredient::create([
                        'company_id' => $companyId,
                        'name' => $name,
                        'unit' => $unit,
                        'cost_per_unit' => (float) ($row['new_cost'] ?? 0),
                        'current_stock' => 0,
                        'min_stock_level' => 0,
                        'is_active' => true,
                    ]);
                }
            }

            if (!$ingredient) {
                $skipped[] = 'ek row (ingredient select/likha nahi gaya)';
                continue;
            }

            $exists = ProductRecipe::where('company_id', $companyId)
                ->where('product_id', $request->product_id)
                ->where('ingredient_id', $ingredient->id)
                ->exists();

            if ($exists || in_array($ingredient->id, array_column($added, 'id'))) {
                $skipped[] = "'{$ingredient->name}' (pehle se recipe mein hai)";
                continue;
            }

            ProductRecipe::create([
                'company_id' => $companyId,
                'product_id' => $request->product_id,
                'ingredient_id' => $ingredient->id,
                'quantity_needed' => $row['quantity_needed'],
            ]);

            $added[] = ['id' => $ingredient->id, 'name' => $ingredient->name];
        }

        if (empty($added)) {
            return back()->with('error', 'Koi ingredient add nahi hua' . (!empty($skipped) ? ' — ' . implode(', ', $skipped) : '.'));
        }

        $msg = count($added) . ' ingredient(s) added: ' . implode(', ', array_column($added, 'name')) . '.';
        if (!empty($skipped)) {
            $msg .= ' Skipped: ' . implode(', ', $skipped) . '.';
        }

        return back()->with('success', $msg);
    }

    public function updateRecipe(Request $request, $id)
    {
        $companyId = app('currentCompanyId');

        $request->validate(['quantity_needed' => 'required|numeric|min:0.0001']);

        $recipe = ProductRecipe::where('company_id', $companyId)->findOrFail($id);
        $recipe->update(['quantity_needed' => $request->quantity_needed]);

        return back()->with('success', 'Recipe updated.');
    }

    public function deleteRecipe($id)
    {
        $companyId = app('currentCompanyId');
        $recipe = ProductRecipe::where('company_id', $companyId)->findOrFail($id);
        $recipe->delete();

        return back()->with('success', 'Recipe ingredient removed.');
    }
}

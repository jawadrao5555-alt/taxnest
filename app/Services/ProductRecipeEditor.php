<?php

namespace App\Services;

use App\Models\Ingredient;
use App\Models\PosProduct;
use App\Models\ProductRecipe;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

/**
 * Strict, tenant-scoped input boundary for the unified Product + Recipe form.
 *
 * A recipe is stock accounting, so a malformed/partial row must never be
 * silently dropped while the product itself is reported as saved.
 */
final class ProductRecipeEditor
{
    private const MAX_ROWS = 100;

    /**
     * @return array<int, array<string, mixed>>|null null means the form did not submit recipe data
     */
    public static function rowsFrom(Request $request): ?array
    {
        if (!$request->exists('ingredients_json') && !$request->exists('ingredients')) {
            return null;
        }

        if ($request->exists('ingredients_json')) {
            try {
                $rows = json_decode((string) $request->input('ingredients_json'), true, 32, JSON_THROW_ON_ERROR);
            } catch (\JsonException) {
                throw ValidationException::withMessages([
                    'ingredients_json' => __('pos.recipe_payload_invalid'),
                ]);
            }
        } else {
            $rows = $request->input('ingredients');
        }

        if (!is_array($rows) || !array_is_list($rows) || count($rows) > self::MAX_ROWS) {
            throw ValidationException::withMessages([
                'ingredients_json' => __('pos.recipe_payload_invalid'),
            ]);
        }

        $clean = [];
        foreach ($rows as $index => $row) {
            if (!is_array($row)) {
                throw self::rowError($index, __('pos.recipe_row_invalid'));
            }

            $ingredientId = trim((string) ($row['ingredient_id'] ?? ''));
            $name = trim((string) ($row['new_name'] ?? ''));
            $unitRaw = trim((string) ($row['new_unit'] ?? ''));
            $qtyRaw = $row['quantity_needed'] ?? '';
            $costRaw = $row['new_cost'] ?? '';

            // The UI keeps one blank starter row. It means "no recipe" and is
            // the only row shape that may be ignored.
            if ($ingredientId === '' && $name === '' && trim((string) $qtyRaw) === '' && trim((string) $costRaw) === '') {
                continue;
            }

            if (!is_numeric($qtyRaw) || !is_finite((float) $qtyRaw) || (float) $qtyRaw <= 0 || (float) $qtyRaw > 999999999) {
                throw self::rowError($index, __('pos.inventory_master_recipe_qty_invalid'));
            }

            if ($ingredientId !== '') {
                if (!ctype_digit($ingredientId) || (int) $ingredientId <= 0) {
                    throw self::rowError($index, __('pos.recipe_ingredient_invalid'));
                }
                $clean[] = [
                    'ingredient_id' => (int) $ingredientId,
                    'quantity_needed' => round((float) $qtyRaw, 4),
                ];
                continue;
            }

            if ($name === '' || mb_strlen($name) > 120) {
                throw self::rowError($index, __('pos.inventory_master_recipe_ing_required'));
            }

            $unit = self::canonicalUnit($unitRaw);
            if ($unit === null) {
                throw self::rowError($index, __('pos.recipe_unit_invalid', [
                    'units' => implode(', ', RecipeInventoryService::UNITS),
                ]));
            }
            if ($costRaw !== '' && (!is_numeric($costRaw) || !is_finite((float) $costRaw) || (float) $costRaw < 0)) {
                throw self::rowError($index, __('pos.recipe_cost_invalid'));
            }

            $clean[] = [
                'new_name' => $name,
                'new_unit' => $unit,
                'new_cost' => $costRaw === '' ? 0.0 : round((float) $costRaw, 2),
                'quantity_needed' => round((float) $qtyRaw, 4),
            ];
        }

        return $clean;
    }

    /**
     * Resolve every row without writing. This catches cross-tenant/missing IDs
     * and duplicates before image or stock side effects can occur.
     *
     * @param array<int, array<string, mixed>> $rows
     * @return array<int, array<string, mixed>>
     */
    public static function prepare(int $companyId, array $rows): array
    {
        $prepared = [];
        $seen = [];

        foreach ($rows as $index => $row) {
            $ingredient = null;
            if (isset($row['ingredient_id'])) {
                $ingredient = Ingredient::where('company_id', $companyId)->find((int) $row['ingredient_id']);
                if (!$ingredient) {
                    throw self::rowError($index, __('pos.recipe_ingredient_invalid'));
                }
            } else {
                $ingredient = Ingredient::where('company_id', $companyId)
                    ->whereRaw('LOWER(name) = ?', [mb_strtolower((string) $row['new_name'])])
                    ->whereRaw('LOWER(unit) = ?', [(string) $row['new_unit']])
                    ->first();
            }

            $identity = $ingredient
                ? 'id:' . $ingredient->id
                : 'new:' . mb_strtolower((string) $row['new_name']) . '|' . (string) $row['new_unit'];
            if (isset($seen[$identity])) {
                throw self::rowError($index, __('pos.recipe_duplicate_ingredient'));
            }
            $seen[$identity] = true;
            $prepared[] = $ingredient
                ? ['ingredient_id' => (int) $ingredient->id, 'quantity_needed' => $row['quantity_needed']]
                : $row;
        }

        return $prepared;
    }

    /** @param array<int, array<string, mixed>> $prepared */
    public static function sync(int $companyId, PosProduct $product, array $prepared): int
    {
        $keep = [];
        foreach ($prepared as $row) {
            $ingredientId = $row['ingredient_id'] ?? null;
            if ($ingredientId === null) {
                $ingredient = Ingredient::create([
                    'company_id' => $companyId,
                    'name' => $row['new_name'],
                    'unit' => $row['new_unit'],
                    'cost_per_unit' => $row['new_cost'],
                    'current_stock' => 0,
                    'min_stock_level' => 0,
                    'is_active' => true,
                ]);
                $ingredientId = $ingredient->id;
            }

            ProductRecipe::updateOrCreate(
                [
                    'company_id' => $companyId,
                    'product_id' => $product->id,
                    'ingredient_id' => $ingredientId,
                ],
                ['quantity_needed' => $row['quantity_needed']]
            );
            $keep[] = (int) $ingredientId;
        }

        $delete = ProductRecipe::where('company_id', $companyId)->where('product_id', $product->id);
        $keep === [] ? $delete->delete() : $delete->whereNotIn('ingredient_id', $keep)->delete();

        return count($keep);
    }

    private static function canonicalUnit(string $value): ?string
    {
        $unit = strtolower(trim($value));
        $unit = match ($unit) {
            'kgs', 'kilogram', 'kilograms' => 'kg',
            'gms', 'gram', 'grams' => 'g',
            'litre', 'liter', 'litres', 'liters' => 'ltr',
            'piece', 'pieces' => 'pcs',
            'doz' => 'dozen',
            'pkt', 'packet', 'packets' => 'pack',
            default => $unit,
        };

        return in_array($unit, RecipeInventoryService::UNITS, true) ? $unit : null;
    }

    private static function rowError(int $index, string $message): ValidationException
    {
        return ValidationException::withMessages([
            'ingredients.' . $index => __('pos.recipe_row_prefix', ['row' => $index + 1]) . ' ' . $message,
        ]);
    }
}

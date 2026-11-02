<?php

namespace App\Services;

use App\Models\Ingredient;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/** Sale-time unit cost. Unconfigured ingredient rates remain unknown, never zero profit cost. */
class PosProductCost
{
    public static function map(int $companyId, iterable $products): array
    {
        $products = collect($products)->filter(fn ($p) => (int) $p->company_id === $companyId)->keyBy('id');
        $costs = $products->map(fn ($p) => (float) $p->cost_price > 0 ? (float) $p->cost_price : null)->all();
        if (!$products->count() || !Schema::hasTable('product_recipes') || !Schema::hasTable('ingredients')) return $costs;
        $query = DB::table('product_recipes')->where('company_id', $companyId)->whereIn('product_id', $products->keys());
        if (Schema::hasColumn('product_recipes', 'is_active')) $query->where('is_active', true);
        $recipes = $query->get();
        $ingredients = Ingredient::where('company_id', $companyId)->whereIn('id', $recipes->pluck('ingredient_id'))->get()->keyBy('id');
        foreach ($recipes->groupBy('product_id') as $id => $parts) {
            $cost = 0.0;
            foreach ($parts as $part) {
                $ingredient = $ingredients[$part->ingredient_id] ?? null;
                if (!$ingredient || !$ingredient->is_active || (float) $ingredient->cost_per_unit <= 0) { $cost = null; break; }
                $qty = round((float) $part->quantity_needed * (1 + max(0, min(100, (float) ($part->waste_percent ?? 0))) / 100), 4);
                $cost += $qty * (float) $ingredient->cost_per_unit;
            }
            $costs[$id] = $cost === null ? null : round($cost, 6);
        }
        return $costs;
    }
}

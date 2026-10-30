<?php

namespace App\Services;

use App\Models\Ingredient;
use Illuminate\Validation\ValidationException;

/** All stored ingredient quantities/costs are in Ingredient::unit (usage unit). */
class IngredientUnitConversion
{
    private const SCALES = ['kg' => ['mass', 1000], 'g' => ['mass', 1],
        'ltr' => ['volume', 1000], 'ml' => ['volume', 1],
        'dozen' => ['count', 12], 'pcs' => ['count', 1]];

    public static function factor(Ingredient $ingredient, ?string $from): float
    {
        $from = strtolower(trim((string) $from));
        $to = strtolower(trim((string) $ingredient->unit));
        if ($from === '' || $from === $to) return 1.0;
        $source = self::SCALES[$from] ?? null;
        $target = self::SCALES[$to] ?? null;
        if ($source && $target) {
            if ($source[0] !== $target[0]) self::invalid($from, $to);
            return $source[1] / $target[1];
        }
        // A pack/cup has no universal size. Only an explicitly saved purchase mapping applies.
        if ($from === strtolower((string) $ingredient->base_unit) && (float) $ingredient->conversion_factor > 0) {
            return (float) $ingredient->conversion_factor;
        }
        self::invalid($from, $to);
    }

    private static function invalid(string $from, string $to): never
    {
        throw ValidationException::withMessages(['unit' => "Cannot convert {$from} to {$to}; use compatible units or an explicitly saved pack size."]);
    }
}

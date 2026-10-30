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
        if ($source && $target && $source[0] !== $target[0]) self::invalid($from, $to);
        // Existing saved mappings are contractual. Never replace a shop's factor
        // when merely receiving stock; the preview exposes the factor being used.
        if ($from === strtolower((string) $ingredient->base_unit) && (float) $ingredient->conversion_factor > 0) {
            return (float) $ingredient->conversion_factor;
        }
        if ($source && $target) return $source[1] / $target[1];
        self::invalid($from, $to);
    }

    public static function defaultFactor(string $from, string $to): ?float
    {
        $from = strtolower(trim($from)); $to = strtolower(trim($to));
        if ($from === '' || $from === $to) return 1.0;
        $source = self::SCALES[$from] ?? null; $target = self::SCALES[$to] ?? null;
        return $source && $target && $source[0] === $target[0] ? $source[1] / $target[1] : null;
    }

    public static function configuredFactor(string $from, string $to, mixed $requested, ?Ingredient $existing = null): float
    {
        $from = strtolower(trim($from)); $to = strtolower(trim($to));
        if ($requested === null || $requested === '') {
            $unchanged = $existing && strtolower((string) ($existing->base_unit ?: $existing->unit)) === $from
                && strtolower((string) $existing->unit) === $to;
            $requested = $unchanged && (float) $existing->conversion_factor > 0
                ? (float) $existing->conversion_factor : self::defaultFactor($from, $to);
        }
        if (!is_numeric($requested) || !is_finite((float) $requested) || (float) $requested <= 0) self::invalid($from, $to);
        $probe = new Ingredient(['unit' => $to, 'base_unit' => $from, 'conversion_factor' => $requested]);
        // Reject physically incompatible dimensions even when a client supplies a factor.
        self::factor($probe, $from);
        return (float) $requested;
    }

    private static function invalid(string $from, string $to): never
    {
        throw ValidationException::withMessages(['unit' => "Cannot convert {$from} to {$to}; use compatible units or an explicitly saved pack size."]);
    }
}

<?php

namespace App\Services;

/** Immutable, paisa-balanced component allocation for newly captured PRA deals. */
class PosDealAllocation
{
    public static function supported(?array $snapshot): bool
    {
        if (!$snapshot) return false;
        foreach ($snapshot as $component) {
            if (!is_array($component) || (int) ($component['allocation_version'] ?? 0) !== 1
                || !is_numeric($component['allocation_weight'] ?? null)
                || !is_finite((float) $component['allocation_weight']) || (float) $component['allocation_weight'] < 0) return false;
        }
        return true;
    }

    /** Largest remainder, stable component order breaks ties; no paisa is created/lost. */
    public static function cents(int $total, array $weights): array
    {
        if (!$weights) return [];
        $weights = array_values(array_map(fn ($weight) => max(0.0, (float) $weight), $weights));
        if (array_sum($weights) <= 0) $weights = array_fill(0, count($weights), 1.0);
        $sum = array_sum($weights);
        $result = $remainders = [];
        foreach ($weights as $index => $weight) {
            $exact = abs($total) * $weight / $sum;
            $result[$index] = (int) floor($exact);
            $remainders[$index] = $exact - $result[$index];
        }
        $order = array_keys($weights);
        usort($order, fn ($a, $b) => ($remainders[$b] <=> $remainders[$a]) ?: ($a <=> $b));
        $left = abs($total) - array_sum($result);
        for ($i = 0; $i < $left; $i++) $result[$order[$i % count($order)]]++;
        if ($total < 0) $result = array_map(fn ($value) => -$value, $result);
        return $result;
    }

    private static function weights(array $snapshot): array
    {
        $weights = array_column($snapshot, 'allocation_weight');
        return array_sum($weights) > 0 ? $weights
            : array_map(fn ($component) => max(1, (int) ($component['quantity'] ?? $component['qty'] ?? 1)), $snapshot);
    }

    private static function exempt(array $component): bool
    {
        return (bool) ($component['tax_facts']['is_tax_exempt'] ?? false)
            || (bool) ($component['tax_facts']['is_third_schedule'] ?? false);
    }

    public static function taxableSubtotal(?array $snapshot, float $subtotal, bool $legacyExempt = false): float
    {
        if (!self::supported($snapshot)) return $legacyExempt ? 0.0 : $subtotal;
        $cents = self::cents((int) round($subtotal * 100), self::weights($snapshot));
        $taxable = 0;
        foreach ($snapshot as $index => $component) if (!self::exempt($component)) $taxable += $cents[$index];
        return $taxable / 100;
    }

    public static function unitCost(?array $snapshot): ?float
    {
        if (!self::supported($snapshot)) return null;
        $cost = 0.0;
        foreach ($snapshot as $component) {
            if (!array_key_exists('unit_cost', $component) || $component['unit_cost'] === null) return null;
            $cost += (float) $component['unit_cost'] * (float) ($component['quantity'] ?? $component['qty'] ?? 1);
        }
        return round($cost, 6);
    }

    /** Price/discount shares are on the entire sold line, not the current master deal. */
    public static function capture(array $snapshot, float $subtotal, float $discount, float $rate, bool $inclusive, ?float $menuRate = null): array
    {
        if (!self::supported($snapshot)) return $snapshot;
        $gross = self::cents((int) round($subtotal * 100), self::weights($snapshot));
        $discounts = self::cents(min(array_sum($gross), max(0, (int) round($discount * 100))), $gross);
        foreach ($snapshot as $index => &$component) {
            $net = ($gross[$index] - $discounts[$index]) / 100;
            $exempt = self::exempt($component);
            $ownRate = $exempt ? 0.0 : $rate;
            $tax = $inclusive ? PosTaxMath::inclusiveLineTax($net, $ownRate, $menuRate) : round($net * $ownRate / 100, 2);
            $cardSave = $menuRate !== null && $menuRate > 0 && abs($menuRate - $ownRate) >= .005;
            $base = !$inclusive || $exempt ? $net
                : ($cardSave ? round($net * 100 / (100 + $menuRate), 2) : round($net - $tax, 2));
            $component['allocation'] = [
                'subtotal' => $gross[$index] / 100, 'discount' => $discounts[$index] / 100,
                'item_discount' => (float) ($component['allocation']['item_discount'] ?? 0),
                'taxable_base' => $exempt ? 0.0 : $base, 'exempt_base' => $exempt ? $base : 0.0,
                'tax_rate' => $ownRate, 'tax' => $tax, 'total' => round($base + $tax, 2),
                'tax_inclusive' => $inclusive, 'menu_rate' => $menuRate,
            ];
        }
        unset($component);
        return $snapshot;
    }

    /** A versioned deal makes discount allocation deterministic across the whole bill. */
    public static function bill(array $lines, float $discount, float $rate, bool $inclusive, ?float $menuRate = null): ?array
    {
        if (!array_filter($lines, fn ($line) => self::supported($line['snapshot'] ?? null))) return null;
        $gross = array_map(fn ($line) => max(0, (int) round($line['subtotal'] * 100)), $lines);
        $discount = min(array_sum($gross), max(0, (int) round($discount * 100))) / 100;
        $discounts = self::cents((int) round($discount * 100), $gross);
        $taxable = $exempt = $tax = 0.0;
        $captured = [];
        foreach ($lines as $index => $line) {
            $snapshot = self::supported($line['snapshot'] ?? null) ? $line['snapshot'] : [[
                'allocation_version' => 1, 'allocation_weight' => 1,
                'tax_facts' => ['is_tax_exempt' => (bool) ($line['exempt'] ?? false)],
            ]];
            $snapshot = self::capture($snapshot, $gross[$index] / 100, $discounts[$index] / 100, $rate, $inclusive, $menuRate);
            $taxable += self::sum($snapshot, 'taxable_base');
            $exempt += self::sum($snapshot, 'exempt_base');
            $tax += self::sum($snapshot, 'tax');
            $captured[] = ['discount' => $discounts[$index] / 100, 'snapshot' => $snapshot];
        }
        $tax = $inclusive ? round($tax, 2) : (float) round($taxable * $rate / 100);
        return ['lines' => $captured, 'discount_amount' => $discount,
            'subtotal_col' => round($taxable + $exempt + $discount, 2),
            'tax_amount' => $tax, 'exempt_amount' => round($exempt, 2),
            'total_amount' => (float) round($taxable + $exempt + $tax)];
    }

    /** Persist nullable splits only for versioned deals; legacy lines retain their semantics. */
    public static function lineFields(?array $snapshot, float $subtotal, float $discount, float $rate, bool $inclusive, ?float $menuRate = null, bool $legacyExempt = false, bool $freeze = false): array
    {
        $deal = self::supported($snapshot);
        if (!$deal && !$freeze) return [];
        if (!$deal) $snapshot = [['allocation_version' => 1, 'allocation_weight' => 1,
            'tax_facts' => ['is_tax_exempt' => $legacyExempt]]];
        $snapshot = self::capture($snapshot, $subtotal, $discount, $rate, $inclusive, $menuRate);
        $exempt = self::taxableSubtotal($snapshot, $subtotal) === 0.0;
        $fields = [...($deal ? ['deal_snapshot' => $snapshot] : []), 'is_tax_exempt' => $exempt,
            'tax_rate' => $exempt ? 0.0 : $rate, 'tax_amount' => self::sum($snapshot, 'tax')];
        if (\Illuminate\Support\Facades\Schema::hasColumn('pos_transaction_items', 'taxable_base')) {
            $fields['taxable_base'] = self::sum($snapshot, 'taxable_base');
            $fields['exempt_base'] = self::sum($snapshot, 'exempt_base');
        }
        if (\Illuminate\Support\Facades\Schema::hasColumn('pos_transaction_items', 'third_schedule_base')) {
            $fields['third_schedule_base'] = self::thirdScheduleBase($snapshot);
        }
        if (\Illuminate\Support\Facades\Schema::hasColumn('pos_transaction_items', 'allocated_discount')) {
            $fields['allocated_discount'] = self::sum($snapshot, 'discount');
        }
        return $fields;
    }

    public static function thirdScheduleBase(array $snapshot): float
    {
        return round(array_sum(array_map(fn ($component) => !empty($component['tax_facts']['is_third_schedule'])
            ? (float) ($component['allocation']['exempt_base'] ?? 0) : 0, $snapshot)), 2);
    }

    public static function payloadItems(array $snapshot, float $quantity): array
    {
        return array_map(function ($component) use ($quantity) {
            $a = $component['allocation'];
            return [
                'ItemCode' => sprintf('%04d', (int) $component['product_id']),
                'ItemName' => preg_replace('/[^a-zA-Z0-9\s]/', '', (string) ($component['name'] ?? 'Deal item')),
                'Quantity' => round($quantity * (float) ($component['qty'] ?? $component['quantity'] ?? 1), 3),
                'PCTCode' => '00000000', 'TaxRate' => (float) $a['tax_rate'],
                'SaleValue' => round($a['taxable_base'] + $a['exempt_base'], 2),
                'TotalAmount' => (float) $a['total'], 'TaxCharged' => (float) $a['tax'],
                'Discount' => 0.0, 'FurtherTax' => 0.0, 'InvoiceType' => 1, 'RefUSIN' => null,
            ];
        }, $snapshot);
    }

    /** Informational item reduction is separate from the invoice discount already allocated by capture(). */
    public static function withItemDiscount(array $snapshot, float $discount): array
    {
        $shares = self::cents((int) round($discount * 100), self::weights($snapshot));
        foreach ($snapshot as $index => &$component) {
            if (isset($component['allocation'])) $component['allocation']['item_discount'] = $shares[$index] / 100;
        }
        unset($component);
        return $snapshot;
    }

    /** Cumulative proration leaves the last partial return the exact original remainder. */
    public static function returnSlice(array $snapshot, float $sold, float $returned, float $quantity): array
    {
        if ($sold <= 0) return $snapshot;
        foreach ($snapshot as &$component) {
            if (!is_array($component['allocation'] ?? null)) continue;
            foreach (['subtotal', 'discount', 'item_discount', 'taxable_base', 'exempt_base', 'tax', 'total'] as $key) {
                $cents = (int) round((float) ($component['allocation'][$key] ?? 0) * 100);
                $component['allocation'][$key] = (round($cents * min(1, ($returned + $quantity) / $sold))
                    - round($cents * min(1, $returned / $sold))) / 100;
            }
        }
        unset($component);
        return $snapshot;
    }

    public static function sum(array $snapshot, string $field): float
    {
        return round(array_sum(array_map(fn ($component) => (float) ($component['allocation'][$field] ?? 0), $snapshot)), 2);
    }
}

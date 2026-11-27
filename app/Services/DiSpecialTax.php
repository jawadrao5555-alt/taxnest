<?php

namespace App\Services;

use Brick\Math\BigDecimal;
use Brick\Math\RoundingMode;
use Illuminate\Validation\ValidationException;

/** The schedule keys identify the rules used by a saved invoice line. */
final class DiSpecialTax
{
    public const RULES = [
        'di_potassium_kg' => [
            'label' => 'Potassium chlorate (18% + PKR 60/kg)',
            'sale_type' => 'Potassium Chlorate',
            'rate_label' => '18% along with rupees 60 per kilogram',
            'percentage' => 18, 'fixed_tax' => 60, 'fed' => 0,
            'hs_prefix' => '28291910', 'scenario' => 'SN022',
            'schedule' => 'EIGHTH SCHEDULE Table 1', 'serial' => '56',
        ],
        'di_cement_kg' => [
            'label' => 'Bulk hydraulic cement (PKR 4/kg FED plus sales tax)',
            'sale_type' => 'Cement /Concrete Block',
            'rate_label' => 'Rs.4',
            'percentage' => 18, 'fixed_tax' => 0, 'fed' => 4,
            'hs_prefix' => '2523', 'scenario' => 'SN021',
            'schedule' => '', 'serial' => '',
        ],
        'di_cement_retail_kg' => [
            'label' => 'Retail-packed hydraulic cement (PKR 4/kg FED plus sales tax)',
            'sale_type' => 'Cement /Concrete Block',
            'rate_label' => 'Rs.4',
            'percentage' => 18, 'fixed_tax' => 0, 'fed' => 4, 'retail' => true,
            'hs_prefix' => '2523', 'scenario' => 'SN021',
            'schedule' => '', 'serial' => '',
        ],
    ];

    public static function rule(?string $schedule): ?array
    {
        return self::RULES[$schedule] ?? null;
    }

    public static function matchesHs(array $rule, ?string $hs): bool
    {
        $digits = preg_replace('/[^0-9]/', '', $hs ?? '');

        return strlen($digits) === 8 && str_starts_with($digits, $rule['hs_prefix']);
    }

    public static function isKilogram(?string $unit): bool
    {
        return in_array(strtolower(trim($unit ?? '')), ['kg', 'kilogram', 'kilograms'], true);
    }

    /** Normalize the explicit selection after the selling unit has been converted. */
    public static function normalizeItems(array $items, string $documentType, string $invoiceDate): array
    {
        foreach ($items as $index => &$item) {
            $rule = self::rule($item['schedule_type'] ?? null);
            if (!$rule) {
                continue;
            }

            $error = match (true) {
                $documentType !== 'Sale Invoice' => 'Select a sale invoice for this tax treatment.',
                $invoiceDate < '2026-07-01' => 'This treatment is available for invoices dated from 1 July 2026.',
                !self::matchesHs($rule, $item['hs_code'] ?? null) => 'The HS code does not match this tax treatment.',
                !self::isKilogram($item['default_uom'] ?? null) => 'Choose kilograms as the FBR reporting unit for this treatment.',
                !empty($rule['retail']) && (float) ($item['mrp'] ?? 0) <= 0 => 'Enter the printed retail price for retail-packed cement.',
                !empty($item['petroleum_levy']) => 'Petroleum levy does not apply to this treatment.',
                default => null,
            };
            if ($error) {
                throw ValidationException::withMessages(["items.$index.schedule_type" => $error]);
            }

            $item['tax_rate'] = $rule['percentage'];
            $item['sro_schedule_no'] = $rule['schedule'] ?: null;
            $item['serial_no'] = $rule['serial'] ?: null;
            if (empty($rule['retail'])) {
                $item['mrp'] = null;
            }
            $item['tax'] = DiInvoiceMath::line($item)['salesTaxApplicable'];
        }
        unset($item);

        return $items;
    }

    public static function excise(array $rule, float $quantity): float
    {
        return round($rule['fed'] * round($quantity, 4), 2);
    }

    public static function salesTax(array $rule, float $value, float $retailValue, float $quantity): float
    {
        $base = !empty($rule['retail']) ? $retailValue : $value + self::excise($rule, $quantity);

        return (float) (string) BigDecimal::of((string) $base)->multipliedBy($rule['percentage'])
            ->dividedBy(100, 8, RoundingMode::HALF_UP)
            ->plus(BigDecimal::of((string) $quantity)->multipliedBy($rule['fixed_tax']))
            ->toScale(2, RoundingMode::HALF_UP);
    }

    public static function taxExclusivePrice(array $rule, BigDecimal $gross, BigDecimal $factor, BigDecimal $retailPrice): BigDecimal
    {
        $percentage = BigDecimal::of($rule['percentage'])->dividedBy(100, 2);
        $fed = $factor->multipliedBy($rule['fed']);
        $net = $gross->minus($factor->multipliedBy($rule['fixed_tax']));

        if (!empty($rule['retail'])) {
            return $net->minus($retailPrice->multipliedBy($percentage))->minus($fed)->toScale(6, RoundingMode::HALF_UP);
        }

        return $net->dividedBy(BigDecimal::one()->plus($percentage), 6, RoundingMode::HALF_UP)->minus($fed);
    }
}

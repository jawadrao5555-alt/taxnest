<?php

namespace App\Services;

/** One calculation for DI drafts, imports, and the fiscal payload. */
class DiInvoiceMath
{
    public static function line($item): array
    {
        $get = fn (string $key, $default = null) => is_array($item) ? ($item[$key] ?? $default) : ($item->$key ?? $default);
        $quantity = round((float) $get('quantity', 0), 4);
        $unitPrice = (float) $get('price', 0);
        $valueSalesExcludingST = round($unitPrice * $quantity, 2);
        $saleType = (string) ($get('sale_type') ?: ScheduleEngine::mapSaleType($get('schedule_type', 'standard')));
        $third = stripos($saleType, '3rd Schedule') !== false;
        $exempt = stripos($saleType, 'exempt') !== false;
        $reduced = stripos($saleType, 'reduced') !== false;
        $mrp = (float) $get('mrp', 0);
        $retailPrice = $third ? round(($mrp > 0 ? $mrp : $unitPrice) * $quantity, 2) : round($unitPrice, 2);
        // Sale consideration and MRP tax base are distinct DI fields.
        $taxBase = $third ? $retailPrice : $valueSalesExcludingST;
        $taxRate = $get('tax_rate');
        if (!is_numeric($taxRate)) $taxRate = $taxBase > 0 ? round((float) $get('tax', 0) / $taxBase * 100, 2) : 0;
        $taxRate = (float) $taxRate;
        $salesTaxApplicable = $exempt ? 0.0 : round($taxBase * $taxRate / 100, 2);
        $extraTaxVal = $exempt || $reduced ? 0.0 : round((float) $get('extra_tax', 0) * $quantity, 2);
        $furtherTax = round((float) $get('further_tax', 0), 2);
        $fedPayable = round((float) $get('fed_payable', 0) * $quantity, 2);
        $discount = round((float) $get('discount', 0) * $quantity, 2);
        $totalValues = round($valueSalesExcludingST + $salesTaxApplicable + $extraTaxVal + $furtherTax + $fedPayable - $discount, 2);
        return compact('quantity', 'unitPrice', 'valueSalesExcludingST', 'retailPrice', 'taxRate', 'salesTaxApplicable', 'extraTaxVal', 'furtherTax', 'fedPayable', 'discount', 'totalValues');
    }

    public static function totals(iterable $items): array
    {
        $totals = ['value' => 0.0, 'tax' => 0.0, 'amount' => 0.0];
        foreach ($items as $item) {
            // Public write paths do not persist these unsupported input fields.
            // Ignore them as before instead of adding money that cannot be stored.
            if (is_array($item)) unset($item['extra_tax'], $item['fed_payable'], $item['discount']);
            $line = self::line($item);
            $totals['value'] += $line['valueSalesExcludingST'];
            $totals['tax'] += $line['salesTaxApplicable'];
            $totals['amount'] += $line['totalValues'];
        }
        return array_map(fn ($v) => round($v, 2), $totals);
    }

    public static function snapshotErrors($invoice, array $payload): array
    {
        $errors = [];
        $expected = ['total_value_excluding_st' => 0, 'total_sales_tax' => 0, 'total_amount' => 0];
        foreach ($payload['items'] as $index => $line) {
            $expected['total_value_excluding_st'] += $line['valueSalesExcludingST'];
            $expected['total_sales_tax'] += $line['salesTaxApplicable'];
            $expected['total_amount'] += $line['totalValues'];
            if (abs((float) $invoice->items[$index]->tax - $line['salesTaxApplicable']) > 0.005) {
                $errors[] = 'Item #' . ($index + 1) . ': saved tax differs from fiscal tax. Edit and confirm this draft before reporting.';
            }
        }
        foreach ($expected as $field => $value) {
            if (abs((float) $invoice->$field - round($value, 2)) > 0.005) {
                $errors[] = "Saved {$field} differs from fiscal payload. Edit and confirm this draft before reporting; historical totals are never rewritten automatically.";
            }
        }
        return $errors;
    }
}

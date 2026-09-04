<?php

namespace App\Services;

use App\Models\Company;
use App\Models\PosTaxRule;
use App\Models\RestaurantOrder;
use Illuminate\Support\Facades\Schema;

/**
 * One calculation source for restaurant-order payment previews and settlement.
 *
 * The table popup must quote exactly what payOrder will store. Keeping the
 * payment-method normalization, discount allocation, tax mode and rounding
 * here prevents a JavaScript preview from drifting away from the final bill.
 */
class RestaurantOrderPaymentCalculator
{
    /**
     * @return array{
     *   payment_method:string,
     *   tax_rate:float,
     *   subtotal:float,
     *   discount_amount:float,
     *   discount_ratio:float,
     *   taxable_subtotal:float,
     *   tax_inclusive:bool,
     *   tax_inclusive_column_exists:bool,
     *   menu_rate_column_exists:bool,
     *   menu_rate:?float,
     *   inclusive_header:array,
     *   tax_amount:float,
     *   total_amount:float
     * }
     */
    public static function calculate(RestaurantOrder $order, Company $company, string $paymentMethod): array
    {
        if (!$order->relationLoaded('items')) {
            $order->load('items');
        }

        if (in_array($paymentMethod, ['card', 'online', 'split'], true)) {
            $paymentMethod = 'debit_card';
        }

        $taxRate = PosTaxRule::getRateForMethod($paymentMethod, $company);
        $subtotal = (float) $order->items->sum('subtotal');
        $discountAmount = (float) ($order->discount_amount ?? 0);
        $discountRatio = $subtotal > 0 ? ($subtotal - $discountAmount) / $subtotal : 1;
        $taxableSubtotal = (float) $order->items->where('is_tax_exempt', false)->sum('subtotal');
        $adjustedTaxable = round($taxableSubtotal * max(0, $discountRatio), 2);

        $taxInclusiveColumnExists = Schema::hasColumn('pos_transactions', 'tax_inclusive');
        $pricingMode = $company->posTaxPricingMode();
        $taxInclusive = $taxInclusiveColumnExists && in_array($pricingMode, ['inclusive', 'inclusive_card_save'], true);
        $menuRateColumnExists = Schema::hasColumn('pos_transactions', 'tax_menu_rate');
        $menuRate = null;
        if ($taxInclusive && $pricingMode === 'inclusive_card_save' && $menuRateColumnExists) {
            $menuRate = (float) PosTaxRule::getRateForMethod('cash', $company);
        }

        $inclusiveHeader = [];
        if ($taxInclusive) {
            $inclusiveHeader = PosTaxMath::inclusiveHeader(
                $subtotal,
                $taxableSubtotal,
                $discountAmount,
                (float) $taxRate,
                $menuRate
            );
            $taxAmount = (float) $inclusiveHeader['tax_amount'];
            $totalAmount = (float) $inclusiveHeader['total_amount'];
        } else {
            $taxAmount = (float) round($adjustedTaxable * $taxRate / 100);
            $totalAmount = (float) round($subtotal - $discountAmount + $taxAmount);
        }

        return [
            'payment_method' => $paymentMethod,
            'tax_rate' => (float) $taxRate,
            'subtotal' => $subtotal,
            'discount_amount' => $discountAmount,
            'discount_ratio' => $discountRatio,
            'taxable_subtotal' => $taxableSubtotal,
            'tax_inclusive' => $taxInclusive,
            'tax_inclusive_column_exists' => $taxInclusiveColumnExists,
            'menu_rate_column_exists' => $menuRateColumnExists,
            'menu_rate' => $menuRate,
            'inclusive_header' => $inclusiveHeader,
            'tax_amount' => $taxAmount,
            'total_amount' => $totalAmount,
        ];
    }
}
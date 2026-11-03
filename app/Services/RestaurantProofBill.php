<?php

namespace App\Services;

use App\Models\Company;
use App\Models\RestaurantOrder;

class RestaurantProofBill
{
    public const METHODS = ['cash', 'card', 'debit_card', 'credit_card', 'qr_payment', 'online', 'split'];

    /** Render a quote, never persist/re-tax the saved order or a finalized bill. */
    public static function prepare(RestaurantOrder $order, Company $company, ?string $method): RestaurantOrder
    {
        $copy = clone $order;
        if ($method !== null) {
            abort_unless(in_array($method, self::METHODS, true), 422);
            abort_unless(in_array($order->status, ['held', 'preparing', 'ready'], true), 409);
            PosPromotions::assertHeldCurrent($order, $company);
            $quote = RestaurantOrderPaymentCalculator::calculate($order, $company, $method);
            foreach (['subtotal', 'discount_amount', 'tax_amount', 'total_amount', 'payment_method'] as $field) {
                $copy->setAttribute($field, $quote[$field]);
            }
        }
        $copy->setRelation('items', PosBillLineConsolidator::consolidate($order->items));

        return $copy;
    }
}

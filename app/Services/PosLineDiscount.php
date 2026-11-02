<?php

namespace App\Services;

use App\Models\Company;
use App\Models\User;
use Illuminate\Validation\ValidationException;

/** Canonical per-line manual discount; subtotal already includes this reduction. */
class PosLineDiscount
{
    public static function calculate(float $gross, ?string $type, float $value, ?Company $company, ?User $actor): array
    {
        $type = $type ?: 'percentage';
        if (!in_array($type, ['percentage', 'amount'], true) || !is_finite($value) || $value < 0) {
            throw ValidationException::withMessages(['items' => 'Invalid item discount.']);
        }
        $amount = $type === 'amount' ? min($gross, round($value, 2)) : round($gross * min(100, $value) / 100, 2);
        if (($actor->pos_role ?? null) === 'pos_cashier'
            && $amount > round($gross * (float) ($company->cashier_discount_limit ?? 50) / 100, 2) + .001) {
            throw ValidationException::withMessages(['items' => 'Item discount exceeds your saved cashier limit. Refresh the discount before payment.']);
        }
        return ['item_discount_type' => $value > 0 ? $type : null, 'item_discount_value' => $type === 'percentage' ? min(100, $value) : $amount,
            'item_discount_amount' => $amount, 'lineTotal' => max(0, round($gross - $amount, 2))];
    }
}

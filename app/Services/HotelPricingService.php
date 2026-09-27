<?php

namespace App\Services;

use App\Exceptions\HotelStayException;
use App\Models\Company;
use App\Models\User;

class HotelPricingService
{
    public static function discount(float $gross, string $type, float $value): float
    {
        if (!in_array($type, ['amount', 'percentage'], true) || !is_finite($value) || $value < 0 || ($type === 'percentage' && $value > 100)) {
            throw new HotelStayException(__('pos.hotel_price_invalid'));
        }
        $discount = round($type === 'percentage' ? $gross * $value / 100 : $value, 2);
        if ($discount > $gross + 0.009) {
            throw new HotelStayException(__('pos.hotel_price_invalid'));
        }
        return $discount;
    }

    public static function validateRate(int $companyId, int $userId, float $standard, float $rate, int $nights, string $type, float $value): float
    {
        if (!is_finite($rate) || $rate < 0 || $rate > 10000000 || $nights < 1) {
            throw new HotelStayException(__('pos.hotel_price_invalid'));
        }
        $discount = self::discount(round($rate * $nights, 2), $type, $value);
        $user = User::where('company_id', $companyId)->find($userId);
        if (!$user || !HotelAccessService::canFrontDesk($user)) {
            throw new HotelStayException(__('pos.custom_access_denied'));
        }
        if (($user->pos_role ?? '') === 'pos_cashier') {
            $limit = max(0, min(100, (float) (Company::find($companyId)?->cashier_discount_limit ?? 50)));
            $reference = max($standard, $rate) * $nights;
            $reduction = $reference - ($rate * $nights - $discount);
            if ($reference > 0 && $reduction - round($reference * $limit / 100, 2) > 0.009) {
                throw new HotelStayException(__('pos.hotel_discount_limit', ['limit' => $limit]));
            }
        }
        return $discount;
    }

    public static function quote(Company $company, float $gross, float $discount, string $method): array
    {
        if (!in_array($method, ['cash', 'card', 'debit_card', 'credit_card', 'qr_payment'], true)) {
            throw new HotelStayException(__('pos.hotel_payment_method_invalid'));
        }
        $net = max(0, round($gross - $discount, 2));
        $rate = (float) \App\Models\PosTaxRule::getRateForMethod($method, $company);
        $mode = $company->posTaxPricingMode();
        $inclusive = in_array($mode, ['inclusive', 'inclusive_card_save'], true);
        if ($inclusive) {
            $math = PosTaxMath::inclusiveHeader($gross, $gross, $discount, $rate, $mode === 'inclusive_card_save' ? (float) \App\Models\PosTaxRule::getRateForMethod('cash', $company) : null);
            $tax = $math['tax_amount'];
            $total = $math['total_amount'];
        } else {
            $tax = (float) round($net * $rate / 100);
            $total = (float) round($net + $tax);
        }
        return ['gross' => round($gross, 2), 'discount' => round($discount, 2), 'net' => $net, 'tax' => $tax, 'total' => $total, 'tax_inclusive' => $inclusive, 'tax_rate' => $rate];
    }
}

<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class PosTaxRule extends Model
{
    protected $fillable = [
        'payment_method', 'tax_rate', 'is_active',
    ];

    protected $casts = [
        'tax_rate' => 'decimal:2',
        'is_active' => 'boolean',
    ];

    public static function getRateForMethod(string $method, ?Company $company = null): float
    {
        // Per-company override (POS Features → Sales Tax Rates). NULL = use global default.
        // Every non-cash method (card / debit_card / credit_card / qr_payment) is a
        // digital channel under PRA's reduced-rate rule, so they all share the card rate.
        if ($company) {
            if ($method === 'cash') {
                if ($company->pos_tax_rate_cash !== null) {
                    return (float) $company->pos_tax_rate_cash;
                }
            } elseif ($company->pos_tax_rate_card !== null) {
                return (float) $company->pos_tax_rate_card;
            }
        }

        $methodMap = [
            'card' => 'debit_card',
        ];
        $lookupMethod = $methodMap[$method] ?? $method;

        $rule = static::where('payment_method', $lookupMethod)->where('is_active', true)->first();

        if (!$rule && $lookupMethod !== $method) {
            $rule = static::where('payment_method', $method)->where('is_active', true)->first();
        }

        return $rule ? (float) $rule->tax_rate : 16.00;
    }

    /**
     * Active tax rules keyed by payment_method, with the company's manual
     * overrides applied on top — this is what the sale-screen JS consumes.
     */
    public static function effectiveRules(?Company $company = null)
    {
        $rules = static::where('is_active', true)->get()->keyBy('payment_method');

        if ($company) {
            foreach ($rules as $method => $rule) {
                $override = $method === 'cash'
                    ? $company->pos_tax_rate_cash
                    : $company->pos_tax_rate_card;
                if ($override !== null) {
                    $rule->tax_rate = $override;
                }
            }
        }

        return $rules;
    }
}

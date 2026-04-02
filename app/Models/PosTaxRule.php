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

    public static function getRateForMethod(string $method): float
    {
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
}

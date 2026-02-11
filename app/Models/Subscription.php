<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Subscription extends Model
{
    protected $fillable = [
        'company_id',
        'pricing_plan_id',
        'billing_cycle',
        'discount_percent',
        'final_price',
        'start_date',
        'end_date',
        'trial_ends_at',
        'active',
    ];

    protected $casts = [
        'trial_ends_at' => 'datetime',
        'start_date' => 'date',
        'end_date' => 'date',
        'discount_percent' => 'decimal:2',
        'final_price' => 'decimal:2',
    ];

    public function isTrialActive(): bool
    {
        return $this->trial_ends_at && $this->trial_ends_at->isFuture();
    }

    public function isTrialExpired(): bool
    {
        return $this->trial_ends_at && $this->trial_ends_at->isPast();
    }

    public function isExpired(): bool
    {
        return $this->end_date && \Carbon\Carbon::parse($this->end_date)->isPast();
    }

    public function pricingPlan()
    {
        return $this->belongsTo(PricingPlan::class);
    }

    public function company()
    {
        return $this->belongsTo(Company::class);
    }

    public static function getDiscountForCycle(string $cycle): float
    {
        return match ($cycle) {
            'quarterly' => 1.0,
            'semi_annual' => 3.0,
            'annual' => 6.0,
            default => 0.0,
        };
    }

    public static function getMonthsForCycle(string $cycle): int
    {
        return match ($cycle) {
            'quarterly' => 3,
            'semi_annual' => 6,
            'annual' => 12,
            default => 1,
        };
    }

    public static function getCycleLabel(string $cycle): string
    {
        return match ($cycle) {
            'quarterly' => 'Quarterly',
            'semi_annual' => 'Semi-Annual',
            'annual' => 'Annual',
            default => 'Monthly',
        };
    }

    public static function calculateFinalPrice(float $monthlyPrice, string $cycle): array
    {
        $months = self::getMonthsForCycle($cycle);
        $discount = self::getDiscountForCycle($cycle);
        $totalBeforeDiscount = $monthlyPrice * $months;
        $discountAmount = $totalBeforeDiscount * ($discount / 100);
        $finalPrice = $totalBeforeDiscount - $discountAmount;

        return [
            'months' => $months,
            'discount_percent' => $discount,
            'total_before_discount' => round($totalBeforeDiscount, 2),
            'discount_amount' => round($discountAmount, 2),
            'final_price' => round($finalPrice, 2),
            'monthly_effective' => round($finalPrice / $months, 2),
        ];
    }
}

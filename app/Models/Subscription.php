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
        'override_type',
        'override_until',
        'override_granted_at',
        'free_invoice_limit',
        'override_reason',
        'override_by',
        'distributor_quote_snapshot',
    ];

    protected $casts = [
        'trial_ends_at' => 'datetime',
        'start_date' => 'date',
        'end_date' => 'date',
        'discount_percent' => 'decimal:2',
        'final_price' => 'decimal:2',
        'override_until' => 'datetime',
        'override_granted_at' => 'datetime',
        'free_invoice_limit' => 'integer',
        'distributor_quote_snapshot' => 'array',
    ];

    public function hasActiveOverride(): bool
    {
        $type = $this->override_type ?? 'none';
        if ($type === 'none' || !$type) return false;
        if ($type === 'lifetime') return true;
        if ($type === 'usage_free') return true;
        if (in_array($type, ['temporary', 'grace'], true)) {
            return $this->override_until && $this->override_until->isFuture();
        }
        return false;
    }

    public function overrideLabel(): string
    {
        return match ($this->override_type) {
            'lifetime' => 'Lifetime Free',
            'usage_free' => 'Free Invoices (' . ($this->free_invoice_limit ?? 'Unlimited') . ')',
            'temporary' => 'Temporary Access until ' . optional($this->override_until)->format('Y-m-d') . ($this->free_invoice_limit ? " · {$this->free_invoice_limit} invoices" : ''),
            'grace' => 'Grace Period until ' . optional($this->override_until)->format('Y-m-d'),
            default => 'No Override',
        };
    }

    public function isTrialActive(): bool
    {
        return $this->trial_ends_at && $this->trial_ends_at->isFuture();
    }

    public function isTrialExpired(): bool
    {
        if ($this->hasActiveOverride()) {
            return false;
        }
        return $this->trial_ends_at && $this->trial_ends_at->isPast();
    }

    public function isExpired(): bool
    {
        if ($this->hasActiveOverride()) {
            return false;
        }
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

    /**
     * Price for a plan on a given cycle, preferring the rate written ON the
     * plan row (Sep 2026 DI packages carry hand-set quarterly / half-year /
     * annual rates so the printed figure is exactly the approved one).
     *
     * Falls back to the global cycle-discount formula for every plan that has
     * no explicit rate — PRA POS and FBR POS quotes are unchanged.
     */
    public static function priceForPlanCycle(\App\Models\PricingPlan $plan, string $cycle): array
    {
        $explicit = $plan->explicitCyclePrice($cycle);
        $monthly = (float) $plan->sale_price;

        if ($explicit === null) {
            return self::calculateFinalPrice($monthly, $cycle);
        }

        $months = self::getMonthsForCycle($cycle);

        // An active sale campaign discounts the explicit cycle rate too,
        // otherwise a sale would only apply to monthly buyers.
        $salePercent = (float) $plan->sale_percent;
        $finalPrice = $salePercent > 0
            ? round($explicit * (1 - $salePercent / 100))
            : round($explicit, 2);

        $totalBeforeDiscount = round($monthly * $months, 2);
        $discountAmount = max(0, round($totalBeforeDiscount - $finalPrice, 2));
        $discountPercent = $totalBeforeDiscount > 0
            ? round(($discountAmount / $totalBeforeDiscount) * 100, 2)
            : 0.0;

        return [
            'months' => $months,
            'discount_percent' => $discountPercent,
            'total_before_discount' => $totalBeforeDiscount,
            'discount_amount' => $discountAmount,
            'final_price' => $finalPrice,
            'monthly_effective' => round($finalPrice / $months, 2),
        ];
    }
}

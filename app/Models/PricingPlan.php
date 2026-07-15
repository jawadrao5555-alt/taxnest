<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class PricingPlan extends Model
{
    protected $fillable = [
        'name',
        'invoice_limit',
        'user_limit',
        'branch_limit',
        'is_trial',
        'price',
        'price_monthly',
        'compare_at_price',
        'features',
        'product_type',
        'max_terminals',
        'max_users',
        'max_products',
        'inventory_enabled',
        'reports_enabled',
        'restaurant_enabled',
    ];

    protected $casts = [
        'features' => 'array',
        'is_trial' => 'boolean',
        'price' => 'decimal:2',
        'compare_at_price' => 'decimal:2',
        'inventory_enabled' => 'boolean',
        'reports_enabled' => 'boolean',
        'restaurant_enabled' => 'boolean',
        // Integer casts guard the strict `=== -1` unlimited checks (and the
        // `=== 1` singular/plural checks in views) against DB drivers or
        // legacy prod columns that hand these back as strings — a string "-1"
        // silently fails `=== -1` and renders a literal "-1 bills / month".
        'invoice_limit' => 'integer',
        'user_limit' => 'integer',
        'branch_limit' => 'integer',
    ];

    public function subscriptions()
    {
        return $this->hasMany(Subscription::class);
    }

    public function isUnlimitedInvoices(): bool
    {
        return $this->invoice_limit === -1;
    }

    public function isUnlimitedUsers(): bool
    {
        return $this->user_limit === -1;
    }

    public function isUnlimitedBranches(): bool
    {
        return $this->branch_limit === -1;
    }

    public function getInvoiceLimitDisplay(): string
    {
        return $this->invoice_limit === -1 ? 'Unlimited' : number_format($this->invoice_limit);
    }

    public function getUserLimitDisplay(): string
    {
        return $this->user_limit === -1 ? 'Unlimited' : (string) $this->user_limit;
    }

    public function getBranchLimitDisplay(): string
    {
        return $this->branch_limit === -1 ? 'Unlimited' : (string) $this->branch_limit;
    }

    /**
     * The best active sale campaign for this plan's product type (or null).
     */
    public function activeSaleCampaign(): ?SaleCampaign
    {
        return SaleCampaign::activeFor((string) $this->product_type);
    }

    /**
     * Active sale discount percent (0 when no sale is running).
     */
    public function getSalePercentAttribute(): float
    {
        $sale = $this->activeSaleCampaign();
        return $sale ? (float) $sale->discount_percent : 0.0;
    }

    /**
     * The price a customer actually pays right now:
     * base price minus the active sale (rounded to whole rupee), or full price when no sale.
     */
    public function getSalePriceAttribute(): float
    {
        $pct = $this->sale_percent;
        $price = (float) $this->price;

        if ($pct <= 0) {
            return $price;
        }

        return round($price * (1 - $pct / 100));
    }

    /**
     * Whole days left in the active sale (null when the sale has no end date / no sale).
     */
    public function getSaleEndsInDaysAttribute(): ?int
    {
        $sale = $this->activeSaleCampaign();
        if (!$sale || !$sale->ends_at) {
            return null;
        }

        $days = (int) ceil(\Carbon\Carbon::now()->floatDiffInDays($sale->ends_at, false));
        return $days < 0 ? 0 : $days;
    }

    /**
     * Ready-to-render offer badge text, e.g. "30% OFF · ends in 5 days" (empty when no sale).
     */
    public function getSaleBadgeAttribute(): string
    {
        $pct = $this->sale_percent;
        if ($pct <= 0) {
            return '';
        }

        $label = rtrim(rtrim(number_format($pct, 2), '0'), '.') . '% OFF';

        $days = $this->sale_ends_in_days;
        if ($days !== null) {
            if ($days <= 0) {
                $label .= ' · ends today';
            } elseif ($days === 1) {
                $label .= ' · ends in 1 day';
            } else {
                $label .= ' · ends in ' . $days . ' days';
            }
        }

        return $label;
    }
}

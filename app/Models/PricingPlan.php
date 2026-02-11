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
        'features',
    ];

    protected $casts = [
        'features' => 'array',
        'is_trial' => 'boolean',
        'price' => 'decimal:2',
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
}

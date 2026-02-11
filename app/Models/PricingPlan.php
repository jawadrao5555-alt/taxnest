<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class PricingPlan extends Model
{
    protected $fillable = [
        'name',
        'invoice_limit',
        'price'
    ];

    public function subscriptions()
    {
        return $this->hasMany(Subscription::class);
    }
}

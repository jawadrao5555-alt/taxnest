<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class PosAddon extends Model
{
    protected $fillable = [
        'company_id',
        'addon_code',
        'active',
        'billing_cycle',
        'amount',
        'starts_at',
        'ends_at',
        'payment_proof_id',
        'subscription_id',
    ];

    protected $casts = [
        'active' => 'boolean',
        'amount' => 'decimal:2',
        'starts_at' => 'date',
        'ends_at' => 'date',
    ];
}
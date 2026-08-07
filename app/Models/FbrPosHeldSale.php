<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class FbrPosHeldSale extends Model
{
    protected $fillable = [
        'company_id', 'terminal_id', 'user_id', 'hold_name',
        'customer_name', 'customer_phone', 'cart_data', 'notes',
        // Order Matching (Aug 2026): token/code assigned at first hold, preserved on re-hold
        'token_no', 'order_code',
    ];

    protected $casts = ['cart_data' => 'array'];
}

<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class FbrPosHeldSale extends Model
{
    protected $fillable = [
        'company_id', 'terminal_id', 'user_id', 'hold_name',
        'customer_name', 'customer_phone', 'cart_data', 'notes',
    ];

    protected $casts = ['cart_data' => 'array'];
}

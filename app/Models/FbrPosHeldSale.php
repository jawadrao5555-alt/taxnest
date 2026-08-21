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
        // Task 1389: stamped the first time this cart's store slip is rendered
        // or enqueued — the ONLY signal that separates a first print from a
        // reprint on the FBR side (see KotPrintService::isTransactionReprint).
        'kot_sent_at',
    ];

    protected $casts = [
        'cart_data' => 'array',
        'kot_sent_at' => 'datetime',
    ];
}

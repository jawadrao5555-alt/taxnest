<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class PosPrintJob extends Model
{
    protected $table = 'pos_print_jobs';

    protected $fillable = [
        'company_id',
        'type',
        'target_printer',
        'transaction_id',
        'restaurant_order_id',
        'render_query',
        'status',
        'claim_token',
        'printed_item_ids',
        'error',
        'attempts',
        'created_by',
    ];

    protected $casts = [
        'attempts' => 'integer',
        'printed_item_ids' => 'array',
    ];
}

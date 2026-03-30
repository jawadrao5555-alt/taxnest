<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class PosTransactionItem extends Model
{
    protected $fillable = [
        'transaction_id', 'item_type', 'item_id', 'item_name',
        'quantity', 'unit_price', 'subtotal',
        'is_tax_exempt', 'tax_rate', 'tax_amount',
        'item_discount_type', 'item_discount_value', 'item_discount_amount',
    ];

    protected $casts = [
        'quantity' => 'integer',
        'unit_price' => 'decimal:2',
        'subtotal' => 'decimal:2',
        'is_tax_exempt' => 'boolean',
        'tax_rate' => 'decimal:2',
        'tax_amount' => 'decimal:2',
        'item_discount_value' => 'decimal:2',
        'item_discount_amount' => 'decimal:2',
    ];

    public function transaction()
    {
        return $this->belongsTo(PosTransaction::class, 'transaction_id');
    }
}

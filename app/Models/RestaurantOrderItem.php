<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class RestaurantOrderItem extends Model
{
    protected $fillable = [
        'order_id', 'item_type', 'item_id', 'item_name',
        'quantity', 'unit_price', 'subtotal', 'special_notes', 'is_tax_exempt',
        'item_discount_type', 'item_discount_value', 'item_discount_amount',
    ];

    protected $casts = [
        'quantity' => 'decimal:2',
        'unit_price' => 'decimal:2',
        'subtotal' => 'decimal:2',
        'is_tax_exempt' => 'boolean',
        'item_discount_value' => 'decimal:2',
        'item_discount_amount' => 'decimal:2',
    ];

    public function order()
    {
        return $this->belongsTo(RestaurantOrder::class, 'order_id');
    }
}

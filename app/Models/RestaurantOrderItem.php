<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class RestaurantOrderItem extends Model
{
    protected $fillable = [
        'order_id', 'item_type', 'item_id', 'item_name',
        // Yeh line kitchen ki cheez nahi (abhi sirf Delivery Charges) — KOT/KDS
        // par kabhi na chhape. Nishan row ke saath hamesha rehta hai, is liye
        // reprint aur purane orders bhi wahi sach parhte hain.
        'skip_kitchen',
        'quantity', 'unit_price', 'subtotal', 'special_notes', 'is_tax_exempt',
        'item_discount_type', 'item_discount_value', 'item_discount_amount',
        'kot_printed_at', 'kot_batch_no',
    ];

    protected $casts = [
        'quantity' => 'decimal:2',
        'unit_price' => 'decimal:2',
        'subtotal' => 'decimal:2',
        'is_tax_exempt' => 'boolean',
        'skip_kitchen' => 'boolean',
        'item_discount_value' => 'decimal:2',
        'item_discount_amount' => 'decimal:2',
        'kot_printed_at' => 'datetime',
        'was_made' => 'boolean',
    ];

    public function order()
    {
        return $this->belongsTo(RestaurantOrder::class, 'order_id');
    }
}

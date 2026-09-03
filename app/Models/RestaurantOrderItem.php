<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class RestaurantOrderItem extends Model
{
    protected $fillable = [
        'order_id', 'item_type', 'item_id', 'item_name',
        // Waiter "Add Items" replay guard — one uuid per append ATTEMPT, shared
        // by every row that attempt writes and by each of its retries.
        'append_uuid',
        // Yeh line kitchen ki cheez nahi (abhi sirf Delivery Charges) — KOT/KDS
        // par kabhi na chhape. Nishan row ke saath hamesha rehta hai, is liye
        // reprint aur purane orders bhi wahi sach parhte hain.
        'skip_kitchen',
        'quantity', 'unit_price', 'subtotal', 'special_notes', 'is_tax_exempt',
        'deal_snapshot',
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
        'deal_snapshot' => 'array',
    ];

    public function order()
    {
        return $this->belongsTo(RestaurantOrder::class, 'order_id');
    }

    /**
     * Cancel audit state is deliberately three-valued.  In particular, NULL
     * means the question was never recorded (legacy/no-KOT), not "Not made".
     */
    public function madeStateLabel(): string
    {
        if ($this->was_made === true) {
            return 'Made';
        }

        return $this->was_made === false ? 'Not made' : 'Not recorded';
    }
}

<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/** One line of a PurchaseReturn (Task 1580). */
class PurchaseReturnItem extends Model
{
    protected $fillable = [
        'purchase_return_id',
        'product_id',
        'purchase_order_item_id',
        'batch_id',
        'batch_number',
        'expiry_date',
        'quantity',
        'unit_cost',
        'total',
    ];

    protected $casts = [
        'quantity' => 'float',
        'unit_cost' => 'float',
        'total' => 'float',
        'expiry_date' => 'date',
    ];

    public function purchaseReturn()
    {
        return $this->belongsTo(PurchaseReturn::class);
    }

    public function product()
    {
        return $this->belongsTo(Product::class);
    }
}

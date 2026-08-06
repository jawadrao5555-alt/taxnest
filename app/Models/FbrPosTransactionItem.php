<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class FbrPosTransactionItem extends Model
{
    protected $fillable = [
        'transaction_id', 'product_id', 'item_name', 'hs_code', 'uom',
        'quantity', 'unit_price', 'cost_price', 'discount', 'item_discount', 'tax_rate',
        'tax_amount', 'subtotal', 'total', 'is_tax_exempt',
        'returned_quantity', 'parent_item_id', 'promotion_discount',
    ];

    protected $casts = [
        'quantity' => 'decimal:3',
        'unit_price' => 'decimal:2',
        'cost_price' => 'decimal:4',
        'discount' => 'decimal:2',
        'item_discount' => 'decimal:2',
        'tax_rate' => 'decimal:2',
        'tax_amount' => 'decimal:2',
        'subtotal' => 'decimal:2',
        'total' => 'decimal:2',
        'is_tax_exempt' => 'boolean',
    ];

    public function transaction()
    {
        return $this->belongsTo(FbrPosTransaction::class, 'transaction_id');
    }

    public function product()
    {
        return $this->belongsTo(Product::class);
    }
}

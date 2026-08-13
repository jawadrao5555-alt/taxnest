<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class PosTransactionItem extends Model
{
    protected $fillable = [
        'transaction_id', 'item_type', 'item_id', 'item_name',
        'special_notes', 'deal_snapshot',
        'quantity', 'unit_price', 'cost_price', 'subtotal',
        'is_tax_exempt', 'is_third_schedule', 'tax_rate', 'tax_amount',
        'item_discount_type', 'item_discount_value', 'item_discount_amount',
        // Return / credit-note flow (Task 570): link to the original sold line +
        // running returned quantity ON the parent's line (over-return guard).
        'parent_item_id', 'returned_quantity',
    ];

    protected $casts = [
        'deal_snapshot' => 'array',
        'quantity' => 'decimal:3',
        'returned_quantity' => 'decimal:3',
        'unit_price' => 'decimal:2',
        'cost_price' => 'decimal:4',
        'subtotal' => 'decimal:2',
        'is_tax_exempt' => 'boolean',
        'is_third_schedule' => 'boolean',
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

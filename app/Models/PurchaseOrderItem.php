<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class PurchaseOrderItem extends Model
{
    protected $fillable = [
        'purchase_order_id',
        'product_id',
        'quantity',
        'unit_price',
        'total_price',
        'received_quantity',
        // Pharmacy Mode (Task 1558): the batch identity is born on the receiving
        // line, so voiding or querying a purchase can still find its batch.
        'batch_number',
        'expiry_date',
        'retail_price',
        // Task 1580: scheme/bonus + discount. quantity = PAID units,
        // received_quantity = paid + bonus (what actually hit the shelf),
        // net_unit_cost = net line cost spread over every received unit.
        'bonus_qty',
        'discount_pct',
        'discount_amount',
        'net_total',
        'net_unit_cost',
    ];

    protected $casts = [
        'quantity' => 'float',
        'unit_price' => 'float',
        'total_price' => 'float',
        'received_quantity' => 'float',
        'expiry_date' => 'date',
        'retail_price' => 'float',
        'bonus_qty' => 'float',
        'discount_pct' => 'float',
        'discount_amount' => 'float',
        'net_total' => 'float',
        'net_unit_cost' => 'float',
    ];

    /**
     * The cost one received unit carries into stock. Legacy lines (before
     * bonus/discount existed) have NULL net_unit_cost and simply cost their
     * unit_price; a real 0 (bonus-only / fully discounted line) is honoured.
     */
    public function effectiveUnitCost(): float
    {
        $net = $this->getAttribute('net_unit_cost');

        return $net === null ? (float) $this->unit_price : (float) $net;
    }

    /** Units that actually went onto the shelf (paid + bonus). */
    public function receivedUnits(): float
    {
        return (float) $this->received_quantity;
    }

    public function purchaseOrder()
    {
        return $this->belongsTo(PurchaseOrder::class);
    }

    public function product()
    {
        return $this->belongsTo(Product::class);
    }
}

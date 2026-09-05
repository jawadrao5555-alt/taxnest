<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class FbrPosTransactionItem extends Model
{
    protected $fillable = [
        'transaction_id', 'product_id', 'item_name', 'hs_code', 'uom',
        'quantity', 'unit_price', 'cost_price', 'discount', 'item_discount', 'tax_rate',
        'tax_amount', 'subtotal', 'total', 'is_tax_exempt', 'is_third_schedule',
        'returned_quantity',
        // Pharmacy Mode (Task 1558): the batch this line actually left the
        // shelf on, plus the loose-unit count when a strip was broken open.
        // `quantity` stays in stocked packs so every existing report, receipt
        // and FBR payload keeps reading one unit of measure.
        'batch_id',
        'batch_number',
        'batch_expiry',
        'batch_allocation',
        'loose_units', 'parent_item_id', 'promotion_discount',
        // Per-item Store note (Task 1403): typed in the cart, printed on the
        // Store slip. Persisted so a slip REPRINTED after payment still carries
        // the note — before this it was cart-only and silently became blank.
        'special_notes',
        // Peti (Wholesale) Rate (Task 1414): TRUE ⇒ this line billed at the
        // auto peti rate (not the retail rate). Receipt shows a small badge;
        // stock still cuts in pieces, FBR gets the billed rate. Missing from
        // $fillable ⇒ the write is silently dropped (known trap).
        'is_peti_rate',
        // FBR Deals (Task 1273): deal-grouping metadata on component rows —
        // NOT in $fillable ⇒ Eloquent silently drops the write (known trap).
        'deal_group', 'deal_id', 'deal_name', 'deal_quantity', 'deal_unit_price',
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
        'is_third_schedule' => 'boolean',
        // Peti (Wholesale) Rate (Task 1414) — receipt/report marker.
        'is_peti_rate' => 'boolean',
        // Pharmacy Mode (Task 1558).
        'batch_expiry' => 'date',
        'batch_allocation' => 'array',
        'loose_units' => 'decimal:3',
    ];

    /** The batch this line was billed from (pharmacy mode). */
    public function batch()
    {
        return $this->belongsTo(\App\Models\ProductBatch::class, 'batch_id');
    }

    public function transaction()
    {
        return $this->belongsTo(FbrPosTransaction::class, 'transaction_id');
    }

    public function product()
    {
        return $this->belongsTo(Product::class);
    }
}

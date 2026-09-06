<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Goods sent back to a distributor (Task 1580) — surplus, wrong item,
 * damaged in transit, or an expiry the distributor takes back informally.
 *
 * Stock leaves through the inventory ledger (return_out movement, batch
 * decremented when tracking is on) and the credit_amount lands on the
 * supplier ledger as a credit note. Distinct from a pharmacy CLAIM (which is
 * a raised list awaiting the distributor's answer): a return is the goods
 * physically leaving right now against an agreed value.
 */
class PurchaseReturn extends Model
{
    public const REASONS = ['surplus', 'wrong', 'damaged', 'expired', 'other'];

    public const STATUS_POSTED = 'posted';

    protected $fillable = [
        'company_id',
        'branch_id',
        'supplier_id',
        'purchase_order_id',
        'return_number',
        'reason',
        'supplier_reference',
        'credit_amount',
        'status',
        'returned_on',
        'notes',
        'created_by',
    ];

    protected $casts = [
        'credit_amount' => 'float',
        'returned_on' => 'date',
    ];

    public function items()
    {
        return $this->hasMany(PurchaseReturnItem::class);
    }

    public function supplier()
    {
        return $this->belongsTo(Supplier::class);
    }

    public function purchaseOrder()
    {
        return $this->belongsTo(PurchaseOrder::class);
    }

    public function branch()
    {
        return $this->belongsTo(Branch::class);
    }

    public function creator()
    {
        return $this->belongsTo(User::class, 'created_by');
    }
}

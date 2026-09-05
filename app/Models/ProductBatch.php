<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * One batch of one medicine, in one branch (Task 1558).
 *
 * This table is a SUB-LEDGER beneath inventory_stocks, never a replacement:
 * inventory_stocks.quantity stays the single number every existing report, sale
 * path and FBR figure reads. A pharmacy simply also knows how that number is
 * split across batch numbers and expiry dates, so it can say which batch it
 * sold and what it may claim back from its distributor.
 *
 * A shop with no batch data at all keeps working unchanged — the batch total is
 * then simply zero and the aggregate carries everything (see
 * PharmacyBatchService::untrackedQuantity()).
 */
class ProductBatch extends Model
{
    public const STATUS_ACTIVE = 'active';
    public const STATUS_QUARANTINED = 'quarantined';
    public const STATUS_WRITTEN_OFF = 'written_off';

    protected $fillable = [
        'company_id',
        'product_id',
        'branch_id',
        'batch_number',
        'expiry_date',
        'quantity',
        'cost_price',
        'retail_price',
        'supplier_id',
        'purchase_order_id',
        'status',
        'received_at',
        'notes',
        'created_by',
    ];

    protected $casts = [
        'quantity' => 'float',
        'cost_price' => 'float',
        'retail_price' => 'float',
        'expiry_date' => 'date',
        'received_at' => 'date',
    ];

    public function product()
    {
        return $this->belongsTo(Product::class);
    }

    public function branch()
    {
        return $this->belongsTo(Branch::class);
    }

    public function supplier()
    {
        return $this->belongsTo(Supplier::class);
    }

    /** Sellable right now: active status, real stock, and not past its date. */
    public function isSellable(): bool
    {
        return $this->status === self::STATUS_ACTIVE
            && (float) $this->quantity > 0
            && !$this->isExpired();
    }

    public function isExpired(): bool
    {
        if (!$this->expiry_date) {
            return false;
        }
        // A medicine is usable THROUGH the last day of its expiry month, so the
        // comparison is against the end of the stored day, never "now".
        return $this->expiry_date->endOfDay()->isPast();
    }

    /** Days left before expiry; null when the batch carries no date at all. */
    public function daysToExpiry(): ?int
    {
        if (!$this->expiry_date) {
            return null;
        }
        return (int) now()->startOfDay()->diffInDays($this->expiry_date->startOfDay(), false);
    }
}

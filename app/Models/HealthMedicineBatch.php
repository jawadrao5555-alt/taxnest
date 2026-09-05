<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * One lot of a medicine, in one branch (Task 1549).
 *
 * `quantity` is the batch remainder. The sum of all active + quarantined
 * batches for a (medicine, branch) pair must equal the matching
 * `inventory_stocks.quantity`; HealthPharmacyStockService is the only writer
 * and keeps both sides in one transaction.
 */
class HealthMedicineBatch extends Model
{
    public const STATUS_ACTIVE = 'active';
    public const STATUS_QUARANTINED = 'quarantined';
    public const STATUS_WRITTEN_OFF = 'written_off';

    protected $fillable = [
        'company_id',
        'branch_id',
        'medicine_id',
        'product_id',
        'batch_no',
        'expiry_date',
        'manufacture_date',
        'received_quantity',
        'quantity',
        'cost_price',
        'sale_price',
        'supplier_id',
        'purchase_order_id',
        'purchase_order_item_id',
        'status',
        'quarantine_reason',
        'notes',
        'created_by',
    ];

    protected $casts = [
        'expiry_date' => 'date',
        'manufacture_date' => 'date',
        'received_quantity' => 'float',
        'quantity' => 'float',
        'cost_price' => 'float',
        'sale_price' => 'float',
    ];

    protected static function booted()
    {
        static::addGlobalScope(new \App\Models\Scopes\CompanyScope);
    }

    public function medicine()
    {
        return $this->belongsTo(HealthMedicine::class, 'medicine_id');
    }

    public function supplier()
    {
        return $this->belongsTo(Supplier::class);
    }

    public function branch()
    {
        return $this->belongsTo(Branch::class);
    }

    /** A batch with no expiry date never expires (devices, some consumables). */
    public function isExpired(?\DateTimeInterface $on = null): bool
    {
        if (!$this->expiry_date) {
            return false;
        }

        return $this->expiry_date->startOfDay()->lt(($on ? \Illuminate\Support\Carbon::instance($on) : now())->startOfDay());
    }

    public function daysToExpiry(): ?int
    {
        if (!$this->expiry_date) {
            return null;
        }

        return (int) now()->startOfDay()->diffInDays($this->expiry_date->startOfDay(), false);
    }

    public function isShortDated(int $withinDays): bool
    {
        $days = $this->daysToExpiry();

        return $days !== null && $days >= 0 && $days <= $withinDays;
    }

    /** Only an active, unexpired, non-empty batch may be dispensed from. */
    public function scopeSellable($query)
    {
        return $query->where('status', self::STATUS_ACTIVE)->where('quantity', '>', 0);
    }
}

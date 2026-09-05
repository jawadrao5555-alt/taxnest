<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * The per-batch ledger (Task 1549).
 *
 * The shared `inventory_movements` table records that stock moved; this one
 * records WHICH LOT moved, why, and against which document — the traceability
 * a pharmacy inspection asks for. Both are written together by
 * HealthPharmacyStockService.
 */
class HealthBatchMovement extends Model
{
    public const TYPE_PURCHASE = 'purchase';
    public const TYPE_OPENING = 'opening';
    public const TYPE_DISPENSE = 'dispense';
    public const TYPE_SALE_RETURN = 'sale_return';
    public const TYPE_PURCHASE_RETURN = 'purchase_return';
    public const TYPE_WASTAGE = 'wastage';
    public const TYPE_EXPIRY_WRITEOFF = 'expiry_writeoff';
    public const TYPE_QUARANTINE = 'quarantine';
    public const TYPE_RELEASE = 'release';
    public const TYPE_TRANSFER_IN = 'transfer_in';
    public const TYPE_TRANSFER_OUT = 'transfer_out';
    public const TYPE_ADJUSTMENT_IN = 'adjustment_in';
    public const TYPE_ADJUSTMENT_OUT = 'adjustment_out';

    public const DIRECTION_IN = 'in';
    public const DIRECTION_OUT = 'out';
    public const DIRECTION_NONE = 'none';

    /** Reasons a manual out-movement may carry. */
    public const REASONS = [
        'damaged', 'breakage', 'spillage', 'expired', 'recall',
        'theft', 'count_correction', 'sample', 'other',
    ];

    protected $fillable = [
        'company_id',
        'branch_id',
        'batch_id',
        'medicine_id',
        'product_id',
        'type',
        'direction',
        'quantity',
        'balance_after',
        'unit_cost',
        'unit_price',
        'reference_type',
        'reference_id',
        'reference_number',
        'reason',
        'notes',
        'created_by',
    ];

    protected $casts = [
        'quantity' => 'float',
        'balance_after' => 'float',
        'unit_cost' => 'float',
        'unit_price' => 'float',
    ];

    protected static function booted()
    {
        static::addGlobalScope(new \App\Models\Scopes\CompanyScope);
    }

    public static function typeLabelKey(?string $type): string
    {
        return 'health.movement_' . ($type ?: 'other');
    }

    public function batch()
    {
        return $this->belongsTo(HealthMedicineBatch::class, 'batch_id');
    }

    public function medicine()
    {
        return $this->belongsTo(HealthMedicine::class, 'medicine_id');
    }

    public function creator()
    {
        return $this->belongsTo(User::class, 'created_by');
    }
}

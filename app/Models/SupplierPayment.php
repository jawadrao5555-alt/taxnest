<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Money handed to a distributor / supplier from the FBR POS stock module
 * (Task 1580). POS-side twin of HealthSupplierPayment — company + branch
 * scoped, no global scope (the FBR panel resolves its company explicitly).
 *
 * A payment is never edited: a wrong one is VOIDED (status=void, audit-logged)
 * and re-entered, so the ledger's history stays honest.
 */
class SupplierPayment extends Model
{
    public const METHODS = ['cash', 'bank', 'online', 'cheque'];

    public const STATUS_ACTIVE = 'active';
    public const STATUS_VOID = 'void';

    protected $fillable = [
        'company_id',
        'branch_id',
        'supplier_id',
        'purchase_order_id',
        'amount',
        'method',
        'paid_on',
        'reference',
        'notes',
        'status',
        'voided_at',
        'voided_by',
        'void_reason',
        'created_by',
    ];

    protected $casts = [
        'amount' => 'float',
        'paid_on' => 'date',
        'voided_at' => 'datetime',
    ];

    public function supplier()
    {
        return $this->belongsTo(Supplier::class);
    }

    public function purchaseOrder()
    {
        return $this->belongsTo(PurchaseOrder::class);
    }

    public function creator()
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function isVoid(): bool
    {
        return $this->status === self::STATUS_VOID;
    }

    public function scopeActive($query)
    {
        return $query->where('status', self::STATUS_ACTIVE);
    }
}

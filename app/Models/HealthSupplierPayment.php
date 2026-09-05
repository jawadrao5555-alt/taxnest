<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Money paid to a medicine supplier.
 *
 * Purchases themselves stay in the shared `purchase_orders` table so the
 * platform's inventory history keeps working; only the payment side is
 * healthcare-owned. Supplier balance = purchases billed − payments recorded.
 */
class HealthSupplierPayment extends Model
{
    public const METHODS = ['cash', 'bank', 'cheque', 'online', 'adjustment'];

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
        'created_by',
    ];

    protected $casts = [
        'amount' => 'float',
        'paid_on' => 'date',
    ];

    protected static function booted()
    {
        static::addGlobalScope(new \App\Models\Scopes\CompanyScope);
    }

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
}

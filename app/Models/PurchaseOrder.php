<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class PurchaseOrder extends Model
{
    protected $fillable = [
        'company_id',
        'supplier_id',
        'branch_id',
        'po_number',
        // Task 1580: distributor bill number + discount breakdown. total_amount
        // stays "what the shop owes for this bill" — now the NET figure.
        'supplier_invoice_no',
        'status',
        'order_date',
        'expected_date',
        'received_date',
        'total_amount',
        'gross_amount',
        'line_discount_amount',
        'invoice_discount_amount',
        'notes',
        'created_by',
    ];

    protected $casts = [
        'order_date' => 'date',
        'expected_date' => 'date',
        'received_date' => 'date',
        'total_amount' => 'float',
        'gross_amount' => 'float',
        'line_discount_amount' => 'float',
        'invoice_discount_amount' => 'float',
    ];

    const STATUS_DRAFT = 'draft';
    const STATUS_ORDERED = 'ordered';
    const STATUS_PARTIAL = 'partial';
    const STATUS_RECEIVED = 'received';
    const STATUS_CANCELLED = 'cancelled';

    public function company()
    {
        return $this->belongsTo(Company::class);
    }

    public function supplier()
    {
        return $this->belongsTo(Supplier::class);
    }

    public function branch()
    {
        return $this->belongsTo(Branch::class);
    }

    public function items()
    {
        return $this->hasMany(PurchaseOrderItem::class);
    }

    public function creator()
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    /** Money handed to the distributor against this bill (Task 1580). */
    public function supplierPayments()
    {
        return $this->hasMany(SupplierPayment::class);
    }

    /** Goods sent back from this bill (Task 1580). */
    public function purchaseReturns()
    {
        return $this->hasMany(PurchaseReturn::class);
    }

    public function scopeForCompany($query, $companyId)
    {
        return $query->where('company_id', $companyId);
    }
}

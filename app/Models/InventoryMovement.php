<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class InventoryMovement extends Model
{
    protected $fillable = [
        'company_id',
        'product_id',
        'branch_id',
        'type',
        'quantity',
        'unit_price',
        'total_price',
        'balance_after',
        'reference_type',
        'reference_id',
        'reference_number',
        'notes',
        'created_by',
    ];

    protected $casts = [
        'quantity' => 'float',
        'unit_price' => 'float',
        'total_price' => 'float',
        'balance_after' => 'float',
    ];

    const TYPE_PURCHASE = 'purchase';
    const TYPE_SALE = 'sale';
    const TYPE_ADJUSTMENT_IN = 'adjustment_in';
    const TYPE_ADJUSTMENT_OUT = 'adjustment_out';
    const TYPE_RETURN_IN = 'return_in';
    const TYPE_RETURN_OUT = 'return_out';
    const TYPE_TRANSFER_IN = 'transfer_in';
    const TYPE_TRANSFER_OUT = 'transfer_out';
    const TYPE_OPENING = 'opening';

    public function company()
    {
        return $this->belongsTo(Company::class);
    }

    public function product()
    {
        return $this->belongsTo(Product::class);
    }

    public function branch()
    {
        return $this->belongsTo(Branch::class);
    }

    public function creator()
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function isIncoming()
    {
        return in_array($this->type, [
            self::TYPE_PURCHASE, self::TYPE_ADJUSTMENT_IN,
            self::TYPE_RETURN_IN, self::TYPE_TRANSFER_IN, self::TYPE_OPENING,
        ]);
    }
}

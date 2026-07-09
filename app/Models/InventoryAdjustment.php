<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class InventoryAdjustment extends Model
{
    protected $fillable = [
        'company_id',
        'product_id',
        'type',
        'quantity',
        'previous_quantity',
        'new_quantity',
        'reason',
        'notes',
        'created_by',
    ];

    protected $casts = [
        'quantity' => 'float',
        'previous_quantity' => 'float',
        'new_quantity' => 'float',
    ];

    const TYPE_ADD = 'add';
    const TYPE_REMOVE = 'remove';
    const TYPE_SET = 'set';

    public function company()
    {
        return $this->belongsTo(Company::class);
    }

    public function product()
    {
        return $this->belongsTo(Product::class);
    }

    // POS panel rows store pos_products ids in the same product_id column
    // (DI and POS companies are fully isolated, so ids never mix per-company).
    public function posProduct()
    {
        return $this->belongsTo(PosProduct::class, 'product_id');
    }

    public function creator()
    {
        return $this->belongsTo(User::class, 'created_by');
    }
}

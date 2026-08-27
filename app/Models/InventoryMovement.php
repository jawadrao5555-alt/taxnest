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
        // In-transit branch transfers (Task 1434): state lives on the
        // TRANSFER_OUT row so both branches read one record. Without these two
        // in $fillable the mass-assign silently drops them and every transfer
        // would look instantly-received again.
        'transfer_status',
        'received_quantity',
        'notes',
        'created_by',
    ];

    protected $casts = [
        'quantity' => 'float',
        'unit_price' => 'float',
        'total_price' => 'float',
        'balance_after' => 'float',
        'received_quantity' => 'float',
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
    const TYPE_RECIPE_SALE = 'recipe_sale';
    const TYPE_RECIPE_RETURN = 'recipe_return';

    // In-transit branch transfers (Task 1434). Only the TRANSFER_OUT row of a
    // branch_transfer carries one of these; every other movement leaves the
    // column NULL.
    const TRANSFER_IN_TRANSIT = 'in_transit';
    const TRANSFER_RECEIVED = 'received';
    const TRANSFER_CANCELLED = 'cancelled';

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

    /** A branch transfer whose maal has left the source but not yet arrived. */
    public function isInTransit()
    {
        return $this->transfer_status === self::TRANSFER_IN_TRANSIT;
    }
}

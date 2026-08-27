<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * One item on a physical stock-count sheet.
 *
 * item_type keeps the three-tier catalogue honest without adding a column to
 * pos_products: a sellable/menu item is a `product` row, a raw material is an
 * `ingredient` row, and a kitchen-prepared item is simply a product that owns a
 * recipe. The name/code/unit are SNAPSHOTS so a later rename never rewrites a
 * signed-off count.
 */
class StockCheckLine extends Model
{
    public const TYPE_PRODUCT = 'product';
    public const TYPE_INGREDIENT = 'ingredient';

    /** Why the count did not match. Free text stays in `notes`. */
    public const REASONS = ['wastage', 'breakage', 'theft', 'entry_error', 'expired', 'staff_use', 'other'];

    protected $fillable = [
        'company_id', 'stock_check_id', 'item_type', 'item_id', 'branch_id',
        'item_name', 'item_code', 'unit',
        'expected_quantity', 'counted_quantity', 'variance',
        'unit_cost', 'variance_value', 'reason', 'notes',
        'counted_by', 'counted_at',
    ];

    protected $casts = [
        'expected_quantity' => 'float',
        'counted_quantity' => 'float',
        'variance' => 'float',
        'unit_cost' => 'float',
        'variance_value' => 'float',
        'counted_at' => 'datetime',
    ];

    public function stockCheck()
    {
        return $this->belongsTo(StockCheck::class);
    }

    public function isCounted(): bool
    {
        return $this->counted_quantity !== null;
    }

    /** Short = the shelf has LESS than the system expected. */
    public function isShort(): bool
    {
        return $this->isCounted() && $this->variance < 0;
    }

    public function isExcess(): bool
    {
        return $this->isCounted() && $this->variance > 0;
    }
}

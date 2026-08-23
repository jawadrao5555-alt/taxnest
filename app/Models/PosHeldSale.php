<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * A PRA retail cart parked mid-bill ("bill rok dein") so the counter can serve
 * the next customer. It is a JSON cart — NOT a PosTransaction — so it never
 * touches invoice numbering, PRA reporting, stock or day-close totals.
 */
class PosHeldSale extends Model
{
    protected $table = 'pos_held_sales';

    protected $fillable = [
        'company_id', 'user_id', 'hold_name',
        'customer_id', 'customer_name', 'customer_phone',
        'total_amount', 'item_count', 'cart_data', 'hold_uuid',
    ];

    protected $casts = [
        'cart_data' => 'array',
        'total_amount' => 'decimal:2',
        'item_count' => 'integer',
    ];

    /** Company-wide list: any counter can pick up a bill any counter parked. */
    public function scopeForCompany($query, int $companyId)
    {
        return $query->where('company_id', $companyId);
    }
}

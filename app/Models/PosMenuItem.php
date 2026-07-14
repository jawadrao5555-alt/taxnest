<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * A POS product pinned onto the company's PUBLIC menu page (F8).
 * Prices are never stored here — always read live from the related
 * PosProduct so the public menu can never go stale.
 */
class PosMenuItem extends Model
{
    protected $fillable = [
        'company_id',
        'pos_product_id',
        'sort_order',
        'is_active',
    ];

    protected $casts = [
        'is_active' => 'boolean',
        'sort_order' => 'integer',
    ];

    public function product()
    {
        return $this->belongsTo(PosProduct::class, 'pos_product_id');
    }
}

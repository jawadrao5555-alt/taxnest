<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class FbrPosDealItem extends Model
{
    protected $table = 'fbr_pos_deal_items';

    protected $fillable = [
        'deal_id', 'product_id', 'quantity',
    ];

    protected $casts = [
        'quantity' => 'integer',
    ];

    public function deal()
    {
        return $this->belongsTo(FbrPosDeal::class, 'deal_id');
    }

    /**
     * Shared `products` table (FBR POS catalog). Product has NO global
     * company scope — callers must verify the parent deal's company_id
     * before trusting this relation cross-tenant.
     */
    public function product()
    {
        return $this->belongsTo(Product::class, 'product_id');
    }
}

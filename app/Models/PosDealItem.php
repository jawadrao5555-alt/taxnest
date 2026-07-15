<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class PosDealItem extends Model
{
    protected $table = 'pos_deal_items';

    protected $fillable = [
        'deal_id', 'pos_product_id', 'quantity',
    ];

    protected $casts = [
        'quantity' => 'integer',
    ];

    public function deal()
    {
        return $this->belongsTo(PosDeal::class, 'deal_id');
    }

    /**
     * PosProduct has NO global CompanyScope — callers must verify the parent
     * deal's company_id before trusting this relation cross-tenant.
     */
    public function posProduct()
    {
        return $this->belongsTo(PosProduct::class, 'pos_product_id');
    }
}

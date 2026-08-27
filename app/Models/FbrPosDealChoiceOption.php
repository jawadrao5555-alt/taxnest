<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class FbrPosDealChoiceOption extends Model
{
    protected $table = 'fbr_pos_deal_choice_options';

    protected $fillable = [
        'group_id', 'product_id',
    ];

    public function group()
    {
        return $this->belongsTo(FbrPosDealChoiceGroup::class, 'group_id');
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

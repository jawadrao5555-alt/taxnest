<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class PosDealChoiceOption extends Model
{
    protected $table = 'pos_deal_choice_options';

    protected $fillable = [
        'group_id', 'pos_product_id',
    ];

    public function group()
    {
        return $this->belongsTo(PosDealChoiceGroup::class, 'group_id');
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

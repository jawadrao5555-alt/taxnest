<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * A required "pick one product" slot inside a deal (e.g. "Pizza Flavor",
 * "Drink"). Task 1531. Sits alongside — never replaces — the deal's fixed
 * PosDealItem components.
 */
class PosDealChoiceGroup extends Model
{
    protected $table = 'pos_deal_choice_groups';

    protected $fillable = [
        'deal_id', 'label', 'quantity', 'sort_order',
    ];

    protected $casts = [
        'quantity' => 'integer',
        'sort_order' => 'integer',
    ];

    public function deal()
    {
        return $this->belongsTo(PosDeal::class, 'deal_id');
    }

    public function options()
    {
        return $this->hasMany(PosDealChoiceOption::class, 'group_id');
    }
}

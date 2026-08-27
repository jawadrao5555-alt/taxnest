<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * FBR twin of PosDealChoiceGroup (Task 1531) — a required "pick one product"
 * slot inside an FBR POS deal. Sits alongside — never replaces — the deal's
 * fixed FbrPosDealItem components.
 */
class FbrPosDealChoiceGroup extends Model
{
    protected $table = 'fbr_pos_deal_choice_groups';

    protected $fillable = [
        'deal_id', 'label', 'quantity', 'sort_order',
    ];

    protected $casts = [
        'quantity' => 'integer',
        'sort_order' => 'integer',
    ];

    public function deal()
    {
        return $this->belongsTo(FbrPosDeal::class, 'deal_id');
    }

    public function options()
    {
        return $this->hasMany(FbrPosDealChoiceOption::class, 'group_id');
    }
}

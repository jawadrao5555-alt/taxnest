<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class IngredientMovement extends Model
{
    protected $fillable = [
        'company_id', 'ingredient_id', 'branch_id', 'type', 'quantity',
        'balance_after', 'reference_type', 'reference_id', 'reference_number',
        'snapshot', 'created_by',
    ];

    protected $casts = [
        'quantity' => 'float',
        'balance_after' => 'float',
        'snapshot' => 'array',
    ];

    public function ingredient()
    {
        return $this->belongsTo(Ingredient::class);
    }
}
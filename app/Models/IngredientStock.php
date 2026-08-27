<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class IngredientStock extends Model
{
    protected $fillable = [
        'company_id', 'ingredient_id', 'branch_id', 'quantity', 'min_stock_level',
    ];

    protected $casts = [
        'quantity' => 'float',
        'min_stock_level' => 'float',
    ];

    public function ingredient()
    {
        return $this->belongsTo(Ingredient::class);
    }

    public function branch()
    {
        return $this->belongsTo(Branch::class);
    }
}
<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Ingredient extends Model
{
    protected $fillable = [
        'company_id', 'code', 'name', 'unit', 'base_unit', 'conversion_factor', 'cost_per_unit',
        'current_stock', 'min_stock_level', 'is_active',
    ];

    protected $casts = [
        'cost_per_unit' => 'decimal:2',
        'current_stock' => 'decimal:4',
        'min_stock_level' => 'decimal:4',
        'conversion_factor' => 'decimal:4',
        'is_active' => 'boolean',
    ];

    public function company()
    {
        return $this->belongsTo(Company::class);
    }

    public function recipes()
    {
        return $this->hasMany(ProductRecipe::class);
    }

    public function stocks()
    {
        return $this->hasMany(IngredientStock::class);
    }

    public function movements()
    {
        return $this->hasMany(IngredientMovement::class);
    }

    public function isLowStock()
    {
        return $this->current_stock <= $this->min_stock_level;
    }
}

<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Ingredient extends Model
{
    protected $fillable = [
        'company_id', 'name', 'unit', 'cost_per_unit',
        'current_stock', 'min_stock_level', 'is_active',
    ];

    protected $casts = [
        'cost_per_unit' => 'decimal:2',
        'current_stock' => 'decimal:2',
        'min_stock_level' => 'decimal:2',
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

    public function isLowStock()
    {
        return $this->current_stock <= $this->min_stock_level;
    }
}

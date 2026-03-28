<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class ProductRecipe extends Model
{
    protected $fillable = ['company_id', 'product_id', 'ingredient_id', 'quantity_needed'];

    protected $casts = ['quantity_needed' => 'decimal:4'];

    public function company()
    {
        return $this->belongsTo(Company::class);
    }

    public function product()
    {
        return $this->belongsTo(PosProduct::class, 'product_id');
    }

    public function ingredient()
    {
        return $this->belongsTo(Ingredient::class);
    }
}

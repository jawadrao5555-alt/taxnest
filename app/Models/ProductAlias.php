<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class ProductAlias extends Model
{
    protected $fillable = ['company_id', 'product_id', 'alias', 'is_active'];

    protected $casts = ['is_active' => 'boolean'];

    public function product()
    {
        return $this->belongsTo(Product::class);
    }
}
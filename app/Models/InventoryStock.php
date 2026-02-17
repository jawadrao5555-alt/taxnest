<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class InventoryStock extends Model
{
    protected $fillable = [
        'company_id',
        'product_id',
        'branch_id',
        'quantity',
        'min_stock_level',
        'max_stock_level',
        'avg_purchase_price',
        'last_purchase_price',
    ];

    protected $casts = [
        'quantity' => 'float',
        'min_stock_level' => 'float',
        'max_stock_level' => 'float',
        'avg_purchase_price' => 'float',
        'last_purchase_price' => 'float',
    ];

    public function company()
    {
        return $this->belongsTo(Company::class);
    }

    public function product()
    {
        return $this->belongsTo(Product::class);
    }

    public function branch()
    {
        return $this->belongsTo(Branch::class);
    }

    public function isLowStock()
    {
        return $this->min_stock_level > 0 && $this->quantity <= $this->min_stock_level;
    }

    public function scopeForCompany($query, $companyId)
    {
        return $query->where('company_id', $companyId);
    }

    public function scopeLowStock($query)
    {
        return $query->whereColumn('quantity', '<=', 'min_stock_level')
                     ->where('min_stock_level', '>', 0);
    }
}

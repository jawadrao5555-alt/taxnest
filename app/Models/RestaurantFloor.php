<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class RestaurantFloor extends Model
{
    protected $fillable = ['company_id', 'name', 'sort_order', 'is_active'];

    protected $casts = ['is_active' => 'boolean'];

    public function company()
    {
        return $this->belongsTo(Company::class);
    }

    public function tables()
    {
        return $this->hasMany(RestaurantTable::class, 'floor_id');
    }
}

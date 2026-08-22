<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

class PosCustomerPlace extends Model
{
    use SoftDeletes;

    protected $fillable = [
        'company_id', 'customer_id', 'customer_phone', 'place_type', 'label',
        'address', 'lat', 'lng', 'accuracy_m', 'is_verified', 'verified_at',
        'last_used_at', 'usage_count', 'created_from', 'updated_by',
        'deleted_by', 'merged_into_id', 'merged_by',
    ];

    protected $casts = [
        'lat' => 'float',
        'lng' => 'float',
        'accuracy_m' => 'integer',
        'is_verified' => 'boolean',
        'verified_at' => 'datetime',
        'last_used_at' => 'datetime',
        'usage_count' => 'integer',
        'deleted_at' => 'datetime',
    ];

    public function customer()
    {
        return $this->belongsTo(PosCustomer::class, 'customer_id');
    }
}
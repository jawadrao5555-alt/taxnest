<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class HotelStayGuest extends Model
{
    protected $fillable = [
        'company_id', 'stay_id', 'customer_id', 'is_primary',
        'name', 'phone', 'cnic',
    ];

    protected $casts = [
        'is_primary' => 'boolean',
    ];

    public function stay(): BelongsTo
    {
        return $this->belongsTo(HotelStay::class, 'stay_id');
    }

    public function customer(): BelongsTo
    {
        return $this->belongsTo(PosCustomer::class, 'customer_id');
    }
}

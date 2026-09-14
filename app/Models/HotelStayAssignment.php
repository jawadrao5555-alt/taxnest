<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class HotelStayAssignment extends Model
{
    protected $fillable = [
        'company_id', 'stay_id', 'room_id', 'from_date', 'to_date',
        'rate_amount', 'rate_unit',
    ];

    protected $casts = [
        'from_date' => 'date',
        'to_date' => 'date',
        'rate_amount' => 'decimal:2',
    ];

    public function stay(): BelongsTo
    {
        return $this->belongsTo(HotelStay::class, 'stay_id');
    }

    public function room(): BelongsTo
    {
        return $this->belongsTo(HotelRoom::class, 'room_id');
    }
}

<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class HotelCreditNote extends Model
{
    protected $fillable = ['company_id', 'stay_id', 'original_transaction_id',
        'credit_transaction_id', 'created_by', 'request_key', 'reason', 'selection'];
    protected $casts = ['selection' => 'array'];
}

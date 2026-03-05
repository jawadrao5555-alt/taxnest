<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class PraLog extends Model
{
    protected $fillable = [
        'company_id', 'transaction_id', 'request_payload', 'response_payload',
        'response_code', 'status',
    ];

    protected $casts = [
        'request_payload' => 'array',
        'response_payload' => 'array',
    ];

    public function company()
    {
        return $this->belongsTo(Company::class);
    }

    public function transaction()
    {
        return $this->belongsTo(PosTransaction::class, 'transaction_id');
    }
}

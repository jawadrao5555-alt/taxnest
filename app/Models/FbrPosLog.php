<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class FbrPosLog extends Model
{
    protected $fillable = [
        'company_id', 'transaction_id', 'request_payload',
        'response_payload', 'response_code', 'status', 'error_message',
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
        return $this->belongsTo(FbrPosTransaction::class, 'transaction_id');
    }
}

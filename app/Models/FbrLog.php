<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class FbrLog extends Model
{
    protected $fillable = [
        'invoice_id',
        'request_payload',
        'response_payload',
        'status',
        'failure_type',
        'response_time_ms',
        'retry_count'
    ];

    public function invoice()
    {
        return $this->belongsTo(Invoice::class);
    }
}

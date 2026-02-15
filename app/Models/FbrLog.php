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
        'retry_count',
        'environment_used',
        'failure_category',
        'submission_latency_ms',
        'request_payload_hash',
    ];

    public function invoice()
    {
        return $this->belongsTo(Invoice::class);
    }
}

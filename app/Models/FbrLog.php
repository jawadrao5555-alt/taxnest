<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class FbrLog extends Model
{
    protected $fillable = [
        'invoice_id',
        'request_payload',
        'response_payload',
        'status'
    ];

    public function invoice()
    {
        return $this->belongsTo(Invoice::class);
    }
}

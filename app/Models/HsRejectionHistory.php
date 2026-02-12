<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class HsRejectionHistory extends Model
{
    public $timestamps = false;

    protected $table = 'hs_rejection_history';

    protected $fillable = [
        'hs_code',
        'rejection_count',
        'last_rejection_reason',
        'error_code',
        'error_message',
        'last_rejected_at',
        'environment',
        'last_seen_at',
        'updated_at',
    ];

    protected $casts = [
        'rejection_count' => 'integer',
        'last_rejected_at' => 'datetime',
        'last_seen_at' => 'datetime',
        'updated_at' => 'datetime',
    ];
}
